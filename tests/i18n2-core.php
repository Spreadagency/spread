<?php
/**
 * Spread AI — الترجمة · المرحلتين 2 و3: الأساس (__() · اللغة · زرار ع | EN · الاتجاه) + أول دفعة (الدخول · التسجيل · الهيدر · الهيرو)
 * للتطوير المحلي بس — بيعمل حساب مؤقت ويمسحه.
 *
 *   php tests/i18n2-core.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';

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
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function loc(string $h): string { return preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : ''; }
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
/** النص الظاهر (من غير سكريبت وستايل وتاجات) */
function visible(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    return html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($html))), ENT_QUOTES, 'UTF-8');
}
function arabic_words(string $s): array
{
    preg_match_all('/[\p{Arabic}][\p{Arabic}\s\.،]*/u', $s, $m);
    return array_values(array_filter(array_map('trim', $m[0]), fn($w) => $w !== 'ع'));   // «ع» = زرار الرجوع للعربي
}
function cookie_jar(string $lang = ''): string
{
    $jar = tempnam(sys_get_temp_dir(), 'i18n');
    if ($lang !== '') file_put_contents($jar, "localhost\tFALSE\t/\tFALSE\t0\tspread_lang\t$lang\n");
    return $jar;
}
$tag = bin2hex(random_bytes(3));
$swap = function (string $k, ?string $v) { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); };
$oldOn = get_setting('i18n_enabled', null);
$swap('i18n_enabled', '1');
db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "trial_analyze", "register")');

/* ─── 1) الأساس ─── */
echo "الأساس\n";
i18n_set('ar');
t('العربي هو المصدر: بيرجع زي ما هو بالحرف', __('تسجيل الدخول') === 'تسجيل الدخول' && __('نص مالوش ترجمة') === 'نص مالوش ترجمة');
i18n_set('en');
t('الإنجليزي من القاموس', __('تسجيل الدخول') === 'Log in');
t('نص مالوش ترجمة ← العربي (مفيش حاجة بتبوظ)', __('نص مالوش ترجمة خالص') === 'نص مالوش ترجمة خالص');
t('المتغيرات', __('الحساب لسه مش مفعل. تشيك على بريدك، أو اطلب رابط تفعيل جديد من هنا: {url}', ['url' => 'X']) === 'Your account is not activated yet. Check your email, or request a new activation link here: X');
t('قيمة الإعدادات: الإنجليزي المكتوب ← ترجمة العربي ← العربي', __v('ابدأ حملتك', 'Go!') === 'Go!' && __v('ابدأ حملتك') === 'Launch your' && __v('حاجة مكتوبة') === 'حاجة مكتوبة');
t('اتجاه الصفحة', i18n_html_attrs() === 'lang="en" dir="ltr"');
i18n_set('ar');
t('العربي: rtl', i18n_html_attrs() === 'lang="ar" dir="rtl"' && __v('ابدأ حملتك', 'Go!') === 'ابدأ حملتك');
t('مسار الرجوع: محلي بس', i18n_safe_back('//evil.com/x') === '/' && i18n_safe_back('https://evil.com') === '/' && i18n_safe_back('/\\evil') === '/' && i18n_safe_back('javascript:alert(1)') === '/');
t('مسار الرجوع: بيشيل ?lang القديم ويسيب الباقي', i18n_safe_back('/public/x.php?lang=en&id=5#top') === '/public/x.php?id=5#top');
$js = i18n_js(['قوية']);
t('نصوص الجافاسكريبت: فاضية للعربي', str_contains($js, 'Object.assign(window.I18N||{},[])') || str_contains($js, 'Object.assign(window.I18N||{},{})'));
i18n_set('en');
t('نصوص الجافاسكريبت: المطلوب بس', str_contains(i18n_js(['قوية']), '"قوية":"Strong"') && !str_contains(i18n_js(['قوية']), 'Log in'));
i18n_set('ar');
exec('php ' . escapeshellarg(__DIR__ . '/i18n-check.php'), $o, $rc);
t('كل نصوص الدفعة الأولى ليها ترجمة (tests/i18n-check.php)', $rc === 0, implode(' | ', array_slice($o, 0, 5)));

