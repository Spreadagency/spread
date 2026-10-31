<?php
/**
 * Spread AI — الباقات (المرحلة 1): الكتابة بالكريدت بسعر ثابت · التصميم من حصة الباقة · النشر بالكريدت
 * للتطوير المحلي بس — بيعمل عميل تجريبي ويمسحه في الآخر، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/pk1-billing.php http://localhost:8080
 *
 * محتاج: ترحيلات المحرك · الـ AI الوهمي شغال.
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
$keys = ['content_generation_cost', 'carousel_generation_cost', 'story_generation_cost', 'video_script_cost', 'ce_plan_cost', 'campaign_plan_eval_cost',
         'content_regeneration_cost', 'publish_cost', 'designs_charge_credits', 'ce_billing_mode', 'quotas_enforced'];
$saved = [];
foreach ($keys as $k) $saved[$k] = get_setting($k, null);
foreach (['content_generation_cost' => '2', 'carousel_generation_cost' => '3', 'video_script_cost' => '1', 'ce_plan_cost' => '4', 'campaign_plan_eval_cost' => '1',
          'content_regeneration_cost' => '1', 'publish_cost' => '2', 'designs_charge_credits' => '0', 'ce_billing_mode' => 'fixed', 'quotas_enforced' => '1'] as $k => $v) set_setting($k, $v);
db_run('DELETE FROM settings WHERE setting_key = "story_generation_cost"');
db_run('DELETE FROM rate_limits');
$bal = function () use (&$uid) { return credits_balance((int) $uid); };

try {
/* ─── 1) الأسعار ─── */
echo "الأسعار\n";
t('سعر الكتابة حسب الشكل (بوست 2 · كاروسيل 3 · سكريبت فيديو/ريلز 1)', cost_for_format('post') === 2 && cost_for_format('carousel') === 3 && cost_for_format('reel') === 1 && cost_for_format('video') === 1);
t('شكل مالوش سعر (ستوري) ← سعر البوست', cost_for_format('story') === 2);
t('التصميم من الحصة ← مفيش كريدت', design_credit_cost() === 0 && design_cost_text() === 'من حصة التصميمات');
set_setting('designs_charge_credits', '1');
t('«التصميم بالكريدت كمان» مفتوح ← سعر التصميم', design_credit_cost() === cost_for('content_design_cost'));
set_setting('designs_charge_credits', '0');
t('الحصص بقت التصميمات والأبحاث بس — المنشورات والفيديو والنشر من الكريدت', array_keys(plan_units()) === ['designs', 'research']);

/* ─── تجهيز: عميل بباقة فيها 3 تصميمات ─── */
echo "تجهيز\n";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at) VALUES (?,?,?,"active",NOW(),NOW())',
    ['PK1 test', 'pk1-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
credits_add($uid, 100, 'PK1 test credits');
db_insert('INSERT INTO user_plans (user_id, package_id, name, source, starts_at, ends_at, credits_allowance, quotas_json, status) VALUES (?,NULL,"باقة اختبار","paid",?,?,100,?, "active")',
    [$uid, date('Y-m-d H:i:s', strtotime('-1 day')), date('Y-m-d H:i:s', strtotime('+29 days')), json_encode(['designs' => 3, 'research' => 1])]);
db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled) VALUES (?, "content_engine", 1)', [$uid]);
db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$uid, 'براند الباقات', 'براند تجريبي']);
db_insert('INSERT INTO brand_services (user_id, name, priority, is_active) VALUES (?,?,?,1)', [$uid, 'خدمة', 'high']);
$per = (int) db_insert('INSERT INTO brand_personas (user_id, name, pains, desires, objections, is_active, source) VALUES (?,?,?,?,?,1,"user")', [$uid, 'عميل', json_encode(['الوقت']), json_encode(['سرعة']), json_encode(['السعر'])]);
run_job((int) ce_dna_build($uid)['job']['id']);
t('حصة الشهر: 3 تصميمات · مفيش حد للمنشورات', plan_gate($uid, 'posts') === null && ce_designs_left($uid) === 3);

