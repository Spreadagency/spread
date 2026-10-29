<?php
/**
 * Spread AI — اختبارات النشر والجدولة من محرك المحتوى
 * للتطوير المحلي بس: بيشغّل Graph API وهمي (tests/mock-graph.php) على 127.0.0.1:8093 ويوجّه الموقع ليه مؤقتًا، وبيمسح كل حاجة في الآخر.
 *
 *   php tests/ce-k-publish.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك لحد 2026_ce_publish · الـ AI الوهمي شغال.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ce-campaign.php';
require_once __DIR__ . '/../includes/ce-design.php';
require_once __DIR__ . '/../includes/ce-publish.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/account.php';
if (!ce_publish_ready()) exit("شغّل ترحيل 2026_ce_publish الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function login(string $base, string $email): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cek');
    [, $html] = http('GET', "$base/public/login.php", [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'Test12345!'], $jar);
    [, $page] = http('GET', "$base/public/dashboard.php", [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $c);
    return [$jar, $c[1] ?? ''];
}
function api_post(string $base, array $s, string $action, array $data = []): array
{
    [$code, $body] = http('POST', "$base/public/api/engine.php", base64_encode(json_encode(['action' => $action] + $data)), $s[0],
        ['X-CSRF-Token: ' . $s[1], 'X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8', 'X-Requested-With: XMLHttpRequest']);
    return [$code, json_decode($body, true) ?: []];
}
function run_job(int $id, int $max = 250): ?array
{
    $j = null;
    for ($i = 0; $i < $max; $i++) {
        $x = ce_job_step($id);
        if ($x) $j = $x;
        if ($j && !in_array($j['status'], ['queued', 'running'], true)) break;
    }
    return $j;
}
$stateFile = sys_get_temp_dir() . '/spread-mock-graph.json';
$logFile = $stateFile . '.log';
$log = fn() => array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents($logFile))));
file_put_contents($stateFile, json_encode(['pages' => ['1111' => []], 'ig' => ['2222' => []], 'metrics' => []]));
@unlink($logFile);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8093', __DIR__ . '/mock-graph.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);
$saved = [];
foreach (['graph_base_url', 'ce_meta_auto_match', 'ce_meta_last_sync', 'ce_meta_last_match'] as $k) $saved[$k] = get_setting($k, null);
set_setting('graph_base_url', 'http://127.0.0.1:8093/v21.0');
set_setting('ce_meta_auto_match', '0');
db_run('DELETE FROM rate_limits');
$q = fn(int $cid, string $pf) => db_one('SELECT * FROM ce_publish_queue WHERE content_id = ? AND platform = ?', [$cid, $pf]);

try {
/* ─── تجهيز: حملة (بوستين + كاروسيل + ريلز) متصممة + صفحة مربوطة ─── */
echo "تجهيز\n";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at) VALUES (?,?,?,"active",NOW(),NOW())',
    ['CE-K test', 'cek-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
$email = db_one('SELECT email FROM users WHERE id = ?', [$uid])['email'];
credits_add($uid, 500, 'CE-K test credits');
foreach (['content_engine', 'social_publishing'] as $f) db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, ?, 1)', [$uid, $f]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'براند النشر', 'براند تجريبي']);
db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, 'خدمة', 'high']);
$per = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")', [$uid, 'عميل', json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($uid)['job']['id']);
$r = ce_campaign_save($uid, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => [$per], 'formats' => ['post' => 2, 'carousel' => 1, 'reel' => 1], 'channel' => 'whatsapp', 'boldness' => 'medium']);
$camp = (int) ($r['campaign']['id'] ?? 0);
run_job((int) ce_plan_start($uid, $camp)['job']['id'], 30);
if (!ce_plan_approve($uid, $camp)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$camp]);
run_job((int) ce_write_start($uid, $camp)['job']['id'], 400);
run_job((int) ce_design_start($uid, $camp, false)['job']['id'], 400);
$cs = db_all('SELECT c.*, s.format sformat, s.slot_date FROM ce_contents c JOIN ce_slots s ON s.id = c.slot_id WHERE c.campaign_id = ? ORDER BY s.position', [$camp]);
$by = [];
foreach ($cs as $c) $by[$c['format']][] = $c;
t('حملة متكتبة ومتصممة: بوستين + كاروسيل + ريلز', count($by['post'] ?? []) === 2 && count($by['carousel'] ?? []) === 1 && count($by['reel'] ?? []) === 1, json_encode(array_map('count', $by)));
$conn = (int) db_insert('INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, ig_user_id, ig_username, access_token_enc, token_type, scopes, status, last_verified_at)
                        VALUES (?, "facebook", "1111", "صفحة النشر", "2222", "pub_ig", ?, "page", "pages_manage_posts,read_insights,instagram_content_publish,instagram_manage_insights", "active", NOW())',
    [$uid, social_token_encrypt('tok_ok')]);
