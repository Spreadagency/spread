<?php
/**
 * Spread AI — الباقات (المرحلة 8): تقرير الربحية لكل باقة
 * بيانات ثابتة في مارس 2025 (بعيد عن أي بيانات تانية) ← أرقام محسوبة باليد ← بيتمسحوا في الآخر.
 *
 *   php tests/pk8-profit.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/account.php';
require_once __DIR__ . '/../includes/profitability.php';
if (!usage_ready()) exit("شغّل ترحيل 8-أ (تكاليف الـ AI) الأول\n");

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, array|string $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post); }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$code, $body, $type];
}
$near = fn($a, $b) => abs((float) $a - (float) $b) < 0.01;
$F = '2025-03-01 00:00:00';
$T = '2025-04-01 00:00:00';
$saved = get_setting('profit_alert_margin', null);
set_setting('profit_alert_margin', '30');
$uids = []; $pkgs = []; $orders = []; $videos = [];
$mkU = function (string $n) use (&$uids) {
    $id = (int) db_insert('INSERT INTO users (name, email, password, status, created_at) VALUES (?,?,?,"active",NOW())', ['PK8 ' . $n, 'pk8-' . $n . '-' . bin2hex(random_bytes(2)) . '@test.local', 'x']);
    $uids[] = $id;
    return $id;
};
$plan = function (int $uid, int $pkg, string $from, string $to, string $source = 'paid', ?string $note = null) {
    return (int) db_insert('INSERT INTO user_plans (user_id, package_id, name, source, starts_at, ends_at, credits_allowance, status, note) VALUES (?,?,?,?,?,?,100,"replaced",?)',
        [$uid, $pkg, 'PK8', $source, $from, $to, $note]);
};
$pay = function (int $uid, ?int $pkg, float $amount, string $at, ?int $planId = null, ?int $addon = null) {
    return (int) db_insert('INSERT INTO payment_requests (user_id, package_id, plan_name, credits, validity_days, original_amount, final_amount, method, method_label, contact_name, contact_email, contact_phone, status, reviewed_at, user_plan_id, addon_id)
                            VALUES (?,?,"PK8",0,30,?,?,"instapay","x","x","x@x","010","approved",?,?,?)', [$uid, $pkg, $amount, $amount, $at, $planId, $addon]);
};
$run = function (?int $uid, string $task, float $egp, string $at, int $sandbox = 0) {
    db_insert('INSERT INTO ai_runs (run_key, user_id, task, status, cost_egp, cost_usd, credits_charged, created_at, sandbox) VALUES (?,?,?,"ok",?,?,2,?,?)',
        ['pk8-' . bin2hex(random_bytes(8)), $uid, $task, $egp, $egp / 50, $at, $sandbox]);
};

try {
/* ─── البيانات ─── */
$A = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, validity_days, is_active, quality_tier) VALUES ("PK8 ربع سنوي", 300, 900, 90, 0, "standard")');
$B = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, validity_days, is_active, quality_tier) VALUES ("PK8 شهري", 100, 300, 30, 0, "pro")');
$pkgs = [$A, $B];
$u1 = $mkU('a'); $u2 = $mkU('b'); $u3 = $mkU('c'); $u4 = $mkU('d');
// u1: 3 شهور (900) بدأت 15 فبراير ← مارس = 31 من 90 يوم = 310 + إضافة 100 في مارس
$p1 = $plan($u1, $A, '2025-02-15 00:00:00', '2025-05-16 00:00:00');
$pay($u1, $A, 900, '2025-02-15 00:00:00', $p1);
$pay($u1, null, 100, '2025-03-10 10:00:00', null, 1);
$run($u1, 'ce_writer', 50, '2025-03-05 10:00:00');
$run($u1, 'ce_image', 20, '2025-03-20 10:00:00');
db_insert('INSERT INTO usage_events (user_id, unit, ref_id, created_at) VALUES (?, "designs", 1, "2025-03-06 10:00:00"), (?, "designs", 2, "2025-03-07 10:00:00")', [$u1, $u1]);
// u2: شهري بـ Paymob (300) — تكلفة 400 ← خسران
$o = (int) db_insert('INSERT INTO payment_orders (user_id, package_id, credits, amount_egp, status, created_at, paid_at) VALUES (?,?,100,300,"paid","2025-03-01 00:00:00","2025-03-01 00:00:00")', [$u2, $B]);
$orders[] = $o;
$plan($u2, $B, '2025-03-01 00:00:00', '2025-03-31 00:00:00', 'paid', 'Paymob #' . $o);
$run($u2, 'ce_writer', 400, '2025-03-12 10:00:00');
// u3: شهري A (300) اتبدّل يوم 16 بـ B (300 · لحد 15 أبريل) ← A كله 300 · B نصيب 16 من 30 = 160
$p3a = $plan($u3, $A, '2025-03-01 00:00:00', '2025-03-31 00:00:00');
$pay($u3, $A, 300, '2025-03-01 00:00:00', $p3a);
$p3b = $plan($u3, $B, '2025-03-16 00:00:00', '2025-04-15 00:00:00');
$pay($u3, $B, 300, '2025-03-16 00:00:00', $p3b);
$run($u3, 'ce_writer', 5, '2025-03-05 10:00:00');
$run($u3, 'ce_writer', 10, '2025-03-20 10:00:00');
// u4: من غير باقة · النظام · تجربة أدمن (sandbox) · برّه الفترة
$run($u4, 'content', 8, '2025-03-08 10:00:00');
$run(null, 'ai_health', 3, '2025-03-09 10:00:00');
$run($u1, 'ce_writer', 999, '2025-03-09 10:00:00', 1);
$run($u1, 'ce_writer', 777, '2025-04-02 10:00:00');
// فيديو مدفوع في مارس
if (vp_ready()) $videos[] = (int) db_insert('INSERT INTO video_orders (user_id, content_id, type_name, duration_label, base_egp, final_egp, status, paid_at) VALUES (?, 0, "PK8", "30", 1200, 1200, "paid", "2025-03-15 10:00:00")', [$u1]);

