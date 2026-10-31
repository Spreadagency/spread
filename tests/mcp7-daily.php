<?php
/**
 * Spread AI — MCP المرحلة 7: التقرير اليومي لوحده
 * الـ cron بعد الساعة ← تقرير Claude موجود؟ «جاهز» · مش موجود؟ تقرير تلقائي + تنبيه ← Claude بيحل محله
 *
 *   php tests/mcp7-daily.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ai-center.php';
require_once __DIR__ . '/../includes/daily-report.php';
if (!dr_ready()) exit("جداول قاعدة الـ AI ناقصة\n");
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
    $h = ['Authorization: Bearer ' . INTERNAL_API_TOKEN, 'X-Scope: ' . $scope, 'X-Client: mcp7-test'];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
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

$day = date('Y-m-d', strtotime('-1 day'));
$keys = ['daily_report_on', 'daily_report_auto', 'daily_report_ai_summary', 'daily_report_email_on', 'daily_report_hour', 'daily_report_emails', 'daily_report_last_run'];
$saved = [];
foreach ($keys as $k) $saved[$k] = get_setting($k, null);
$bak = dr_get($day);
$clean = function () use ($day) {
    db_run('DELETE FROM ' . mcp_t('ai_reports') . ' WHERE type = "daily" AND report_date = ?', [$day]);
    db_run('DELETE FROM settings WHERE setting_key LIKE ?', ['daily_report_notified_' . $day . '%']);
    db_run('DELETE FROM admin_alerts WHERE dedupe_key LIKE ?', ['dr:' . $day . '%']);
};
$clean();
foreach (['daily_report_on' => '1', 'daily_report_auto' => '1', 'daily_report_ai_summary' => '1', 'daily_report_email_on' => '0', 'daily_report_hour' => '9', 'daily_report_last_run' => ''] as $k => $v) set_setting($k, $v);
// أرقام امبارح: منشور + عملية AI
$u = (int) (db_one('SELECT id FROM users ORDER BY id LIMIT 1')['id'] ?? 1);
$bp = (int) (db_one('SELECT id FROM brand_profiles WHERE user_id = ? LIMIT 1', [$u])['id'] ?? 0);
$cid = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, generated_text, status, created_at) VALUES (?, ?, "MCP7", "generated", ?)', [$u, $bp, $day . ' 11:00:00']);
db_insert('INSERT INTO ai_runs (run_key, user_id, task, status, cost_egp, created_at, sandbox) VALUES (?,?,?,?,?,?,0)', ['mcp7-' . bin2hex(random_bytes(6)), $u, 'content', 'ok', 1.5, $day . ' 11:00:00']);

try {
echo "الأرقام\n";
$s = dr_snapshot($day);
t('أرقام امبارح + اليوم اللي قبله + متوسط 7 أيام', $s['now']['posts'] >= 1 && $s['now']['ai_runs'] >= 1 && isset($s['avg7']['posts'], $s['prev']['posts']), json_encode($s['now']));
$md = dr_markdown($s, 'خلاصة تجريبية');
t('التقرير: الخلاصة + جدول الأرقام + مستنيك + طريقة المراجعة الكاملة', str_contains($md, '## الخلاصة') && str_contains($md, '| البند | امبارح |') && str_contains($md, '## مستنيك') && str_contains($md, 'daily_review') && str_contains($md, 'خلاصة تجريبية'));

echo "الـ cron\n";
t('قبل الساعة ← مابيعملش حاجة', dr_tick(8) === null && get_setting('daily_report_last_run', '') === '');
$r = dr_tick(9);
$rep = dr_get($day);
t('بعد الساعة ومفيش تقرير من Claude ← تقرير تلقائي', ($r['source'] ?? '') === 'auto' && $rep && dr_source($rep) === 'auto' && str_contains((string) $rep['summary'], 'التقرير اليومي التلقائي'), json_encode($r));
$last = json_decode((string) @file_get_contents('/tmp/claude-0/-home-user-spread/c847aa0b-e606-5d77-9835-79b34f925adf/scratchpad/mockai/last_req.json'), true);
t('الخلاصة بالـ AI اتطلبت بأرقام اليوم', str_contains(json_encode($last, JSON_UNESCAPED_UNICODE), 'اكتب خلاصة لصاحب المنصة'));
t('تنبيه للأدمن «تلقائي — Claude ماشتغلش»', (bool) db_one('SELECT id FROM admin_alerts WHERE dedupe_key = ?', ['dr:' . $day . ':auto']));
t('مرة واحدة في اليوم', dr_tick(10) === null);

echo "Claude\n";
[$c, $x] = call('save_report', 'ai_write', ['type' => 'daily', 'report_date' => $day, 'summary' => '# مراجعة Claude — ' . $day . "\nكل حاجة تمام والتوصيات تحت.", 'payload' => ['posts' => 1]]);
$rep = dr_get($day);
t('Claude حفظ ← تقريره حل محل التلقائي', $c === 200 && dr_source($rep) === 'claude' && str_contains((string) $rep['summary'], 'مراجعة Claude'), json_encode([$c, $x]));
t('ولما وصل بعد الميعاد ← تنبيه «تقرير Claude جاهز»', (bool) db_one('SELECT id FROM admin_alerts WHERE dedupe_key = ?', ['dr:' . $day . ':claude']));
$a = dr_make_auto($day);
t('التقرير التلقائي عمره ما بيكتب فوق تقرير Claude', !$a['ok'] && ($a['skipped'] ?? '') === 'claude' && str_contains((string) dr_get($day)['summary'], 'مراجعة Claude'));

echo "يوم Claude عمله الأول\n";
$clean();
set_setting('daily_report_last_run', '');
call('save_report', 'ai_write', ['type' => 'daily', 'report_date' => $day, 'summary' => '# مراجعة Claude بدري — ' . $day . "\nتمام.", 'payload' => ['posts' => 1]]);
t('قبل الميعاد ← مفيش تنبيه لسه', !db_one('SELECT id FROM admin_alerts WHERE dedupe_key LIKE ?', ['dr:' . $day . '%']));
$r = dr_tick(9);
t('الميعاد ← «جاهز» من غير تقرير تلقائي', ($r['source'] ?? '') === 'claude' && dr_source(dr_get($day)) === 'claude' && (bool) db_one('SELECT id FROM admin_alerts WHERE dedupe_key = ?', ['dr:' . $day . ':claude']));

echo "مقفول\n";
$clean();
set_setting('daily_report_last_run', '');
set_setting('daily_report_auto', '0');
$r = dr_tick(9);
t('التلقائي مقفول ← تنبيه «ماوصلش» ومفيش تقرير', ($r['source'] ?? '') === 'missing' && !dr_get($day) && (bool) db_one('SELECT id FROM admin_alerts WHERE dedupe_key = ?', ['dr:' . $day . ':missing']));
set_setting('daily_report_auto', '1');
set_setting('daily_report_on', '0');
set_setting('daily_report_last_run', '');
t('الخاصية كلها مقفولة ← مفيش حاجة', dr_tick(23) === null);
set_setting('daily_report_on', '1');

echo "الإيميل\n";
set_setting('daily_report_emails', 'a@x.com, bad, b@y.com');
t('الإيميلات من الإعدادات (الغلط بيتشال)', dr_recipients() === ['a@x.com', 'b@y.com']);
set_setting('daily_report_emails', '');
t('فاضي ← حسابات الأدمن الرئيسية', in_array('owner@app.test', dr_recipients(), true), json_encode(dr_recipients()));

echo "الأدمن\n";
$clean();
$jar = tempnam(sys_get_temp_dir(), 'mcp7');
[, $lp] = http('GET', "$site/admin/login.php", [], $jar);
http('POST', "$site/admin/login.php", ['csrf' => csrf($lp), 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c, $h] = http('GET', "$site/admin/ai-report.php?type=daily", [], $jar);
t('صفحة التقرير: الإعدادات + نص المهمة المجدولة', $c === 200 && str_contains($h, 'name="daily_report_hour"') && str_contains($h, 'Scheduled task') && str_contains($h, 'daily_review') && !str_contains($h, 'Warning:'), (string) $c);
http('POST', "$site/admin/ai-report.php", ['csrf' => csrf($h), 'action' => 'dr_run', 'day' => $day], $jar);
[, $h2] = http('GET', "$site/admin/ai-report.php?type=daily", [], $jar);
t('«اعمل التقرير التلقائي دلوقتي» + شارة «تلقائي من الموقع»', dr_source(dr_get($day)) === 'auto' && str_contains($h2, 'تلقائي من الموقع'));
http('POST', "$site/admin/ai-report.php", ['csrf' => csrf($h2), 'action' => 'dr_settings', 'daily_report_hour' => 7, 'daily_report_emails' => 'me@spread.test', 'daily_report_on' => 1, 'daily_report_auto' => 1], $jar);
t('حفظ الإعدادات (اللي مش متعلّم بيتقفل)', get_setting('daily_report_hour') === '7' && get_setting('daily_report_emails') === 'me@spread.test' && get_setting('daily_report_ai_summary') === '0' && get_setting('daily_report_email_on') === '0');

} finally {
$clean();
if ($bak) db_run('INSERT INTO ' . mcp_t('ai_reports') . ' (type, report_date, summary, payload) VALUES (?,?,?,?)', [$bak['type'], $bak['report_date'], $bak['summary'], $bak['payload']]);
db_run('DELETE FROM contents WHERE id = ?', [$cid]);
db_run('DELETE FROM ai_runs WHERE run_key LIKE "mcp7-%"');
foreach ($saved as $k => $v) { if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); else set_setting($k, (string) $v); }
}
t('رجّعنا كل حاجة', !db_one('SELECT id FROM ai_runs WHERE run_key LIKE "mcp7-%"'));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
