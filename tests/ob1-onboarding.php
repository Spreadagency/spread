<?php
/**
 * Spread AI — البداية السريعة (خريطة التطوير · المرحلة 1)
 * للتطوير المحلي بس — بيعمل حسابات مؤقتة ويمسحها، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/ob1-onboarding.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/credits.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_string($post) ? $post : http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function loc(string $headers): string { return preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : ''; }
function csrf_of(string $html): string
{
    if (preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) return $m[1];
    return preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';
}
function setting_swap(string $k, ?string $v): void
{
    db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    if ($v !== null) set_setting($k, $v);
}
function register(string $base, string $tag, string $phone, string $jar): array
{
    [, $h] = http('GET', "$base/public/register.php", [], $jar);
    return http('POST', "$base/public/register.php", ['csrf' => csrf_of($h), 'name' => "OB1 $tag", 'email' => "ob1-$tag@test.local",
        'phone' => $phone, 'password' => 'Test12345!', 'password_confirm' => 'Test12345!', 'terms' => '1', 'agree' => '1'], $jar);
}
function uid_of(string $tag): int { return (int) (db_one('SELECT id FROM users WHERE email = ?', ["ob1-$tag@test.local"])['id'] ?? 0); }

$keys = ['onboarding_quick', 'onboarding_unverified_days', 'onboarding_gift', 'manual_approval'];
$saved = [];
foreach ($keys as $k) $saved[$k] = db_one('SELECT setting_value v FROM settings WHERE setting_key = ?', [$k])['v'] ?? null;
db_run('DELETE FROM rate_limits WHERE action IN ("register", "login", "onb_name", "bk_autofill", "bk_draft", "generate")');
$tags = [];
$mk = function () use (&$tags) { $t = bin2hex(random_bytes(3)); $tags[] = $t; return $t; };

/* ─── 1) الإعداد مقفول ← كل حاجة زي الأول ─── */
echo "الإعداد مقفول (السلوك القديم)\n";
setting_swap('onboarding_quick', '0'); setting_swap('manual_approval', '0');
$a = $mk(); $jarA = tempnam(sys_get_temp_dir(), 'ob');
[$c, , $hd] = register($base, $a, '010' . random_int(10000000, 99999999), $jarA);
t('التسجيل بيحوّل لصفحة الدخول (مفيش دخول تلقائي)', $c === 302 && str_contains(loc($hd), 'login.php'), $c . ' ' . loc($hd));
[$c, , $hd] = http('GET', "$base/public/start.php", [], $jarA);
t('start.php من غير جلسة ← دخول', $c === 302 && str_contains(loc($hd), 'login.php'), loc($hd));
[, $h] = http('GET', "$base/public/login.php", [], $jarA);
[$c, $b] = http('POST', "$base/public/login.php", ['csrf' => csrf_of($h), 'email' => "ob1-$a@test.local", 'password' => 'Test12345!'], $jarA);
t('الحساب غير المؤكد مايدخلش', $c === 200 && str_contains($b, 'مش مفعل'), (string) $c);
t('مفيش هدية اتصرفت', !db_one('SELECT id FROM credit_transactions WHERE user_id = ? AND reference_type = "onboarding_gift"', [uid_of($a)]));

/* ─── 2) الإعداد شغال ─── */
echo "البداية السريعة شغالة\n";
setting_swap('onboarding_quick', '1'); setting_swap('onboarding_gift', '1'); setting_swap('onboarding_unverified_days', '3');
$b1 = $mk(); $jar = tempnam(sys_get_temp_dir(), 'ob'); $phone = '011' . random_int(10000000, 99999999);
[$c, , $hd] = register($base, $b1, $phone, $jar);
t('التسجيل ← دخول تلقائي ← start.php', $c === 302 && str_contains(loc($hd), 'start.php'), $c . ' ' . loc($hd));
$uid = uid_of($b1);
$bal0 = credits_balance($uid);
[$c, $page] = http('GET', "$base/public/start.php", [], $jar);
$csrf = csrf_of($page);
t('start.php بتفتح للحساب غير المؤكد وفيها رحلة الهوية (اللينك · الأسئلة · أول بوست)', $c === 200 && str_contains($page, 'id="s-link"') && str_contains($page, 'id="s-qs"') && str_contains($page, 'id="s-post"'), (string) $c);
$gift = (int) (db_one('SELECT COALESCE(SUM(amount),0) n FROM credit_transactions WHERE user_id = ? AND reference_type = "onboarding_gift"', [$uid])['n'] ?? 0);
t('هدية البداية اتضافت = تكلفة (قراءة + مسودة + ألوان + عملاء + بوست)', $gift === onb_gift_amount() && $gift > 0 && credits_balance($uid) === $bal0 + $gift, "gift=$gift expected=" . onb_gift_amount());
http('GET', "$base/public/start.php", [], $jar);
t('الهدية مرة واحدة (فتح الصفحة تاني مايزودش)', credits_balance($uid) === $bal0 + $gift);

