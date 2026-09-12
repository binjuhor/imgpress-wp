<?php

namespace ImgPress;

defined('ABSPATH') || exit;

/**
 * Optional Apache .htaccess rules for ImgPress page caching:
 *
 *   1. mod_deflate / mod_expires for static assets (incl. minified cache files).
 *   2. Direct web-server serving of cached HTML in "never expire" mode.
 *
 * Only the site root .htaccess is touched, between owned BEGIN/END markers.
 * On nginx or when .htaccess is absent the module reports an error and stays
 * inert (the drop-in / PHP layers still work).
 */
class Cache_Htaccess
{
    private const BEGIN = '# BEGIN ImgPress';
    private const END = '# END ImgPress';

    private static ?string $lastError = null;

    public static function install(): bool
    {
        $path = self::htaccessPath();
        if (!is_file($path)) {
            self::$lastError = 'No .htaccess found at the site root; Apache direct-serve is unavailable (the drop-in still works).';
            return false;
        }
        if (!is_writable($path)) {
            self::$lastError = '.htaccess is not writable.';
            return false;
        }

        $current = (string) file_get_contents($path);
        $block = self::block();

        $updated = self::replaceBlock($current, $block);
        if ($updated === $current) {
            self::$lastError = null;
            return true;
        }

        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            self::$lastError = 'Could not write to .htaccess.';
            return false;
        }

        self::$lastError = null;
        return true;
    }

    public static function remove(): bool
    {
        $path = self::htaccessPath();
        if (!is_file($path)) {
            return true;
        }

        $current = (string) file_get_contents($path);
        $cleaned = self::stripBlock($current);
        if ($cleaned === $current) {
            return true;
        }

        if (!is_writable($path)) {
            self::$lastError = '.htaccess is not writable.';
            return false;
        }

        return file_put_contents($path, $cleaned, LOCK_EX) !== false;
    }

    public static function isInstalled(): bool
    {
        $path = self::htaccessPath();
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        return str_contains((string) file_get_contents($path), self::BEGIN);
    }

    public static function lastError(): string
    {
        return (string) self::$lastError;
    }

    public static function htaccessPath(): string
    {
        if (function_exists('get_home_path')) {
            return trailingslashit(get_home_path()) . '.htaccess';
        }

        return ABSPATH . '.htaccess';
    }

    private static function block(): string
    {
        $defaults = Config::defaults();
        $opts = array_merge($defaults, (array) get_option(Config::OPTION_KEY, []));
        $lifespan = max(0, (int) ($opts['cache_lifespan'] ?? DAY_IN_SECONDS));

        $cookieNeedles = array_values(array_filter(
            self::lines((string) ($opts['cache_excluded_cookies'] ?? '')),
            static fn($needle) => $needle !== '' && $needle !== 'wordpress_logged_in_'
        ));
        $extraCookies = '';
        if (!empty($cookieNeedles)) {
            $extraCookies = '|' . implode('|', array_map(static fn($needle) => preg_quote($needle, '/'), $cookieNeedles));
        }

        $rules = [];
        $rules[] = '<IfModule mod_deflate.c>';
        $rules[] = '  AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml application/javascript application/json image/svg+xml';
        $rules[] = '</IfModule>';
        $rules[] = '';
        $rules[] = '<IfModule mod_expires.c>';
        $rules[] = '  ExpiresActive On';
        $rules[] = '  <FilesMatch "\.(css|js|mjs|svg|woff|woff2|ttf|eot|otf|webp|avif|png|jpe?g|gif|ico)$">';
        $rules[] = '    ExpiresDefault "access plus 1 year"';
        $rules[] = '  </FilesMatch>';
        $rules[] = '</IfModule>';

        // Direct web-server serve only in never-expire mode (otherwise the
        // drop-in honours the lifespan TTL and 304 semantics).
        if ($lifespan === 0) {
            $rules[] = '';
            $rules[] = '<IfModule mod_rewrite.c>';
            $rules[] = '  RewriteEngine On';
            $rules[] = '  RewriteCond %{REQUEST_METHOD} GET';
            $rules[] = '  RewriteCond %{QUERY_STRING} ^$';
            $rules[] = '  RewriteCond %{REQUEST_URI} !^/(wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php)';
            $rules[] = '  RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in' . $extraCookies . ')';
            $rules[] = '  RewriteCond %{DOCUMENT_ROOT}/wp-content/cache/imgpress/page-cache/%{SERVER_NAME}%{REQUEST_URI}index\.html -f';
            $rules[] = '  RewriteRule .* /wp-content/cache/imgpress/page-cache/%{SERVER_NAME}%{REQUEST_URI}index\.html [L,T=text/html]';
            $rules[] = '</IfModule>';
        }

        return self::BEGIN . "\n" . implode("\n", $rules) . "\n" . self::END;
    }

    private static function lines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        return array_values(array_filter(array_map('trim', $lines), static fn($line) => $line !== ''));
    }

    private static function replaceBlock(string $contents, string $block): string
    {
        $cleaned = self::stripBlock($contents);
        if (trim((string) $cleaned) === '') {
            return $block . "\n";
        }

        return rtrim($cleaned, "\n") . "\n\n" . $block . "\n";
    }

    private static function stripBlock(string $contents): string
    {
        $pattern = '/\s*' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\s*/s';
        return (string) preg_replace($pattern, '', $contents);
    }
}
