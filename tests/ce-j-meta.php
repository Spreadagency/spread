<?php
/**
 * Spread AI — اختبارات ربط النتايج بـ Meta Insights
 * للتطوير المحلي بس: بيشغّل Graph API وهمي (tests/mock-graph.php) على 127.0.0.1:8093 ويوجّه الموقع ليه مؤقتًا، وبيمسح كل حاجة في الآخر.
 *
 *   php tests/ce-j-meta.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك لحد 2026_ce_meta_insights · الـ AI الوهمي شغال.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ce-campaign.php';
require_once __DIR__ . '/../includes/ce-learning.php';
require_once __DIR__ . '/../includes/account.php';
if (!ce_meta_ready()) exit("شغّل ترحيل 2026_ce_meta_insights الأول\n");

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function login(string $base, string $email): array
{
    $jar = tempnam(sys_get_temp_dir(), 'cej');
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
function run_job(int $id, int $max = 200): ?array
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
$setState = fn(array $s) => file_put_contents($stateFile, json_encode($s, JSON_UNESCAPED_UNICODE));
$perf = fn(int $cid) => db_one('SELECT * FROM ce_performance WHERE content_id = ?', [$cid]);

// Graph وهمي + توجيه الموقع ليه
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8093', __DIR__ . '/mock-graph.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);
$saved = [];
foreach (['graph_base_url', 'ce_meta_auto_match', 'ce_meta_match_min', 'ce_meta_sync_days', 'ce_meta_sync_hours', 'ce_meta_last_sync', 'ce_meta_last_match'] as $k) $saved[$k] = get_setting($k, null);
set_setting('graph_base_url', 'http://127.0.0.1:8093/v21.0');
set_setting('ce_meta_auto_match', '1');
set_setting('ce_meta_match_min', '0.75');
db_run('DELETE FROM rate_limits');

try {
/* ─── تجهيز: عميل + حملة 3 بوستات + صفحة مربوطة ─── */
echo "تجهيز\n";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at) VALUES (?,?,?,"active",NOW(),NOW())',
    ['CE-J test', 'cej-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
$email = db_one('SELECT email FROM users WHERE id = ?', [$uid])['email'];
credits_add($uid, 300, 'CE-J test credits');
db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, "content_engine", 1)', [$uid]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'براند ميتا', 'براند تجريبي']);
db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, 'خدمة', 'high']);
$per = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")', [$uid, 'عميل', json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($uid)['job']['id']);
$r = ce_campaign_save($uid, ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => [$per], 'formats' => ['post' => 4], 'channel' => 'whatsapp', 'boldness' => 'medium']);
$cid = (int) ($r['campaign']['id'] ?? 0);
run_job((int) ce_plan_start($uid, $cid)['job']['id'], 30);
if (!ce_plan_approve($uid, $cid)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$cid]);
run_job((int) ce_write_start($uid, $cid)['job']['id'], 300);
$cs = db_all('SELECT c.* FROM ce_contents c JOIN ce_slots s ON s.id = c.slot_id WHERE c.campaign_id = ? ORDER BY s.position', [$cid]);
// نصوص مختلفة لكل قطعة (عشان التشابه يبقى واضح)
$texts = ['ابتسامتك تستاهل اهتمام كامل كل يوم مع نصايح سهلة للعناية بالأسنان في البيت ومن غير مجهود',
          'ليه الكشف الدوري كل ست شهور بيوفر عليك فلوس ووجع كتير بعدين وإزاي تحجز من الموبايل',
          'خمس أكلات بتأذي اللثة من غير ما تاخد بالك والبديل الصحي ليها اللي ولادك هيحبوه',
          'قصة مريضة كانت خايفة من الدكتور وبقت بتيجي في ميعادها كل مرة بابتسامة'];
foreach ($cs as $i => $c) db_run('UPDATE ce_contents SET body_json = ? WHERE id = ?', [json_encode(['hooks' => [$texts[$i]], 'body' => $texts[$i], 'cta' => 'احجز'], JSON_UNESCAPED_UNICODE), (int) $c['id']]);
t('4 قطع جاهزة', count($cs) === 4 && !array_filter($cs, fn($c) => !in_array($c['status'], ['pass', 'manual'], true)));
$connId = (int) db_insert('INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, ig_user_id, ig_username, access_token_enc, token_type, scopes, status, last_verified_at)
                          VALUES (?, "facebook", "1111", "صفحة الاختبار", "2222", "test_ig", ?, "page", "pages_manage_posts,pages_read_engagement,read_insights,instagram_manage_insights", "active", NOW())',
    [$uid, social_token_encrypt('tok_ok')]);