[$c, $b] = http('POST', "$base/public/start.php", ['name' => 'عيادة OB1 للأسنان'], $jar);
t('حفظ الاسم من غير CSRF مرفوض', $c === 403, (string) $c);
[$c, $b] = http('POST', "$base/public/start.php", ['csrf' => $csrf, 'name' => 'عيادة OB1 للأسنان'], $jar);
t('حفظ اسم النشاط', $c === 200 && (json_decode($b, true)['ok'] ?? false) && db_one('SELECT business_name b FROM brand_profiles WHERE user_id = ?', [$uid])['b'] === 'عيادة OB1 للأسنان', $b);

$H = ['X-CSRF-Token: ' . $csrf, 'Content-Type: application/json'];
[$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=autofill", json_encode(['build' => '0']), $jar, $H);
$j = json_decode($b, true);
t('autofill (من غير لينك) شغال للحساب غير المؤكد', $c === 200 && !empty($j['ok']) && isset($j['state']['sectors']), substr($b, 0, 200));
$sector = (int) ($j['state']['sectors'][0]['id'] ?? 0);
if ($sector) {
    [$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=sector_set", json_encode(['sector_id' => $sector]), $jar, $H);
    t('اختيار القطاع بيتحفظ', $c === 200 && (int) (json_decode($b, true)['state']['sector']['id'] ?? 0) === $sector, substr($b, 0, 160));
}
[$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=draft", json_encode(['answers' => ['نشاطك إيه' => 'عيادة أسنان', 'بتبيع لمين' => 'أسر وأطفال', 'أهم خدمة أو منتج عايز تبيعه دلوقتي' => 'تبييض الأسنان']]), $jar, $H);
$d = json_decode($b, true);
t('مسودة الهوية من الإجابات', $c === 200 && !empty($d['ok']) && !empty($d['draft']['business']), substr($b, 0, 240));
if (!empty($d['draft'])) {
    [$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=apply_draft", json_encode(['draft' => $d['draft'], 'sections' => array_keys($d['draft']), 'unsure' => []]), $jar, $H);
    t('حفظ المسودة', $c === 200 && !empty(json_decode($b, true)['ok']), substr($b, 0, 200));
}
[$c, $b] = http('POST', "$base/public/ajax/generate-content.php", ['csrf' => $csrf, 'content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'dialect' => 'egyptian', 'extra_notes' => 'البوست عن: تبييض الأسنان'], $jar);
$g = json_decode($b, true);
t('أول بوست اتولد واتحفظ', $c === 200 && !empty($g['ok']) && !empty($g['content_id']) && (bool) db_one('SELECT id FROM contents WHERE id = ? AND user_id = ?', [(int) ($g['content_id'] ?? 0), $uid]), substr($b, 0, 240));
t('الرصيد بعد البداية ≥ رصيد التسجيل (الهدية غطّت التكلفة)', credits_balance($uid) >= $bal0, credits_balance($uid) . " vs $bal0");
[$c, , $hd] = http('GET', "$base/public/start.php", [], $jar);
t('قبل اعتماد الهوية start.php لسه مفتوحة (رحلة الهوية)', $c === 200, (string) $c);
http('POST', "$base/public/start.php", ['csrf' => $csrf, 'act' => 'finish'], $jar);
[$c, , $hd] = http('GET', "$base/public/start.php", [], $jar);
t('بعد اعتماد الهوية start.php بتحوّل للوحة', $c === 302 && str_contains(loc($hd), 'dashboard.php'), loc($hd));
[$c] = http('GET', "$base/public/dashboard.php", [], $jar);
t('لوحة التحكم مفتوحة للتجربة', $c === 200, (string) $c);

echo "اللي لسه محتاج تأكيد الإيميل\n";
[$c, , $hd] = http('GET', "$base/public/checkout.php", [], $jar);
t('الشراء ← صفحة «فعّل إيميلك»', $c === 302 && str_contains(loc($hd), 'start.php?verify=1'), $c . ' ' . loc($hd));
[$c, $b] = http('GET', "$base/public/start.php?verify=1", [], $jar);
t('صفحة «فعّل إيميلك» بتظهر ومعاها إعادة الإرسال', $c === 200 && str_contains($b, 'resend-verification.php'), (string) $c);
[$c, $b] = http('POST', "$base/public/ajax/publish-direct.php", ['csrf' => $csrf, 'content_id' => (int) ($g['content_id'] ?? 0)], $jar);
t('النشر المباشر مرفوض (403 verify)', $c === 403 && (json_decode($b, true)['code'] ?? '') === 'verify', $c . ' ' . substr($b, 0, 120));
[$c, $b] = http('POST', "$base/public/ajax/schedule-content.php", ['csrf' => $csrf, 'content_id' => (int) ($g['content_id'] ?? 0)], $jar);
t('الجدولة مرفوضة', $c === 403, (string) $c);
[$c, $b] = http('POST', "$base/public/api/campaigns.php?action=create", json_encode(['title' => 'x']), $jar, $H);
t('POST لأي API غير الهوية مرفوض (verify)', $c === 403 && (json_decode($b, true)['code'] ?? '') === 'verify', $c . ' ' . substr($b, 0, 120));

echo "الحماية\n";
$b2 = $mk(); $jar2 = tempnam(sys_get_temp_dir(), 'ob');
register($base, $b2, $phone, $jar2);
$uid2 = uid_of($b2);
http('GET', "$base/public/start.php", [], $jar2);
t('حساب تاني بنفس رقم الموبايل مابياخدش الهدية', $uid2 > 0 && !db_one('SELECT id FROM credit_transactions WHERE user_id = ? AND reference_type = "onboarding_gift"', [$uid2]));
db_run('UPDATE users SET created_at = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE id = ?', [$uid]);
[$c, , $hd] = http('GET', "$base/public/dashboard.php", [], $jar);
t('بعد مهلة التجربة الحساب غير المؤكد بيخرج', $c === 302 && str_contains(loc($hd), 'login.php'), $c . ' ' . loc($hd));
[$c, $b] = http('POST', "$base/public/api/brand-kit.php?action=autofill", json_encode(['build' => '0']), $jar2, ['X-CSRF-Token: bad', 'Content-Type: application/json']);
t('API الهوية من غير CSRF صح مرفوض', $c === 419, (string) $c);
db_run('UPDATE users SET email_verified_at = NOW(), created_at = NOW() WHERE id = ?', [$uid]);
[, $h] = http('GET', "$base/public/login.php", [], $jar);
http('POST', "$base/public/login.php", ['csrf' => csrf_of($h), 'email' => "ob1-$b1@test.local", 'password' => 'Test12345!'], $jar);
[$c] = http('GET', "$base/public/checkout.php", [], $jar);
t('بعد تأكيد الإيميل الشراء بيفتح', $c === 200 || ($c === 302 && !str_contains(loc($hd), 'verify')), (string) $c);

echo "الموافقة اليدوية بتقفل البداية السريعة\n";
setting_swap('manual_approval', '1');
$m = $mk(); $jarM = tempnam(sys_get_temp_dir(), 'ob');
db_run('DELETE FROM rate_limits WHERE action = "register"');
[$c, , $hd] = register($base, $m, '012' . random_int(10000000, 99999999), $jarM);
t('مع الموافقة اليدوية: مفيش دخول تلقائي', $c === 302 && str_contains(loc($hd), 'login.php'), loc($hd));

echo "الفورم البسيط\n";
setting_swap('manual_approval', '0');
[$c, $b] = http('GET', "$base/public/create-content.php", [], $jar);
t('«منشور جديد»: الإعدادات الإضافية مخفية وزرار «إعدادات أكتر» موجود', $c === 200 && str_contains($b, 'id="adv-toggle"') && substr_count($b, 'cc-adv') >= 3 && str_contains($b, 'name="length" value="medium"'), (string) $c);
setting_swap('onboarding_quick', '0');
[$c, $b] = http('GET', "$base/public/create-content.php", [], $jar);
t('والإعداد مقفول: الفورم الكامل زي ما هو', $c === 200 && !str_contains($b, 'adv-toggle') && !str_contains($b, 'cc-adv'), (string) $c);

/* ─── تنضيف ─── */
foreach ($tags as $tg) {
    $u = uid_of($tg);
    if (!$u) continue;
    foreach (['content_versions' => 'content_id IN (SELECT id FROM contents WHERE user_id = ?)', 'contents' => 'user_id = ?', 'brand_services' => 'user_id = ?', 'brand_topics' => 'user_id = ?',
              'brand_kits' => 'user_id = ?', 'brand_profiles' => 'user_id = ?', 'credit_transactions' => 'user_id = ?', 'credit_wallets' => 'user_id = ?', 'credit_lots' => 'user_id = ?', 'user_plans' => 'user_id = ?', 'brand_personas' => 'user_id = ?'] as $tb => $w) {
        try { db_run("DELETE FROM $tb WHERE $w", [$u]); } catch (\Throwable $e) {}
    }
    try { db_run('DELETE FROM users WHERE id = ?', [$u]); } catch (\Throwable $e) { echo "  (تنضيف $tg: " . $e->getMessage() . ")\n"; }
}
foreach ($saved as $k => $v) setting_swap($k, $v);
db_run('DELETE FROM rate_limits WHERE action IN ("register", "login", "onb_name", "bk_autofill", "bk_draft", "generate")');
foreach ([$jarA, $jar, $jar2, $jarM] as $f) @unlink($f);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
