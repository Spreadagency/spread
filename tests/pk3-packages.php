<?php
/**
 * Spread AI — الباقات (المرحلة 3): المدد · الحد الأسبوعي · الجودة · البحث · الإضافات · باقة التسجيل
 * للتطوير المحلي بس — بيعمل باقة وعملاء تجريبيين ويمسحهم في الآخر، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/pk3-packages.php http://localhost:8080
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
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/auth.php';
if (!pkg_v2_ready()) exit("شغّل ترحيل 2026_packages_v2 الأول\n");

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
$saved = [];
foreach (['signup_package_id', 'signup_package_price_id', 'quotas_enforced'] as $k) $saved[$k] = get_setting($k, null);
set_setting('quotas_enforced', '1');
db_run('DELETE FROM rate_limits');
$uids = [];
$mkUser = function (string $tag) use (&$uids) {
    $id = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at, phone) VALUES (?,?,?,"active",NOW(),NOW(),"01000000000")',
        ['PK3 ' . $tag, 'pk3-' . $tag . '-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_run('INSERT IGNORE INTO credit_wallets (user_id, balance) VALUES (?, 0)', [$id]);
    $uids[] = $id;
    return $id;
};
$approve = function (int $uid, array $in) {
    $u = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
    $r = billing_create_request($u, $in + ['phone' => '01000000000'], null);
    if (!$r['ok']) return $r;
    return billing_approve((int) $r['id'], 1, 'PK3') + ['id' => $r['id']];
};
$pkg = 0;
$addD = $addC = 0;

try {
/* ─── 1) المدد ─── */
echo "المدد والأسعار\n";
$pkg = (int) db_insert('INSERT INTO credit_packages (name, credits, price_egp, validity_days, is_active, quotas_json, weekly_credits, weekly_designs, quality_tier, allow_research)
                         VALUES ("PK3 باقة", 100, 0, 30, 1, ?, 10, 2, "pro", 0)', [json_encode(['designs' => 20, 'research' => 0])]);
$m = (int) db_insert('INSERT INTO package_prices (package_id, label, days, price_egp, credits, designs, research, sort_order) VALUES (?, "شهري", 30, 0, 100, 20, 0, 0)', [$pkg]);
$y = (int) db_insert('INSERT INTO package_prices (package_id, label, days, price_egp, compare_egp, credits, designs, research, sort_order) VALUES (?, "سنة", 365, 0, 1200, 1200, 240, 0, 1)', [$pkg]);
$basePkg = db_one("SELECT * FROM credit_packages WHERE id = ?", [$pkg]);
$v = pkg_variant($basePkg, $y);
t('المدة بسعرها وكريدتها وأيامها وتصميماتها', $v && (int) $v['validity_days'] === 365 && (int) $v['credits'] === 1200 && (int) plan_quotas_decode($v['quotas_json'])['designs'] === 240 && $v['duration_label'] === 'سنة' && (float) $v['compare_egp'] === 1200.0);
t('من غير مدة ← أول مدة (شهري)', (int) pkg_variant($basePkg)['price_id'] === $m);
t('مدة مش بتاعة الباقة ← مرفوضة', pkg_variant($basePkg, 999999) === null);
t('نص المدة', pkg_duration_text(90) === '3 شهور' && pkg_duration_text(365) === 'سنة' && pkg_duration_text(45) === '45 يوم');

/* ─── 2) الاشتراك السنوي ─── */
echo "الاشتراك بمدة\n";
$u1 = $mkUser('a');
$r = $approve($u1, ['package_id' => $pkg, 'price_id' => $y]);
$pl = db_one('SELECT * FROM user_plans WHERE user_id = ? AND status = "active"', [$u1]);
$req = db_one('SELECT * FROM payment_requests WHERE id = ?', [(int) ($r['id'] ?? 0)]);
t('طلب الدفع فيه المدة', $req && (int) $req['price_id'] === $y && str_contains((string) $req['plan_name'], 'سنة') && (int) $req['validity_days'] === 365, json_encode($r, JSON_UNESCAPED_UNICODE));
t('الباقة اتفعّلت سنة: الكريدت نزل مرة واحدة + حصة التصميمات بتاعة السنة', $r['ok'] && $pl && (int) round((strtotime($pl['ends_at']) - strtotime($pl['starts_at'])) / 86400) === 365
  && credits_balance($u1) === 1200 && (int) plan_quotas_decode($pl['quotas_json'])['designs'] === 240);
t('لقطة الباقة: الحد الأسبوعي · الجودة · البحث · المدة', (int) $pl['weekly_credits'] === 10 && (int) $pl['weekly_designs'] === 2 && $pl['quality_tier'] === 'pro' && (int) $pl['allow_research'] === 0 && (int) $pl['price_id'] === $y);
t('مستوى الجودة للعميل', pkg_quality_tier($u1) === 'pro');

/* ─── 3) الحد الأسبوعي ─── */
echo "الحد الأسبوعي\n";
t('في الحد ← الخصم ماشي', credits_consume($u1, 8, 'PK3', 'test', null));
t('أكتر من حد الأسبوع ← مرفوض برسالة الأسبوع (مش الرصيد)', !credits_consume($u1, 5, 'PK3', 'test', null) && str_contains(credits_short_msg(5), 'حد الأسبوع') && credits_balance($u1) === 1192);
db_run('UPDATE user_plans SET starts_at = DATE_SUB(starts_at, INTERVAL 8 DAY) WHERE user_id = ? AND status = "active"', [$u1]);
plan_active($u1, true);
$st = pkg_weekly_credits($u1);
t('الأسبوع التاني ← الحد بقى 20 والفاضل من الأول بيفضل', $st['week'] === 2 && $st['allow'] === 20 && $st['left'] === 12 && credits_consume($u1, 12, 'PK3', 'test', null), json_encode($st));
t('حد التصميمات الأسبوعي (تراكمي) — 2 × أسبوعين = 4', plan_gate($u1, 'designs', 4) === null && str_contains((string) plan_gate($u1, 'designs', 5), 'الأسبوع'));

/* ─── 4) البحث ─── */
echo "البحث\n";
t('الباقة من غير بحث ← مقفول برسالة', !pkg_research_allowed($u1) && str_contains((string) plan_gate($u1, 'research'), 'البحث'));
$_SESSION['user_id'] = $u1;
t('البحث بيختفي من القايمة', !menu_visible('research'));
unset($_SESSION['user_id']);

/* ─── 5) الإضافات ─── */
echo "الإضافات\n";
$addD = (int) db_insert('INSERT INTO addon_packs (kind, name, amount, price_egp, validity_days, is_active) VALUES ("designs", "PK3 تصميمات", 10, 0, 30, 1)');
$addC = (int) db_insert('INSERT INTO addon_packs (kind, name, amount, price_egp, validity_days, is_active) VALUES ("credits", "PK3 كريدت", 50, 0, 30, 1)');
$u2 = $mkUser('b');
t('من غير باقة شغالة ← الإضافة مرفوضة', str_contains((string) ($approve($u2, ['addon_id' => $addD])['error'] ?? ''), 'باقة'));
$beforeD = pkg_weekly_designs($u1)['allow'];
$ra = $approve($u1, ['addon_id' => $addD]);
t('تصميمات زيادة ← الحصة والحد الأسبوعي زادوا 10', $ra['ok'] && pkg_weekly_designs($u1)['allow'] === $beforeD + 10 && (bool) db_one('SELECT id FROM quota_overrides WHERE user_id = ? AND unit = "designs" AND extra = 10', [$u1]), json_encode($ra, JSON_UNESCAPED_UNICODE));
t('الإضافة مابتغيرش الباقة', (int) db_one('SELECT COUNT(*) n FROM user_plans WHERE user_id = ? AND status = "active"', [$u1])['n'] === 1);
$b0 = credits_balance($u1);
$beforeC = pkg_weekly_credits($u1)['allow'];
$rc = $approve($u1, ['addon_id' => $addC]);
$lot = db_one('SELECT * FROM credit_lots WHERE user_id = ? AND addon_until IS NOT NULL ORDER BY id DESC LIMIT 1', [$u1]);
t('كريدت مساعد ← الرصيد +50 وصالح 30 يوم والحد الأسبوعي زاد', $rc['ok'] && credits_balance($u1) === $b0 + 50 && $lot && abs(strtotime($lot['addon_until']) - strtotime('+30 days')) < 120 && pkg_weekly_credits($u1)['allow'] === $beforeC + 50);
t('كود خصم على إضافة ← مرفوض', str_contains((string) (billing_create_request(db_one('SELECT * FROM users WHERE id = ?', [$u1]), ['addon_id' => $addC, 'promo_code' => 'X', 'phone' => '01000000000'], null)['error'] ?? ''), 'الباقات'));

/* ─── 6) الباقة خلصت ← الإضافة بتقف وتكمل مع الجديدة ─── */
echo "وقفة الإضافة\n";
$u3 = $mkUser('c');
$approve($u3, ['package_id' => $pkg, 'price_id' => $m]);
db_run('UPDATE user_plans SET weekly_credits = 0 WHERE user_id = ?', [$u3]);
plan_active($u3, true);
$approve($u3, ['addon_id' => $addC]);
t('باقة + إضافة', credits_balance($u3) === 150);
db_run('UPDATE credit_wallets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE user_id = ?', [$u3]);
credits_check_expiry($u3);
t('الباقة خلصت ← كل الرصيد اتجمّد', credits_balance($u3) === 0 && (int) db_one('SELECT expired_balance FROM credit_wallets WHERE user_id = ?', [$u3])['expired_balance'] === 150);
db_run('UPDATE credit_wallets SET expired_at = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE user_id = ?', [$u3]);
credits_check_expiry($u3);
$w = db_one('SELECT * FROM credit_wallets WHERE user_id = ?', [$u3]);
t('بعد 3 أيام ← كريدت الباقة سقط والإضافة فضلت واقفة', (int) $w['expired_balance'] === 50 && $w['expired_at'] !== null, json_encode($w));
$until0 = db_one('SELECT addon_until FROM credit_lots WHERE user_id = ? AND addon_until IS NOT NULL', [$u3])['addon_until'];
db_run('UPDATE user_plans SET status = "ended" WHERE user_id = ?', [$u3]);
plan_active($u3, true);
$approve($u3, ['package_id' => $pkg, 'price_id' => $m]);
$until1 = db_one('SELECT addon_until FROM credit_lots WHERE user_id = ? AND addon_until IS NOT NULL', [$u3])['addon_until'];
t('جدد بعد ما الفترة عدّت ← الإضافة كمّلت (100 جديد + 50) وأيامها اتمدت بمدة الوقفة', credits_balance($u3) === 150 && strtotime($until1) - strtotime($until0) >= 5 * 86400 - 120, json_encode([credits_balance($u3), $until0, $until1]));
db_run('UPDATE credit_lots SET addon_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE user_id = ? AND addon_until IS NOT NULL', [$u3]);
credits_check_expiry($u3);
t('أيام الإضافة خلصت والباقة شغالة ← كريدت الإضافة بيسقط بس', credits_balance($u3) === 100, (string) credits_balance($u3));

/* ─── 7) باقة التسجيل ─── */
echo "باقة التسجيل\n";
set_setting('signup_package_id', (string) $pkg);
set_setting('signup_package_price_id', (string) $m);
$reg = register_user('PK3 جديد', 'pk3-new-' . bin2hex(random_bytes(3)) . '@test.local', 'Test12345!');
$nu = (int) ($reg['user_id'] ?? 0);
if ($nu) $uids[] = $nu;
$np = db_one('SELECT * FROM user_plans WHERE user_id = ? AND status = "active"', [$nu]);
t('حساب جديد ← باقة التسجيل اتفعّلت بكريدتها (مش الكريدت الترحيبي)', $reg['ok'] && $np && $np['source'] === 'signup' && credits_balance($nu) === 100 && (int) $np['price_id'] === $m, json_encode([$reg, credits_balance($nu)]));
set_setting('signup_package_id', '');
$reg2 = register_user('PK3 عادي', 'pk3-old-' . bin2hex(random_bytes(3)) . '@test.local', 'Test12345!');
if (!empty($reg2['user_id'])) $uids[] = (int) $reg2['user_id'];
t('من غير باقة تسجيل ← الكريدت الترحيبي العادي', credits_balance((int) $reg2['user_id']) === (int) STARTER_CREDITS && !db_one('SELECT id FROM user_plans WHERE user_id = ?', [(int) $reg2['user_id']]));

/* ─── 8) الصفحات ─── */
echo "الصفحات\n";
$jar = tempnam(sys_get_temp_dir(), 'pk3a');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $mm);
http('POST', "$base/admin/login.php", ['csrf' => $mm[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c1, $h1] = http('GET', "$base/admin/packages.php?edit=$pkg", [], $jar);
t('الأدمن: المدد · الحد الأسبوعي · الجودة · البحث · الإضافات · باقة التسجيل', $c1 === 200 && str_contains($h1, 'pp_days[') && str_contains($h1, 'name="weekly_credits"') && str_contains($h1, 'name="quality_tier"')
  && str_contains($h1, 'id="addons"') && str_contains($h1, 'name="signup_package_id"') && !str_contains($h1, 'Warning:'), (string) $c1);
preg_match('/name="csrf" value="([^"]+)"/', $h1, $cm);
$post = ['csrf' => $cm[1] ?? '', 'action' => 'save', 'pkg_id' => $pkg, 'name' => 'PK3 باقة', 'credits' => 100, 'price_egp' => 0, 'validity_days' => 30, 'is_active' => 1,
         'weekly_credits' => 10, 'weekly_designs' => 2, 'quality_tier' => 'premium', 'allow_research' => 1,
         'pp_id' => [0 => $m, 1 => $y, 2 => 0], 'pp_label' => ['شهري', 'سنة', '3 شهور'], 'pp_days' => [30, 365, 90], 'pp_price' => [0, 0, 250], 'pp_compare' => ['', '', '300'],
         'pp_credits' => [100, 1200, 300], 'pp_designs' => [20, 240, 60], 'pp_research' => [0, 0, 2], 'pp_active' => [1, 1, 1], 'pp_del' => [1 => 1]];
http('POST', "$base/admin/packages.php", http_build_query($post), $jar, ['Content-Type: application/x-www-form-urlencoded']);
$ps = db_all('SELECT * FROM package_prices WHERE package_id = ? ORDER BY sort_order', [$pkg]);
t('حفظ المدد: مدة جديدة اتضافت · المدة اللي عليها اشتراكات اتخفت بدل ما تتمسح · الجودة اتغيرت',
  count($ps) === 3 && (int) db_one('SELECT is_active FROM package_prices WHERE id = ?', [$y])['is_active'] === 0 && (bool) array_filter($ps, fn($p) => (int) $p['days'] === 90 && (float) $p['compare_egp'] === 300.0)
  && db_one('SELECT quality_tier FROM credit_packages WHERE id = ?', [$pkg])['quality_tier'] === 'premium', json_encode(array_map(fn($p) => [$p['label'], $p['days'], $p['is_active']], $ps), JSON_UNESCAPED_UNICODE));
$s = login($base, db_one('SELECT email FROM users WHERE id = ?', [$u1])['email']);
[$c2, $h2] = http('GET', "$base/public/packages.php", [], $s[0]);
t('العميل: تبديل المدة في كارت الباقة + «زوّد باقتك»', $c2 === 200 && str_contains($h2, 'bl-dur') && str_contains($h2, 'price=') && str_contains($h2, 'id="addons"') && !str_contains($h2, 'Warning:'), (string) $c2);
$px = (int) array_values(array_filter($ps, fn($p) => (int) $p['days'] === 90))[0]['id'];
[$c3, $h3] = http('GET', "$base/public/checkout.php?package=$pkg&price=$px", [], $s[0]);
t('صفحة الدفع بالمدة (السعر والاسم)', $c3 === 200 && str_contains($h3, '3 شهور') && str_contains($h3, 'priceId: ' . $px) && !str_contains($h3, 'Warning:'), (string) $c3);
[$c4, $h4] = http('GET', "$base/public/checkout.php?addon=$addD", [], $s[0]);
t('صفحة الدفع لإضافة (من غير كود خصم)', $c4 === 200 && str_contains($h4, 'تصميم زيادة') && str_contains($h4, 'addon: ' . $addD), (string) $c4);
$s2 = login($base, db_one('SELECT email FROM users WHERE id = ?', [$u2])['email']);
[$c5] = http('GET', "$base/public/checkout.php?addon=$addD", [], $s2[0]);
t('إضافة من غير باقة ← رجوع للباقات', $c5 === 302 || $c5 === 303, (string) $c5);

} finally {
foreach ($uids as $id) {
    db_run('DELETE FROM payment_request_events WHERE request_id IN (SELECT id FROM payment_requests WHERE user_id = ?)', [$id]);
    db_run('DELETE FROM payment_requests WHERE user_id = ?', [$id]);
    db_run('DELETE FROM quota_overrides WHERE user_id = ?', [$id]);
    db_run('DELETE FROM user_plans WHERE user_id = ?', [$id]);
    db_run('DELETE FROM user_notifications WHERE user_id = ?', [$id]);
    account_purge($id);
}
if ($pkg) { db_run('DELETE FROM package_prices WHERE package_id = ?', [$pkg]); db_run('DELETE FROM credit_packages WHERE id = ?', [$pkg]); }
if ($addD || $addC) db_run('DELETE FROM addon_packs WHERE name LIKE "PK3%"');
db_run('DELETE FROM credit_transactions WHERE user_id IS NULL AND notes LIKE "%PK3%"');
foreach ($saved as $k => $vv) {
    if ($vv === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $vv);
}
db_run('DELETE FROM rate_limits');
}
t('مسح الحسابات التجريبية', !db_one('SELECT id FROM users WHERE email LIKE "pk3-%@test.local"'));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
