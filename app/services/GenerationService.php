<?php
declare(strict_types=1);

/**
 * Limits and the actual AI run for one generation.
 */
final class GenerationService
{
    /** Minutes after which a "processing" row is considered dead. */
    private const STUCK_AFTER = 4;

    /**
     * Check every limit before a paid call (spec §6).
     * @return array|null null when allowed, otherwise an API payload explaining why not
     */
    public static function checkLimits(array $lead, string $ip, string $cookie): ?array
    {
        // 1. One completed generation per phone (admin can allow one more)
        $donePhone = (int) q_value("SELECT COUNT(*) FROM generations WHERE lead_id = ? AND status = 'done'", [$lead['id']]);
        if (!$lead['regen_allowed'] && $donePhone >= max(1, Settings::int('limit_per_phone', 1))) {
            $last = q_row("SELECT * FROM generations WHERE lead_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$lead['id']]);
            return $last ? generation_payload($last) + ['returning' => true] : ['status' => 'limit'];
        }

        // 2. Global daily cap (day boundary in the site time zone)
        $dayStart = (new DateTimeImmutable('today'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $today = (int) q_value("SELECT COUNT(*) FROM generations WHERE status IN ('processing','done') AND started_at >= ?", [$dayStart]);
        if ($today >= Settings::int('limit_daily_global', 300)) {
            return ['status' => 'cap'];
        }

        // 3. Per IP and per device in the last 24h (rejected/failed attempts don't count)
        $since = utc_now('-24 hours');
        $perIp = Settings::int('limit_per_ip', 3);
        if ($perIp > 0 && (int) q_value("SELECT COUNT(*) FROM generations WHERE ip = ? AND status IN ('processing','done') AND started_at >= ?", [$ip, $since]) >= $perIp) {
            return ['status' => 'limit'];
        }
        $perCookie = Settings::int('limit_per_cookie', 2);
        if ($perCookie > 0 && (int) q_value("SELECT COUNT(*) FROM generations WHERE device_cookie = ? AND status IN ('processing','done') AND started_at >= ?", [$cookie, $since]) >= $perCookie) {
            return ['status' => 'limit'];
        }
        return null;
    }

    /**
     * Atomically move a generation to "processing". Returns false if another
     * request already claimed it (double click, two tabs).
     */
    public static function claim(int $genId, string $ip, string $cookie): bool
    {
        $n = q("UPDATE generations SET status = 'processing', attempts = attempts + 1, started_at = ?, ip = ?, device_cookie = ?, error_message = NULL
                WHERE id = ? AND status IN ('uploaded','failed') AND attempts < 5", [utc_now(), $ip, $cookie, $genId])->rowCount();
        return $n === 1;
    }

    /** Run safety check + edit, with one automatic retry. Updates the row. */
    public static function run(int $genId): array
    {
        @set_time_limit(180);
        ignore_user_abort(true);
        $gen = q_row('SELECT * FROM generations WHERE id = ?', [$genId]);
        if (!$gen) {
            return ['status' => 'failed'];
        }
        $start = microtime(true);
        $original = ORIGINALS_PATH . '/' . $gen['original_path'];
        $jpeg = is_file($original) ? (string) file_get_contents($original) : '';
        if ($jpeg === '') {
            self::fail($genId, 'failed', 'Original image missing');
            return self::payload($genId);
        }

        $tries = Settings::bool('gemini_auto_retry') ? 2 : 1;
        $lastError = '';
        for ($i = 1; $i <= $tries; $i++) {
            try {
                $gemini = GeminiService::fromSettings();
                if ($i === 1) {
                    $gemini->safetyCheck($jpeg);
                }
                $bytes = $gemini->edit($jpeg, (string) Settings::get('gemini_prompt'));
                $rel = ImageService::storeResult($bytes);
                ImageService::shareCard(RESULTS_PATH . '/' . $rel, ImageService::ogPath($rel));
                q("UPDATE generations SET status = 'done', result_path = ?, share_token = ?, completed_at = ?, duration_ms = ?, error_message = NULL WHERE id = ?", [
                    $rel, bin2hex(random_bytes(16)), utc_now(), (int) ((microtime(true) - $start) * 1000), $genId,
                ]);
                q('UPDATE leads SET regen_allowed = 0 WHERE id = ?', [$gen['lead_id']]);
                app_log('info', 'Generation done', ['gen' => $genId, 'ms' => (int) ((microtime(true) - $start) * 1000), 'try' => $i]);
                return self::payload($genId);
            } catch (GeminiException $e) {
                $lastError = $e->getMessage();
                if ($e->kind === 'rejected') {
                    self::fail($genId, 'rejected', $lastError);
                    return self::payload($genId);
                }
            } catch (Throwable $e) {
                $lastError = get_class($e) . ': ' . $e->getMessage();
            }
            log_error('Generation attempt failed', ['gen' => $genId, 'try' => $i, 'error' => $lastError]);
            if ($i < $tries) {
                sleep(2);
            }
        }
        self::fail($genId, 'failed', $lastError);
        return self::payload($genId);
    }

    /** Mark rows stuck in "processing" (worker killed, timeout) as failed. */
    public static function reapStuck(?int $genId = null): void
    {
        $sql = "UPDATE generations SET status = 'failed', error_message = 'Timed out' WHERE status = 'processing' AND started_at < ?";
        $params = [utc_now('-' . self::STUCK_AFTER . ' minutes')];
        if ($genId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $genId;
        }
        q($sql, $params);
    }

    private static function fail(int $genId, string $status, string $error): void
    {
        q('UPDATE generations SET status = ?, error_message = ?, completed_at = ? WHERE id = ?', [$status, mb_substr($error, 0, 500), utc_now(), $genId]);
    }

    private static function payload(int $genId): array
    {
        return generation_payload((array) q_row('SELECT * FROM generations WHERE id = ?', [$genId]));
    }
}
