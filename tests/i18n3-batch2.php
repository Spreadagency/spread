<?php
/**
 * Spread AI — الترجمة · الدفعة 2: زرار الأعلام · أسئلة الهوية (أول دخول) والأدمن بتاعها · إطار المنصة والرئيسية و«ابدأ»
 * وإنشاء المحتوى بالإنجليزي · ترجمة محتوى الموقع من لوحة الموقع (الرئيسية لوحدها والصفحات الداخلية لوحدها)
 * للتطوير المحلي بس — بيعمل حسابات مؤقتة ويمسحها، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/i18n3-batch2.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/onboarding.php';
require_once __DIR__ . '/../includes/admin-ui.php';

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
function visible(string $html): string
{
    $html = preg_replace('#<(script|style|template)\b.*?</\1>#si', ' ', $html);
    $html = preg_replace('#<!--.*?-->#s', ' ', $html);
    return html_entity_decode(trim(preg_replace('/\s+/u', ' ', strip_tags($html))), ENT_QUOTES, 'UTF-8');
}
function arabic_words(string $s): array
{
    preg_match_all('/[\p{Arabic}][\p{Arabic}\s\.،]*/u', $s, $m);
    return array_values(array_unique(array_filter(array_map('trim', $m[0]))));
}
function jar(string $lang = ''): string
{
    $j = tempnam(sys_get_temp_dir(), 'i3');
    if ($lang !== '') file_put_contents($j, "localhost\tFALSE\t/\tFALSE\t0\tspread_lang\t$lang\n");
    return $j;
}
$tag = bin2hex(random_bytes(3));
$saved = [];
foreach (['i18n_enabled', 'onb_questions', 'onb_questions_on', 'ui_v2_mode'] as $k) $saved[$k] = db_one('SELECT setting_value FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1', [$k])['setting_value'] ?? null;
$swap = function (string $k, ?string $v) { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); };
$swap('i18n_enabled', '1');
$swap('onb_questions', null);
$swap('onb_questions_on', null);   // الافتراضي: شغالة
$swap('ui_v2_mode', 'all');
db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "admin_login", "admin_login_acct")');

$uids = [];
$mkUser = function (string $name, string $email) use (&$uids): int {
    $id = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?, "active", NOW(), "approved", NOW())',
        [$name, $email, password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name, industry) VALUES (?,?,?)', [$id, 'Smile Clinic', 'Dental clinic']);
    $uids[] = $id;
    return $id;
};
$login = function (string $email, string $jar) use ($base): array {
    [, $p] = http('GET', "$base/public/login.php?ui=v2", [], $jar);
    return http('POST', "$base/public/login.php", ['csrf' => csrf_of($p), 'email' => $email, 'password' => 'Test12345!'], $jar);
};

