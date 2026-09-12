<?php

namespace ImgPress;

use Wa72\Url\Url;

defined('ABSPATH') || exit;

class Html_Optimizer
{
    public function __construct(
        private Settings $settings,
        private Logger $logger
    ) {
    }

    public function init(): void
    {
        add_action('template_redirect', [$this, 'start'], 1);
    }

    public function start(): void
    {
        if (!$this->hasAnyEnabledFeature() || is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        ob_start([$this, 'optimize']);
    }

    public function optimize(string $html): string
    {
        if (stripos($html, '</html>') === false) {
            return $html;
        }

        $html = $this->maybeOptimizeFonts($html);
        $html = $this->maybeOptimizeImages($html);
        $html = $this->maybeOptimizeIframes($html);
        $html = $this->maybeRemoveUnusedCss($html);
        $html = $this->maybeOptimizeScripts($html);

        if ($this->settings->isHtmlMinifyEnabled()) {
            $html = $this->minifyHtml($html);
        }

        return $html;
    }

    private function hasAnyEnabledFeature(): bool
    {
        return $this->settings->isHtmlMinifyEnabled()
            || $this->settings->isRemoveUnusedCssEnabled()
            || $this->settings->isJsDeferEnabled()
            || $this->settings->isJsDelayEnabled()
            || $this->settings->isFontSwapEnabled()
            || $this->settings->isImgLazyloadEnabled()
            || $this->settings->isImgAddDimensionsEnabled()
            || $this->settings->isIframeLazyloadEnabled();
    }

    private function maybeOptimizeFonts(string $html): string
    {
        if (!$this->settings->isFontSwapEnabled()) {
            return $html;
        }

        if (!preg_match_all('/<link\b[^>]*>/i', $html, $matches)) {
            return $html;
        }

        $foundGoogle = false;
        foreach ($matches[0] as $tag) {
            $attrs = $this->parseTag($tag);
            $href = (string) ($attrs['href'] ?? '');
            $rel = (string) ($attrs['rel'] ?? '');
            if ($rel !== 'stylesheet' || !str_contains($href, 'fonts.googleapis.com')) {
                continue;
            }

            $parts = wp_parse_url($href);
            $query = [];
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
            }
            if (!empty($query['display']) && strtolower((string) $query['display']) === 'swap') {
                $foundGoogle = true;
                continue;
            }

            $query['display'] = 'swap';
            $href = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'fonts.googleapis.com') . ($parts['path'] ?? '/css')
                . '?' . http_build_query($query, '', '&');
            $attrs['href'] = $href;
            $html = str_replace($tag, $this->buildTag('link', $attrs), $html);
            $foundGoogle = true;
        }

        if ($foundGoogle && strpos($html, 'imgpress-gfonts-preconnect') === false) {
            $preconnects = '<link rel="preconnect" class="imgpress-gfonts-preconnect" href="https://fonts.googleapis.com" crossorigin>'
                . '<link rel="preconnect" class="imgpress-gfonts-preconnect" href="https://fonts.gstatic.com" crossorigin>';
            if (preg_match('/<head\b[^>]*>/i', $html, $headMatch)) {
                $html = str_replace($headMatch[0], $headMatch[0] . $preconnects, $html);
            }
        }

