<?php
/**
 * Spread AI — MCP المرحلة 8: الأمان وسلامة البيانات
 *
 *   php tests/mcp8-security.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ai-center.php';
require_once __DIR__ . '/../includes/security-center.php';
if (!sec_ready()) exit("جداول قاعدة الـ AI ناقصة\n");
$site = rtrim($argv[1] ?? 'http://localhost:8080', '/');

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function call(string $route, string $scope, ?array $body = null): array
{
    global $site;
    $ch = curl_init($site . '/internal-api/v1/index.php?r=' . $route);
    $h = ['Authorization: Bearer ' . INTERNAL_API_TOKEN, 'X-Scope: ' . $scope, 'X-Client: mcp8-test'];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true) ?: []];
}
function http(string $method, string $url, array $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$c, $b];
}
function csrf(string $h): string { preg_match('/name="csrf" value="([^"]+)"/', $h, $m); return $m[1] ?? ''; }

$saved = ['security_last_run' => get_setting('security_last_run', null), 'security_auto' => get_setting('security_auto', null), 'daily_report_hour' => get_setting('daily_report_hour', null)];
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES ("PK8 sec", ?, "x", "active", NOW())', ['mcp8-' . bin2hex(random_bytes(3)) . '@test.local']);
$jobId = 0;
$tmp = sys_get_temp_dir() . '/mcp8-' . bin2hex(random_bytes(3));

try {
echo "فحص الإعدادات\n";
$cfg = sec_config_checks();
$by = array_column($cfg, null, 'key');
t('15+ نقطة بالحل لكل واحدة', count($cfg) >= 15 && !array_filter($cfg, fn($c) => $c['fix'] === '' || $c['title'] === ''), (string) count($cfg));
t('بيكشف وضع التطوير (محلي = شغال)', isset($by['app_debug']) && $by['app_debug']['ok'] === false);
t('بيكشف باسورد أدمن افتراضي (حساب الاختبار)', isset($by['admin_passwords']) && !$by['admin_passwords']['ok'] && str_contains($by['admin_passwords']['detail'], 'owner@app.test') && $by['admin_passwords']['severity'] === 'critical');
t('مجلد الرفع مايشغّلش PHP ✓', !empty($by['uploads_php']['ok']));

echo "سلامة البيانات\n";
db_run('INSERT INTO credit_wallets (user_id, balance) VALUES (?, -5) ON DUPLICATE KEY UPDATE balance = -5', [$uid]);
$jobId = (int) db_insert('INSERT INTO ce_jobs (user_id, kind, status, idem_key, created_at, updated_at) VALUES (?, "brand_dna", "running", ?, DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 3 HOUR))', [$uid, 'mcp8-' . bin2hex(random_bytes(4))]);
$int = array_column(integrity_checks(), null, 'key');
t('رصيد بالسالب ← اتكشف بالرقم', in_array($uid, $int['wallet_negative']['ids'] ?? [], true));
t('شغلانة واقفة أكتر من ساعتين ← اتكشفت', in_array($jobId, $int['jobs_stuck']['ids'] ?? [], true));
$r = sec_run();
$wi = db_one('SELECT * FROM ' . mcp_t('integrity_issues') . ' WHERE check_key = "wallet_negative"');
t('التسجيل في قاعدة الـ AI (مفتوحة بالعدد والحل)', $wi && $wi['status'] === 'open' && (int) $wi['count'] >= 1 && str_contains((string) $wi['entity_ids'], (string) $uid) && $wi['suggested_fix'] !== '');
$cf = db_one('SELECT * FROM ' . mcp_t('security_findings') . ' WHERE source = "config" AND location = "config:app_debug"');
t('نقط الإعدادات اتسجلت كملاحظات أمان (config)', $cf && $cf['status'] === 'open' && $r['config_total'] === count($cfg));
db_run('UPDATE ' . mcp_t('integrity_issues') . ' SET status = "ignored" WHERE check_key = "jobs_stuck"');
sec_run();
t('المتجاهَلة بتفضل متجاهلة', db_one('SELECT status FROM ' . mcp_t('integrity_issues') . ' WHERE check_key = "jobs_stuck"')['status'] === 'ignored');
db_run('UPDATE credit_wallets SET balance = 0 WHERE user_id = ?', [$uid]);
db_run('DELETE FROM ce_jobs WHERE id = ?', [$jobId]);
sec_run();
t('اتصلّحت ← «سليمة» لوحدها', db_one('SELECT status FROM ' . mcp_t('integrity_issues') . ' WHERE check_key = "wallet_negative"')['status'] === 'fixed'
    && db_one('SELECT status FROM ' . mcp_t('integrity_issues') . ' WHERE check_key = "jobs_stuck"')['status'] === 'fixed');
db_run('UPDATE ' . mcp_t('security_findings') . ' SET status = "accepted_risk" WHERE source = "config" AND location = "config:https"');
sec_run();
t('ملاحظة «مقبولة» من الأدمن مابترجعش مفتوحة', db_one('SELECT status FROM ' . mcp_t('security_findings') . ' WHERE location = "config:https"')['status'] === 'accepted_risk');

echo "الـ Internal API\n";
[$c, $x] = call('integrity_check', 'read');
t('integrity_check بيرجّع الفحوصات (من غير ما يغيّر حاجة)', $c === 200 && count($x['checks'] ?? []) >= 10 && isset($x['last_saved']), json_encode([$c, $x['error'] ?? null]));
[$c, $x] = call('security_scan', 'read', ['source' => 'config']);
t('Claude بيشوف ملاحظات الإعدادات', $c === 200 && str_contains(json_encode($x, JSON_UNESCAPED_UNICODE), 'config:app_debug'));

echo "الـ cron\n";
set_setting('security_last_run', '');
set_setting('daily_report_hour', '9');
set_setting('security_auto', '1');
t('قبل الميعاد ← مفيش', sec_tick(7) === null);
$tk = sec_tick(8);
t('الميعاد (قبل التقرير بساعة) ← فحص + تنبيه لو فيه حاجة حرجة', is_array($tk) && $tk['critical'] >= 1 && (bool) db_one('SELECT id FROM admin_alerts WHERE dedupe_key = ?', ['sec:' . date('Y-m-d')]));
t('مرة واحدة في اليوم', sec_tick(12) === null);

echo "E2E والاستيراد\n";
file_put_contents($tmp . '-e2e.json', json_encode(['run_at' => date('c'), 'results' => [['flow' => 'mcp8:test-ok', 'status' => 'passed', 'ms' => 120], ['flow' => 'mcp8:test-bad', 'status' => 'failed', 'ms' => 50, 'error' => 'HTTP 500']]]));
exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/e2e/record.php') . ' ' . escapeshellarg($tmp . '-e2e.json') . ' 2>&1', $o1, $rc1);
$e = array_column(sec_e2e_latest(), null, 'flow_key');
t('نتيجة E2E اتسجلت (نجح/فشل + الخطأ)', $rc1 === 0 && ($e['mcp8:test-ok']['status'] ?? '') === 'passed' && ($e['mcp8:test-bad']['error'] ?? '') === 'HTTP 500', implode(' ', $o1));
[$c, $x] = call('e2e_results', 'read');
t('Claude بيشوف نتايج E2E', $c === 200 && str_contains(json_encode($x), 'mcp8:test-bad'));
file_put_contents($tmp . '-zap.json', json_encode(['site' => [['@name' => 'https://s', 'alerts' => [['pluginid' => '99901', 'name' => 'MCP8 header missing', 'riskcode' => '3', 'desc' => 'd', 'solution' => 's', 'instances' => [['uri' => 'https://s/mcp8']]]]]]]));
exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/security/import-findings.php') . ' zap ' . escapeshellarg($tmp . '-zap.json') . ' 2>&1', $o2, $rc2);
$z = db_one('SELECT * FROM ' . mcp_t('security_findings') . ' WHERE location = "zap:99901:/mcp8"');
t('استيراد ZAP ← ملاحظة «عالية» بالحل', $rc2 === 0 && $z && $z['severity'] === 'high' && $z['status'] === 'open' && $z['recommendation'] === 's');
file_put_contents($tmp . '-zap.json', json_encode(['site' => [['@name' => 'https://s', 'alerts' => []]]]));
exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/security/import-findings.php') . ' zap ' . escapeshellarg($tmp . '-zap.json') . ' 2>&1', $o3);
t('الفحص الجديد مالقاهاش ← «اتصلّحت»', db_one('SELECT status FROM ' . mcp_t('security_findings') . ' WHERE location = "zap:99901:/mcp8"')['status'] === 'fixed');

echo "من الـ VPS (Internal API)\n";
[$c, $x] = call('save_e2e_results', 'ai_write', ['results' => [['flow' => 'mcp8:vps-ok', 'status' => 'passed', 'ms' => 90], ['flow' => 'mcp8:vps-bad', 'status' => 'failed', 'error' => 'JS: boom']]]);
$e = array_column(sec_e2e_latest(), null, 'flow_key');
t('save_e2e_results ← اتسجلت بالخطأ', $c === 200 && ($x['saved'] ?? 0) === 2 && ($x['failed'] ?? 0) === 1 && ($e['mcp8:vps-bad']['error'] ?? '') === 'JS: boom', json_encode($x, JSON_UNESCAPED_UNICODE));
[$c] = call('save_e2e_results', 'read', ['results' => [['flow' => 'mcp8:x', 'status' => 'passed']]]);
t('بصلاحية القراءة مرفوض', $c === 403, (string) $c);
[$c, $x] = call('sync_security_findings', 'ai_write', ['source' => 'zap', 'findings' => [['title' => 'MCP8 VPS', 'severity' => 'medium', 'location' => 'zap:99901:/mcp8-vps', 'recommendation' => 'r']]]);
$v = db_one('SELECT status, severity FROM ' . mcp_t('security_findings') . ' WHERE location = "zap:99901:/mcp8-vps"');
t('sync_security_findings ← ملاحظة جديدة', $c === 200 && $v && $v['status'] === 'open' && $v['severity'] === 'medium', json_encode($x, JSON_UNESCAPED_UNICODE));
[$c, $x] = call('sync_security_findings', 'ai_write', ['source' => 'zap', 'findings' => []]);
t('تقرير جديد من غيرها ← «اتصلّحت» (مفيش حذف)', $c === 200 && ($x['marked_fixed'] ?? 0) >= 1 && db_one('SELECT status FROM ' . mcp_t('security_findings') . ' WHERE location = "zap:99901:/mcp8-vps"')['status'] === 'fixed');
[$c] = call('sync_security_findings', 'ai_write', ['source' => 'config', 'findings' => []]);
t('مصدر مش مسموح (config) ← مرفوض', $c === 422, (string) $c);

echo "الأدمن\n";
$jar = tempnam(sys_get_temp_dir(), 'mcp8');
[, $lp] = http('GET', "$site/admin/login.php", [], $jar);
http('POST', "$site/admin/login.php", ['csrf' => csrf($lp), 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
$ok = true; $why = '';
foreach (['overview', 'integrity', 'findings', 'logs', 'e2e'] as $tb) {
    [$c, $h] = http('GET', "$site/admin/ai-security.php?tab=$tb", [], $jar);
    if ($c !== 200 || str_contains($h, 'Warning:') || str_contains($h, 'Fatal')) { $ok = false; $why .= "$tb:$c "; }
}
t('صفحة «الأمان»: التبويبات الخمسة', $ok, $why);
[, $h] = http('GET', "$site/admin/ai-security.php?tab=overview", [], $jar);
t('فحص الإعدادات بالحل + المشكلة الحرجة ظاهرة', str_contains($h, 'مفيش حساب أدمن بباسورد سهل') && str_contains($h, 'حرجة'));
[, $h] = http('GET', "$site/admin/ai-security.php?tab=e2e", [], $jar);
t('تبويب E2E بالنتايج', str_contains($h, 'mcp8:test-bad') && str_contains($h, 'HTTP 500'));
http('POST', "$site/admin/ai-security.php", ['csrf' => csrf($h), 'action' => 'finding', 'tab' => 'findings', 'id' => (int) $z['id'], 'status' => 'false_positive'], $jar);
t('تغيير حالة ملاحظة من الأدمن', db_one('SELECT status FROM ' . mcp_t('security_findings') . ' WHERE id = ?', [(int) $z['id']])['status'] === 'false_positive');

} finally {
db_run('DELETE FROM credit_wallets WHERE user_id = ?', [$uid]);
if ($jobId) db_run('DELETE FROM ce_jobs WHERE id = ?', [$jobId]);
db_run('DELETE FROM users WHERE id = ?', [$uid]);
db_run('DELETE FROM ' . mcp_t('e2e_runs') . ' WHERE flow_key LIKE "mcp8:%"');
db_run('DELETE FROM ' . mcp_t('security_findings') . ' WHERE location LIKE "zap:99901:%"');
db_run('UPDATE ' . mcp_t('security_findings') . ' SET status = "open" WHERE location = "config:https" AND status = "accepted_risk"');
db_run('DELETE FROM admin_alerts WHERE dedupe_key = ?', ['sec:' . date('Y-m-d')]);
foreach (glob($tmp . '*') as $f) @unlink($f);
foreach ($saved as $k => $v) { if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); else set_setting($k, (string) $v); }
sec_run();
}
t('رجّعنا البيانات (والفحص سليم تاني)', !db_one('SELECT id FROM users WHERE id = ?', [$uid]) && db_one('SELECT status FROM ' . mcp_t('integrity_issues') . ' WHERE check_key = "wallet_negative"')['status'] === 'fixed');
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