$day = date('Y-m-d', strtotime('-3 days'));
$state = [
    'pages' => [
        '1111' => [['id' => '1111_1', 'message' => $texts[0] . ' #صحة_الأسنان', 'created_time' => $day . 'T10:00:00+0000'],
                   ['id' => '1111_2', 'message' => 'عرض العيد على كل الخدمات لحد آخر الشهر', 'created_time' => $day . 'T12:00:00+0000'],
                   ['id' => '1111_3', 'message' => $texts[1], 'created_time' => $day . 'T13:00:00+0000']],
        '9999' => [['id' => '9999_1', 'message' => $texts[0], 'created_time' => $day . 'T10:00:00+0000']],
    ],
    'ig' => ['2222' => [['id' => '30001', 'caption' => $texts[2], 'timestamp' => $day . 'T15:00:00+0000']]],
    'metrics' => [
        '1111_1' => ['post_impressions_unique' => 2000, 'post_impressions' => 3000, 'post_clicks' => 40, 'post_total_media_view_unique' => 2100, 'post_media_view' => 3500, 'reactions' => 100, 'comments' => 10, 'shares' => 5],
        '1111_3' => ['post_impressions_unique' => 800, 'post_impressions' => 900, 'post_clicks' => 4, 'reactions' => 20, 'comments' => 1, 'shares' => 0],
        '30001' => ['reach' => 500, 'saved' => 20, 'shares' => 3, 'views' => 900, 'like_count' => 50, 'comments_count' => 4],
    ],
    'deprecated' => false,
];
$setState($state);

/* ─── 1) اختيار البوست ─── */
echo "اختيار البوست\n";
$cand = ce_meta_candidates($uid, (int) $cs[0]['id'], $connId, 'facebook');
t('آخر بوستات الصفحة مترتبة بالتشابه مع نص القطعة', $cand['ok'] && $cand['posts'][0]['id'] === '1111_1' && $cand['posts'][0]['similarity'] >= 0.8 && end($cand['posts'])['similarity'] < 0.3, json_encode(array_map(fn($p) => [$p['id'], $p['similarity']], $cand['posts'] ?? [])));
$ig = ce_meta_candidates($uid, (int) $cs[2]['id'], $connId, 'instagram');
t('بوستات إنستجرام من الحساب المربوط بالصفحة', $ig['ok'] && $ig['posts'][0]['id'] === '30001');
t('صفحة عميل تاني ← مش موجودة', !ce_meta_candidates($uid + 1, (int) $cs[0]['id'], $connId, 'facebook')['ok']);
$wv = ce_write_view($uid, ce_campaign_get($cid, $uid));
t('شاشة الكتابة فيها الصفحات (من غير توكنز)', count($wv['meta']['connections']) === 1 && count($wv['meta']['connections'][0]['targets']) === 2 && !str_contains(json_encode($wv), 'access_token') && $wv['meta']['connections'][0]['insights'] === true);

/* ─── 2) الربط والسحب ─── */
echo "الربط والسحب\n";
t('بوست من صفحة تانية ← مرفوض', !ce_meta_link($uid, (int) $cs[0]['id'], $connId, 'facebook', '9999_1')['ok']);
t('رقم بوست غلط ← مرفوض', !ce_meta_link($uid, (int) $cs[0]['id'], $connId, 'facebook', "1'; DROP")['ok']);
$l = ce_meta_link($uid, (int) $cs[0]['id'], $connId, 'facebook', '1111_1');
$p0 = $perf((int) $cs[0]['id']);
t('الربط بيسحب الأرقام على طول (الوصول · المشاهدات · التفاعل · الضغطات)', $l['ok'] && (int) $p0['reach'] === 2000 && (int) $p0['impressions'] === 3000 && (int) $p0['likes'] === 100 && (int) $p0['comments'] === 10 && (int) $p0['shares'] === 5 && (int) $p0['clicks'] === 40, json_encode($l, JSON_UNESCAPED_UNICODE));
t('المصدر Meta · متزامن · تاريخ النشر من البوست · اللينك', $p0['source'] === 'meta' && $p0['sync_status'] === 'ok' && $p0['published_at'] === $day && str_contains((string) $p0['permalink'], '1111_1') && $p0['link_mode'] === 'manual');
t('المعدل اتحسب', (float) $p0['eng_rate'] == ce_perf_rate($p0) && (float) $p0['eng_rate'] > 0);
t('نفس البوست لقطعة تانية ← مرفوض', (ce_meta_link($uid, (int) $cs[1]['id'], $connId, 'facebook', '1111_1')['code'] ?? '') === 'taken');
$m = ce_perf_save($uid, (int) $cs[0]['id'], ['messages' => 7, 'reach' => 1, 'likes' => 999999]);
$p0 = $perf((int) $cs[0]['id']);
t('العميل بيدخّل الرسايل بس — أرقام Meta مابتتغيرش', $m['ok'] && (int) $p0['messages'] === 7 && (int) $p0['reach'] === 2000 && (int) $p0['likes'] === 100 && $p0['source'] === 'meta');

