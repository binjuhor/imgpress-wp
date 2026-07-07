<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Html_Optimizer
{
    public function __construct(private Settings $settings)
    {
    }

    public function init(): void
    {
        add_action('template_redirect', [$this, 'start'], 1);
    }

    public function start(): void
    {
        if (!$this->settings->isHtmlMinifyEnabled() || is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        ob_start([$this, 'minify']);
    }

    public function minify(string $html): string
    {
        if (stripos($html, '</html>') === false) {
            return $html;
        }

        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        $html = preg_replace('/\s{2,}/', ' ', $html) ?? $html;

        return trim($html);
    }
}