$p0 = $by['post'][0];
$p1 = $by['post'][1];
$car = $by['carousel'][0];
$reel = $by['reel'][0];

/* ─── 1) تجهيز البوست ─── */
echo "تجهيز البوست\n";
$msg = ce_publish_message(['hooks' => ['Hook قوي', 'تاني'], 'body' => 'النص الأساسي', 'cta' => 'احجز على واتساب', 'hashtags' => ['صحة الأسنان', '#ابتسامة']], 'post');
t('النص: الـ Hook + النص + الـ CTA + الهاشتاجات', str_starts_with($msg, 'Hook قوي') && str_contains($msg, 'النص الأساسي') && str_contains($msg, 'احجز على واتساب') && str_contains($msg, '#صحة_الأسنان #ابتسامة'), $msg);
t('الكاروسيل: الكابشن', str_starts_with(ce_publish_message(['caption' => 'كابشن الكاروسيل', 'slides' => [['text' => 'x']], 'cta' => 'c'], 'carousel'), 'كابشن الكاروسيل'));
$imgs = ce_publish_images((int) $car['slot_id']);
t('صور التصميم بروابط كاملة (البوست صورة · الكاروسيل كل الشرايح بالترتيب)', count(ce_publish_images((int) $p0['slot_id'])) === 1 && count($imgs) >= 2 && str_starts_with($imgs[0], APP_URL . '/storage/'), json_encode($imgs));
$cr = db_one('SELECT * FROM social_connections WHERE id = ?', [$conn]);
t('الريلز مابتتنشرش من هنا', str_contains((string) ce_publish_problem($reel, $cr, 'facebook'), 'الريلز'));
t('إنستجرام من غير حساب مربوط ← السبب واضح', str_contains((string) ce_publish_problem($p0, ['ig_user_id' => null] + $cr, 'instagram'), 'إنستجرام'));

/* ─── 2) انشر دلوقتي ─── */
echo "انشر دلوقتي\n";
$a = ce_publish_queue_add($uid, $camp, [(int) $p0['id']], $conn, ['facebook'], 'now');
t('اتحط في الطابور', $a['ok'] && $a['queued'] === 1 && $q((int) $p0['id'], 'facebook')['status'] === 'pending', json_encode($a, JSON_UNESCAPED_UNICODE));
t('حصة النشر اتحسبت للقطعة (ref بالسالب)', (bool) db_one('SELECT id FROM usage_events WHERE user_id = ? AND unit = "publishes" AND ref_id = ?', [$uid, -(int) $p0['id']]));
$tk = ce_publish_tick();
$row = $q((int) $p0['id'], 'facebook');
t('الـ worker نشر: الحالة · رقم البوست · اللينك', $tk['published'] === 1 && $row['status'] === 'published' && str_starts_with((string) $row['provider_post_id'], '1111_') && str_contains((string) $row['permalink'], 'facebook.com/1111/posts/'), json_encode([$tk, $row['status'], $row['error']], JSON_UNESCAPED_UNICODE));
$l = $log();
$body0 = json_decode((string) $p0['body_json'], true);
$last = end($l);
t('فيسبوك استلم الصورة والنص', ($last['path'] ?? '') === '1111/photos' && str_contains((string) ($last['url'] ?? ''), '/storage/') && str_contains((string) ($last['caption'] ?? ''), mb_substr((string) ($body0['hooks'][0] ?? $body0['body'] ?? ''), 0, 15)), json_encode($last, JSON_UNESCAPED_UNICODE));
$perf = db_one('SELECT * FROM ce_performance WHERE content_id = ?', [(int) $p0['id']]);
t('القطعة اتربطت ببوستها لوحدها (النتايج هتتسحب)', $perf && $perf['provider_post_id'] === $row['provider_post_id'] && $perf['link_mode'] === 'publish' && $perf['source'] === 'meta' && $perf['sync_status'] === 'pending');
$sy = ce_meta_sync_due(10);
$perf = db_one('SELECT * FROM ce_performance WHERE content_id = ?', [(int) $p0['id']]);
t('المزامنة سحبت أرقام البوست الجديد', $sy['synced'] >= 1 && (int) $perf['reach'] === 50 && $perf['sync_status'] === 'ok', json_encode([$sy, $perf['reach'], $perf['sync_status'], $perf['sync_error']], JSON_UNESCAPED_UNICODE));
$again = ce_publish_queue_add($uid, $camp, [(int) $p0['id']], $conn, ['facebook'], 'now');
t('نفس القطعة مرة تانية على نفس المنصة ← «اتنشرت قبل كده»', $again['queued'] === 0 && str_contains($again['skipped'][0]['reason'] ?? '', 'اتنشرت'));