$rep = pf_report($F, $T);
$by = [];
foreach ($rep['rows'] as $r) $by[$r['id']] = $r;
echo "الإيراد\n";
t('الربع سنوي: نصيب مارس (310) + الدورة اللي اتبدّلت كاملة (300) + الإضافة (100)', $near($by[$A]['accrued'], 610) && $near($by[$A]['addons'], 100) && $near($by[$A]['revenue'], 710), json_encode([$by[$A]['accrued'], $by[$A]['addons']]));
t('الشهري: Paymob (300) + نصيب الدورة الجديدة (16 من 30 = 160)', $near($by[$B]['accrued'], 460), (string) $by[$B]['accrued']);
t('المدفوع فعلًا في الفترة (كاش) منفصل', $near($by[$A]['cash'], 300) && $near($by[$B]['cash'], 600), json_encode([$by[$A]['cash'], $by[$B]['cash']]));
t('المشتركين والجداد', $by[$A]['subs'] === 2 && $by[$B]['subs'] === 2 && $by[$A]['new'] === 1 && $by[$B]['new'] === 2, json_encode([$by[$A]['subs'], $by[$B]['subs'], $by[$A]['new'], $by[$B]['new']]));
if (vp_ready()) t('الفيديو صف لوحده (1200)', isset($by[PF_VIDEO]) && $near($by[PF_VIDEO]['revenue'], 1200) && $by[PF_VIDEO]['new'] === 1);

echo "التكلفة\n";
t('تكلفة كل باقة على الباقة اللي كانت شغالة يوم العملية', $near($by[$A]['cost'], 75) && $near($by[$B]['cost'], 410), json_encode([$by[$A]['cost'], $by[$B]['cost']]));
t('من غير باقة ← «بدون باقة مدفوعة»', isset($by[PF_NONE]) && $near($by[PF_NONE]['cost'], 8) && $by[PF_NONE]['level'] === 'cost');
t('عمليات النظام لوحدها · تجارب الأدمن وبرّه الفترة مش محسوبة', $near($rep['system']['cost'], 3) && $rep['system']['runs'] === 1);
t('أعلى المهام تكلفة لكل باقة', array_key_first($by[$A]['tasks']) === 'ce_writer' && $near($by[$A]['tasks']['ce_writer'], 55));
t('التصميمات من الحصة', $by[$A]['designs'] === 2);

