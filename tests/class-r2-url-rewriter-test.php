<?php

namespace ImgPress\Tests;

use ImgPress\R2_URL_Rewriter;
use ImgPress\Settings;

/**
 * Tests for R2_URL_Rewriter
 */
class R2_URL_Rewriter_Test extends \WP_UnitTestCase
{
    private R2_URL_Rewriter $rewriter;
    private Settings $settings;

    public function setUp(): void
    {
        parent::setUp();

        $this->settings = $this->createMock(Settings::class);
        $this->rewriter = new R2_URL_Rewriter($this->settings);
    }

    /**
     * Test: filterAttachmentUrl returns local URL when R2 disabled
     */
    public function test_filter_attachment_url_disabled()
    {
        $attachmentId = $this->factory->attachment->create([
            'file' => 'test.jpg',
        ]);

        $this->settings->method('isR2Enabled')->willReturn(false);

        $localUrl = wp_get_attachment_url($attachmentId);
        $result = $this->rewriter->filterAttachmentUrl($localUrl, $attachmentId);

        $this->assertEquals($localUrl, $result);
    }

    /**
     * Test: filterAttachmentUrl returns local URL when attachment has no R2 meta
     */
    public function test_filter_attachment_url_no_meta()
    {
        $attachmentId = $this->factory->attachment->create([
            'file' => 'test.jpg',
        ]);

        $this->settings->method('isR2Enabled')->willReturn(true);

        $localUrl = wp_get_attachment_url($attachmentId);
        $result = $this->rewriter->filterAttachmentUrl($localUrl, $attachmentId);

        $this->assertEquals($localUrl, $result);
    }

    /**
     * Test: filterAttachmentUrl returns R2 URL when attachment uploaded
     */
    public function test_filter_attachment_url_uploaded()
    {
        $attachmentId = $this->factory->attachment->create([
            'file' => '2026/01/test.jpg',
        ]);

        $this->settings->method('isR2Enabled')->willReturn(true);

        $r2Url = 'https://media.example.com/2026/01/test.jpg';
        $meta = [
            'status' => 'uploaded',
            'url' => $r2Url,
            'key' => '2026/01/test.jpg',
        ];
        update_post_meta($attachmentId, '_imgpress_r2', $meta);

        $localUrl = wp_get_attachment_url($attachmentId);
        $result = $this->rewriter->filterAttachmentUrl($localUrl, $attachmentId);

        $this->assertEquals($r2Url, $result);
    }

    /**
     * Test: filterAttachmentUrl returns local URL when meta status=failed
     */
    public function test_filter_attachment_url_failed()
    {
        $attachmentId = $this->factory->attachment->create([
            'file' => '2026/01/test.jpg',
        ]);

        $this->settings->method('isR2Enabled')->willReturn(true);

        $meta = [
            'status' => 'failed',
        ];
        update_post_meta($attachmentId, '_imgpress_r2', $meta);

        $localUrl = wp_get_attachment_url($attachmentId);
        $result = $this->rewriter->filterAttachmentUrl($localUrl, $attachmentId);

        $this->assertEquals($localUrl, $result);
    }

    /**
     * Test: filterImageSrcset swaps hosts correctly
     */
    public function test_filter_image_srcset_host_swap()
    {
        $attachmentId = $this->factory->attachment->create([
            'file' => '2026/01/test.jpg',
        ]);

        $this->settings->method('isR2Enabled')->willReturn(true);
        $this->settings->method('getR2CustomDomain')->willReturn('media.example.com');

        $meta = [
            'status' => 'uploaded',
            'url' => 'https://media.example.com/2026/01/test.jpg',
            'key' => '2026/01/test.jpg',
        ];
        update_post_meta($attachmentId, '_imgpress_r2', $meta);

        $uploads = wp_upload_dir();
        $baseUrl = $uploads['baseurl'];

        $srcset = [
            [
                'url' => $baseUrl . '/2026/01/test-300x300.jpg',
                'descriptor' => 'w',
                'value' => 300,
            ],
            [
                'url' => $baseUrl . '/2026/01/test-600x600.jpg',
                'descriptor' => 'w',
                'value' => 600,
            ],
        ];

        $result = $this->rewriter->filterImageSrcset($srcset, [], '', [], $attachmentId);

        $this->assertStringStartsWith('https://media.example.com', $result[0]['url']);
        $this->assertStringStartsWith('https://media.example.com', $result[1]['url']);
        $this->assertStringContainsString('2026/01/test-300x300.jpg', $result[0]['url']);
        $this->assertStringContainsString('2026/01/test-600x600.jpg', $result[1]['url']);
    }