/* ─── 2) زرار اللغة ─── */
echo "زرار اللغة\n";
$jar = cookie_jar();
[$c, , $h] = http('GET', "$base/public/lang.php?l=en&back=" . urlencode('/public/login.php?lang=ar&x=1'), [], $jar);
t('lang.php بيرجّع لنفس الصفحة', $c === 302 && loc($h) === '/public/login.php?x=1', loc($h));
t('وبيحفظ الكوكي سنة على الدومين كله', (bool) preg_match('/Set-Cookie:\s*spread_lang=en;.*path=\//i', $h));
[$c, , $h] = http('GET', "$base/public/lang.php?l=en&back=" . urlencode('//evil.com/'), [], cookie_jar());
t('مفيش رجوع لموقع تاني', loc($h) === '/', loc($h));
[$c, , $h] = http('GET', "$base/public/lang.php?l=xx&back=/", [], cookie_jar());
t('لغة مش معروفة ← مفيش كوكي', $c === 302 && !str_contains($h, 'spread_lang='));

/* ─── 3) صفحة الدخول بالإنجليزي (الشكلين) ─── */
echo "صفحة الدخول\n";
foreach (['v2' => '&ui=v2', 'v1' => '&ui=v1'] as $ui => $q) {
    [$c, $b] = http('GET', "$base/public/login.php?lang=en$q", [], cookie_jar());
    t("[$ui] بالإنجليزي: ltr + ملف الاتجاه + زرار «ع»", $c === 200 && str_contains($b, '<html lang="en" dir="ltr">') && str_contains($b, 'ltr.css') && preg_match('/class="lang-sw[^"]*"[^>]*>ع</u', $b) === 1);
    $ar = arabic_words(visible($b));
    t("[$ui] مفيش نص عربي ظاهر", !$ar, implode(' | ', array_slice($ar, 0, 6)));
    t("[$ui] الكلام إنجليزي", str_contains($b, 'Log in') && str_contains($b, 'Password'));
    [$c, $b] = http('GET', "$base/public/login.php?lang=ar$q", [], cookie_jar());
    t("[$ui] بالعربي زي ما كان: rtl ومن غير ملف الاتجاه", str_contains($b, '<html lang="ar" dir="rtl">') && !str_contains($b, 'ltr.css') && str_contains($b, 'كلمة المرور') && str_contains($b, '>EN<'));
}
[$c, $b] = http('GET', "$base/public/register.php?lang=en&ui=v2", [], cookie_jar());
$ar = arabic_words(visible($b));
t('صفحة التسجيل بالإنجليزي من غير عربي ظاهر', $c === 200 && !$ar && str_contains($b, 'Create account'), implode(' | ', array_slice($ar, 0, 6)));
foreach (['forgot-password.php', 'resend-verification.php', 'reset-password.php?token=x'] as $pg) {
    [$c, $b] = http('GET', "$base/public/$pg", [], cookie_jar('en'));
    $ar = arabic_words(visible($b));
    t("$pg بالإنجليزي من غير عربي ظاهر", $c === 200 && str_contains($b, 'dir="ltr"') && !$ar, implode(' | ', array_slice($ar, 0, 6)));
}
$swap('i18n_enabled', '0');
[, $b] = http('GET', "$base/public/login.php?lang=ar&ui=v2", [], cookie_jar());
t('الإعداد مقفول ← الزرار مستخبي', !str_contains($b, 'lang-sw'));
$swap('i18n_enabled', '1');

/* ─── 4) لغة الحساب ─── */
echo "لغة الحساب\n";
$email = "i18n2-$tag@test.local";
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?, "active", NOW(), "approved", NOW())',
    ["I18N $tag", $email, password_hash('Test12345!', PASSWORD_DEFAULT)]);