/* ─── 2) المحرك بالسعر الثابت ─── */
echo "المحرك بالسعر الثابت\n";
$brief = ['goal' => 'awareness', 'all_services' => 1, 'persona_ids' => [$per], 'formats' => ['post' => 2, 'carousel' => 1, 'reel' => 1], 'channel' => 'whatsapp', 'boldness' => 'medium'];
t('تقدير الحملة = الخطة 4 + بوستين × 2 + كاروسيل 3 + ريلز 1 = 12', ce_estimate($brief)['credits'] === 12, (string) ce_estimate($brief)['credits']);
$r = ce_campaign_save($uid, $brief);
$camp = (int) ($r['campaign']['id'] ?? 0);
$b0 = $bal();
$pj = run_job((int) ce_plan_start($uid, $camp)['job']['id'], 30);
t('الخطة اتحسبت بسعرها الثابت (4) مهما كانت تكلفة الـ AI', $b0 - $bal() === 4 && ($pj['status'] ?? '') === 'done', ($b0 - $bal()) . ' ' . ($pj['status'] ?? ''));
if (!ce_plan_approve($uid, $camp)['ok']) db_run('UPDATE ce_campaigns SET status = "plan_ok" WHERE id = ? AND status = "review"', [$camp]);
$c = ce_campaign_get($camp, $uid);
t('حجز الكتابة = مجموع أسعار القطع (8)', ce_write_hold_credits($c) === 8);
$b1 = $bal();
run_job((int) ce_write_start($uid, $camp)['job']['id'], 400);
$done = db_all('SELECT s.format FROM ce_contents x JOIN ce_slots s ON s.id = x.slot_id WHERE x.campaign_id = ? AND x.status IN ("pass", "manual")', [$camp]);
t('الكتابة اتحسبت على القطع اللي خلصت بس بسعرها', $b1 - $bal() === ce_price_slots($done) && count($done) === 4, ($b1 - $bal()) . ' / ' . count($done));
$slot = db_one('SELECT x.slot_id, i.id iid FROM ce_contents x JOIN ce_ideas i ON i.slot_id = x.slot_id WHERE x.campaign_id = ? LIMIT 1', [$camp]);
$b2 = $bal();
$w = ce_write_slot($uid, $camp, (int) $slot['slot_id'], (int) $slot['iid']);
if ($w['ok']) run_job((int) $w['job']['id'], 100);
t('إعادة كتابة خانة = سعر إعادة التوليد (1)', $w['ok'] && $b2 - $bal() === 1, json_encode([$w['ok'], $b2 - $bal()]));

/* ─── 3) التصميم من الحصة ─── */
echo "التصميم من حصة الباقة\n";
$b3 = $bal();
$d = ce_design_start($uid, $camp, false);
t('الحملة محتاجة تصميمات أكتر من الحصة (3) ← مرفوض برسالة الحصة ومن غير خصم', !$d['ok'] && ($d['code'] ?? '') === 'quota' && str_contains((string) $d['error'], 'فاضل 3') && $bal() === $b3, json_encode($d, JSON_UNESCAPED_UNICODE));
t('الشاشة بتعرض الباقي من الحصة', (ce_design_view($uid, ce_campaign_get($camp, $uid))['designs_left'] ?? -1) === 3);
db_insert('INSERT INTO quota_overrides (user_id, unit, extra, reason) VALUES (?, "designs", 47, "PK1 test")', [$uid]);
$d = ce_design_start($uid, $camp, false);
$dj = $d['ok'] ? run_job((int) $d['job']['id'], 400) : null;
$made = (int) db_one('SELECT COUNT(*) n FROM ce_designs WHERE campaign_id = ? AND status IN ("pass", "manual")', [$camp])['n'];
$u = plan_usage($uid, true);
$du = array_values(array_filter($u['units'], fn($x) => $x['key'] === 'designs'))[0];
t('بعد الزيادة ← اتصمم ومفيش كريدت اتخصم', $d['ok'] && ($dj['status'] ?? '') === 'done' && $made > 3 && $bal() === $b3, json_encode([$d['ok'], $dj['status'] ?? null, $made, $b3 - $bal()]));
t('التصميمات اتحسبت من الحصة (كل تصميم نهائي = 1)', $du['used'] === $made && $du['left'] === 50 - $made, json_encode($du));
$one = db_one('SELECT * FROM ce_designs WHERE campaign_id = ? AND kind = "ai" AND status IN ("pass", "manual") LIMIT 1', [$camp]);
$rd = ce_design_redo($uid, $camp, (int) $one['slot_id'], (int) $one['frame']);
if ($rd['ok']) run_job((int) $rd['job']['id'], 100);
$du2 = array_values(array_filter(plan_usage($uid, true)['units'], fn($x) => $x['key'] === 'designs'))[0];
t('«صورة جديدة» بطلب العميل = تصميم كمان من الحصة · من غير كريدت', $rd['ok'] && $du2['used'] === $made + 1 && $bal() === $b3, json_encode([$rd['ok'], $du2['used'], $made]));

