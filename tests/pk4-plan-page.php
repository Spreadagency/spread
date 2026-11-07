<?php
/**
 * Spread AI — الباقات (المرحلة 4): صفحة «باقتي» الموحدة + اختيار مكافأة «ادع واكسب»
 * للتطوير المحلي بس — بيعمل عملاء تجريبيين ويمسحهم في الآخر، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/pk4-plan-page.php http://localhost:8080
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
require_once __DIR__ . '/../includes/offers.php';

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
foreach (['plan_page_unified', 'referral_enabled', 'referral_reward_credits_on', 'referral_reward_designs', 'referral_reward_designs_days', 'referral_reward_discount_pct', 'referral_reward_discount_days', 'referral_default_reward'] as $k) $saved[$k] = get_setting($k, null);
foreach (['plan_page_unified' => '1', 'referral_enabled' => '1', 'referral_reward_credits_on' => '1', 'referral_reward_designs' => '5', 'referral_reward_discount_pct' => '20', 'referral_reward_discount_days' => '60', 'referral_default_reward' => '30'] as $k => $v) set_setting($k, $v);
db_run('DELETE FROM rate_limits');
$uids = [];
$mk = function (string $tag) use (&$uids) {
    $id = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, created_at, phone) VALUES (?,?,?,"active",NOW(),NOW(),"01000000000")',
        ['PK4 ' . $tag, 'pk4-' . $tag . '-' . bin2hex(random_bytes(3)) . '@test.local', password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_run('INSERT IGNORE INTO credit_wallets (user_id, balance) VALUES (?, 0)', [$id]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$id, 'PK4']);
    $uids[] = $id;
    return $id;
};
$offerIds = [];

try {
/* ─── 1) خيارات المكافأة ─── */
echo "خيارات المكافأة\n";
$ref = $mk('ref');
$offer = ensure_user_referral_offer($ref);
$offerIds[] = (int) $offer['id'];
db_run('UPDATE offers SET referrer_credits = 30 WHERE id = ?', [(int) $offer['id']]);
$offer['referrer_credits'] = 30;
$opts = referral_reward_options($offer);
t('الخيارات من الأدمن: كريدت · تصميمات · خصم', array_keys($opts) === ['credits', 'designs', 'discount'] && str_contains($opts['designs'][0], '5') && str_contains($opts['discount'][0], '20%'));
t('الافتراضي = كريدت', referral_reward_choice($ref) === 'credits');
t('اختيار مش موجود ← مرفوض', !referral_reward_set($ref, 'cash'));
t('اختيار التصميمات اتحفظ', referral_reward_set($ref, 'designs') && referral_reward_choice($ref) === 'designs');
set_setting('referral_reward_designs', '0');
t('الأدمن قفل التصميمات ← بيرجع لأول خيار متاح', referral_reward_choice($ref) === 'credits' && !isset(referral_reward_options($offer)['designs']));
set_setting('referral_reward_designs', '5');

/* ─── 2) صرف المكافآت (مكافأة لكل صاحب) ─── */
echo "صرف المكافآت\n";
$mkRef = function (int $referee) use ($offer, $ref) {
    return (int) db_insert('INSERT INTO referrals (offer_id, referrer_user_id, referee_user_id, status, qualified_at) VALUES (?,?,?,"qualified",NOW())', [(int) $offer['id'], $ref, $referee]);
};
referral_reward_set($ref, 'credits');
$r1 = referral_reward($mkRef($mk('f1')));
t('مكافأة كريدت ← الرصيد +30', $r1['ok'] && credits_balance($ref) === 30, json_encode($r1, JSON_UNESCAPED_UNICODE));
referral_reward_set($ref, 'designs');
$r2 = referral_reward($mkRef($mk('f2')));
$qo = db_one('SELECT * FROM quota_overrides WHERE user_id = ? AND unit = "designs"', [$ref]);
t('صاحب تاني بنفس الكود ← مكافأة تانية (تصميمات +5) من غير كريدت', $r2['ok'] && $qo && (int) $qo['extra'] === 5 && credits_balance($ref) === 30, json_encode($r2, JSON_UNESCAPED_UNICODE));
referral_reward_set($ref, 'discount');
$r3 = referral_reward($mkRef($mk('f3')));
$cp = referral_coupons($ref);
t('خصم ← كوبون شخصي REF… في «كوبوناتك»', $r3['ok'] && count($cp) === 1 && str_starts_with($cp[0]['code'], 'REF') && str_contains((string) $r3['reward_text'], $cp[0]['code']), json_encode([$r3, $cp], JSON_UNESCAPED_UNICODE));
$offerIds[] = (int) db_one('SELECT id FROM offers WHERE code = ?', [$cp[0]['code']])['id'];
t('3 مكافآت اتسجلت لنفس المُحيل (كل إحالة لوحدها)', (int) db_one('SELECT COUNT(*) n FROM offer_redemptions WHERE user_id = ? AND role = "referrer"', [$ref])['n'] === 3
  && (int) db_one('SELECT COUNT(*) n FROM referrals WHERE referrer_user_id = ? AND status = "rewarded"', [$ref])['n'] === 3);
t('نفس الإحالة مرتين ← مفيش صرف تاني', !referral_reward((int) db_one('SELECT id FROM referrals WHERE referrer_user_id = ? ORDER BY id LIMIT 1', [$ref])['id'])['ok']);
$pkg = db_one('SELECT * FROM credit_packages WHERE is_active = 1 AND price_egp > 0 ORDER BY id LIMIT 1');
$q = promo_quote($cp[0]['code'], db_one('SELECT * FROM users WHERE id = ?', [$ref]), $pkg);
t('الكوبون بيخصم 20% في صفحة الدفع', $q['ok'] && abs((float) $q['discount'] - round((float) $pkg['price_egp'] * 0.2, 2)) < 0.01, json_encode($q, JSON_UNESCAPED_UNICODE));
$other = $mk('other');
t('الكوبون مايشتغلش لعميل تاني', !promo_quote($cp[0]['code'], db_one('SELECT * FROM users WHERE id = ?', [$other]), $pkg)['ok']);