/* ─── 3) تغييرات Meta والأخطاء ─── */
echo "تغييرات Meta والأخطاء\n";
$state['deprecated'] = true;
$setState($state);
ce_meta_sync_row($perf((int) $cs[0]['id']));
$p0 = $perf((int) $cs[0]['id']);
t('metric اتلغى عند Meta ← بنجرّب الأسماء الجديدة (views)', $p0['sync_status'] === 'ok' && (int) $p0['impressions'] === 3500 && (int) $p0['reach'] === 2100, json_encode([$p0['sync_status'], $p0['impressions'], $p0['sync_error']]));
t('الرسايل بتفضل بعد السحب', (int) $p0['messages'] === 7);
$state['deprecated'] = false;
$setState($state);
db_run('UPDATE social_connections SET access_token_enc = ? WHERE id = ?', [social_token_encrypt('tok_noins'), $connId]);
ce_meta_sync_row($perf((int) $cs[0]['id']));
$p0 = $perf((int) $cs[0]['id']);
t('مفيش صلاحية Insights ← التفاعل بس + رسالة «اربط تاني»', $p0['sync_status'] === 'partial' && (int) $p0['likes'] === 100 && (int) $p0['reach'] === 2100 && str_contains((string) $p0['sync_error'], 'Insights'), json_encode([$p0['sync_status'], $p0['sync_error']], JSON_UNESCAPED_UNICODE));
db_run('UPDATE social_connections SET access_token_enc = ? WHERE id = ?', [social_token_encrypt('tok_expired'), $connId]);
ce_meta_sync_row($perf((int) $cs[0]['id']));
t('التوكن انتهى ← الصفحة بتتعلّم «منتهية» والقطعة «اربط تاني»', $perf((int) $cs[0]['id'])['sync_status'] === 'expired' && db_one('SELECT status FROM social_connections WHERE id = ?', [$connId])['status'] === 'expired');
t('الصفحة المنتهية مش بتظهر للربط', ce_meta_connections($uid) === []);
db_run('UPDATE social_connections SET access_token_enc = ?, status = "active" WHERE id = ?', [social_token_encrypt('tok_ok'), $connId]);