/* ─── 4) تصميمات الموقع القديم ─── */
echo "تصميمات الموقع\n";
$bp = db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$uid]);
$ct = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status) VALUES (?,?, "marketing", "facebook", "نص", "generated")', [$uid, (int) $bp['id']]);
$before = $du2['used'];
db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used, provider) VALUES (?,?, "x.png", "p", "img-model", 0, "img-model")', [$ct, $uid]);
db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used) VALUES (?,?, "y.png", "تصميم مرفوع من العميل", "upload", 0)', [$ct, $uid]);
$du3 = array_values(array_filter(plan_usage($uid, true)['units'], fn($x) => $x['key'] === 'designs'))[0];
t('تصميم AI من غير كريدت بيتحسب · المرفوع مابيتحسبش', $du3['used'] === $before + 1, json_encode([$before, $du3['used']]));

/* ─── 5) النشر بالكريدت ─── */
echo "النشر بالكريدت\n";
$b4 = $bal();
t('الرصيد يكفي ← النشر مسموح', plan_gate($uid, 'publishes') === null);
plan_event($uid, 'publishes', $ct);
t('أول نشر للمنشور ← اتخصم سعر النشر (2)', $b4 - $bal() === 2);
plan_event($uid, 'publishes', $ct);
t('نفس المنشور تاني (المنصة التانية) ← مفيش خصم تاني', $b4 - $bal() === 2);
plan_event_release($uid, 'publishes', $ct);
t('اتلغى/فشل ← الكريدت رجع', $bal() === $b4);
plan_event_release($uid, 'publishes', $ct);
t('الإلغاء مرتين ← مايرجعش مرتين', $bal() === $b4);
plan_event($uid, 'publishes', -999);
plan_event_release($uid, 'publishes', -999);
t('نشر المحرك (ref بالسالب) بيتخصم ويرجع برضه', $bal() === $b4);
set_setting('publish_cost', '100000');
t('الرصيد مش كفاية للنشر ← رسالة الرصيد', (string) plan_gate($uid, 'publishes') !== '');
set_setting('publish_cost', '2');

/* ─── 6) الأدمن ─── */
echo "الأدمن\n";
$jar = tempnam(sys_get_temp_dir(), 'pk1a');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $mm);
http('POST', "$base/admin/login.php", ['csrf' => $mm[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c1, $h1] = http('GET', "$base/admin/pricing.php", [], $jar);
t('«التكاليف»: أسعار الكاروسيل والستوري والسكريبت والخطة والنشر + التصميم من الحصة + حساب المحرك',
  $c1 === 200 && str_contains($h1, 'name="publish_cost"') && str_contains($h1, 'name="video_script_cost"') && str_contains($h1, 'name="designs_charge_credits"') && str_contains($h1, 'name="ce_billing_mode"') && !str_contains($h1, 'Warning:'), (string) $c1);
[$c2, $h2] = http('GET', "$base/admin/packages.php", [], $jar);
t('«الباقات» بتفتح من غير أخطاء', $c2 === 200 && !str_contains($h2, 'Warning:') && !str_contains($h2, 'Fatal'), (string) $c2);

} finally {
if (!empty($uid)) {
    db_run('DELETE FROM ce_jobs WHERE user_id = ?', [$uid]);
    db_run('DELETE FROM usage_events WHERE user_id = ?', [$uid]);
    db_run('DELETE FROM quota_overrides WHERE user_id = ?', [$uid]);
    db_run('DELETE FROM user_plans WHERE user_id = ?', [$uid]);
    account_purge($uid);
}
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND (notes LIKE "PK1%" OR notes LIKE "حجز:%" OR notes LIKE "استرداد الفرق:%")');
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM rate_limits');
}
t('مسح الحساب بيمسح بياناته', !db_one('SELECT id FROM ce_designs WHERE user_id = ?', [$uid]));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
