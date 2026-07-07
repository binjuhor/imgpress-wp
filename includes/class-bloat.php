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
