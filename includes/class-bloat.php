<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Bloat
{
    public function __construct(private Settings $settings)
    {
    }

    public function init(): void
    {
        add_action('init', [$this, 'disableEmojis']);
        add_action('init', [$this, 'disableFeeds'], 1);
        add_action('wp_default_scripts', [$this, 'disableJqueryMigrate']);
        add_action('wp_enqueue_scripts', [$this, 'disableFrontendAssets'], 100);
        add_action('init', [$this, 'disableXmlRpc'], 1);

        add_filter('style_loader_src', [$this, 'removeQueryStrings'], 20, 2);
        add_filter('script_loader_src', [$this, 'removeQueryStrings'], 20, 2);
        add_filter('style_loader_tag', [$this, 'removeGoogleFontTags'], 20, 4);

        add_action('admin_enqueue_scripts', [$this, 'disableHeartbeatAdmin'], 100);
    }

    public function removeQueryStrings(string $src, string $handle): string
    {
        if (!$this->settings->isBloatDisabled('query_strings')) {
            return $src;
        }

        $parts = wp_parse_url($src);
        if (empty($parts['query'])) {
            return $src;
        }

        parse_str($parts['query'], $query);
        unset($query['ver']);
        if (empty($query)) {
            return strtok($src, '?');
        }

        if (empty($parts['host']) || empty($parts['scheme'])) {
            $base = strtok($src, '?');
            return $base !== false ? $base . '?' . http_build_query($query) : $src;
        }

        return $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '') . '?' . http_build_query($query);
    }

    public function removeGoogleFontTags(string $tag, string $handle, string $href, string $media): string
    {
        if (!$this->settings->isBloatDisabled('google_fonts')) {
            return $tag;
        }

        if (str_contains($href, 'fonts.googleapis.com')) {
            return '';
        }

        return $tag;
    }

    public function disableHeartbeatAdmin(string $hook): void
    {
        if (!$this->settings->isBloatDisabled('heartbeat')) {
            return;
        }

        // Keep heartbeat on post-editing screens (autosave relies on it).
        if (in_array($hook, ['post.php', 'post-new.php', 'site-editor.php'], true)) {
            return;
        }

        wp_deregister_script('heartbeat');
    }

    public function disableHeartbeatFrontend(): void
    {
        if (!$this->settings->isBloatDisabled('heartbeat')) {
            return;
        }

        wp_dequeue_script('heartbeat');
    }

    public function disableWooCartFragments(): void
    {
        if (!$this->settings->isBloatDisabled('woo_cart_fragments')) {
            return;
        }

        if (!class_exists('WooCommerce') || !function_exists('is_cart')) {
            return;
        }

        if (is_cart() || is_checkout() || is_account_page()) {
            return;
        }

        wp_dequeue_script('wc-cart-fragments');
    }

    public function disableEmojis(): void
    {
        if (!$this->settings->isBloatDisabled('emojis')) {
            return;
        }

        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    }

    public function disableJqueryMigrate(\WP_Scripts $scripts): void
    {
        if (!$this->settings->isBloatDisabled('jquery_migrate')) {
            return;
        }

        if (isset($scripts->registered['jquery']) && !empty($scripts->registered['jquery']->deps)) {
            $scripts->registered['jquery']->deps = array_values(array_filter(
                $scripts->registered['jquery']->deps,
                static fn($dep) => $dep !== 'jquery-migrate'
            ));
        }
    }

    public function disableFrontendAssets(): void
    {
        if ($this->settings->isBloatDisabled('block_css')) {
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('wc-block-style');
            wp_dequeue_style('wc-blocks-style');
        }

        if ($this->settings->isBloatDisabled('dashicons') && !is_user_logged_in()) {
            wp_dequeue_style('dashicons');
        }

        if ($this->settings->isBloatDisabled('oembeds')) {
            wp_dequeue_script('wp-embed');
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_oembed_add_host_js');
        }

        $this->disableWooCartFragments();
        $this->disableHeartbeatFrontend();
    }

    public function disableXmlRpc(): void
    {
        if (!$this->settings->isBloatDisabled('xml_rpc')) {
            return;
        }

        add_filter('xmlrpc_enabled', '__return_false');
    }

    public function disableFeeds(): void
    {
        if (!$this->settings->isBloatDisabled('rss_feed')) {
            return;
        }

        add_action('do_feed', [$this, 'feedDisabled'], 1);
        add_action('do_feed_rdf', [$this, 'feedDisabled'], 1);
        add_action('do_feed_rss', [$this, 'feedDisabled'], 1);
        add_action('do_feed_rss2', [$this, 'feedDisabled'], 1);
        add_action('do_feed_atom', [$this, 'feedDisabled'], 1);
    }

    public function feedDisabled(): void
    {
        wp_die(esc_html__('RSS feeds are disabled by ImgPress.', 'imgpress-wp'), '', ['response' => 404]);
    }
}