/* ─── 3) الكاروسيل على المنصتين في معاد ─── */
echo "الكاروسيل على فيسبوك + إنستجرام في معاد\n";
$at = date('Y-m-d H:i', strtotime('+2 days'));
t('معاد عدّى ← مرفوض', !ce_publish_queue_add($uid, $camp, [(int) $car['id']], $conn, ['facebook'], 'at', date('Y-m-d H:i', strtotime('-1 hour')))['ok']);
$b = ce_publish_queue_add($uid, $camp, [(int) $car['id']], $conn, ['facebook', 'instagram'], 'at', $at);
t('صفين (فيسبوك + إنستجرام) مستنيين المعاد', $b['queued'] === 2 && substr((string) $q((int) $car['id'], 'instagram')['scheduled_at'], 0, 16) === $at);
t('قبل المعاد ← مابيتنشرش', ce_publish_tick()['published'] === 0);
t('تغيير المعاد لوقت عدّى ← مرفوض', !ce_publish_reschedule($uid, (int) $q((int) $car['id'], 'facebook')['id'], date('Y-m-d H:i', strtotime('-5 minutes')))['ok']);
db_run('UPDATE ce_publish_queue SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE content_id = ?', [(int) $car['id']]);
@unlink($logFile);
$tk = ce_publish_tick();
$l = $log();
$paths = array_column($l, 'path');
$n = count($imgs);
t('المعاد جه ← الاتنين اتنشروا', $tk['published'] === 2 && $q((int) $car['id'], 'facebook')['status'] === 'published' && $q((int) $car['id'], 'instagram')['status'] === 'published', json_encode($tk));
t('فيسبوك: كل شريحة اترفعت لوحدها وبوست واحد بيجمعهم', count(array_filter($l, fn($x) => $x['path'] === '1111/photos' && ($x['published'] ?? '') === 'false')) === $n && (bool) array_filter($l, fn($x) => $x['path'] === '1111/feed' && isset($x['attached_media'][$n - 1])), json_encode($paths));
t('إنستجرام: شريحة شريحة ← كاروسيل ← نشر', count(array_filter($l, fn($x) => $x['path'] === '2222/media' && ($x['is_carousel_item'] ?? '') === 'true')) === $n && (bool) array_filter($l, fn($x) => ($x['media_type'] ?? '') === 'CAROUSEL') && in_array('2222/media_publish', $paths, true));
t('إنستجرام: لينك البوست', str_contains((string) $q((int) $car['id'], 'instagram')['permalink'], 'instagram.com/p/'));
t('القطعة اتربطت بفيسبوك (الأولوية) مش إنستجرام', db_one('SELECT platform FROM ce_performance WHERE content_id = ?', [(int) $car['id']])['platform'] === 'facebook');

