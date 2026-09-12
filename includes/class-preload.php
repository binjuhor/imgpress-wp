<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * Cache preloader.
 *
 * URL discovery: home + every public post type + public taxonomies (no term
 * caches, ids only) for low DB cost, cached in a transient.
 *
 * Warming: a persistent queue option is drained in chunks by background jobs.
 * Each warm request carries a cache-bust arg + header so it always regenerates
 * and stores the page (never served from cache). The job chain re-enqueues
 * itself until the queue empties, which throttles load and avoids one giant
 * cron run.
 */
class Preload
{
    public const QUEUE_KEY = 'imgpress_preload_queue';
    public const INVENTORY_KEY = 'imgpress_preload_inventory';
    public const CHAIN_FLAG_KEY = 'imgpress_preload_running';
    public const INVENTORY_TTL = DAY_IN_SECONDS;
    public const CHUNK = 15;

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
        add_action('wp_trash_post', [$this, 'scheduleRelated']);
        add_action('untrash_post', [$this, 'scheduleRelated']);
        add_action('switch_theme', [$this, 'scheduleHome']);
        add_action('activated_plugin', [$this, 'scheduleHome']);
        add_action('deactivated_plugin', [$this, 'scheduleHome']);
        add_action('comment_post', [$this, 'onComment'], 10, 2);
        add_action('edit_comment', [$this, 'onComment'], 10, 2);
        add_action('created_term', [$this, 'scheduleHome']);
        add_action('edited_term', [$this, 'scheduleHome']);
        add_action('delete_term', [$this, 'scheduleHome']);
        add_action('update_option_' . Config::OPTION_KEY, [$this, 'scheduleHome']);
        add_action('admin_post_imgpress_preload_cache', [$this, 'handleManualPreload']);

        if (class_exists('WooCommerce')) {
            add_action('woocommerce_product_set_stock', [$this, 'scheduleWcProduct']);
            add_action('woocommerce_variation_set_stock', [$this, 'scheduleWcProduct']);
        }

