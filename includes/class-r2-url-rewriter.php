<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * R2_URL_Rewriter — Rewrites attachment URLs to custom domain when offloaded to R2.
 * Implements safe fallback to local URLs when R2 is disabled or attachment not offloaded.
 *
 * Rewrite decisions use post-meta flags (trust, never live-HEAD check).
 * Filters: wp_get_attachment_url, wp_calculate_image_srcset, optional the_content.
 */
class R2_URL_Rewriter
{
    private string $uploadBaseUrl;
    private string $customDomainUrl;

    public function __construct(private Settings $settings)
    {
        $uploads = wp_upload_dir();
        $this->uploadBaseUrl = $uploads['baseurl'] ?? '';

        $this->customDomainUrl = $this->settings->getR2PublicBaseUrl();

        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        add_filter('wp_get_attachment_url', [$this, 'filterAttachmentUrl'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'filterImageSrcset'], 10, 5);

        if ($this->settings->isR2RewriteContent()) {
            add_filter('the_content', [$this, 'filterTheContent'], 20);
        }
    }

    /**
     * Rewrite attachment URL for single image.
     *
     * @param string $url          The attachment URL (local)
     * @param int    $attachmentId The attachment post ID
     *
     * @return string Rewritten URL (R2 or local fallback)
     */
    public function filterAttachmentUrl(string $url, int $attachmentId): string
    {
        if (!$this->shouldRewrite($attachmentId)) {
            return $url;
        }

        $meta = $this->getRawR2Meta($attachmentId);
        if (!$meta || $meta['status'] !== 'uploaded') {
            return $url;
        }

        return $meta['url'] ?? $url;
    }

    /**
     * Rewrite srcset URLs for responsive images.
     * Host-swaps each size URL: local baseurl → custom domain.
     *
     * @param array  $sources       Array of {url, descriptor, value} per size
     * @param array  $sizes         Array of size definitions
     * @param string $imageSrc      Original image src URL
     * @param array  $imageMeta     Attachment metadata
     * @param int    $attachmentId  Attachment post ID
     *
     * @return array Rewritten srcset array
     */
    public function filterImageSrcset(
        array $sources,
        array $sizes,
        string $imageSrc,
        array $imageMeta,
        int $attachmentId
    ): array {
        if (!$this->shouldRewrite($attachmentId)) {
            return $sources;
        }

        foreach ($sources as &$source) {
            $source['url'] = $this->swapHostToCustomDomain($source['url']);
        }

        return $sources;
    }

    /**
     * Optionally rewrite hardcoded URLs in post content (legacy).
     * Replaces wp_upload_dir baseurl with custom domain URL.
     *
     * @param string $content Post content HTML
     *
     * @return string Content with rewritten URLs
     */
    public function filterTheContent(string $content): string
    {
        if (empty($this->uploadBaseUrl) || empty($this->customDomainUrl) || !$this->settings->isR2Enabled()) {
            return $content;
        }

        return str_replace($this->uploadBaseUrl, $this->customDomainUrl, $content);
    }

    /**
     * Check if attachment should be rewritten (R2 enabled AND uploaded).
     *
     * @param int $attachmentId Attachment post ID
     *
     * @return bool True if attachment uploaded to R2
     */
    private function shouldRewrite(int $attachmentId): bool
    {
        if (!$this->settings->isR2Enabled()) {
            return false;
        }

        $meta = $this->getRawR2Meta($attachmentId);

        return $meta !== null && $meta['status'] === 'uploaded';
    }

    /**
     * Get R2 meta for attachment.
     *
     * @param int $attachmentId Attachment post ID
     *
     * @return array<string, mixed>|null Meta array or null if not set
     */
    private function getRawR2Meta(int $attachmentId): ?array
    {
        $meta = get_post_meta($attachmentId, '_imgpress_r2', true);

        if (!is_array($meta)) {
            return null;
        }

        return $meta;
    }

    /**
     * Swap local upload baseurl with custom domain URL (host-swap).
     * Idempotent: only rewrites URLs starting with uploadBaseUrl.
     *
     * @param string $url Local URL to rewrite
     *
     * @return string Rewritten or original URL
     */
    private function swapHostToCustomDomain(string $url): string
    {
        if (empty($this->uploadBaseUrl) || empty($this->customDomainUrl)) {
            return $url;
        }

        if (str_starts_with($url, $this->uploadBaseUrl)) {
            $tail = substr($url, strlen($this->uploadBaseUrl));
            return $this->customDomainUrl . $tail;
        }

        return $url;
    }
}
