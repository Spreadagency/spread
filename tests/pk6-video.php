<?php
/**
 * Spread AI — الباقات (المرحلة 6): الفيديو بالفلوس — الأنواع والأسعار · الأمثلة · خصم الباقة · الطلب والدفع
 * للتطوير المحلي بس — بيعمل عملاء وأنواع تجريبية ويمسحهم في الآخر، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/pk6-video.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/content-formats.php';
if (!vp_ready()) exit("شغّل ترحيل 2026_video_pricing الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null, array $headers = [], bool $multipart = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart || !is_array($post) ? $post : http_build_query($post)); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, $body, $loc];
}
function login(string $base, string $email): array
{
    $jar = tempnam(sys_get_temp_dir(), 'pk6');
    [, $html] = http('GET', "$base/public/login.php", [], $jar);
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    http('POST', "$base/public/login.php", ['csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'Test12345!'], $jar);
    [, $page] = http('GET', "$base/public/dashboard.php", [], $jar);
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $c);
    return [$jar, $c[1] ?? ''];
}
function capi(string $base, array $s, string $action, array $data = []): array
{
    [$code, $body] = http('POST', "$base/public/api/contents.php", base64_encode(json_encode(['action' => $action] + $data)), $s[0],
        ['X-CSRF-Token: ' . $s[1], 'X-Payload: b64', 'Content-Type: text/plain;charset=UTF-8', 'X-Requested-With: XMLHttpRequest']);
    return [$code, json_decode($body, true) ?: []];
}
function cget(string $base, array $s, int $id): array
{
    [, $body] = http('GET', "$base/public/api/contents.php?action=get&id=$id", [], $s[0], ['X-Requested-With: XMLHttpRequest']);
    return json_decode($body, true) ?: [];
}
function pay(string $base, array $s, array $fields, string $proof): array
{
    [$code, $body] = http('POST', "$base/public/api/billing.php", $fields + ['action' => 'submit', 'proof' => new CURLFile($proof, 'image/png', 'r.png')], $s[0],
        ['X-CSRF-Token: ' . $s[1], 'X-Requested-With: XMLHttpRequest'], true);
    return [$code, json_decode($body, true) ?: []];
}

$saved = [];
foreach (['video_orders_enabled', 'video_whatsapp'] as $k) $saved[$k] = get_setting($k, null);
set_setting('video_orders_enabled', '1');
set_setting('video_whatsapp', '201000000000');
$typesBak = db_all('SELECT id, is_active FROM video_types');
db_run('UPDATE video_types SET is_active = 0');   // أنواع محلية قديمة ماتدخلش في الاختبار
$uids = []; $pkgs = []; $typeIds = []; $reqs = [];
$proof = sys_get_temp_dir() . '/pk6-proof.png';
$im = imagecreatetruecolor(4, 4); imagepng($im, $proof); imagedestroy($im);
$adminId = (int) (db_one('SELECT id FROM admin_users WHERE email = "owner@app.test"')['id'] ?? 1);

$mkUser = function (float $disc) use (&$uids, &$pkgs) {
    $id = (int) db_insert('INSERT INTO users (name, email, password, phone, status, email_verified_at, created_at) VALUES (?,?,?,?,"active",NOW(),NOW())',
        ['PK6 ' . $disc, 'pk6-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT), '01012345678']);
    db_run('INSERT IGNORE INTO credit_wallets (user_id, balance) VALUES (?, 0)', [$id]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name, description) VALUES (?,?,?)', [$id, 'براند الفيديو', 'براند تجريبي']);
    $p = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, validity_days, is_active, video_discount_pct) VALUES (?, 50, 0, 30, 0, ?)', ['PK6 ' . $disc, $disc]);
    $pkgs[] = $p;
    plan_start($id, $p, 50, 30, 'paid', null, 'PK6');
    credits_add($id, 50, 'PK6 test');
    $uids[] = $id;
    return $id;
};
$mkVideo = function (int $uid, string $status = 'script') {
    $bp = db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$uid]);
    $b = ['type' => 'ريلز', 'duration' => '30 ثانية', 'platform' => 'إنستجرام', 'ratio' => '9:16', 'notes' => '',
          'script' => ['hook' => 'هوك', 'body' => 'مشهد 1 · مشهد 2', 'cta' => 'احجز', 'shoot' => 'في العيادة']];
    return (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, format, video_status, video_brief_json) VALUES (?,?, "marketing", "instagram", "كابشن", "generated", "video", ?, ?)',
        [$uid, (int) $bp['id'], $status, json_encode($b, JSON_UNESCAPED_UNICODE)]);
};

try {
/* ─── 1) من غير أنواع ← واتساب زي الأول ─── */
echo "من غير أسعار\n";
$u1 = $mkUser(20);
$s1 = login($base, db_one('SELECT email FROM users WHERE id = ?', [$u1])['email']);
$c0 = $mkVideo($u1, 'approved');
t('مفيش أنواع ← طلب الفيديو بالدفع مقفول', !vp_on());
$g0 = cget($base, $s1, $c0);
t('المكتبة: pay.on = false', ($g0['content']['video']['pay']['on'] ?? null) === false, json_encode($g0['content']['video']['pay'] ?? null));
[$k0, $r0] = capi($base, $s1, 'video_request', ['id' => $c0]);
t('«تواصل معنا» على واتساب شغال زي ما هو', $k0 === 200 && str_starts_with((string) ($r0['wa'] ?? ''), 'https://wa.me/') && ($r0['video']['status']['key'] ?? '') === 'sent', json_encode([$k0, $r0['error'] ?? null]));

