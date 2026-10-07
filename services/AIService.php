<?php
namespace Spread;

/**
 * AIService — Facade موحد لاستدعاء الـ AI في v2
 *  1. Smart Routing  → يختار الموديل حسب المهمة
 *  2. Failover       → لو فشل يجرب الـ fallback chain
 *  3. Retry          → إعادة محاولة مع backoff
 *  4. Logging        → ai_job_logs + ai_failover_logs
 */
class AIService
{
    private const MAX_RETRIES = 2;

    /**
     * توليد نص
     *
     * @param string $taskCode  content_generation / source_summary / plan_ideas ...
     * @param array  $messages  [['role'=>..,'content'=>..], ...] (vision multi-part مدعوم)
     * @param array  $options   max_tokens, temperature, json_mode, user_id, reference_type, reference_id
     * @return array {ok, content, model, provider, tokens_in, tokens_out, cost_usd, error}
     */
    public static function generate(string $taskCode, array $messages, array $options = []): array
    {
        $jsonMode = $options['json_mode'] ?? false;

        try {
            $route = SmartRouter::route($taskCode);
        } catch (\Throwable $e) {
            return self::failResult($e->getMessage());
        }

        if (empty($options['max_tokens']) && !empty($route['max_tokens'])) {
            $options['max_tokens'] = $route['max_tokens'];
        }

        $chain = array_merge(
            [['model' => $route['model'], 'provider' => $route['provider']]],
            $route['fallback']
        );

        $lastError = null;
        $jobLogId = null;

        foreach ($chain as $idx => $target) {
            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                $jobLogId = self::startJobLog($options['job_type'] ?? 'content_gen', $target, $taskCode, $options);

                try {
                    $adapter = ProviderFactory::make($target['provider']);
                    $result  = $adapter->chat($target['model'], $messages, $options);

                    if (!$result['ok']) {
                        throw new \RuntimeException($result['error'] ?? 'unknown error');
                    }

                    if ($jsonMode) {
                        $content = self::extractJson($result['content']);
                        json_decode($content, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
                        }
                        $result['content'] = $content;
                    }

                    self::completeJobLog($jobLogId, $result, 'success', $attempt);

                    return [
                        'ok'         => true,
                        'content'    => $result['content'],
                        'model'      => $target['model'],
                        'provider'   => $target['provider'],
                        'tokens_in'  => $result['tokens_in'],
                        'tokens_out' => $result['tokens_out'],
                        'cost_usd'   => $result['cost_usd'],
                        'error'      => null,
                    ];
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    self::completeJobLog($jobLogId, ['error' => $lastError], 'failed', $attempt);

                    $isLastModel   = ($idx === count($chain) - 1);
                    $isLastAttempt = ($attempt === self::MAX_RETRIES);

                    if ($isLastAttempt && !$isLastModel) {
                        self::logFailover($jobLogId, $target, $chain[$idx + 1], $lastError, 'recovered');
                        break; // جرب الموديل التالي
                    }
                    if (!$isLastAttempt) {
                        usleep(500000 * $attempt);
                    }
                }
            }
        }

        self::logFailover($jobLogId, end($chain), null, $lastError, 'final_failure');
        return self::failResult($lastError ?? 'All providers failed');
    }

    /**
     * توليد صورة — يرجع URLs أو base64 strings
     */
    public static function generateImage(string $imagePrompt, array $options = []): array
    {
        try {
            $route = SmartRouter::route($options['task_code'] ?? 'image_generation');
        } catch (\Throwable $e) {
            return ['ok' => false, 'images' => [], 'error' => $e->getMessage()];
        }

        $target = ['model' => $route['model'], 'provider' => $route['provider']];
        $jobLogId = self::startJobLog('design_gen', $target, $options['task_code'] ?? 'image_generation', $options);

        try {
            $adapter = ProviderFactory::make($target['provider']);
            $result  = $adapter->image($target['model'], $imagePrompt, $options);

            if (!$result['ok']) {
                self::completeJobLog($jobLogId, ['error' => $result['error']], 'failed', 1);
                return ['ok' => false, 'images' => [], 'error' => $result['error']];
            }

            self::completeJobLog($jobLogId, $result, 'success', 1);
            return [
                'ok'       => true,
                'images'   => $result['images'],
                'model'    => $target['model'],
                'provider' => $target['provider'],
                'cost_usd' => $result['cost_usd'],
                'error'    => null,
            ];
        } catch (\Throwable $e) {
            self::completeJobLog($jobLogId, ['error' => $e->getMessage()], 'failed', 1);
            return ['ok' => false, 'images' => [], 'error' => $e->getMessage()];
        }
    }

    // ─── Helpers ────────────────────────────────────────────

    private static function extractJson(string $content): string
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(\{.*\}|\[.*\])\s*```/s', $content, $m)) {
            return $m[1];
        }
        $start = strpos($content, '{');
        $end   = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($content, $start, $end - $start + 1);
        }
        return $content;
    }

    private static function failResult(string $error): array
    {
        return [
            'ok' => false, 'content' => null, 'model' => null, 'provider' => null,
            'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0, 'error' => $error,
        ];
    }

    private static function startJobLog(string $jobType, array $target, string $taskCode, array $options): int
    {
        try {
            return db_insert(
                'INSERT INTO ai_job_logs (job_type, user_id, reference_type, reference_id, provider, model, task_code, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "failed")',
                [
                    $jobType,
                    $options['user_id']        ?? null,
                    $options['reference_type'] ?? null,
                    $options['reference_id']   ?? null,
                    $target['provider'],
                    $target['model'],
                    $taskCode,
                ]
            );
        } catch (\Throwable $e) {
            return 0; // اللوج مش يوقف التوليد أبدًا
        }
    }

    private static function completeJobLog(int $logId, array $result, string $status, int $attempt): void
    {
        if (!$logId) {
            return;
        }
        try {
            db_run(
                'UPDATE ai_job_logs SET tokens_in=?, tokens_out=?, cost_usd=?, duration_ms=?, status=?, error_message=?, retry_count=? WHERE id=?',
                [
                    $result['tokens_in']   ?? 0,
                    $result['tokens_out']  ?? 0,
                    $result['cost_usd']    ?? 0,
                    $result['duration_ms'] ?? 0,
                    $status,
                    $result['error'] ?? null,
                    $attempt,
                    $logId,
                ]
            );
        } catch (\Throwable $e) {
        }
    }

    private static function logFailover(?int $jobLogId, $failed, ?array $next, ?string $reason, string $outcome): void
    {
        if (!is_array($failed)) {
            return;
        }
        try {
            db_run(
                'INSERT INTO ai_failover_logs (job_log_id, failed_provider, failed_model, failure_reason, fallback_provider, fallback_model, outcome)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $jobLogId ?: null,
                    $failed['provider'],
                    $failed['model'],
                    $reason,
                    $next['provider'] ?? null,
                    $next['model']    ?? null,
                    $outcome,
                ]
            );
        } catch (\Throwable $e) {
        }
    }
}
