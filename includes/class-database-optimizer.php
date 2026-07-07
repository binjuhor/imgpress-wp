<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Database_Optimizer
{
    private const CRON_HOOK = 'imgpress_db_cleanup_cron';
    private const LAST_RUN_OPTION = 'imgpress_db_cleanup_last_run';

    public function __construct(
        private Settings $settings,
        private Logger $logger
    ) {
        add_filter('cron_schedules', [$this, 'registerCronSchedules']);
        add_action(self::CRON_HOOK, [$this, 'runScheduledCleanup']);
        add_action('wp_ajax_imgpress_db_cleanup_counts', [$this, 'handleGetCounts']);
        add_action('wp_ajax_imgpress_db_cleanup_run', [$this, 'handleRunCleanup']);
        add_action('update_option_' . Config::OPTION_KEY, [$this, 'handleSettingsUpdated'], 20, 2);
    }

    public function registerCronSchedules(array $schedules): array
    {
        $schedules['monthly'] = [
            'interval' => MONTH_IN_SECONDS,
            'display'  => __('Once a Month', 'imgpress-wp'),
        ];

        return $schedules;
    }

    public function init(): void
    {
        $this->syncSchedule();
    }

    public function handleSettingsUpdated(array $oldValue, array $newValue): void
    {
        $this->syncSchedule();
    }

    public function handleRunCleanup(): void
    {
        check_ajax_referer('imgpress_db_cleanup');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $result = $this->runCleanup();
        wp_send_json_success($result);
    }

    public function handleGetCounts(): void
    {
        check_ajax_referer('imgpress_db_cleanup_counts');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        wp_send_json_success([
            'counts' => $this->getCleanupCounts(),
            'total' => $this->getCleanupTotal(),
        ]);
    }

    public function runScheduledCleanup(): void
    {
        $this->runCleanup();
    }

    private function syncSchedule(): void
    {
        $schedule = $this->settings->getDbCleanupSchedule();
        $enabled = $this->settings->isDbCleanupEnabled() && in_array($schedule, ['daily', 'weekly', 'monthly'], true);
        $currentSchedule = wp_get_schedule(self::CRON_HOOK);

        if (!$enabled) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        if ($currentSchedule === $schedule && wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        if ($currentSchedule && $currentSchedule !== $schedule) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        $timestamp = time() > strtotime('today 5:00') ? strtotime('tomorrow 5:00') : strtotime('today 5:00');
        wp_schedule_event($timestamp, $schedule, self::CRON_HOOK);
    }

    private function runCleanup(): array
    {
        global $wpdb;

        $options = $this->settings->getDbCleanupOptions();
        $results = [];

        if (!array_filter($options)) {
            return [
                'success' => true,
                'message' => __('No database cleanup options are enabled.', 'imgpress-wp'),
                'results' => [],
                'totalDeleted' => 0,
                'lastRun' => current_time('mysql'),
            ];
        }

        if (!empty($options['post_revisions'])) {
            $results['post_revisions'] = $this->deleteFromTable(
                $wpdb->posts,
                "post_type = 'revision'"
            );
        }

        if (!empty($options['trashed_contents'])) {
            $results['trashed_contents'] = $this->deleteFromTable(
                $wpdb->posts,
                "post_status = 'trash'"
            );
        }

        if (!empty($options['trashed_spam_comments'])) {
            $results['trashed_spam_comments'] = $this->deleteFromTable(
                $wpdb->comments,
                "comment_approved = 'spam' OR comment_approved = 'trash'"
            );
        }

        if (!empty($options['trackback_pingback'])) {
            $results['trackback_pingback'] = $this->deleteFromTable(
                $wpdb->comments,
                "comment_type = 'trackback' OR comment_type = 'pingback'"
            );
        }

        if (!empty($options['transient_options'])) {
            $results['transient_options'] = $this->deleteFromTable(
                $wpdb->options,
                "option_name LIKE '%\\_transient\\_%'"
            );
        }

        if (!empty($options['orphaned_post_meta'])) {
            $results['orphaned_post_meta'] = $this->deleteOrphanedRows(
                $wpdb->postmeta,
                $wpdb->posts,
                'post_id',
                'ID'
            );
        }

        if (!empty($options['orphaned_comment_meta'])) {
            $results['orphaned_comment_meta'] = $this->deleteOrphanedRows(
                $wpdb->commentmeta,
                $wpdb->comments,
                'comment_id',
                'comment_ID'
            );
        }

        if (!empty($options['orphaned_user_meta'])) {
            $results['orphaned_user_meta'] = $this->deleteOrphanedRows(
                $wpdb->usermeta,
                $wpdb->users,
                'user_id',
                'ID'
            );
        }

        if (!empty($options['orphaned_term_meta'])) {
            $results['orphaned_term_meta'] = $this->deleteOrphanedRows(
                $wpdb->termmeta,
                $wpdb->terms,
                'term_id',
                'term_id'
            );
        }

        if (!empty($options['orphaned_term_relationships'])) {
            $results['orphaned_term_relationships'] = $this->deleteOrphanedRows(
                $wpdb->term_relationships,
                $wpdb->posts,
                'object_id',
                'ID'
            );
        }

        $totalDeleted = array_sum($results);
        $lastRun = current_time('mysql');

        $this->persistLastRun($lastRun);

        $this->logger->info('Database cleanup completed.', [
            'total_deleted' => $totalDeleted,
            'types' => implode(',', array_keys(array_filter($options))),
        ]);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: deleted row count */
                __('Database cleanup completed. Deleted %d rows.', 'imgpress-wp'),
                $totalDeleted
            ),
            'results' => $results,
            'totalDeleted' => $totalDeleted,
            'lastRun' => $lastRun,
        ];
    }

    public function getCleanupCounts(): array
    {
        global $wpdb;

        return [
            'post_revisions' => $this->countRows($wpdb->posts, "post_type = 'revision'"),
            'trashed_contents' => $this->countRows($wpdb->posts, "post_status = 'trash'"),
            'trashed_spam_comments' => $this->countRows($wpdb->comments, "comment_approved = 'spam' OR comment_approved = 'trash'"),
            'trackback_pingback' => $this->countRows($wpdb->comments, "comment_type = 'trackback' OR comment_type = 'pingback'"),
            'transient_options' => $this->countRows($wpdb->options, "option_name LIKE '%\\_transient\\_%'"),
            'orphaned_post_meta' => $this->countOrphanedRows($wpdb->postmeta, $wpdb->posts, 'post_id', 'ID'),
            'orphaned_comment_meta' => $this->countOrphanedRows($wpdb->commentmeta, $wpdb->comments, 'comment_id', 'comment_ID'),
            'orphaned_user_meta' => $this->countOrphanedRows($wpdb->usermeta, $wpdb->users, 'user_id', 'ID'),
            'orphaned_term_meta' => $this->countOrphanedRows($wpdb->termmeta, $wpdb->terms, 'term_id', 'term_id'),
            'orphaned_term_relationships' => $this->countOrphanedRows($wpdb->term_relationships, $wpdb->posts, 'object_id', 'ID'),
        ];
    }

    public function getCleanupTotal(): int
    {
        return array_sum($this->getCleanupCounts());
    }

    private function persistLastRun(string $lastRun): void
    {
        update_option(self::LAST_RUN_OPTION, $lastRun, false);
    }

    private function deleteFromTable(string $table, string $where): int
    {
        global $wpdb;

        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        $deleted = (int) $wpdb->query($sql);

        return max(0, $deleted);
    }

    private function deleteOrphanedRows(string $table, string $parentTable, string $childColumn, string $parentColumn): int
    {
        global $wpdb;

        $sql = sprintf(
            'DELETE child FROM `%1$s` child LEFT JOIN `%2$s` parent ON parent.%4$s = child.%3$s WHERE parent.%4$s IS NULL',
            $table,
            $parentTable,
            $childColumn,
            $parentColumn
        );

        return max(0, (int) $wpdb->query($sql));
    }

    private function countRows(string $table, string $where): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        return max(0, (int) $wpdb->get_var($sql));
    }

    private function countOrphanedRows(string $table, string $parentTable, string $childColumn, string $parentColumn): int
    {
        global $wpdb;

        $sql = sprintf(
            'SELECT COUNT(*) FROM `%1$s` child LEFT JOIN `%2$s` parent ON parent.%4$s = child.%3$s WHERE parent.%4$s IS NULL',
            $table,
            $parentTable,
            $childColumn,
            $parentColumn
        );

        return max(0, (int) $wpdb->get_var($sql));
    }
}
