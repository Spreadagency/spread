<?php
/**
 * Spread AI — تسجيل نتيجة اختبار الموقع (site-e2e.mjs) في قاعدة الـ AI (e2e_runs)
 * بتظهر في «الأمان ← اختبارات الموقع» ولـ Claude (spread_get_e2e_results).
 *
 *   php tests/e2e/record.php e2e-results.json
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mcp-config.php';
$file = $argv[1] ?? '';
$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) exit("ملف النتيجة مش صحيح: $file\n");
$at = !empty($data['run_at']) && strtotime((string) $data['run_at']) ? date('Y-m-d H:i:s', strtotime((string) $data['run_at'])) : date('Y-m-d H:i:s');
$n = 0;
foreach ($data['results'] as $r) {
    $flow = mb_substr(preg_replace('/[^\w:\/.\-?=&]/u', '', (string) ($r['flow'] ?? '')), 0, 80);
    if ($flow === '') continue;
    db_insert('INSERT INTO ' . mcp_t('e2e_runs') . ' (flow_key, status, duration_ms, error, run_at) VALUES (?,?,?,?,?)',
        [$flow, ($r['status'] ?? '') === 'passed' ? 'passed' : 'failed', isset($r['ms']) ? (int) $r['ms'] : null, isset($r['error']) ? mb_substr((string) $r['error'], 0, 2000) : null, $at]);
    $n++;
}
db_run('DELETE FROM ' . mcp_t('e2e_runs') . ' WHERE run_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
echo "اتسجّل $n نتيجة ✓\n";