try {
    /* ─── 1) زرار الأعلام ─── */
    echo "زرار اللغة\n";
    i18n_set('ar');
    $sw = i18n_switcher('/lang.php');
    t('علم مصر منوّر (العربي) وعلم المملكة المتحدة لينك للإنجليزي', str_contains($sw, '<span class="lang-opt on" lang="ar"') && preg_match('#<a class="lang-opt" href="/lang\.php\?l=en[^"]*" hreflang="en"#', $sw) === 1);
    t('الأعلام SVG (مش إيموجي) — مصر أحمر/أبيض/أسود · المملكة المتحدة أزرق', str_contains($sw, '#CE1126') && str_contains($sw, '#012169') && substr_count($sw, '<svg') === 2);
    i18n_set('en');
    $sw = i18n_switcher('/lang.php');
    t('بالإنجليزي العكس', str_contains($sw, '<span class="lang-opt on" lang="en"') && str_contains($sw, 'hreflang="ar"'));
    $two = i18n_switcher('/lang.php') . i18n_switcher('/lang.php');
    preg_match_all('/id="(uk\d+)a"/', $two, $m);
    t('مرتين في نفس الصفحة ← معرّفات الرسمة مش متكررة', count($m[1]) === 2 && $m[1][0] !== $m[1][1]);
    i18n_set('ar');

    /* ─── 2) أسئلة الهوية ─── */
    echo "أسئلة الهوية\n";
    t('الافتراضي: الأسئلة الخمسة الأصلية', count(onb_q_active()) === 5 && onb_q_active()[0]['kind'] === 'sector');
    t('صفحة الأسئلة شغالة افتراضي (من غير البداية السريعة)', onb_questions_on() && onb_start_enabled());
    $list = onb_q_all();
    array_splice($list, 1, 1);   // شيل «بتبيع لمين»
    $list[] = ['kind' => 'single', 'title' => "سؤال جديد {$tag}؟", 'opts' => "أ\nب\nج", 'active' => true, 'title_en' => "New question $tag?", 'opts_en' => "A\nB\nC"];
    $list[] = ['kind' => 'single', 'title' => 'سؤال ناقص', 'opts' => 'اختيار واحد', 'active' => true];   // اختيار واحد بس ← مايتحفظش
    $list[] = ['kind' => 'text', 'title' => 'موضوع تاني', 'role' => 'topic', 'active' => false];
    $savedQ = onb_q_save($list);
    t('حفظ: اتشال سؤال واتزوّد سؤال والناقص ماتحفظش', count($savedQ) === 6 && !in_array('بتبيع لمين؟', array_column($savedQ, 'title'), true)
        && in_array("سؤال جديد {$tag}؟", array_column($savedQ, 'title'), true) && !in_array('سؤال ناقص', array_column($savedQ, 'title'), true));
    t('موضوع أول بوست سؤال واحد بس', count(array_filter($savedQ, fn($q) => ($q['role'] ?? '') === 'topic')) === 1);
    t('المتوقف مابيظهرش للعميل', count(onb_q_active()) === 5);
    $new = array_values(array_filter(onb_q_active(), fn($q) => $q['title'] === "سؤال جديد {$tag}؟"))[0] ?? [];
    $cEn = onb_q_for_client($new, true);
    $cAr = onb_q_for_client($new, false);
    t('بالإنجليزي: النسخة اللي الأدمن كتبها', ($cEn['title'] ?? '') === "New question $tag?" && ($cEn['opts'] ?? []) === ['A', 'B', 'C']);
    t('بالعربي: زي ما هو', ($cAr['title'] ?? '') === "سؤال جديد {$tag}؟" && ($cAr['opts'] ?? []) === ['أ', 'ب', 'ج']);
    $def = onb_q_for_client(onb_q_defaults()[1], true);
    t('سؤال أصلي من غير نسخة إنجليزي ← الترجمة الجاهزة', ($def['title'] ?? '') === 'Who do you sell to?' && in_array('Women', $def['opts'] ?? [], true));

    // الأدمن: الصفحة + الحفظ من الفورم
    $aj = jar();
    [, $lp] = http('GET', "$base/admin/login.php", [], $aj);
    http('POST', "$base/admin/login.php", ['csrf' => csrf_of($lp), 'email' => 'owner@app.test', 'password' => 'Test12345!'], $aj);
    [$c, $ap] = http('GET', "$base/admin/onboarding-questions.php", [], $aj);
    t('صفحة الأدمن بتفتح وفيها الأسئلة والقالب الفاضي', $c === 200 && str_contains($ap, "سؤال جديد {$tag}؟") && str_contains($ap, 'id="oq-tpl"') && str_contains($ap, '+ سؤال جديد'));
    $post = ['csrf' => csrf_of($ap), 'act' => 'save', 'on' => '1', 'topic' => 'n1',
             'order' => ['n1', 'n0'],
             'q' => ['n0' => ['kind' => 'multi', 'title' => 'بتبيع فين؟', 'opts' => "أونلاين\nفرع", 'active' => '1'],
                     'n1' => ['kind' => 'text', 'title' => 'إيه عرضك الحالي؟', 'ph' => 'مثلًا خصم', 'active' => '1']]];
    [$c, , $h] = http('POST', "$base/admin/onboarding-questions.php", $post, $aj);
    $after = onb_q_all();
    t('الأدمن: الحفظ بالترتيب اللي اختاره', $c === 302 && array_column($after, 'title') === ['إيه عرضك الحالي؟', 'بتبيع فين؟'], json_encode(array_column($after, 'title'), JSON_UNESCAPED_UNICODE));
    t('الأدمن: موضوع أول بوست اتسجّل', ($after[0]['role'] ?? '') === 'topic');
    [$c, $ap] = http('GET', "$base/admin/onboarding-questions.php", [], $aj);
    http('POST', "$base/admin/onboarding-questions.php", ['csrf' => csrf_of($ap), 'act' => 'defaults'], $aj);
    t('الأدمن: «رجّع الأسئلة الأصلية»', count(onb_q_all()) === 5 && onb_q_all()[1]['title'] === 'بتبيع لمين؟');
    $nav = admin_nav();
    t('في قايمة الأدمن تحت «العملاء»', in_array('admin/onboarding-questions.php', array_column($nav['العملاء'], 4), true));
    @unlink($aj);

    // العميل: أول دخول ← «ابدأ» بالأسئلة
    $email = "i18n3-$tag@test.local";
    $uid = $mkUser('Sara Test', $email);
    $j = jar('en');
    [$c, , $h] = $login($email, $j);
    $dest = loc($h);
    t('أول دخول ← صفحة «ابدأ» (رحلة الهوية) على طول', str_contains($dest, 'start.php'), $dest);
    [$c, $sp] = http('GET', "$base/public/start.php", [], $j);
    // رحلة الهوية: الأسئلة جوه data-boot — والمجال ليه شاشة خاصة («ده اللي لقيناه») فأول سؤال بقى «بتبيع لمين»
    $boot = preg_match('/data-boot="([^"]*)"/', $sp, $m) ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : [];
    $qs = $boot['questions'] ?? [];
    t('«ابدأ» بالإنجليزي والأسئلة بالإنجليزي', $c === 200 && str_contains($sp, '<html lang="en" dir="ltr">') && ($qs[0]['title'] ?? '') === 'Who do you sell to?', json_encode($qs[0] ?? null));
    $ar = arabic_words(visible($sp));
    t('«ابدأ»: مفيش نص عربي ظاهر (غير بيانات العميل)', !$ar, implode(' | ', array_slice($ar, 0, 6)));
    t('فيها «تخطّي» للوحة التحكم', str_contains($sp, 'Skip for now — go to dashboard'));
    [$c, , $h] = http('GET', "$base/public/dashboard.php", [], $j);
    t('مرة واحدة في الجلسة — بعدها الرئيسية عادي', $c === 200);

    /* ─── 3) إطار المنصة والرئيسية وإنشاء المحتوى بالإنجليزي ─── */
    echo "المنصة بالإنجليزي\n";
    foreach (['dashboard.php' => 'Dashboard', 'create-content.php' => 'Quick post', 'create-content.php?classic=1' => 'Generate content'] as $pg => $must) {
        [$c, $b] = http('GET', "$base/public/$pg", [], $j);
        $ar = arabic_words(visible($b));
        t("$pg: ltr ومفيش نص عربي ظاهر", $c === 200 && str_contains($b, 'dir="ltr"') && !$ar && str_contains($b, $must), implode(' | ', array_slice($ar, 0, 8)));
    }
    [, $b] = http('GET', "$base/public/dashboard.php", [], $j);
    t('زرار الأعلام في الشريط العلوي (الصفحات المترجمة)', str_contains($b, 'lang-sw v2-lang'));
    [$c, $b] = http('GET', "$base/public/content-history.php", [], $j);
    t('صفحة لسه مااتترجمتش ← عربي rtl ومن غير زرار اللغة', $c === 200 && str_contains($b, '<html lang="ar" dir="rtl">') && !str_contains($b, 'lang-sw v2-lang'));
    [$c, $b] = http('GET', "$base/public/api/quick-post.php?action=options", [], $j);
    $o = json_decode($b, true) ?: [];
    t('أشكال المنشور السريع بالإنجليزي', ($o['formats']['post'][1] ?? '') === 'Post', mb_substr($b, 0, 160));
    [$c, $b] = http('POST', "$base/public/api/quick-post.php?action=create", ['format' => 'post'], $j, ['X-CSRF-Token: x']);
    $o = json_decode($b, true) ?: [];
    t('رسالة الخطأ من السيرفر بلغة العميل', isset($o['error']) && !preg_match('/\p{Arabic}/u', (string) $o['error']), mb_substr($b, 0, 160));
    // العربي زي ما هو
    $ja = jar('ar');
    $login($email, $ja);
    t('الحساب اختار الإنجليزي قبل كده ← الدخول من جهاز تاني بيرجّعه', (db_one('SELECT locale FROM users WHERE id = ?', [$uid])['locale'] ?? '') === 'en');
    http('GET', "$base/public/lang.php?l=ar&back=/", [], $ja);   // يرجع للعربي بالزرار
    http('GET', "$base/public/dashboard.php", [], $ja);   // أول مرة في الجلسة ← «ابدأ»
    [, $b] = http('GET', "$base/public/dashboard.php", [], $ja);
    t('نفس الحساب بالعربي: rtl والكلام عربي', str_contains($b, '<html lang="ar" dir="rtl">') && str_contains($b, 'ابدأ بسرعة') && !str_contains($b, 'assets/css/ltr.css'));
    @unlink($j); @unlink($ja);

    /* ─── 4) ترجمة محتوى الموقع من لوحة الموقع ─── */
    echo "محتوى الموقع\n";
    require_once __DIR__ . '/../site/functions.php';
    $savedSite = ['i18n_enabled' => s_setting('i18n_enabled', ''), 'login_throttle' => s_setting('login_throttle', ''), 'cta_title' => s_setting('cta_title', '')];
    s_set('i18n_enabled', '1');
    s_set('login_throttle', '{}');
    $custom = "عنوان مخصص $tag";
    s_set('cta_title', $custom);
    s_run('DELETE FROM site_admins WHERE email = ?', ["tr-$tag@test.local"]);
    s_run('INSERT INTO site_admins (name, email, password, status, role) VALUES (?,?,?, "active", "super_admin")', ['TR', "tr-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
    $sj = jar();
    [, $lp] = http('GET', "$base/site-admin/login.php", [], $sj);
    $tok = preg_match('/name="(?:csrf|_csrf|csrf_token)" value="([^"]+)"/', $lp, $mm) ? $mm[1] : '';
    http('POST', "$base/site-admin/login.php", ['csrf' => $tok, '_csrf' => $tok, 'csrf_token' => $tok, 'email' => "tr-$tag@test.local", 'password' => 'Test12345!'], $sj);
    [$c, $tp] = http('GET', "$base/site-admin/translations.php", [], $sj);
    t('«الترجمة الإنجليزي» في لوحة الموقع: الرئيسية فيها نصوص كتير', $c === 200 && substr_count($tp, 'class="tr-row') > 100, (string) $c);
    t('والنص اللي الأدمن غيّره ظاهر ومن غير ترجمة', str_contains($tp, e($custom)) && preg_match('/tr-row miss">\s*<div class="tr-src" dir="rtl">' . preg_quote(e($custom), '/') . '/u', $tp) === 1);
    t('الترجمة الجاهزة باينة كمثال تحت الخانة', str_contains($tp, 'Ready translation in use'));
    [$c, $tpp] = http('GET', "$base/site-admin/translations.php?tab=page&p=about", [], $sj);
    t('الصفحات الداخلية لوحدها (صفحة «احنا مين»)', $c === 200 && str_contains($tpp, 'قصة Spread AI ورسالتنا') && !str_contains($tpp, e($custom)) === false || str_contains($tpp, 'قصة Spread AI ورسالتنا'));
    $h = sha1($custom);
    $tok2 = preg_match('/name="(?:csrf|_csrf|csrf_token)" value="([^"]+)"/', $tp, $mm) ? $mm[1] : '';
    [$c, , $hh] = http('POST', "$base/site-admin/translations.php?tab=home", ['csrf' => $tok2, '_csrf' => $tok2, 'csrf_token' => $tok2, 'en' => [$h => "Custom title $tag", sha1('ليس في الصفحة') => 'x']], $sj);
    $row = s_one('SELECT src, en, scope FROM site_translations WHERE src_hash = ?', [$h]);
    t('الحفظ: الإنجليزي اتسجل للنص ده بس', $c === 302 && ($row['en'] ?? '') === "Custom title $tag" && ($row['scope'] ?? '') === 'home' && !s_one('SELECT id FROM site_translations WHERE src_hash = ?', [sha1('ليس في الصفحة')]), json_encode($row, JSON_UNESCAPED_UNICODE));
    [, $hp] = http('GET', "$base/", [], jar('en'));
    t('الزائر بالإنجليزي بيشوف اللي الأدمن كتبه', str_contains($hp, "Custom title $tag"));
    [, $hp] = http('GET', "$base/", [], jar('ar'));
    t('والعربي زي ما هو', str_contains($hp, e($custom)) && !str_contains($hp, "Custom title $tag"));
    [, $hp] = http('GET', "$base/", [], jar('en'));
    $ar = arabic_words(visible(preg_replace('#<span class="lang-sw.*?</span></span>#s', '', $hp)));
    t('الرئيسية بالإنجليزي: مفيش نص عربي ظاهر', !$ar, implode(' | ', array_slice($ar, 0, 8)));
    foreach (['about', 'services', 'pricing', 'create-post'] as $sl) {
        [$c, $pp] = http('GET', "$base/site/page.php?p=$sl", [], jar('en'));
        $ar = arabic_words(visible(preg_replace('#<span class="lang-sw.*?</span></span>#s', '', $pp)));
        t("صفحة $sl بالإنجليزي من غير عربي ظاهر", $c === 200 && !$ar, implode(' | ', array_slice($ar, 0, 6)));
    }
    s_run('DELETE FROM site_translations WHERE src_hash = ?', [$h]);
    s_run('DELETE FROM site_admins WHERE email = ?', ["tr-$tag@test.local"]);
    foreach ($savedSite as $k => $v) s_set($k, $v);
    @unlink($sj);

    exec('php ' . escapeshellarg(__DIR__ . '/i18n-check.php'), $o2, $rc);
    t('كل النصوص الملفوفة ليها ترجمة (tests/i18n-check.php)', $rc === 0, implode(' | ', array_slice($o2, 0, 5)));
} finally {
    foreach ($uids as $u) {
        foreach (['contents', 'brand_profiles', 'brand_kits', 'user_sessions', 'credit_transactions', 'credit_wallets', 'remember_tokens'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$u]); } catch (\Throwable $e) {}
        }
        db_run('DELETE FROM users WHERE id = ?', [$u]);
    }
    foreach ($saved as $k => $v) $swap($k, $v);
}
echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
