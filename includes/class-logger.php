<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Logger
{
    private const OPTION_KEY = 'imgpress_wp_logs';
    private const MAX_ENTRIES = 200;

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function recent(int $limit = 20): array
    {
        $entries = (array) get_option(self::OPTION_KEY, []);
        return array_slice(array_reverse($entries), 0, max(1, $limit));
    }

    public function clear(): void
    {
        delete_option(self::OPTION_KEY);
    }

    private function log(string $level, string $message, array $context): void
    {
        $entries = (array) get_option(self::OPTION_KEY, []);
        $entries[] = [
            'time' => current_time('mysql'),
            'level' => sanitize_key($level),
            'message' => sanitize_text_field($message),
            'context' => $this->sanitizeContext($context),
        ];

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        update_option(self::OPTION_KEY, $entries, false);
    }

    private function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }
}