/* ─── 4) جدول الحملة كلها في معاد الخطة ─── */
echo "جدول الحملة كلها\n";
db_run('UPDATE ce_slots SET slot_date = ? WHERE id = ?', [date('Y-m-d', strtotime('+3 days')), (int) $p1['slot_id']]);
db_run('UPDATE ce_slots SET slot_date = ? WHERE id = ?', [date('Y-m-d', strtotime('+4 days')), (int) $reel['slot_id']]);
$all = ce_publish_queue_add($uid, $camp, array_map(fn($c) => (int) $c['id'], $cs), $conn, ['facebook'], 'slot');
$reasons = array_column($all['skipped'], 'reason');
t('اللي جاهز اتجدول في معاد الخطة (البوست التاني)', $all['queued'] === 1 && $q((int) $p1['id'], 'facebook')['scheduled_at'] === default_publish_datetime(date('Y-m-d', strtotime('+3 days'))), json_encode($all, JSON_UNESCAPED_UNICODE));
t('اللي اتنشر قبل كده والريلز اتسابوا بسببهم', (bool) array_filter($reasons, fn($x) => str_contains($x, 'الريلز')) && (bool) array_filter($reasons, fn($x) => str_contains($x, 'اتنشرت')));
db_run('UPDATE ce_slots SET slot_date = ? WHERE id = ?', [date('Y-m-d', strtotime('-2 days')), (int) $p1['slot_id']]);
db_run('DELETE FROM ce_publish_queue WHERE content_id = ?', [(int) $p1['id']]);
$past = ce_publish_queue_add($uid, $camp, [(int) $p1['id']], $conn, ['facebook'], 'slot');
t('معاد الخطة عدّى ← بيتساب بسبب واضح', $past['queued'] === 0 && str_contains($past['skipped'][0]['reason'] ?? '', 'عدّى'));

/* ─── 5) الإلغاء والفشل ─── */
echo "الإلغاء والفشل\n";
ce_publish_queue_add($uid, $camp, [(int) $p1['id']], $conn, ['facebook'], 'at', date('Y-m-d H:i', strtotime('+1 day')));
$qid = (int) $q((int) $p1['id'], 'facebook')['id'];
t('إلغاء ← اتلغى والحصة رجعت', ce_publish_cancel($uid, $qid) && $q((int) $p1['id'], 'facebook')['status'] === 'cancelled' && !db_one('SELECT id FROM usage_events WHERE user_id = ? AND unit = "publishes" AND ref_id = ?', [$uid, -(int) $p1['id']]));
t('عميل تاني مايقدرش يلغي', !ce_publish_cancel($uid + 1, $qid));
db_run('UPDATE social_connections SET access_token_enc = ? WHERE id = ?', [social_token_encrypt('tok_failpub'), $conn]);
ce_publish_queue_add($uid, $camp, [(int) $p1['id']], $conn, ['facebook'], 'now');
ce_publish_tick();
$row = $q((int) $p1['id'], 'facebook');
t('خطأ مؤقت من فيسبوك ← محاولة تانية بعد شوية', $row['status'] === 'pending' && $row['next_attempt_at'] !== null && (int) $row['attempts'] === 1 && $row['error'] !== null, json_encode([$row['status'], $row['attempts'], $row['error']], JSON_UNESCAPED_UNICODE));
for ($i = 0; $i < 3; $i++) { db_run('UPDATE ce_publish_queue SET next_attempt_at = NULL WHERE id = ?', [(int) $row['id']]); ce_publish_tick(); }
$row = $q((int) $p1['id'], 'facebook');
t('بعد 3 محاولات ← فشل نهائي والحصة رجعت', $row['status'] === 'failed' && !db_one('SELECT id FROM usage_events WHERE user_id = ? AND unit = "publishes" AND ref_id = ?', [$uid, -(int) $p1['id']]), json_encode([$row['status'], $row['attempts']]));
db_run('UPDATE social_connections SET access_token_enc = ? WHERE id = ?', [social_token_encrypt('tok_expired'), $conn]);
ce_publish_queue_add($uid, $camp, [(int) $p1['id']], $conn, ['facebook'], 'now');
ce_publish_tick();
t('التوكن انتهى ← فشل نهائي والصفحة «منتهية»', $q((int) $p1['id'], 'facebook')['status'] === 'failed' && db_one('SELECT status FROM social_connections WHERE id = ?', [$conn])['status'] === 'expired');
db_run('UPDATE social_connections SET access_token_enc = ?, status = "active" WHERE id = ?', [social_token_encrypt('tok_ok'), $conn]);