echo "الربح\n";
t('الربع سنوي: ربح 635 · هامش 89.4% · كويس', $near($by[$A]['profit'], 635) && $by[$A]['margin'] == 89.4 && $by[$A]['level'] === 'ok', json_encode([$by[$A]['profit'], $by[$A]['margin']]));
t('الشهري: ربح 50 · هامش 10.9% ← «هامش قليل» (أقل من 30%)', $near($by[$B]['profit'], 50) && $by[$B]['margin'] == 10.9 && $by[$B]['level'] === 'low', json_encode([$by[$B]['profit'], $by[$B]['margin']]));
$tot = $rep['totals'];
$vid = vp_ready() ? 1200 : 0;
t('الإجمالي: الإيراد − التكلفة − النظام', $near($tot['revenue'], 710 + 460 + $vid) && $near($tot['profit'], 710 + 460 + $vid - 493 - 3), json_encode([$tot['revenue'], $tot['profit']]));
$lu = array_column($rep['users'], 'profit', 'user_id');
t('العملاء اللي تكلفتهم أكتر من اللي دفعوه (الأسوأ الأول)', ($rep['users'][0]['user_id'] ?? 0) === $u2 && $near($lu[$u2], -100) && $near($lu[$u4], -8) && !isset($lu[$u1]) && !isset($lu[$u3]), json_encode($lu));
t('حسب مستوى الجودة', $near($rep['tiers']['standard']['revenue'] ?? 0, 710) && $near($rep['tiers']['pro']['cost'] ?? 0, 410));
set_setting('profit_alert_margin', '5');
t('حد التنبيه من الإعدادات (5% ← الشهري يبقى كويس)', pf_report($F, $T)['rows'][array_search($B, array_column(pf_report($F, $T)['rows'], 'id'))]['level'] === 'ok');
set_setting('profit_alert_margin', '30');

echo "الأدمن\n";
$ja = tempnam(sys_get_temp_dir(), 'pk8');
[, $lp] = http('GET', "$base/admin/login.php", [], $ja);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $m1);
http('POST', "$base/admin/login.php", ['csrf' => $m1[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $ja);
[$c1, $h1] = http('GET', "$base/admin/profitability.php?p=custom&from=2025-03-01&to=2025-03-31", [], $ja);
t('صفحة «ربحية الباقات»: الباقات والأرقام', $c1 === 200 && str_contains($h1, 'PK8 ربع سنوي') && str_contains($h1, '635.00') && str_contains($h1, 'هامش قليل') && str_contains($h1, 'PK8 b') && !str_contains($h1, 'Warning:'), (string) $c1);
[$c2, $h2, $ty] = http('GET', "$base/admin/profitability.php?p=custom&from=2025-03-01&to=2025-03-31&export=csv", [], $ja);
t('CSV', $c2 === 200 && str_contains($ty, 'text/csv') && str_contains($h2, 'PK8 شهري') && str_contains($h2, 'الإجمالي'));
[$c3, $h3] = http('GET', "$base/admin/profitability.php", [], $ja);
t('الفترة الافتراضية (الشهر ده) شغالة', $c3 === 200 && !str_contains($h3, 'Fatal'));
preg_match('/name="csrf" value="([^"]+)"/', $h1, $m2);
http('POST', "$base/admin/profitability.php?p=month", ['csrf' => $m2[1] ?? '', 'action' => 'alert', 'profit_alert_margin' => 25], $ja);
t('حفظ حد التنبيه', get_setting('profit_alert_margin') === '25');

} finally {
db_run('DELETE FROM ai_runs WHERE run_key LIKE "pk8-%"');
foreach ($uids as $id) {
    foreach (['payment_requests', 'user_plans', 'usage_events', 'payment_orders'] as $tb) { try { db_run("DELETE FROM {$tb} WHERE user_id = ?", [$id]); } catch (\Throwable $e) {} }
    if (vp_ready()) db_run('DELETE FROM video_orders WHERE user_id = ?', [$id]);
    account_purge($id);
}
foreach ($pkgs as $p) db_run('DELETE FROM credit_packages WHERE id = ?', [$p]);
if ($saved === null) db_run('DELETE FROM settings WHERE setting_key = "profit_alert_margin"'); else set_setting('profit_alert_margin', (string) $saved);
}
t('مسح البيانات التجريبية', !db_one('SELECT id FROM users WHERE email LIKE "pk8-%@test.local"') && !db_one('SELECT id FROM ai_runs WHERE run_key LIKE "pk8-%"'));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
