<?php
declare(strict_types=1);

/**
 * Daily cleanup (spec §8.3). cPanel → Cron Jobs, once a day:
 *   /usr/local/bin/php /home/USER/slim/cron/cleanup.php >/dev/null 2>&1
 *
 * - deletes original photos older than retention_originals_days
 * - deletes generated images (and share cards) older than retention_results_days
 * - marks stuck "processing" rows as failed
 * - prunes old rate-limit rows
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$origDays = max(1, Settings::int('retention_originals_days', 30));
$resDays = max(1, Settings::int('retention_results_days', 90));
$stats = ['originals' => 0, 'results' => 0, 'stuck' => 0, 'rate_limits' => 0];

$rows = q('SELECT id, original_path FROM generations WHERE original_path IS NOT NULL AND created_at < ?', [utc_now("-{$origDays} days")])->fetchAll();
foreach ($rows as $r) {
    ImageService::deleteFile(ORIGINALS_PATH, $r['original_path']);
    q('UPDATE generations SET original_path = NULL WHERE id = ?', [$r['id']]);
    $stats['originals']++;
}

$rows = q('SELECT id, result_path FROM generations WHERE result_path IS NOT NULL AND COALESCE(completed_at, created_at) < ?', [utc_now("-{$resDays} days")])->fetchAll();
foreach ($rows as $r) {
    ImageService::deleteFile(RESULTS_PATH, $r['result_path']);
    $og = ImageService::ogPath($r['result_path']);
    if (is_file($og)) {
        @unlink($og);
    }
    q('UPDATE generations SET result_path = NULL WHERE id = ?', [$r['id']]);
    $stats['results']++;
}

$stats['stuck'] = q("UPDATE generations SET status = 'failed', error_message = 'Timed out' WHERE status = 'processing' AND started_at < ?", [utc_now('-10 minutes')])->rowCount();
$stats['rate_limits'] = RateLimiter::prune();

// Remove empty shard folders
foreach ([ORIGINALS_PATH, RESULTS_PATH] as $base) {
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (count(scandir($dir) ?: []) === 2) {
            @rmdir($dir);
        }
    }
}

Settings::set('cleanup_last_run', json_encode(['at' => date(DATE_ATOM)] + $stats));
app_log('info', 'Cleanup done', $stats);
echo json_encode($stats), PHP_EOL;