/* ─── 4) الربط التلقائي والمزامنة الدورية ─── */
echo "الربط التلقائي والمزامنة\n";
$am = ce_meta_auto_match();
$p1 = $perf((int) $cs[1]['id']);
$p2 = $perf((int) $cs[2]['id']);
t('ربط تلقائي لما النص زي ما هو (فيسبوك + إنستجرام)', $am['linked'] === 2 && $p1 && $p1['provider_post_id'] === '1111_3' && $p1['link_mode'] === 'auto' && $p2 && $p2['provider_post_id'] === '30001', json_encode($am));
t('إنستجرام: الوصول · الحفظ · المشاركة · المشاهدات · اللايك', (int) $p2['reach'] === 500 && (int) $p2['saves'] === 20 && (int) $p2['shares'] === 3 && (int) $p2['impressions'] === 900 && (int) $p2['likes'] === 50 && $p2['platform'] === 'instagram');
t('قطعة مالهاش بوست شبهها ← مابتتربطش', !$perf((int) $cs[3]['id']));
set_setting('ce_meta_auto_match', '0');
t('الربط التلقائي مقفول من الإعدادات ← ولا حاجة', ce_meta_auto_match()['linked'] === 0);
set_setting('ce_meta_auto_match', '1');
$state['metrics']['1111_3']['reactions'] = 80;
$setState($state);
db_run('UPDATE ce_performance SET synced_at = ? WHERE content_id = ?', [date('Y-m-d H:i:s', strtotime('-13 hours')), (int) $cs[1]['id']]);
db_run('UPDATE ce_performance SET synced_at = NOW() WHERE content_id IN (?, ?)', [(int) $cs[0]['id'], (int) $cs[2]['id']]);
$sd = ce_meta_sync_due(20);
t('المزامنة الدورية: اللي عدّى عليه أكتر من 12 ساعة بس', $sd['synced'] === 1 && (int) $perf((int) $cs[1]['id'])['likes'] === 80, json_encode($sd));
db_run('UPDATE ce_performance SET synced_at = ?, published_at = ? WHERE content_id = ?', [date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d', strtotime('-40 days')), (int) $cs[1]['id']]);
t('بعد 30 يوم من النشر ← بنبطّل نسحب', ce_meta_sync_due(20)['synced'] === 0);
set_setting('ce_meta_last_sync', '');
set_setting('ce_meta_last_match', '');
$t1 = ce_meta_tick();
t('الـ worker: أول مرة بيربط ويسحب · المرة اللي بعدها مستني', isset($t1['match'], $t1['sync']) && ce_meta_tick() === null);

/* ─── 5) فك الربط والتعلّم ─── */
echo "فك الربط والتعلّم\n";
ce_meta_unlink($uid, (int) $cs[2]['id']);
$p2 = $perf((int) $cs[2]['id']);
t('فك الربط: الأرقام بتفضل كإدخال يدوي', $p2 && $p2['provider_post_id'] === null && $p2['source'] === 'manual' && (int) $p2['reach'] === 500);
$rep = ce_learning_compute(ce_learning_rows(date('Y-m', strtotime($day)), 'user', $uid));
t('نتايج Meta بتدخل التقرير الشهري', $rep['n'] >= 2);

/* ─── 6) الـ API والصفحات ─── */
echo "الـ API والصفحات\n";
$s = login($base, $email);
[$a1, $j1] = api_post($base, $s, 'meta_posts', ['id' => $cid, 'content_id' => (int) $cs[3]['id'], 'connection_id' => $connId, 'platform' => 'facebook']);
t('العميل بيجيب بوستات صفحته', $a1 === 200 && count($j1['posts'] ?? []) === 3, $a1 . ' ' . json_encode($j1, JSON_UNESCAPED_UNICODE));
[$a2, $j2] = api_post($base, $s, 'meta_link', ['id' => $cid, 'content_id' => (int) $cs[3]['id'], 'connection_id' => $connId, 'platform' => 'facebook', 'post_id' => '1111_2']);
$s3 = array_values(array_filter($j2['write']['slots'] ?? [], fn($x) => ($x['content']['id'] ?? 0) === (int) $cs[3]['id']))[0]['content']['perf'] ?? [];
t('ربط من الـ API ← الشاشة بترجع بالأرقام والحالة', $a2 === 200 && !empty($s3['linked']) && $s3['sync_status'] === 'ok', $a2 . ' ' . json_encode($s3, JSON_UNESCAPED_UNICODE));
[$a3] = api_post($base, $s, 'meta_sync', ['id' => $cid, 'content_id' => (int) $cs[3]['id']]);
t('«حدّث دلوقتي» بعد التحديث بثواني ← استنى 10 دقايق', $a3 === 429, (string) $a3);
[$a4] = api_post($base, $s, 'meta_link', ['id' => $cid, 'content_id' => (int) (db_one('SELECT id FROM ce_contents WHERE user_id <> ? LIMIT 1', [$uid])['id'] ?? 0), 'connection_id' => $connId, 'post_id' => '1111_2']);
t('قطعة مش من الحملة ← 404', $a4 === 404, (string) $a4);
[$a5] = http('POST', "$base/public/api/engine.php", base64_encode(json_encode(['action' => 'meta_unlink', 'id' => $cid, 'content_id' => (int) $cs[3]['id']])), $s[0], ['X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8']);
t('من غير CSRF ← مرفوض', $a5 === 403 || $a5 === 419, (string) $a5);
[$a6] = api_post($base, $s, 'meta_unlink', ['id' => $cid, 'content_id' => (int) $cs[3]['id']]);
t('فك الربط من الـ API', $a6 === 200 && $perf((int) $cs[3]['id'])['provider_post_id'] === null);
t('زرار ربط الصفحة بيطلب صلاحيات Insights', str_contains((string) file_get_contents(__DIR__ . '/../public/social/connect.php'), 'read_insights,instagram_basic,instagram_content_publish,instagram_manage_insights'));
$jar = tempnam(sys_get_temp_dir(), 'ceja');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $mm);
http('POST', "$base/admin/login.php", ['csrf' => $mm[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c7, $h7] = http('GET', "$base/admin/ce-insights.php?tab=results", [], $jar);
t('الأدمن: أرقام الربط والإعدادات', $c7 === 200 && str_contains($h7, 'Meta Insights') && str_contains($h7, 'قطع مربوطة ببوست') && !str_contains($h7, 'Warning:'), (string) $c7);

} finally {
/* ─── التنضيف ─── */
if (!empty($uid)) {
    db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$uid]);
    db_run('DELETE FROM publish_logs WHERE connection_id = ?', [$connId ?? 0]);
    account_purge($uid);
}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "CE-J%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM rate_limits');
@unlink($stateFile);
proc_terminate($proc);
}
t('مسح الحساب بيمسح الربط والصفحة', !db_one('SELECT id FROM ce_performance WHERE user_id = ?', [$uid]) && !db_one('SELECT id FROM social_connections WHERE user_id = ?', [$uid]));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
