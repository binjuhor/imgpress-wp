<?php

namespace ImgPress;

defined('ABSPATH') || exit;

class Jobs
{
    private const OPTION_KEY = 'imgpress_wp_jobs';
    private const HOOK = 'imgpress_process_job';

    public function __construct(private Logger $logger)
    {
    }

    public function init(): void
    {
        add_action(self::HOOK, [$this, 'process'], 10, 1);
    }

    public function enqueue(string $type, array $payload = []): string
    {
        $jobId = wp_generate_uuid4();
        $jobs = (array) get_option(self::OPTION_KEY, []);
        $jobs[$jobId] = [
            'type' => sanitize_key($type),
            'payload' => $this->sanitizePayload($payload),
            'created_at' => time(),
        ];

        update_option(self::OPTION_KEY, $jobs, false);
        $this->schedule($jobId);

        return $jobId;
    }

    public function process(string $jobId): void
    {
        $jobs = (array) get_option(self::OPTION_KEY, []);
        if (empty($jobs[$jobId]) || !is_array($jobs[$jobId])) {
            return;
        }

        $job = $jobs[$jobId];
        unset($jobs[$jobId]);
        update_option(self::OPTION_KEY, $jobs, false);

        $type = sanitize_key((string) ($job['type'] ?? ''));
        if ($type === '') {
            return;
        }

        try {
            do_action("imgpress_job_{$type}", (array) ($job['payload'] ?? []), $jobId);
        } catch (\Throwable $throwable) {
            $this->logger->error('Background job failed.', [
                'job_id' => $jobId,
                'type' => $type,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function schedule(string $jobId): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$jobId], 'imgpress');
            return;
        }

        wp_schedule_single_event(time() + 1, self::HOOK, [$jobId]);
    }

    private function sanitizePayload(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }
}