/* ─── 2) الأدمن: الأنواع والأسعار والأمثلة ─── */
echo "الأدمن\n";
$ja = tempnam(sys_get_temp_dir(), 'pk6a');
[, $lp] = http('GET', "$base/admin/login.php", [], $ja);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $m1);
http('POST', "$base/admin/login.php", ['csrf' => $m1[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $ja);
[$ca, $ha] = http('GET', "$base/admin/video-types.php?id=new", [], $ja);
t('صفحة «أنواع الفيديو وأسعارها»', $ca === 200 && str_contains($ha, 'name="d_price[]"') && str_contains($ha, 'name="s_url[]"') && !str_contains($ha, 'Warning:'), (string) $ca);
preg_match('/name="csrf" value="([^"]+)"/', $ha, $m2);
http('POST', "$base/admin/video-types.php", http_build_query(['csrf' => $m2[1] ?? '', 'action' => 'save', 'id' => 0, 'name' => 'PK6 ريلز تصوير', 'description' => 'تصوير + مونتاج',
    'd_label' => ['30 ثانية', '60 ثانية', '', 'من غير سعر'], 'd_seconds' => [30, 60, '', 10], 'd_price' => ['1000', '1750.5', '', '0'], 'd_days' => [5, 7, '', 1],
    's_title' => ['مثال عيادة', 'لينك وحش', ''], 's_url' => ['https://youtu.be/abc', 'javascript:alert(1)', ''],
    'sort_order' => 1, 'is_active' => 1]), $ja, ['Content-Type: application/x-www-form-urlencoded']);
$vt = db_one('SELECT * FROM video_types WHERE name = "PK6 ريلز تصوير"');
$typeIds[] = (int) ($vt['id'] ?? 0);
$vtd = $vt ? vp_decode($vt) : null;
t('الحفظ: مدتين بس (الفاضي واللي من غير سعر اتشالوا)', $vtd && count($vtd['durations']) === 2 && $vtd['durations'][1]['price_egp'] === 1750.5 && $vtd['durations'][0]['days'] === 5, json_encode($vtd['durations'] ?? null, JSON_UNESCAPED_UNICODE));
t('الأمثلة: http/https بس (javascript: اتشال)', $vtd && count($vtd['samples']) === 1 && $vtd['samples'][0]['url'] === 'https://youtu.be/abc');
$t2 = (int) db_insert('INSERT INTO video_types (name, durations_json, is_active) VALUES ("PK6 متقفل", ?, 0)', [json_encode([['label' => '15', 'price_egp' => 100]])]);
$typeIds[] = $t2;
vp_types(false, true);
[$cp, $hp] = http('GET', "$base/admin/packages.php?edit=" . $pkgs[0], [], $ja);
t('«الباقات»: خانة خصم الفيديو', $cp === 200 && str_contains($hp, 'name="video_discount_pct"') && str_contains($hp, 'value="20"'));

/* ─── 3) السعر بعد خصم الباقة ─── */
echo "السعر\n";
t('خصم الفيديو لقطة من الباقة وقت الاشتراك', vp_discount_pct($u1) === 20.0);
db_run('UPDATE credit_packages SET video_discount_pct = 50 WHERE id = ?', [$pkgs[0]]);
plan_active($u1, true);
t('تعديل الباقة بعدين مابيغيّرش خصم المشترك الحالي', vp_discount_pct($u1) === 20.0);
$u2 = $mkUser(0);
$q1 = vp_quote($u1, vp_type($typeIds[0]), 1);
$q2 = vp_quote($u2, vp_type($typeIds[0]), 1);
t('1750.5 − 20% = 1400.40 · من غير خصم = 1750.5', $q1['final'] === 1400.4 && $q1['discount'] === 350.1 && $q2['final'] === 1750.5, json_encode([$q1, $q2]));
t('النوع المتقفل مايظهرش للعميل', vp_type($t2) === null && vp_on());

/* ─── 4) العميل: الاختيار والطلب ─── */
echo "الطلب\n";
$c1 = $mkVideo($u1, 'script');
$g1 = cget($base, $s1, $c1);
$pay = $g1['content']['video']['pay'] ?? [];
t('المكتبة: الأنواع بأسعار العميل + الأمثلة', ($pay['on'] ?? false) && count($pay['types']) === 1 && $pay['types'][0]['durations'][1]['final'] === 1400.4 && ($pay['discount_pct'] ?? 0) == 20 && count($pay['types'][0]['samples']) === 1, json_encode($pay, JSON_UNESCAPED_UNICODE));
[$k1, $r1] = capi($base, $s1, 'video_order', ['id' => $c1, 'type_id' => $typeIds[0], 'dur' => 0]);
t('قبل اعتماد السكريبت ← مينفعش يطلب', $k1 === 409 && ($r1['code'] ?? '') === 'state');
capi($base, $s1, 'video_approve', ['id' => $c1]);
[$k2, $r2] = capi($base, $s1, 'video_request', ['id' => $c1]);
t('بعد الاعتماد: واتساب مقفول لحد ما يدفع', $k2 === 409 && ($r2['code'] ?? '') === 'needs_order' && (db_one('SELECT video_status FROM contents WHERE id = ?', [$c1])['video_status']) === 'approved');
[$k3, $r3] = capi($base, $s1, 'video_order', ['id' => $c1, 'type_id' => $t2, 'dur' => 0]);
t('نوع متقفل ← مرفوض', $k3 === 422);
[$k4, $r4] = capi($base, $s1, 'video_order', ['id' => $c1, 'type_id' => $typeIds[0], 'dur' => 1]);
$o1 = vp_order((int) ($r4['order_id'] ?? 0));
t('«اطلب وادفع» ← طلب مستني الدفع بالسعر بعد الخصم', $k4 === 200 && $o1 && $o1['status'] === 'pending_payment' && (float) $o1['final_egp'] === 1400.4 && (float) $o1['discount_pct'] === 20.0
    && str_contains((string) $r4['checkout'], 'checkout.php?video=' . $o1['id']), json_encode([$k4, $r4['error'] ?? null]));
[$k5, $r5] = capi($base, $s1, 'video_order', ['id' => $c1, 'type_id' => $typeIds[0], 'dur' => 0]);
t('غيّر رأيه قبل الدفع ← نفس الطلب اتحدّث (مش طلب جديد)', $k5 === 200 && (int) $r5['order_id'] === (int) $o1['id'] && (float) vp_order((int) $o1['id'])['final_egp'] === 800.0);
$s2 = login($base, db_one('SELECT email FROM users WHERE id = ?', [$u2])['email']);
[$ck, $hk, $lk] = http('GET', "$base/public/checkout.php?video=" . $o1['id'], [], $s2[0]);
t('عميل تاني مايقدرش يفتح دفع طلب مش بتاعه', $ck === 302 && !str_contains($hk, '800'));
[$cc, $hc] = http('GET', "$base/public/checkout.php?video=" . $o1['id'], [], $s1[0]);
t('صفحة الدفع: الفيديو بسعره والخصم ومن غير كود خصم', $cc === 200 && str_contains($hc, 'فيديو: PK6 ريلز تصوير — 30 ثانية') && str_contains($hc, '800.00') && str_contains($hc, 'خصم باقتك 20%') && str_contains($hc, 'video: ' . $o1['id']) && !str_contains($hc, 'Warning:'));

/* ─── 5) الدفع والاعتماد ─── */
echo "الدفع\n";
$bal0 = credits_balance($u1);
$plan0 = plan_active($u1, true)['id'];
[$p1, $rp1] = pay($base, $s1, ['video_order_id' => $o1['id'], 'method' => 'instapay', 'promo_code' => 'SPREAD50', 'phone' => '01012345678'], $proof);
t('كود خصم على الفيديو ← مرفوض', $p1 === 422 && str_contains((string) ($rp1['error'] ?? ''), 'الباقات'));
[$p2, $rp2] = pay($base, $s1, ['video_order_id' => $o1['id'], 'method' => 'instapay', 'phone' => '01012345678'], $proof);
$pr = $rp2['id'] ?? 0 ? billing_request((int) $rp2['id']) : null;
if ($pr) $reqs[] = (int) $pr['id'];
t('طلب الدفع: 800 جنيه · من غير كريدت · مربوط بالطلب', $p2 === 200 && $pr && (float) $pr['final_amount'] === 800.0 && (int) $pr['credits'] === 0 && (int) $pr['video_order_id'] === (int) $o1['id']
    && (int) vp_order((int) $o1['id'])['payment_request_id'] === (int) $pr['id'], json_encode([$p2, $rp2]));
[$p3] = pay($base, $s1, ['video_order_id' => $o1['id'], 'method' => 'instapay', 'phone' => '01012345678'], $proof);
t('مايتدفعش مرتين وهو بيتراجع', $p3 === 422);
[$k6, $r6] = capi($base, $s1, 'video_order', ['id' => $c1, 'type_id' => $typeIds[0], 'dur' => 1]);
t('تغيير النوع والدفع بيتراجع ← مرفوض ولينك المتابعة', $k6 === 409 && ($r6['code'] ?? '') === 'pending' && str_contains((string) ($r6['payment_url'] ?? ''), 'payments.php?id=' . $pr['id']));
$g2 = cget($base, $s1, $c1);
t('المكتبة: «طلب الدفع بيتراجع»', (int) ($g2['content']['video']['pay']['order']['payment_id'] ?? 0) === (int) $pr['id']);
$ap = billing_approve((int) $pr['id'], $adminId, 'PK6');
$o1b = vp_order((int) $o1['id']);
t('الاعتماد ← الطلب مدفوع والفيديو اتبعت لفريق التنفيذ', $ap['ok'] && $o1b['status'] === 'paid' && $o1b['paid_at'] !== null && db_one('SELECT video_status FROM contents WHERE id = ?', [$c1])['video_status'] === 'sent', json_encode($ap));
t('الفيديو مالمسش الكريدت ولا الباقة', credits_balance($u1) === $bal0 && plan_active($u1, true)['id'] === $plan0);
t('إشعار للعميل', (bool) db_one('SELECT id FROM user_notifications WHERE user_id = ? AND title LIKE "%دفع الفيديو%"', [$u1]));
t('الاعتماد مرة واحدة بس', !billing_approve((int) $pr['id'], $adminId)['ok']);
[$k7, $r7] = capi($base, $s1, 'video_order', ['id' => $c1, 'type_id' => $typeIds[0], 'dur' => 0]);
t('بعد الدفع ← مفيش طلب تاني لنفس الفيديو', $k7 === 409);
[$k8, $r8] = capi($base, $s1, 'video_request', ['id' => $c1]);
t('واتساب للمتابعة بعد الدفع شغال وفيه الطلب المدفوع', $k8 === 200 && str_contains(rawurldecode((string) $r8['wa']), 'الطلب المدفوع: PK6 ريلز تصوير — 30 ثانية'));
[$cv, $hv] = http('GET', "$base/admin/video-requests.php?f=sent", [], $ja);
t('«طلبات الفيديو» في الأدمن: مدفوع + النوع والمبلغ ولينك الدفع', $cv === 200 && str_contains($hv, '💳 مدفوع') && str_contains($hv, 'PK6 ريلز تصوير — 30 ثانية') && str_contains($hv, 'payment-view.php?id=' . $pr['id']));

/* ─── 6) الرفض ← يدفع تاني ─── */
echo "الرفض\n";
$c2 = $mkVideo($u2, 'approved');
[, $r9] = capi($base, $s2, 'video_order', ['id' => $c2, 'type_id' => $typeIds[0], 'dur' => 0]);
[, $rp4] = pay($base, $s2, ['video_order_id' => $r9['order_id'] ?? 0, 'method' => 'instapay', 'phone' => '01012345678'], $proof);
if (!empty($rp4['id'])) $reqs[] = (int) $rp4['id'];
billing_reject((int) ($rp4['id'] ?? 0), $adminId, 'التحويل مش واضح');
$o2 = vp_order((int) ($r9['order_id'] ?? 0));
t('الرفض ← الطلب لسه مستني دفع والفيديو مااتبعتش', $o2 && $o2['status'] === 'pending_payment' && db_one('SELECT video_status FROM contents WHERE id = ?', [$c2])['video_status'] === 'approved' && (float) $o2['final_egp'] === 1000.0);
[$p5, $rp5] = pay($base, $s2, ['video_order_id' => $o2['id'], 'method' => 'instapay', 'phone' => '01012345678'], $proof);
if (!empty($rp5['id'])) $reqs[] = (int) $rp5['id'];
t('يقدر يدفع تاني بعد الرفض', $p5 === 200 && !empty($rp5['id']));

/* ─── 7) الإعداد مقفول ← واتساب ─── */
set_setting('video_orders_enabled', '0');
$c3 = $mkVideo($u2, 'approved');
$g3 = cget($base, $s2, $c3);
[$k10] = capi($base, $s2, 'video_request', ['id' => $c3]);
t('الإعداد مقفول ← واتساب زي الأول', ($g3['content']['video']['pay']['on'] ?? null) === false && $k10 === 200);
set_setting('video_orders_enabled', '1');
[$cpk, $hpk] = http('GET', "$base/public/my-plan.php?tab=plans", [], $s1[0]);
t('«باقتي» شغالة', $cpk === 200 && !str_contains($hpk, 'Warning:') && !str_contains($hpk, 'Fatal'));

} finally {
foreach ($reqs as $rid) { db_run('DELETE FROM payment_request_events WHERE request_id = ?', [$rid]); }
foreach ($uids as $id) {
    db_run('DELETE FROM video_orders WHERE user_id = ?', [$id]);
    foreach (db_all('SELECT proof_path FROM payment_requests WHERE user_id = ? AND proof_path IS NOT NULL', [$id]) as $pp) @unlink(dirname(UPLOADS_PATH) . '/' . $pp['proof_path']);
    db_run('DELETE FROM user_notifications WHERE user_id = ?', [$id]);
    db_run('DELETE FROM payment_requests WHERE user_id = ?', [$id]);
    db_run('DELETE FROM user_plans WHERE user_id = ?', [$id]);
    account_purge($id);
}
foreach ($pkgs as $p) db_run('DELETE FROM credit_packages WHERE id = ?', [$p]);
foreach ($typeIds as $tid) db_run('DELETE FROM video_types WHERE id = ?', [$tid]);
foreach ($typesBak as $r) db_run('UPDATE video_types SET is_active = ? WHERE id = ?', [(int) $r['is_active'], (int) $r['id']]);
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM admin_alerts WHERE (dedupe_key LIKE "vo:%" OR dedupe_key LIKE "pr:%") AND body LIKE "%PK6%"');
@unlink($proof);
}
t('مسح الحسابات التجريبية والأنواع', !db_one('SELECT id FROM users WHERE email LIKE "pk6-%@test.local"') && !db_one('SELECT id FROM video_types WHERE name LIKE "PK6%"') && !db_one('SELECT id FROM video_orders WHERE user_id IN (' . implode(',', $uids ?: [0]) . ')'));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
