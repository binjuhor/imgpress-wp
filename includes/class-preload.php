<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Preload
{
    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private Jobs $jobs,
        private Page_Cache $pageCache
    ) {
    }

    public function init(): void
    {
        add_action('save_post', [$this, 'scheduleRelated'], 20, 3);
        add_action('deleted_post', [$this, 'scheduleHome']);
        add_action('switch_theme', [$this, 'scheduleHome']);
        add_action('comment_post', [$this, 'scheduleHome']);
        add_action('edit_comment', [$this, 'scheduleHome']);
        add_action('admin_post_imgpress_preload_cache', [$this, 'handleManualPreload']);

        add_action('imgpress_job_preload_url', [$this, 'processPreloadUrl'], 10, 2);
        add_action('imgpress_job_preload_urls', [$this, 'processPreloadUrls'], 10, 2);
    }

    public function scheduleHome(): void
    {
        if (!$this->settings->isCachePreloadEnabled()) {
            return;
        }

        $this->scheduleUrls([home_url('/')]);
    }

    public function scheduleRelated(int $postId = 0, ?\WP_Post $post = null, bool $update = false): void
    {
        if (!$this->settings->isCachePreloadEnabled()) {
            return;
        }

        $urls = $this->relatedUrls($postId, $post);
        if (!empty($urls)) {
            $this->scheduleUrls($urls);
        }
    }

    public function handleManualPreload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'imgpress-wp'), 403);
        }

        check_admin_referer('imgpress_preload_cache');
        $this->preloadSite();
        wp_safe_redirect(admin_url('admin.php?page=imgpress'));
        exit;
    }

    public function preloadSite(): void
    {
        if (!$this->settings->isCachePreloadEnabled()) {
            return;
        }

        $urls = [home_url('/')];

        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            'numberposts' => 10,
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        foreach ($posts as $postId) {
            $urls = array_merge($urls, $this->relatedUrls((int) $postId));
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if (!empty($urls)) {
            $this->scheduleUrls($urls);
        }
    }

    public function processPreloadUrl(array $payload, string $jobId): void
    {
        $url = (string) ($payload['url'] ?? '');
        if ($url === '') {
            return;
        }

        $this->pageCache->warmUrl($url);
    }

    public function processPreloadUrls(array $payload, string $jobId): void
    {
        $urls = $this->normalizeUrls((array) ($payload['urls'] ?? []));
        foreach ($urls as $url) {
            $this->pageCache->warmUrl($url);
        }
    }

    private function scheduleUrls(array $urls): void
    {
        $urls = $this->normalizeUrls($urls);
        if (empty($urls)) {
            return;
        }

        if (count($urls) === 1) {
            $this->jobs->enqueue('preload_url', ['url' => $urls[0]]);
            return;
        }

        $this->jobs->enqueue('preload_urls', ['urls' => implode("\n", $urls)]);
    }

    private function relatedUrls(int $postId, ?\WP_Post $post = null): array
    {
        $post = $post ?: get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return [];
        }

        $urls = [home_url('/'), get_permalink($postId)];
        $archive = get_post_type_archive_link($post->post_type);
        if ($archive) {
            $urls[] = $archive;
        }

        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($postId, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (empty($term->term_id)) {
                    continue;
                }

                $link = get_term_link($term);
                if (!is_wp_error($link)) {
                    $urls[] = $link;
                }
            }
        }

        return $this->normalizeUrls($urls);
    }

    private function normalizeUrls(array $urls): array
    {
        return array_values(array_unique(array_filter(array_map(static function ($url) {
            $url = trim((string) $url);
            return $url === '' ? '' : esc_url_raw($url);
        }, $urls))));
    }
}