db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?,?)', [$uid, 'I18N ' . $tag]);
$login = function (string $jar) use ($base, $email): array {
    [, $p] = http('GET', "$base/public/login.php?ui=v2", [], $jar);
    return http('POST', "$base/public/login.php", ['csrf' => csrf_of($p), 'email' => $email, 'password' => 'Test12345!'], $jar);
};
try {
    // اختار الإنجليزي قبل الدخول ← بيتحفظ في الحساب
    $j1 = cookie_jar('en');
    [$c, , $h] = $login($j1);
    t('الدخول اشتغل', $c === 302, (string) $c);
    t('اللغة اللي كان فاتح بيها اتحفظت في الحساب', (db_one('SELECT locale FROM users WHERE id = ?', [$uid])['locale'] ?? '') === 'en');
    // جهاز تاني من غير كوكي ← بعد الدخول اللغة بترجع معاه
    $j2 = cookie_jar();
    [, , $h] = $login($j2);
    t('جهاز جديد: الدخول بيرجّع لغة الحساب', (bool) preg_match('/Set-Cookie:\s*spread_lang=en/i', $h));
    [$c, $b] = http('GET', "$base/public/dashboard.php", [], $j2);
    t('صفحة لسه مااتترجمتش (الرئيسية جوه) بتفضل عربي ومن اليمين للشمال', $c === 200 && str_contains($b, '<html lang="ar" dir="rtl">') && !str_contains($b, 'assets/css/ltr.css'));
    // من جوه: الرجوع للعربي بيتحفظ في الحساب (العربي = NULL زي الأول)
    http('GET', "$base/public/lang.php?l=ar&back=/", [], $j2);
    t('الرجوع للعربي من جوه الحساب', db_one('SELECT locale FROM users WHERE id = ?', [$uid])['locale'] === null);
    @unlink($j1); @unlink($j2);
} finally {
    foreach (['brand_profiles', 'user_sessions', 'credit_transactions', 'credit_wallets'] as $tb) {
        try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
    }
    try { db_run('DELETE FROM remember_tokens WHERE user_id = ?', [$uid]); } catch (\Throwable $e) {}
    db_run('DELETE FROM users WHERE id = ?', [$uid]);
}

/* ─── 5) موقع الهبوط ─── */
echo "موقع الهبوط\n";
[$c, $b] = http('GET', "$base/?lang=en", [], cookie_jar());
$hero = preg_match('#<header class="h-head".*?</section>#s', $b, $m) ? $m[0] : '';
$ar = arabic_words(visible($hero));
t('الرئيسية بالإنجليزي: ltr + الهيدر والهيرو من غير عربي', $c === 200 && str_contains($b, '<html lang="en" dir="ltr">') && $hero !== '' && !$ar, implode(' | ', array_slice($ar, 0, 6)));
t('الهيرو: العنوان والزرار بالإنجليزي', str_contains($hero, 'Launch your') && str_contains($hero, 'Start your campaign now'));
t('نصوص الجافاسكريبت بتاعة الهيرو في الصفحة', str_contains($b, '"ابعتلي اللينك 👇":"Send me the link 👇"'));
[$c, $b] = http('GET', "$base/?lang=ar", [], cookie_jar());
t('الرئيسية بالعربي زي ما هي', str_contains($b, '<html lang="ar" dir="rtl">') && str_contains($b, 'ابدأ حملتك الآن') && !str_contains($b, 'site-assets/css/ltr.css'));

/* ─── 6) التجربة من الموقع بالإنجليزي ─── */
echo "التجربة\n";
$tj = cookie_jar('en');
[$c, $b] = http('POST', "$base/public/ajax/trial.php", ['action' => 'analyze', 'url' => ''], $tj, ['Origin: ' . $base, 'X-Requested-With: XMLHttpRequest']);
$j = json_decode($b, true) ?: [];
t('رسالة الخطأ بالإنجليزي', ($j['error'] ?? '') === 'Paste your website or Facebook page link', $b);
$biz = "EN Clinic $tag";
[$c, $b] = http('POST', "$base/public/ajax/trial.php", ['action' => 'analyze', 'url' => '', 'business_name' => $biz, 'industry' => 'Dental'], $tj, ['Origin: ' . $base, 'X-Requested-With: XMLHttpRequest']);
$row = db_one('SELECT dialect FROM trial_sessions WHERE business_name = ? ORDER BY id DESC LIMIT 1', [$biz]);
t('زائر بالإنجليزي ← هويته بلغة English (المحتوى بعد التسجيل إنجليزي)', ($row['dialect'] ?? '') === 'english', $b);
db_run('DELETE FROM trial_sessions WHERE business_name = ?', [$biz]);
@unlink($tj);

$swap('i18n_enabled', $oldOn);
echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
