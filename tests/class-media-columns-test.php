<?php

namespace ImgPress\Tests;

use ImgPress\Compressor;
use ImgPress\Media_Columns;
use ImgPress\R2_Uploader;
use ImgPress\Settings;

class Media_Columns_Test extends \WP_UnitTestCase
{
    private Compressor $compressor;
    private Settings $settings;
    private R2_Uploader $uploader;
    private Media_Columns $mediaColumns;

    public function setUp(): void
    {
        parent::setUp();

        $this->compressor = $this->createMock(Compressor::class);
        $this->settings = $this->createMock(Settings::class);
        $this->uploader = $this->createMock(R2_Uploader::class);
        $this->mediaColumns = new Media_Columns($this->compressor, $this->settings, $this->uploader);

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
    }

    public function test_registers_all_bulk_actions_when_r2_is_configured(): void
    {
        $this->settings->method('isR2Configured')->willReturn(true);

        $actions = $this->mediaColumns->addBulkActions([]);

        $this->assertArrayHasKey('imgpress_compress', $actions);
        $this->assertArrayHasKey('imgpress_restore_original', $actions);
        $this->assertArrayHasKey('imgpress_r2_offload', $actions);
    }

    public function test_hides_offload_action_when_r2_is_not_configured(): void
    {
        $this->settings->method('isR2Configured')->willReturn(false);

        $actions = $this->mediaColumns->addBulkActions([]);

        $this->assertArrayHasKey('imgpress_compress', $actions);
        $this->assertArrayHasKey('imgpress_restore_original', $actions);
        $this->assertArrayNotHasKey('imgpress_r2_offload', $actions);
    }

    public function test_registers_bulk_ajax_handler(): void
    {
        $this->assertNotFalse(
            has_action('wp_ajax_imgpress_media_bulk_item', [$this->mediaColumns, 'handleBulkItem'])
        );
    }

    public function test_server_fallback_reports_controller_failure_without_processing(): void
    {
        $this->compressor->expects($this->never())->method('compress');

        $redirect = $this->mediaColumns->handleBulkActionFallback(
            'upload.php',
            'imgpress_compress',
            [10, 10, 20]
        );

        parse_str((string) wp_parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('2', $query['imgpress_failed']);
        $this->assertSame('1', $query['imgpress_js_required']);
    }

    public function test_author_cannot_manage_another_users_attachment(): void
    {
        $authorId = $this->factory->user->create(['role' => 'author']);
        $otherAuthorId = $this->factory->user->create(['role' => 'author']);
        $attachmentId = $this->factory->attachment->create(['post_author' => $otherAuthorId]);
        wp_set_current_user($authorId);

        $method = new \ReflectionMethod($this->mediaColumns, 'canManageAttachment');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->mediaColumns, $attachmentId));
    }

    public function test_author_can_manage_own_attachment(): void
    {
        $authorId = $this->factory->user->create(['role' => 'author']);
        $attachmentId = $this->factory->attachment->create(['post_author' => $authorId]);
        wp_set_current_user($authorId);

        $method = new \ReflectionMethod($this->mediaColumns, 'canManageAttachment');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->mediaColumns, $attachmentId));
    }
}
