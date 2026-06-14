<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Api_Client
{
    public function __construct(private Settings $settings) {}

    /**
     * Send a file to imgpress /api/compress and return the compressed binary.
     *
     * @return array{data:string,mime:string,originalSize:int,compressedSize:int,ratio:float}|null
     */
    public function compress(string $filePath, string $mimeType, array $options = []): ?array
    {
        $apiUrl  = $this->settings->getApiUrl();
        $timeout = $this->settings->getRequestTimeout();

        $quality = $options['quality'] ?? $this->settings->getQuality();
        $format  = $options['format']  ?? $this->settings->getFormat();
        $width   = $options['width']   ?? $this->settings->getMaxWidth();

        $fileData = file_get_contents($filePath);
        if ($fileData === false) {
            error_log("[ImgPress] Cannot read file: {$filePath}");
            return null;
        }

        $boundary = 'IPWPBoundary' . md5(uniqid('', true));
        $filename = basename($filePath);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"files\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $fileData . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $compressUrl = add_query_arg([
            'quality' => $quality,
            'format'  => $format,
            'width'   => $width,
        ], "{$apiUrl}/api/compress");

        $headers = ['Content-Type' => "multipart/form-data; boundary={$boundary}"];

        $licenseKey = $this->settings->getLicenseKey();
        if ($licenseKey !== '') {
            $headers['X-API-Key'] = $licenseKey;
        }

        $response = wp_remote_post($compressUrl, [
            'timeout' => $timeout,
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            error_log('[ImgPress] API error: ' . $response->get_error_message());
            return null;
        }

        $json = json_decode(wp_remote_retrieve_body($response), true);
        $result = $json['results'][0] ?? null;

        if (!$result || !empty($result['error'])) {
            error_log('[ImgPress] Compress failed: ' . ($result['message'] ?? 'unknown'));
            return null;
        }

        $downloadUrl = $apiUrl . $result['downloadUrl'];
        $downloadHeaders = [];
        if ($licenseKey !== '') {
            $downloadHeaders['X-API-Key'] = $licenseKey;
        }
        $download = wp_remote_get($downloadUrl, ['timeout' => $timeout, 'headers' => $downloadHeaders]);

        if (is_wp_error($download)) {
            error_log('[ImgPress] Download error: ' . $download->get_error_message());
            return null;
        }

        return [
            'data'           => wp_remote_retrieve_body($download),
            'mime'           => $result['mime'],
            'originalSize'   => (int) $result['originalSize'],
            'compressedSize' => (int) $result['compressedSize'],
            'ratio'          => (float) $result['ratio'],
        ];
    }
}