/* ─── 6) الـ API والشاشات ─── */
echo "الـ API والشاشات\n";
$wv = ce_write_view($uid, ce_campaign_get($camp, $uid));
$rows = (array) $wv['publish']['rows'];
t('شاشة الكتابة: حالة النشر تحت كل قطعة', $wv['publish']['ready'] && $wv['publish']['allowed'] && ($rows[(int) $p0['id']][0]['status'] ?? '') === 'published' && count($rows[(int) $car['id']] ?? []) === 2);
$s = login($base, $email);
db_run('DELETE FROM ce_publish_queue WHERE content_id = ?', [(int) $p1['id']]);
[$c1, $j1] = api_post($base, $s, 'publish_queue', ['id' => $camp, 'content_ids' => [(int) $p1['id']], 'connection_id' => $conn, 'platforms' => ['facebook'], 'when' => 'now']);
$st = $j1['write']['publish']['rows'][(string) $p1['id']][0]['status'] ?? null;
t('«انشر دلوقتي» من الـ API ← اتنشر على طول', $c1 === 200 && ($j1['result']['queued'] ?? 0) === 1 && $st === 'published', $c1 . ' ' . json_encode([$j1['error'] ?? null, $st], JSON_UNESCAPED_UNICODE));
[$c2] = api_post($base, $s, 'publish_queue', ['id' => $camp + 999999, 'all' => 1, 'connection_id' => $conn, 'platforms' => ['facebook'], 'when' => 'slot']);
t('حملة مش بتاعته ← 404', $c2 === 404, (string) $c2);
[$c3] = http('POST', "$base/public/api/engine.php", base64_encode(json_encode(['action' => 'publish_queue', 'id' => $camp])), $s[0], ['X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8']);
t('من غير CSRF ← مرفوض', $c3 === 403 || $c3 === 419, (string) $c3);
[$c4, $j4] = api_post($base, $s, 'publish_queue', ['id' => $camp, 'content_ids' => [(int) $p1['id']], 'connection_id' => $conn, 'platforms' => ['twitter'], 'when' => 'now']);
t('منصة مش معروفة ← 422', $c4 === 422, $c4 . ' ' . json_encode($j4, JSON_UNESCAPED_UNICODE));
// صلاحية النشر بتتقري مرة في الطلب ← بنجرّبها من طلب جديد
db_run('UPDATE user_feature_access SET is_enabled = 0 WHERE user_id = ? AND feature_key = "social_publishing"', [$uid]);
[$c6, $j6] = api_post($base, $s, 'publish_queue', ['id' => $camp, 'content_ids' => [(int) $p1['id']], 'connection_id' => $conn, 'platforms' => ['facebook'], 'when' => 'now']);
t('النشر المباشر مش مفتوح للعميل ← 403', $c6 === 403 && ($j6['code'] ?? '') === 'feature', $c6 . ' ' . json_encode($j6, JSON_UNESCAPED_UNICODE));
db_run('UPDATE user_feature_access SET is_enabled = 1 WHERE user_id = ? AND feature_key = "social_publishing"', [$uid]);
t('الـ worker بينشر كل دقيقة', str_contains((string) file_get_contents(__DIR__ . '/../cron/ce-worker.php'), 'ce_publish_tick('));
$jar = tempnam(sys_get_temp_dir(), 'ceka');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $mm);
http('POST', "$base/admin/login.php", ['csrf' => $mm[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c5, $h5] = http('GET', "$base/admin/ce-insights.php?tab=results", [], $jar);
t('الأدمن: أرقام النشر وآخر الأخطاء', $c5 === 200 && str_contains($h5, 'النشر من المحرك') && !str_contains($h5, 'Warning:'), (string) $c5);

} finally {
if (!empty($uid)) {
    db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$uid]);
    db_run('DELETE FROM publish_logs WHERE connection_id = ?', [$conn ?? 0]);
    db_run('DELETE FROM usage_events WHERE user_id = ?', [$uid]);
    account_purge($uid);
}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-K%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM rate_limits');
@unlink($stateFile);
@unlink($logFile);
proc_terminate($proc);
}
t('مسح الحساب بيمسح طابور النشر', !db_one('SELECT id FROM ce_publish_queue WHERE user_id = ?', [$uid]));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