        return $html;
    }

    private function maybeOptimizeImages(string $html): string
    {
        $lazyload = $this->settings->isImgLazyloadEnabled();
        $dimensions = $this->settings->isImgAddDimensionsEnabled();
        if (!$lazyload && !$dimensions) {
            return $html;
        }

        $aboveFold = $this->settings->getImgLazyloadAboveFold();
        $index = 0;

        return (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $match) use ($lazyload, $dimensions, $aboveFold, &$index): string {
            $tag = $match[0];
            $attrs = $this->parseTag($tag);
            $src = (string) ($attrs['src'] ?? '');

            if ($src === '' || str_starts_with($src, 'data:')) {
                return $tag;
            }

            $index++;
            $aboveTheFold = $index <= $aboveFold;

            if ($lazyload) {
                if (empty($attrs['loading']) && !$aboveTheFold) {
                    $attrs['loading'] = 'lazy';
                }
                if (empty($attrs['decoding'])) {
                    $attrs['decoding'] = 'async';
                }
                if ($index === 1 && empty($attrs['fetchpriority'])) {
                    $attrs['fetchpriority'] = 'high';
                }
            }

            if ($dimensions && empty($attrs['width']) && empty($attrs['height']) && !str_contains($src, '.svg')) {
                $local = $this->localPathFromUrl($src);
                if ($local !== '' && is_file($local) && function_exists('getimagesize')) {
                    $size = @getimagesize($local);
                    if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                        $attrs['width'] = (int) $size[0];
                        $attrs['height'] = (int) $size[1];
                    }
                }
            }

            $built = $this->buildTag('img', $attrs);
            return $built !== '<img>' ? $built : $tag;
        }, $html) ?: $html;
    }

    private function maybeOptimizeIframes(string $html): string
    {
        if (!$this->settings->isIframeLazyloadEnabled()) {
            return $html;
        }

        return (string) preg_replace_callback('/<iframe\b[^>]*>/i', function (array $match): string {
            $tag = $match[0];
            $attrs = $this->parseTag($tag);
            $src = (string) ($attrs['src'] ?? '');

            if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, 'about:')) {
                return $tag;
            }

            if (empty($attrs['loading'])) {
                $attrs['loading'] = 'lazy';
            }

            return $this->buildTag('iframe', $attrs);
        }, $html) ?: $html;
    }

    private function maybeRemoveUnusedCss(string $html): string
    {
        if (!$this->settings->isRemoveUnusedCssEnabled() || is_user_logged_in()) {
            return $html;
        }

        if (!preg_match_all("/<link[^>]*\srel=['\"]stylesheet['\"][^>]*>/i", $html, $matches)) {
            return $html;
        }

        $htmlSelectors = $this->getUsedHtmlSelectors($html);
        $excludeStylesheets = $this->settings->getRemoveUnusedCssExcludeStylesheets();
        $includeSelectors = $this->settings->getRemoveUnusedCssIncludeSelectors();
        $includePattern = $this->buildIncludePattern($includeSelectors);
        $method = $this->settings->getRemoveUnusedCssMethod();
        $needsInteractionLoader = false;

        foreach ($matches[0] as $stylesheetTag) {
            if ($this->matchesAnyKeyword($stylesheetTag, $excludeStylesheets)) {
                continue;
            }

            $stylesheet = $this->parseTag($stylesheetTag);
            if (empty($stylesheet['href']) || ($stylesheet['media'] ?? '') === 'print') {
                continue;
            }

            $cssFilePath = $this->localPathFromUrl($stylesheet['href']);
            if ($cssFilePath === '' || !is_file($cssFilePath)) {
                continue;
            }

            $css = (string) file_get_contents($cssFilePath);
            if ($css === '') {
                continue;
            }

            $media = $stylesheet['media'] ?? 'all';
            if ($media !== '' && $media !== 'all') {
                $css = "@media {$media} { {$css} }";
            }

            $usedCss = $this->getUsedCssBlocks($htmlSelectors, $css, $includePattern);
            if ($usedCss === '') {
                continue;
            }

            $usedCss = $this->rewriteAbsoluteUrls($usedCss, $stylesheet['href']);
            $usedCss = "<style class=\"imgpress-used-css\" data-original-href=\"" . esc_attr($stylesheet['href']) . "\">{$usedCss}</style>";

            switch ($method) {
                case 'remove':
                    $replacement = $usedCss;
                    break;
                case 'interaction':
                    $needsInteractionLoader = true;
                    $replacement = $usedCss . PHP_EOL . $this->makeInteractionStylesheet($stylesheetTag, $stylesheet['href']);
                    break;
                case 'async':
                default:
                    $replacement = $usedCss . PHP_EOL . $this->makeAsyncStylesheet($stylesheetTag, $stylesheet['href'], $stylesheet['media'] ?? 'all');
                    break;
            }

            $html = str_replace($stylesheetTag, $replacement, $html);
        }

        if ($needsInteractionLoader) {
            $html = $this->injectCssInteractionLoader($html);
        }

        return $html;
    }

    private function maybeOptimizeScripts(string $html): string
    {
        $hasDelay = $this->settings->isJsDelayEnabled();
        $hasDefer = $this->settings->isJsDeferEnabled();

        if (!$hasDelay && !$hasDefer) {
            return $html;
        }

        if (!preg_match_all('/<script[^>]*>[\s\S]*?<\/script>/i', $html, $scripts)) {
            return $html;
        }

        $delayTargets = [];
        $delayMethod = $this->settings->getJsDelayMethod();
        $delaySelected = $this->settings->getJsDelaySelected();
        $delayAllExcludes = $this->settings->getJsDelayAllExcludes();
        $deferExcludes = $this->settings->getJsDeferExcludes();
        $delayCreatedLoader = false;

        foreach ($scripts[0] as $scriptTag) {
            $script = $this->parseTag($scriptTag);

            if (empty($script)) {
                continue;
            }

            if (($script['type'] ?? '') === 'module') {
                continue;
            }

            if (!empty($script['type'])
                && !in_array($script['type'], ['text/javascript', 'application/javascript'], true)) {
                continue;
            }

            if ($hasDelay && $this->shouldDelayScript($scriptTag, $script, $delayMethod, $delaySelected, $delayAllExcludes)) {
                $replacement = $this->makeDelayedScript($scriptTag, $script);
                $delayTargets[] = true;
                $html = str_replace($scriptTag, $replacement, $html);
                continue;
            }

            if ($hasDefer && !empty($script['src'])
                && empty($script['defer'])
                && empty($script['async'])
                && !$this->matchesAnyKeyword($scriptTag, $deferExcludes)) {
                $replacement = $this->makeDeferredScript($scriptTag, $script);
                $html = str_replace($scriptTag, $replacement, $html);
            }
        }

        if (!empty($delayTargets) && stripos($html, 'imgpress-delay-loader') === false) {
            $html = $this->injectDelayLoader($html);
            $delayCreatedLoader = true;
        }

        if ($delayCreatedLoader) {
            $this->logger->info('ImgPress JS delay loader injected.');
        }

        return $html;
    }

    private function makeDeferredScript(string $originalTag, array $attrs): string
    {
        $content = $this->tagContent($originalTag);
        unset($attrs['async']);
        $tag = $this->buildTag('script', $attrs, $content);
        if (!str_contains($tag, ' defer')) {
            $tag = preg_replace('/<script\b/i', '<script defer', $tag, 1) ?? $tag;
        }

        return $tag;
    }

    private function makeDelayedScript(string $originalTag, array $attrs): string
    {
        $content = $this->tagContent($originalTag);
        $attrs['type'] = 'text/plain';
        $attrs['data-imgpress-delay'] = '1';

        if (!empty($attrs['src'])) {
            $attrs['data-imgpress-src'] = $attrs['src'];
            unset($attrs['src']);
        }

        return $this->buildTag('script', $attrs, $content);
    }

    private function injectDelayLoader(string $html): string
    {
        $timeoutMs = max(0, (int) $this->settings->getJsDelayTimeout());
        $loader = <<<'JS'
<script id="imgpress-delay-loader">
(function () {
  var fired = false;
  function loadDelayedScripts() {
    if (fired) {
      return;
    }
    fired = true;
    document.querySelectorAll('script[data-imgpress-delay="1"]').forEach(function (tag) {
      var script = document.createElement('script');
      Array.from(tag.attributes).forEach(function (attr) {
        if (attr.name.indexOf('data-imgpress-') === 0 || attr.name === 'type') {
          return;
        }
        script.setAttribute(attr.name, attr.value);
      });
      if (tag.getAttribute('data-imgpress-src')) {
        script.src = tag.getAttribute('data-imgpress-src');
      } else {
        script.text = tag.textContent || '';
      }
      tag.parentNode.replaceChild(script, tag);
    });
  }
  ['mousemove', 'touchstart', 'keydown', 'scroll', 'click'].forEach(function (eventName) {
    window.addEventListener(eventName, loadDelayedScripts, { once: true, passive: true });
  });
  var timeout = __IMGPRESS_DELAY_TIMEOUT__;
  if (timeout > 0) {
    setTimeout(loadDelayedScripts, timeout);
  }
})();
</script>
JS;
        $loader = str_replace('__IMGPRESS_DELAY_TIMEOUT__', (string) $timeoutMs, $loader);

        return str_replace('</body>', $loader . '</body>', $html);
    }

    private function injectCssInteractionLoader(string $html): string
    {
        $loader = <<<'JS'
<script id="imgpress-rucss-loader">
(function () {
  var fired = false;
  function loadStylesheets() {
    if (fired) {
      return;
    }
    fired = true;
    document.querySelectorAll('link[data-href]').forEach(function (link) {
      link.href = link.getAttribute('data-href');
      link.rel = 'stylesheet';
      link.removeAttribute('data-href');
    });
  }
  ['mousemove', 'touchstart', 'keydown', 'scroll', 'click'].forEach(function (eventName) {
    window.addEventListener(eventName, loadStylesheets, { once: true, passive: true });
  });
  setTimeout(loadStylesheets, 5000);
})();
</script>
JS;

        return str_replace('</body>', $loader . '</body>', $html);
    }

    private function shouldDelayScript(string $scriptTag, array $attrs, string $delayMethod, array $selected, array $allExcludes): bool
    {
        $source = $attrs['src'] ?? $scriptTag;
        $matches = $delayMethod === 'selected'
            ? $this->matchesAnyKeyword($scriptTag, $selected)
            : !$this->matchesAnyKeyword($scriptTag, $allExcludes);

        if (!$matches) {
            return false;
        }

        return empty($attrs['type']) || in_array($attrs['type'], ['text/javascript', 'application/javascript'], true);
    }

    private function minifyHtml(string $html): string
    {
        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        $html = preg_replace('/\s{2,}/', ' ', $html) ?? $html;

        return trim($html);
    }

    private function getUsedHtmlSelectors(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $selectors = [
            'tags' => [],
            'classes' => [],
            'ids' => [],
            'attributes' => [],
        ];

        foreach ($dom->getElementsByTagName('*') as $element) {
            $selectors['tags'][$element->tagName] = 1;

            if ($element->hasAttribute('class')) {
                $classes = preg_split('/\s+/', str_replace([':', '/'], ['\:', '\/'], $element->getAttribute('class'))) ?: [];
                foreach ($classes as $class) {
                    if ($class !== '') {
                        $selectors['classes'][$class] = 1;
                    }
                }
            }

            if ($element->hasAttribute('id')) {
                $selectors['ids'][$element->getAttribute('id')] = 1;
            }

            foreach ($element->attributes as $attribute) {
                $selectors['attributes'][$attribute->name] = 1;
            }
        }

        return $selectors;
    }

    private function getUsedCssBlocks(array $htmlSelectors, string $css, string $includePattern): string
    {
        $blocks = $this->parseCss($css);

        foreach ($blocks as $block) {
            $selectors = $block['selectors'];

            if ($includePattern !== '' && preg_match("/{$includePattern}/", $selectors)) {
                continue;
            }

            $selectorList = array_filter(array_map('trim', explode(',', $selectors)));
            $selectorList = array_filter($selectorList, function (string $selector) use ($htmlSelectors): bool {
                return $this->isSelectorUsed($selector, $htmlSelectors);
            });

            if (empty($selectorList)) {
                $css = str_replace($block['css'], '', $css);
            }
        }

        return trim($css);
    }

    private function isSelectorUsed(string $selector, array $htmlSelectors): bool
    {
        $selector = preg_replace('/(?<!\\\\)::?[a-zA-Z0-9_-]+(\(.+?\))?/', '', $selector) ?? $selector;

        if (preg_match('/\[([A-Za-z0-9_:-]+)(\W?=[^\]]+)?\]/', $selector, $matches) && !isset($htmlSelectors['attributes'][$matches[1]])) {
            return false;
        }
        $selector = preg_replace('/\[([A-Za-z0-9_:-]+)(\W?=[^\]]+)?\]/', '', $selector) ?? $selector;

        if (preg_match_all('/\.((?:[a-zA-Z0-9_-]+|\\\\.)+)/', $selector, $matches)) {
            foreach ($matches[1] as $class) {
                if (!isset($htmlSelectors['classes'][$class])) {
                    return false;
                }
            }
        }
        $selector = preg_replace('/\.((?:[a-zA-Z0-9_-]+|\\\\.)+)/', '', $selector) ?? $selector;

        if (preg_match('/#([a-zA-Z0-9_-]+)/', $selector, $matches) && !isset($htmlSelectors['ids'][$matches[1]])) {
            return false;
        }
        $selector = preg_replace('/#([a-zA-Z0-9_-]+)/', '', $selector) ?? $selector;

        if (preg_match('/[a-zA-Z0-9_-]+/', $selector, $matches) && !isset($htmlSelectors['tags'][$matches[0]])) {
            return false;
        }

        return true;
    }

    private function parseCss(string $css): array
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
        $css = preg_replace('/@import[^;]+;/', '', $css) ?? $css;
        $css = preg_replace('/@charset[^;]+;/', '', $css) ?? $css;
        $css = preg_replace('/@font-face[^}]+}/', '', $css) ?? $css;
        $css = preg_replace('/@(-webkit-|-moz-|-o-|-ms-)?keyframes[\s\S]*?}\s*}/', '', $css) ?? $css;
        $css = preg_replace('/@[^{]+{[^}]+}/', '', $css) ?? $css;
        $css = preg_replace('/@[^{]+{/', '', $css) ?? $css;
        $css = preg_replace('/\}\s*(\}\s*)+/s', '}', $css) ?? $css;

        preg_match_all('/([^{]+)\s*\{([^}]+)\}\s*/', $css, $matches);

        $blocks = [];
        foreach (($matches[1] ?? []) as $index => $selectors) {
            $blocks[] = [
                'selectors' => trim((string) $selectors),
                'css' => trim((string) ($matches[0][$index] ?? '')),
            ];
        }

        return $blocks;
    }

    private function rewriteAbsoluteUrls(string $content, string $baseUrl): string
    {
        $regex = '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)|@import\s+[\'"]([^\'"]+\.[^\s]+)[\'"]/';

        return (string) preg_replace_callback(
            $regex,
            function (array $match) use ($baseUrl): string {
                $match = array_values(array_filter($match));
                $urlString = $match[0] ?? '';
                $relativeUrl = $match[1] ?? '';
                if ($relativeUrl === '') {
                    return $urlString;
                }

                $absoluteUrl = Url::parse($relativeUrl);
                $absoluteUrl->makeAbsolute(Url::parse($baseUrl));
                return str_replace($relativeUrl, (string) $absoluteUrl, $urlString);
            },
            $content
        ) ?: $content;
    }

    private function matchesAnyKeyword(string $subject, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($subject, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function localPathFromUrl(string $url): string
    {
        $urlParts = wp_parse_url(html_entity_decode($url));
        $homeParts = wp_parse_url(home_url());

        if (!empty($urlParts['host']) && !empty($homeParts['host']) && strtolower($urlParts['host']) !== strtolower($homeParts['host'])) {
            return '';
        }

        $path = (string) ($urlParts['path'] ?? '');
        if ($path === '') {
            return '';
        }

        $contentPath = wp_parse_url(content_url(), PHP_URL_PATH) ?: '/wp-content';
        if (str_starts_with($path, $contentPath)) {
            return WP_CONTENT_DIR . substr($path, strlen($contentPath));
        }

        return ABSPATH . ltrim($path, '/');
    }

    private function parseTag(string $tag): array
    {
        $attrs = [];
        if (!preg_match('/<\w+\s*([^>]*)>/s', $tag, $matches)) {
            return $attrs;
        }

        preg_match_all('/([a-zA-Z0-9_\-:]+)(?:=(["\'])(.*?)\2|=([^\s>]+))?/', $matches[1], $attrMatches, PREG_SET_ORDER);
        foreach ($attrMatches as $attrMatch) {
            $value = $attrMatch[3] ?? $attrMatch[4] ?? true;
            $attrs[strtolower($attrMatch[1])] = $value;
        }

        return $attrs;
    }

    private function buildTag(string $tagName, array $attrs, ?string $content = null): string
    {
        $html = '<' . $tagName;
        foreach ($attrs as $name => $value) {
            if ($value === false || $value === null || $value === '') {
                continue;
            }

            if ($value === true) {
                $html .= ' ' . esc_attr($name);
                continue;
            }

            $html .= ' ' . esc_attr($name) . '="' . esc_attr((string) $value) . '"';
        }

        if ($content === null) {
            return $html . '>';
        }

        return $html . '>' . $content . '</' . $tagName . '>';
    }

    private function tagContent(string $tag): string
    {
        if (!preg_match('/<script[^>]*>([\s\S]*?)<\/script>/i', $tag, $matches)) {
            return '';
        }

        return (string) ($matches[1] ?? '');
    }

    private function makeAsyncStylesheet(string $originalTag, string $href, string $media): string
    {
        $attributes = $this->parseTag($originalTag);
        $attributes['media'] = 'print';
        $attributes['onload'] = "this.onload=null;this.media='" . esc_js($media ?: 'all') . "';";
        $attributes['href'] = $href;

        return $this->buildTag('link', $attributes);
    }

    private function makeInteractionStylesheet(string $originalTag, string $href): string
    {
        $attributes = $this->parseTag($originalTag);
        $attributes['data-href'] = $href;
        unset($attributes['href']);

        return $this->buildTag('link', $attributes);
    }

    private function buildIncludePattern(array $selectors): string
    {
        $selectors = array_filter(array_map('trim', $selectors));
        if (empty($selectors)) {
            return '';
        }

        return implode('|', array_map('preg_quote', $selectors));
    }
}