    /**
     * Test: filterImageSrcset returns original URLs when R2 disabled
     */
    public function test_filter_image_srcset_disabled()
    {
        $attachmentId = $this->factory->attachment->create([
            'file' => '2026/01/test.jpg',
        ]);

        $this->settings->method('isR2Enabled')->willReturn(false);

        $uploads = wp_upload_dir();
        $baseUrl = $uploads['baseurl'];

        $srcset = [
            [
                'url' => $baseUrl . '/2026/01/test-300x300.jpg',
                'descriptor' => 'w',
                'value' => 300,
            ],
        ];

        $result = $this->rewriter->filterImageSrcset($srcset, [], '', [], $attachmentId);

        $this->assertEquals($srcset, $result);
    }

    /**
     * Test: filterTheContent rewrites URLs when enabled
     */
    public function test_filter_the_content_rewrite()
    {
        $this->settings->method('isR2Enabled')->willReturn(true);
        $this->settings->method('isR2RewriteContent')->willReturn(true);
        $this->settings->method('getR2CustomDomain')->willReturn('media.example.com');

        $uploads = wp_upload_dir();
        $baseUrl = $uploads['baseurl'];

        $content = '<img src="' . $baseUrl . '/2026/01/photo.jpg" />';

        $result = $this->rewriter->filterTheContent($content);

        $this->assertStringContainsString('https://media.example.com/2026/01/photo.jpg', $result);
        $this->assertStringNotContainsString($baseUrl, $result);
    }

    /**
     * Test: filterTheContent returns original content when R2 disabled
     */
    public function test_filter_the_content_disabled()
    {
        $this->settings->method('isR2Enabled')->willReturn(false);
        $this->settings->method('isR2RewriteContent')->willReturn(true);
        $this->settings->method('getR2CustomDomain')->willReturn('media.example.com');

        $uploads = wp_upload_dir();
        $baseUrl = $uploads['baseurl'];

        $content = '<img src="' . $baseUrl . '/2026/01/photo.jpg" />';

        $result = $this->rewriter->filterTheContent($content);

        $this->assertEquals($content, $result);
    }

    /**
     * Test: swapHostToCustomDomain is idempotent
     */
    public function test_swap_host_idempotent()
    {
        $this->settings->method('isR2Enabled')->willReturn(true);
        $this->settings->method('getR2CustomDomain')->willReturn('media.example.com');

        $uploads = wp_upload_dir();
        $baseUrl = $uploads['baseurl'];

        $localUrl = $baseUrl . '/2026/01/test.jpg';

        $attachmentId = $this->factory->attachment->create([
            'file' => '2026/01/test.jpg',
        ]);

        $meta = [
            'status' => 'uploaded',
            'url' => 'https://media.example.com/2026/01/test.jpg',
        ];
        update_post_meta($attachmentId, '_imgpress_r2', $meta);

        $srcset = [
            ['url' => 'https://media.example.com/2026/01/test-300x300.jpg', 'descriptor' => 'w', 'value' => 300],
        ];

        $result = $this->rewriter->filterImageSrcset($srcset, [], '', [], $attachmentId);

        $this->assertStringStartsWith('https://media.example.com', $result[0]['url']);
    }
}
