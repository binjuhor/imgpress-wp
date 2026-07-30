<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * R2_Client — S3-compatible Cloudflare R2 client with native AWS SigV4 signing.
 * No external dependencies. Supports object transfer, deletion, listing, and bucket checks.
 */
class R2_Client
{
    private const REGION = 'auto';
    private const SERVICE = 's3';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private Settings $settings,
    ) {
    }

    /**
     * Upload file to R2 via SigV4-signed PUT request.
     *
     * @param string $key        Object key in bucket (e.g., 'uploads/photo.jpg')
     * @param string $data       File contents (binary string)
     * @param string $contentType MIME type (e.g., 'image/jpeg')
     * @param array  $metadata    Standard and custom S3 object metadata
     *
     * @return array{ok: bool, status: int, etag?: string, size?: int, error?: string}
     */
    public function putObject(
        string $key,
        string $data,
        string $contentType = 'application/octet-stream',
        array $metadata = []
    ): array
    {
        if ($this->isStaticAsset($key) && empty($metadata['CacheControl'])) {
            $metadata['CacheControl'] = method_exists($this->settings, 'getR2CacheControl')
                ? $this->settings->getR2CacheControl()
                : 'public, max-age=31536000, immutable';
        }

        $method = 'PUT';
        $payloadHash = $this->hashPayload($data);
        $headers = [
            'content-type' => $contentType,
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $this->getAmzDate(),
        ];
        $headers = array_merge($headers, $this->metadataHeaders($metadata));

        $signedHeaders = $this->signRequest($method, $key, $headers, $payloadHash);

        $url = $this->getEndpoint() . $this->getObjectPath($key);

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $signedHeaders,
            'body' => $data,
            'timeout' => $this->settings->getRequestTimeout(),
            'sslverify' => true,
            'blocking' => true,
        ]);

        return $this->parseResponse($response);
    }

    /** Read object headers and metadata without downloading its body. */
    public function headObject(string $key): array
    {
        $payloadHash = $this->hashPayload('');
        $headers = [
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $this->getAmzDate(),
        ];
        $signedHeaders = $this->signRequest('HEAD', $key, $headers, $payloadHash);
        $response = wp_remote_request($this->getEndpoint() . $this->getObjectPath($key), [
            'method' => 'HEAD',
            'headers' => $signedHeaders,
            'timeout' => $this->settings->getRequestTimeout(),
            'sslverify' => true,
            'blocking' => true,
        ]);
        $parsed = $this->parseResponse($response);
        if (!$parsed['ok']) {
            return $parsed;
        }

        $parsed['metadata'] = $this->objectMetadata(wp_remote_retrieve_headers($response));

        return $parsed;
    }

    /** Replace metadata on an existing object by copying it onto itself. */
    public function copyObject(string $key, array $metadata): array
    {
        $payloadHash = $this->hashPayload('');
        $headers = [
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-copy-source' => $this->getObjectPath($key),
            'x-amz-date' => $this->getAmzDate(),
            'x-amz-metadata-directive' => 'REPLACE',
        ];
        $headers = array_merge($headers, $this->metadataHeaders($metadata));
        $signedHeaders = $this->signRequest('PUT', $key, $headers, $payloadHash);
        $response = wp_remote_request($this->getEndpoint() . $this->getObjectPath($key), [
            'method' => 'PUT',
            'headers' => $signedHeaders,
            'body' => '',
            'timeout' => $this->settings->getRequestTimeout(),
            'sslverify' => true,
            'blocking' => true,
        ]);

        return $this->parseResponse($response);
    }

    /** Update only Cache-Control while retaining all existing object metadata. */
    public function updateObjectCacheControl(string $key, string $cacheControl): array
    {
        $head = $this->headObject($key);
        if (!$head['ok']) {
            return $head;
        }

        $metadata = $head['metadata'];
        $metadata['CacheControl'] = $cacheControl;

        return $this->copyObject($key, $metadata);
    }

    /**
     * Delete object from R2 via SigV4-signed DELETE request.
     *
     * @param string $key Object key in bucket
     *
     * @return array{ok: bool, status: int, error?: string}
     */
    public function deleteObject(string $key): array
    {
        $method = 'DELETE';
        $payloadHash = $this->hashPayload('');
        $headers = [
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $this->getAmzDate(),
        ];

        $signedHeaders = $this->signRequest($method, $key, $headers, $payloadHash);

        $url = $this->getEndpoint() . $this->getObjectPath($key);

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $signedHeaders,
            'timeout' => $this->settings->getRequestTimeout(),
            'sslverify' => true,
            'blocking' => true,
        ]);

        $parsed = $this->parseResponse($response);

        // DELETE on missing key returns 204; treat as success
        if (!$parsed['ok'] && isset($parsed['status']) && ($parsed['status'] === 404 || $parsed['status'] === 204)) {
            return ['ok' => true, 'status' => $parsed['status']];
        }

        return $parsed;
    }

    /** Download an object from R2. */
    public function getObject(string $key): array
    {
        $method = 'GET';
        $payloadHash = $this->hashPayload('');
        $headers = [
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $this->getAmzDate(),
        ];
        $signedHeaders = $this->signRequest($method, $key, $headers, $payloadHash);
        $response = wp_remote_request($this->getEndpoint() . $this->getObjectPath($key), [
            'method' => $method,
            'headers' => $signedHeaders,
            'timeout' => $this->settings->getRequestTimeout(),
            'sslverify' => true,
            'blocking' => true,
        ]);

        $parsed = $this->parseResponse($response);
        if ($parsed['ok']) {
            $parsed['data'] = wp_remote_retrieve_body($response);
        }

        return $parsed;
    }

    /**
     * Test R2 bucket connection via SigV4-signed HEAD request.
     *
     * @return array{ok: bool, status: int, error?: string}
     */
    public function headBucket(): array
    {
        $method = 'HEAD';
        $bucket = $this->settings->getR2Bucket();
        $payloadHash = $this->hashPayload('');
        $headers = [
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $this->getAmzDate(),
        ];

        // HeadBucket uses just the bucket path, not object key
        $canonicalRequest = $this->buildCanonicalRequest($method, $this->getBucketPath(), '', $headers, $payloadHash);
        $signedHeaders = $this->getSignedHeaders($headers);
        $auth = $this->buildAuthorizationHeader($canonicalRequest, $headers, $signedHeaders);
        $headers['Authorization'] = $auth;

        $url = $this->getEndpoint() . $this->getBucketPath();

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'timeout' => $this->settings->getRequestTimeout(),
            'sslverify' => true,
            'blocking' => true,
        ]);

        return $this->parseResponse($response);
    }

    /**
     * List one page of bucket objects through S3 ListObjectsV2.
     *
     * @return array{ok:bool,status:int,objects?:array<int,array{key:string,size:int,lastModified:string}>,nextToken?:string,error?:string}
     */
    public function listObjects(string $continuationToken = '', int $maxKeys = 250, string $prefix = ''): array
    {
        $params = [
            'list-type' => '2',
            'max-keys' => max(1, min(1000, $maxKeys)),
        ];
        if ($continuationToken !== '') {
            $params['continuation-token'] = $continuationToken;
        }
        if ($prefix !== '') {
            $params['prefix'] = $prefix;
        }
        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $payloadHash = $this->hashPayload('');
        $headers = [
            'host' => $this->getHost(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $this->getAmzDate(),
        ];
        $canonicalRequest = $this->buildCanonicalRequest(
            'GET',
            $this->getBucketPath(),
            $query,
            $headers,
            $payloadHash
        );
        $headers['Authorization'] = $this->buildAuthorizationHeader(
            $canonicalRequest,
            $headers,
            $this->getSignedHeaders($headers)
        );

        $response = wp_remote_request($this->getEndpoint() . $this->getBucketPath() . '?' . $query, [
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => $this->settings->getRequestTimeout(),
            'sslverify' => true,
            'blocking' => true,
        ]);
        $parsed = $this->parseResponse($response);
        if (!$parsed['ok']) {
            return $parsed;
        }

        $xml = @simplexml_load_string(wp_remote_retrieve_body($response), 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            return ['ok' => false, 'status' => $parsed['status'], 'error' => 'Malformed list response'];
        }

        $objects = [];
        foreach ($xml->Contents ?? [] as $item) {
            $objects[] = [
                'key' => (string) $item->Key,
                'size' => (int) $item->Size,
                'lastModified' => (string) $item->LastModified,
            ];
        }

        $isTruncated = (string) ($xml->IsTruncated ?? '') === 'true';
        $nextToken = $isTruncated ? (string) ($xml->NextContinuationToken ?? '') : '';
        if ($isTruncated && $nextToken === '') {
            return ['ok' => false, 'status' => $parsed['status'], 'error' => 'Malformed paginated list response'];
        }

        return [
            'ok' => true,
            'status' => $parsed['status'],
            'objects' => $objects,
            'nextToken' => $nextToken,
        ];
    }

    /**
     * Sign a request and return headers with Authorization header.
     *
     * @param string $method       HTTP method (PUT, DELETE, etc.)
     * @param string $key          Object key in bucket
     * @param array  $headers      Base headers (host, x-amz-*, etc.)
     * @param string $payloadHash  Hex SHA256 of payload
     *
     * @return array Headers with Authorization header added
     */
    private function signRequest(string $method, string $key, array $headers, string $payloadHash): array
    {
        $path = $this->getObjectPath($key);
        $canonicalRequest = $this->buildCanonicalRequest($method, $path, '', $headers, $payloadHash);
        $signedHeaders = $this->getSignedHeaders($headers);
        $auth = $this->buildAuthorizationHeader($canonicalRequest, $headers, $signedHeaders);

        $headers['Authorization'] = $auth;

        return $headers;
    }

    /**
     * Build canonical request per AWS SigV4 spec.
     * Format: METHOD\nURI\nQUERY\nCANONICAL_HEADERS\n\nSIGNED_HEADERS\nPAYLOAD_HASH
     */
    private function buildCanonicalRequest(
        string $method,
        string $path,
        string $query,
        array $headers,
        string $payloadHash
    ): string {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = trim($value);
        }
        ksort($normalized);

        $canonicalHeaders = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        $signedHeaders = implode(';', array_keys($normalized));

        return "{$method}\n{$path}\n{$query}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    }

    /**
     * Build AWS SigV4 Authorization header.
     */
    private function buildAuthorizationHeader(string $canonicalRequest, array $headers, string $signedHeaders): string
    {
        $amzDate = $headers['x-amz-date'];
        $dateStamp = substr($amzDate, 0, 8);
        $credentialScope = "{$dateStamp}/" . self::REGION . '/' . self::SERVICE . '/aws4_request';

        $hashedCanonicalRequest = hash('sha256', $canonicalRequest, false);
        $stringToSign = self::ALGORITHM . "\n{$amzDate}\n{$credentialScope}\n{$hashedCanonicalRequest}";

        $signature = $this->calculateSignature($stringToSign, $dateStamp);

        return sprintf(
            '%s Credential=%s/%s,SignedHeaders=%s,Signature=%s',
            self::ALGORITHM,
            $this->settings->getR2AccessKey(),
            $credentialScope,
            $signedHeaders,
            $signature
        );
    }

    /**
     * Calculate HMAC-SHA256 signature using key derivation chain.
     */
    private function calculateSignature(string $stringToSign, string $dateStamp): string
    {
        $secret = $this->settings->getR2SecretKey();

        // Derive signing key: AWS4{secret} -> date -> region -> service -> aws4_request
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', self::REGION, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning, false);
    }

    /**
     * Get signed headers string (semicolon-separated, sorted, lowercase).
     */
    private function getSignedHeaders(array $headers): string
    {
        $names = array_map('strtolower', array_keys($headers));
        sort($names);

        return implode(';', $names);
    }

    /**
     * Hash payload with SHA256 (hex output).
     */
    private function hashPayload(string $data): string
    {
        return hash('sha256', $data, false);
    }

    /**
     * Get ISO8601 UTC timestamp (YYYYMMDDTHHmmssZ).
     */
    private function getAmzDate(): string
    {
        return gmdate('Ymd\THis\Z');
    }

    /**
     * Get R2 endpoint host.
     */
    private function getHost(): string
    {
        return $this->settings->getR2AccountId() . '.r2.cloudflarestorage.com';
    }

    /**
     * Get R2 endpoint URL.
     */
    private function getEndpoint(): string
    {
        return 'https://' . $this->getHost();
    }

    /**
     * Get path-style bucket URI used by Cloudflare R2 S3 API.
     */
    private function getBucketPath(): string
    {
        return '/' . rawurlencode($this->settings->getR2Bucket());
    }

    /**
     * Get path-style object URI used by Cloudflare R2 S3 API.
     */
    private function getObjectPath(string $key): string
    {
        return $this->getBucketPath() . '/' . $this->getUrlEncodedKey($key);
    }

    /**
     * URL-encode object key per-segment (keep slashes).
     * e.g., 'my path/file.jpg' -> 'my%20path/file.jpg'
     */
    private function getUrlEncodedKey(string $key): string
    {
        $segments = explode('/', $key);
        $segments = array_map('rawurlencode', $segments);

        return implode('/', $segments);
    }

    private function isStaticAsset(string $key): bool
    {
        return in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), [
            'jpg',
            'jpeg',
            'png',
            'webp',
            'avif',
            'svg',
            'woff',
            'woff2',
            'mp4',
            'webm',
        ], true);
    }

    /** Convert AWS SDK-style metadata keys to signed HTTP headers. */
    private function metadataHeaders(array $metadata): array
    {
        $headers = [];
        $standardHeaders = [
            'CacheControl' => 'cache-control',
            'ContentDisposition' => 'content-disposition',
            'ContentEncoding' => 'content-encoding',
            'ContentLanguage' => 'content-language',
            'ContentType' => 'content-type',
            'Expires' => 'expires',
        ];

        foreach ($standardHeaders as $name => $header) {
            if (isset($metadata[$name]) && $metadata[$name] !== '') {
                $headers[$header] = (string) $metadata[$name];
            }
        }

        foreach ((array) ($metadata['Metadata'] ?? []) as $name => $value) {
            $headers['x-amz-meta-' . strtolower((string) $name)] = (string) $value;
        }

        return $headers;
    }

    /** Convert response headers into metadata accepted by putObject/copyObject. */
    private function objectMetadata($headers): array
    {
        $headers = is_object($headers) && method_exists($headers, 'getAll')
            ? $headers->getAll()
            : (array) $headers;
        $headers = array_change_key_case($headers, CASE_LOWER);
        $metadata = [];
        $standardMetadata = [
            'cache-control' => 'CacheControl',
            'content-disposition' => 'ContentDisposition',
            'content-encoding' => 'ContentEncoding',
            'content-language' => 'ContentLanguage',
            'content-type' => 'ContentType',
            'expires' => 'Expires',
        ];

        foreach ($standardMetadata as $header => $name) {
            if (isset($headers[$header]) && $headers[$header] !== '') {
                $metadata[$name] = (string) $headers[$header];
            }
        }

        foreach ($headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $metadata['Metadata'][substr($name, 11)] = (string) $value;
            }
        }

        return $metadata;
    }

    /**
     * Parse response and return standardized result.
     *
     * @param array|WP_Error $response Response from wp_remote_request
     *
     * @return array{ok: bool, status: int, etag?: string, size?: int, error?: string}
     */
    private function parseResponse($response): array
    {
        if (is_wp_error($response)) {
            error_log('[ImgPress R2] WP Error: ' . $response->get_error_message());

            return [
                'ok' => false,
                'status' => 0,
                'error' => $response->get_error_message(),
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        if ($status >= 200 && $status < 300) {
            return [
                'ok' => true,
                'status' => $status,
                'etag' => isset($headers['etag']) ? trim($headers['etag'], '"') : null,
                'size' => $headers['content-length'] ?? null,
            ];
        }

        $error = $this->parseErrorResponse($body);
        error_log('[ImgPress R2] HTTP ' . $status . ': ' . $error);

        return [
            'ok' => false,
            'status' => $status,
            'error' => $error,
        ];
    }

    /**
     * Extract error code and message from XML response.
     */
    private function parseErrorResponse(string $body): string
    {
        if (empty($body)) {
            return 'Empty response';
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return 'Malformed response';
        }

        $code = (string) ($xml->Code ?? '');
        $message = (string) ($xml->Message ?? '');

        if ($code) {
            return "{$code}: {$message}";
        }

        return 'Unknown error';
    }
}
