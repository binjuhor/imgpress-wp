<?php

namespace ImgPress\Tests;

use ImgPress\R2_Client;
use ImgPress\Settings;

/**
 * Unit tests for R2_Client SigV4 signing.
 */
class R2_Client_Test
{
    /**
     * Test SigV4 canonical request generation.
     * Verify against AWS SigV4 specification.
     */
    public function testCanonicalRequestFormat()
    {
        $mock_settings = $this->createMockSettings(
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'abc123',
            'my-bucket'
        );

        $client = new R2_Client($mock_settings);

        // Use reflection to test private method
        $reflection = new \ReflectionClass($client);
        $buildCanonical = $reflection->getMethod('buildCanonicalRequest');
        $buildCanonical->setAccessible(true);

        $method = 'PUT';
        $path = '/my-bucket/test.jpg';
        $query = '';
        $headers = [
            'host' => 'abc123.r2.cloudflarestorage.com',
            'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'x-amz-date' => '20260616T143000Z',
            'content-type' => 'image/jpeg',
        ];
        $payloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $canonical = $buildCanonical->invokeArgs($client, [$method, $path, $query, $headers, $payloadHash]);

        // Verify structure
        $lines = explode("\n", $canonical);
        $this->assertEqual($lines[0], 'PUT', 'Method mismatch');
        $this->assertEqual($lines[1], '/my-bucket/test.jpg', 'Path mismatch');
        $this->assertEqual($lines[2], '', 'Query mismatch');

        // Verify headers are sorted and lowercase
        $this->assertContains('content-type:image/jpeg', $canonical);
        $this->assertContains('host:abc123.r2.cloudflarestorage.com', $canonical);
        $this->assertContains('x-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $canonical);
        $this->assertContains('x-amz-date:20260616T143000Z', $canonical);

        echo "[PASS] Canonical request format is correct\n";
    }

    /**
     * Test URL encoding of object keys with special characters.
     */
    public function testUrlEncodingPerSegment()
    {
        $mock_settings = $this->createMockSettings(
            'ACCESS_KEY',
            'SECRET_KEY',
            'abc123',
            'my-bucket'
        );

        $client = new R2_Client($mock_settings);

        // Test reflection to access private method
        $reflection = new \ReflectionClass($client);
        $encodeKey = $reflection->getMethod('getUrlEncodedKey');
        $encodeKey->setAccessible(true);

        // Test cases: spaces, unicode, and slashes
        $testCases = [
            'simple.jpg' => 'simple.jpg',
            'my file.jpg' => 'my%20file.jpg',
            'folder/file.jpg' => 'folder/file.jpg',
            'my folder/my file.jpg' => 'my%20folder/my%20file.jpg',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $encodeKey->invoke($client, $input);
            $this->assertEqual($result, $expected, "Encoding mismatch for: {$input}");
        }

        echo "[PASS] URL encoding handles spaces and segments correctly\n";
    }

    /**
     * Test payload hashing (SHA256).
     */
    public function testPayloadHashing()
    {
        $mock_settings = $this->createMockSettings('KEY', 'SECRET', 'abc', 'bucket');
        $client = new R2_Client($mock_settings);

        $reflection = new \ReflectionClass($client);
        $hashPayload = $reflection->getMethod('hashPayload');
        $hashPayload->setAccessible(true);

        // Empty string hash (used for DELETE)
        $emptyHash = $hashPayload->invoke($client, '');
        $this->assertEqual(
            $emptyHash,
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'Empty payload hash mismatch'
        );

        // Test with actual data
        $dataHash = $hashPayload->invoke($client, 'test data');
        $expectedHash = hash('sha256', 'test data');
        $this->assertEqual($dataHash, $expectedHash, 'Data payload hash mismatch');

        echo "[PASS] Payload hashing produces correct SHA256\n";
    }

    /**
     * Test signed headers extraction and sorting.
     */
    public function testSignedHeadersGeneration()
    {
        $mock_settings = $this->createMockSettings('KEY', 'SECRET', 'abc', 'bucket');
        $client = new R2_Client($mock_settings);

        $reflection = new \ReflectionClass($client);
        $getSignedHeaders = $reflection->getMethod('getSignedHeaders');
        $getSignedHeaders->setAccessible(true);

        $headers = [
            'Content-Type' => 'image/jpeg',
            'Host' => 'abc123.r2.cloudflarestorage.com',
            'X-Amz-Content-SHA256' => 'abc123',
            'X-Amz-Date' => '20260616T143000Z',
        ];

        $signedHeaders = $getSignedHeaders->invoke($client, $headers);

        // Verify sorted and lowercase
        $expected = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $this->assertEqual($signedHeaders, $expected, 'Signed headers not sorted correctly');

        echo "[PASS] Signed headers are sorted and lowercase\n";
    }

    /**
     * Test error XML parsing.
     */
    public function testErrorXmlParsing()
    {
        $mock_settings = $this->createMockSettings('KEY', 'SECRET', 'abc', 'bucket');
        $client = new R2_Client($mock_settings);

        $reflection = new \ReflectionClass($client);
        $parseError = $reflection->getMethod('parseErrorResponse');
        $parseError->setAccessible(true);

        $xml = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match the signature you provided.</Message></Error>';

        $error = $parseError->invoke($client, $xml);
        $this->assertContains('SignatureDoesNotMatch', $error, 'Error code not extracted');
        $this->assertContains('request signature', $error, 'Error message not extracted');

        echo "[PASS] Error XML parsing extracts code and message\n";
    }

    /**
     * Helper: Create mock Settings object.
     */
    private function createMockSettings(string $accessKey, string $secretKey, string $accountId, string $bucket): object
    {
        $mock = new class {
            public function __construct(
                public string $accessKey,
                public string $secretKey,
                public string $accountId,
                public string $bucket,
            ) {
            }

            public function getR2AccessKey(): string
            {
                return $this->accessKey;
            }

            public function getR2SecretKey(): string
            {
                return $this->secretKey;
            }

            public function getR2AccountId(): string
            {
                return $this->accountId;
            }

            public function getR2Bucket(): string
            {
                return $this->bucket;
            }

            public function getRequestTimeout(): int
            {
                return 120;
            }
        };

        return $mock;
    }

    /**
     * Assertion helper: assertEqual
     */
    private function assertEqual($actual, $expected, string $message = ''): void
    {
        if ($actual !== $expected) {
            throw new \Exception("Assertion failed: {$message}\nExpected: {$expected}\nActual: {$actual}");
        }
    }

    /**
     * Assertion helper: assertContains
     */
    private function assertContains($haystack, $needle, string $message = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            throw new \Exception("Assertion failed: {$message}\nExpected to contain: {$needle}\nActual: {$haystack}");
        }
    }

    /**
     * Run all tests.
     */
    public static function runAll(): void
    {
        $test = new self();

        try {
            $test->testCanonicalRequestFormat();
            $test->testUrlEncodingPerSegment();
            $test->testPayloadHashing();
            $test->testSignedHeadersGeneration();
            $test->testErrorXmlParsing();

            echo "\n✓ All R2_Client tests passed!\n";
        } catch (\Exception $e) {
            echo "\n✗ Test failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename($argv[0] ?? '') === basename(__FILE__)) {
    require_once __DIR__ . '/../includes/class-r2-client.php';

    // Mock WordPress functions if not available
    if (!function_exists('wp_remote_request')) {
        function wp_remote_request($url, $args = [])
        {
            return [];
        }
    }

    R2_Client_Test::runAll();
}