/* ─── 3) صفحة «باقتي» ─── */
echo "صفحة «باقتي»\n";
$email = fn(int $id) => db_one('SELECT email FROM users WHERE id = ?', [$id])['email'];
$s = login($base, $email($other));
[$c1, $h1] = http('GET', "$base/public/my-plan.php", [], $s[0]);
t('من غير باقة ← «اشتراك» فوق + التبويبات التلاتة', $c1 === 200 && str_contains($h1, 'mp-kicker">اشتراك') && str_contains($h1, 'id="mpt-plans"') && str_contains($h1, 'id="mpt-balance"') && str_contains($h1, 'id="mpt-refer"') && !str_contains($h1, 'Warning:') && !str_contains($h1, 'Fatal'), (string) $c1);
t('التبويبات جوّاها الباقات والرصيد ودعواتك', str_contains($h1, 'bl-plans') && str_contains($h1, 'كود الدعوة') && substr_count($h1, '<main') === 1);
plan_start($ref, (int) $pkg['id'], 100, 30, 'paid', null, 'PK4');
$s2 = login($base, $email($ref));
[$c2, $h2] = http('GET', "$base/public/my-plan.php?tab=refer", [], $s2[0]);
t('بباقة ← «ترقية» + اسم الباقة والأيام + التصميمات', $c2 === 200 && str_contains($h2, 'mp-kicker">ترقية') && str_contains($h2, e($pkg['name'])) && str_contains($h2, 'باقي') && str_contains($h2, 'التصميمات'), (string) $c2);
t('تبويب «ادع واكسب» مفتوح من الرابط + اختيار المكافأة + الكوبون', preg_match('/id="mpp-refer"[^>]*>/', $h2, $mm) && !str_contains($mm[0], 'hidden') && str_contains($h2, 'name="reward"') && str_contains($h2, $cp[0]['code']));
foreach ([['packages.php', 'plans'], ['credits.php', 'balance'], ['referrals.php', 'refer']] as [$pg, $tb]) {
    $ch = curl_init("$base/public/$pg?code=ABC");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $s2[0]]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    t("الصفحة القديمة $pg ← «باقتي» تبويب $tb (والكود بيفضل)", in_array($code, [301, 302, 303], true) && preg_match('#Location: [^\r\n]*my-plan\.php\?tab=' . $tb . '&code=ABC#i', $raw), $code . ' ' . substr($raw, 0, 300));
}
preg_match('/name="csrf" value="([^"]+)"/', $h2, $cm);
[$c3] = http('POST', "$base/public/referrals.php", ['csrf' => $cm[1] ?? '', 'action' => 'ref_reward', 'reward' => 'designs'], $s2[0]);
t('حفظ الاختيار من الصفحة ← رجوع لـ «باقتي»', in_array($c3, [200, 302, 303], true) && referral_reward_choice($ref) === 'designs', (string) $c3);
[, $h4] = http('GET', "$base/public/dashboard.php", [], $s2[0]);
t('القايمة: «باقتي» بدل الرصيد والدعوات', str_contains($h4, 'my-plan.php') && !str_contains($h4, 'href="' . url('referrals.php') . '"'));
set_setting('plan_page_unified', '0');
[$c5, $h5] = http('GET', "$base/public/credits.php", [], $s2[0]);
t('الصفحة الموحدة مقفولة ← الصفحات القديمة زي ما هي', $c5 === 200 && str_contains($h5, '<main') && !str_contains($h5, 'mp-hero'), (string) $c5);
set_setting('plan_page_unified', '1');
$jar = tempnam(sys_get_temp_dir(), 'pk4a');
[, $lp] = http('GET', "$base/admin/login.php", [], $jar);
preg_match('/name="csrf" value="([^"]+)"/', $lp, $mm2);
http('POST', "$base/admin/login.php", ['csrf' => $mm2[1] ?? '', 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c6, $h6] = http('GET', "$base/admin/offer-settings.php", [], $jar);
t('الأدمن: خيارات المكافأة وقيمها', $c6 === 200 && str_contains($h6, 'referral_reward_designs') && str_contains($h6, 'referral_reward_discount_pct'), (string) $c6);

} finally {
foreach ($uids as $id) {
    db_run('DELETE FROM referrals WHERE referrer_user_id = ? OR referee_user_id = ?', [$id, $id]);
    db_run('DELETE FROM offer_redemptions WHERE user_id = ?', [$id]);
    db_run('DELETE FROM referral_prefs WHERE user_id = ?', [$id]);
    db_run('DELETE FROM quota_overrides WHERE user_id = ?', [$id]);
    db_run('DELETE FROM user_plans WHERE user_id = ?', [$id]);
    db_run('DELETE FROM user_notifications WHERE user_id = ?', [$id]);
    db_run('DELETE FROM offers WHERE owner_user_id = ? OR description = ?', [$id, 'ref_coupon:' . $id]);
    account_purge($id);
}
foreach ($saved as $k => $v) {
    if ($v === null) db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    else set_setting($k, (string) $v);
}
db_run('DELETE FROM rate_limits');
}
t('مسح الحسابات التجريبية', !db_one('SELECT id FROM users WHERE email LIKE "pk4-%@test.local"'));
echo "\n$pass ✓ · $fail ✗\n";
exit($fail ? 1 : 0);