        add_action('imgpress_job_preload_url', [$this, 'processLegacyUrl'], 10, 2);
        add_action('imgpress_job_preload_urls', [$this, 'processLegacyUrls'], 10, 2);
        add_action('imgpress_job_preload_batch', [$this, 'processPreloadBatch'], 10, 2);
    }

    public function scheduleHome(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->scheduleUrls([home_url('/')]);
    }

    public function scheduleRelated(int $postId = 0, ?\WP_Post $post = null, bool $update = false): void
    {
        if (!$this->enabled()) {
            return;
        }

        // New content invalidates the site URL inventory.
        $this->invalidateInventory();

        $urls = $this->relatedUrls($postId, $post);
        if (!empty($urls)) {
            $this->scheduleUrls($urls);
        }
    }

    public function onComment(int $commentId, $approved = null): void
    {
        $comment = get_comment($commentId);
        if ($comment instanceof \WP_Comment && !empty($comment->comment_post_ID)) {
            $this->scheduleRelated((int) $comment->comment_post_ID);
        }
    }

    public function scheduleWcProduct($product): void
    {
        $productId = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        if ($productId > 0) {
            $this->scheduleRelated($productId);
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
        if (!$this->enabled()) {
            return;
        }

        $urls = $this->getSiteUrls();
        if (empty($urls)) {
            $this->logger->warning('Preload finished: no URLs found to warm.');
            return;
        }

        $this->logger->info('Site preload queued.', ['count' => (string) count($urls)]);
        $this->scheduleUrls($urls);
    }

    public function processPreloadBatch(array $payload, string $jobId): void
    {
        if (!$this->enabled()) {
            $this->clearQueue();
            return;
        }

        $queue = (array) get_option(self::QUEUE_KEY, []);
        if (empty($queue)) {
            update_option(self::CHAIN_FLAG_KEY, false, false);
            return;
        }

        $chunk = array_splice($queue, 0, self::CHUNK);
        update_option(self::QUEUE_KEY, array_values($queue), false);

        foreach ($chunk as $url) {
            $url = (string) $url;
            if ($url === '') {
                continue;
            }

            $this->pageCache->warmUrl($url);
            usleep(200000);
        }

        if (empty($queue)) {
            update_option(self::CHAIN_FLAG_KEY, false, false);
            $this->logger->info('Cache preload finished.');
            return;
        }

        // Continue the chain on the remaining queue.
        $this->jobs->enqueue('preload_batch', []);
    }

    public function processLegacyUrl(array $payload, string $jobId): void
    {
        $url = (string) ($payload['url'] ?? '');
        if ($url === '') {
            return;
        }

        $this->scheduleUrls([$url]);
    }

    public function processLegacyUrls(array $payload, string $jobId): void
    {
        $urls = $this->normalizeUrls(explode("\n", (string) ($payload['urls'] ?? '')));
        if (!empty($urls)) {
            $this->scheduleUrls($urls);
        }
    }

    /**
     * Discover the full site URL inventory (posts, pages, public CPTs and
     * public taxonomy archives). Cached in a transient for 24h.
     */
    public function getSiteUrls(): array
    {
        $cached = get_transient(self::INVENTORY_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $max = (int) apply_filters('imgpress_preload_max_urls', 2000);
        $urls = [home_url('/')];

        $postTypes = array_values(get_post_types(['public' => true], 'names'));
        $postTypes = array_diff($postTypes, ['attachment']);

        foreach ($postTypes as $postType) {
            $page = 1;
            $perPage = 100;
            while ($page * $perPage <= $max * 2) {
                $ids = get_posts([
                    'post_type' => $postType,
                    'post_status' => 'publish',
                    'posts_per_page' => $perPage,
                    'paged' => $page,
                    'fields' => 'ids',
                    'no_found_rows' => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                    'orderby' => 'modified',
                    'order' => 'DESC',
                ]);

                if (empty($ids)) {
                    break;
                }

                foreach ($ids as $id) {
                    $link = (string) get_permalink($id);
                    if ($link !== '') {
                        $urls[] = $link;
                    }
                }

                if (count($ids) < $perPage) {
                    break;
                }
                $page++;
            }

            if (count($urls) >= $max) {
                break;
            }
        }

        $taxonomies = get_taxonomies(['public' => true], 'names');
        if (!empty($taxonomies)) {
            $terms = get_terms([
                'taxonomy' => $taxonomies,
                'hide_empty' => true,
                'number' => min(1000, $max),
                'fields' => 'ids',
            ]);
            if (!is_wp_error($terms)) {
                foreach ((array) $terms as $termId) {
                    $link = (string) get_term_link((int) $termId);
                    if ($link !== '' && !is_wp_error($link)) {
                        $urls[] = $link;
                    }
                }
            }
        }

        $urls = $this->normalizeUrls($urls);
        $urls = array_slice($urls, 0, $max);

        set_transient(self::INVENTORY_KEY, $urls, self::INVENTORY_TTL);

        return $urls;
    }

    public function invalidateInventory(): void
    {
        delete_transient(self::INVENTORY_KEY);
    }

    private function scheduleUrls(array $urls): void
    {
        $urls = $this->normalizeUrls($urls);
        if (empty($urls)) {
            return;
        }

        $queue = (array) get_option(self::QUEUE_KEY, []);
        $queue = array_values(array_unique(array_merge($queue, $urls)));
        update_option(self::QUEUE_KEY, $queue, false);

        $running = (bool) get_option(self::CHAIN_FLAG_KEY, false);
        if ($running) {
            return;
        }

        update_option(self::CHAIN_FLAG_KEY, true, false);
        $this->jobs->enqueue('preload_batch', []);
    }

    private function enabled(): bool
    {
        return $this->settings->isCachePreloadEnabled() && $this->settings->isCacheEnabled();
    }

    private function clearQueue(): void
    {
        delete_option(self::QUEUE_KEY);
        update_option(self::CHAIN_FLAG_KEY, false, false);
    }

    private function relatedUrls(int $postId, ?\WP_Post $post = null): array
    {
        $urls = [home_url('/')];
        $post = $post ?: get_post($postId);

        if (!$post instanceof \WP_Post) {
            return $urls;
        }

        $permalink = get_permalink($post);
        if ($permalink && !is_wp_error($permalink)) {
            $urls[] = (string) $permalink;
        }

        if ($post->post_status !== 'publish') {
            return $urls;
        }

        $archive = get_post_type_archive_link($post->post_type);
        if ($archive) {
            $urls[] = (string) $archive;
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
                    $urls[] = (string) $link;
                }
            }
        }

        return $this->normalizeUrls($urls);
    }

    private function normalizeUrls(array $urls): array
    {
        $clean = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            $host = wp_parse_url($url, PHP_URL_HOST);
            $homeHost = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($host !== $homeHost) {
                continue;
            }

            $clean[$url] = esc_url_raw($url);
        }

        return array_values($clean);
    }
}
