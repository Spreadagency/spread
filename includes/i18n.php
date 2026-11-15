<?php
/**
 * Spread AI — الترجمة (المرحلة 2): واجهة بالعربي أو بالإنجليزي
 *
 * المفتاح هو النص العربي نفسه:   <?= e(__('تسجيل الدخول')) ?>   ←  «تسجيل الدخول» أو «Log in»
 *   - العربي بيفضل زي ما هو بالحرف (هو المصدر) — مفيش ملف للعربي
 *   - الإنجليزي في lang/en.php (+ lang/en/*.php) ← [النص العربي => English]
 *   - أي نص مالوش ترجمة لسه بيظهر بالعربي — مفيش حاجة بتبوظ وإحنا بنترجم على دفعات
 *   - متغيرات: __('أهلًا {name}', ['name' => $n])
 *
 * اللغة: ?lang=en|ar (بتتحفظ) ← حساب العميل (users.locale) ← الكوكي spread_lang ← العربي.
 * الملف ده مستقل (مالوش دعوة بقاعدة البيانات) علشان المنصة والموقع الاتنين يستخدموه.
 */

const I18N_LOCALES = [
    'ar' => ['dir' => 'rtl', 'label' => 'العربية', 'short' => 'ع'],
    'en' => ['dir' => 'ltr', 'label' => 'English', 'short' => 'EN'],
];
const I18N_DEFAULT = 'ar';
const I18N_COOKIE = 'spread_lang';

/** زرار «ع | EN» ظاهر؟ (المنصة: إعداد i18n_enabled · الموقع: نفس الإعداد في إعدادات الموقع) — اللغة نفسها بتشتغل بالكوكي في الحالتين */
function i18n_enabled(): bool
{
    static $on = null;
    if ($on !== null) return $on;
    try {
        if (function_exists('get_setting')) return $on = get_setting('i18n_enabled', '0') === '1';
        if (function_exists('s_setting')) return $on = s_setting('i18n_enabled', '0') === '1';
        if (function_exists('db_one')) return $on = (string) (db_one('SELECT setting_value FROM settings WHERE setting_key = "i18n_enabled" ORDER BY id DESC LIMIT 1')['setting_value'] ?? '0') === '1';
    } catch (\Throwable $e) {
    }
    return $on = false;
}

function i18n_valid(?string $l): bool
{
    return is_string($l) && isset(I18N_LOCALES[$l]);
}

/** اللغة الحالية للطلب ده */
function i18n_locale(): string
{
    if (isset($GLOBALS['__i18n_locale'])) return $GLOBALS['__i18n_locale'];
    $l = null;
    // ?lang= في اللينك (مثلًا لينك مشاركة بالإنجليزي) ← بتتحفظ
    $q = $_GET['lang'] ?? null;
    if (i18n_valid($q)) {
        $l = $q;
        i18n_remember($l);
    }
    if ($l === null && isset($GLOBALS['__i18n_user_locale']) && i18n_valid($GLOBALS['__i18n_user_locale'])) $l = $GLOBALS['__i18n_user_locale'];
    if ($l === null && i18n_valid($_COOKIE[I18N_COOKIE] ?? null)) $l = $_COOKIE[I18N_COOKIE];
    return $GLOBALS['__i18n_locale'] = $l ?? I18N_DEFAULT;
}

/** تثبيت اللغة للطلب ده (بعد الدخول من حساب العميل · الإيميلات والرسائل للعميل بلغته) */
function i18n_set(string $l): void
{
    if (i18n_valid($l)) $GLOBALS['__i18n_locale'] = $l;
}

/** الكوكي (سنة · على الدومين كله — الموقع والمنصة) */
function i18n_remember(string $l): void
{
    if (!i18n_valid($l) || headers_sent() || PHP_SAPI === 'cli') return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(I18N_COOKIE, $l, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => false, 'samesite' => 'Lax']);
    $_COOKIE[I18N_COOKIE] = $l;
}

function i18n_is_en(): bool
{
    return i18n_locale() === 'en';
}

function i18n_dir(): string
{
    return I18N_LOCALES[i18n_locale()]['dir'];
}

function i18n_html_attrs(): string
{
    return 'lang="' . i18n_locale() . '" dir="' . i18n_dir() . '"';
}

/** القاموس الإنجليزي: lang/en.php + كل ملفات lang/en/*.php (كل دفعة ترجمة في ملف) */
function i18n_dict(string $l = 'en'): array
{
    static $cache = [];
    if (isset($cache[$l])) return $cache[$l];
    $d = [];
    $root = dirname(__DIR__) . '/lang';
    $files = array_merge(is_file("$root/$l.php") ? ["$root/$l.php"] : [], glob("$root/$l/*.php") ?: []);
    foreach ($files as $f) {
        $part = include $f;
        if (is_array($part)) $d = $part + $d;
    }
    return $cache[$l] = $d;
}

/** ترجمة نص عربي للغة الحالية (أو $l) — مافيش ترجمة ← العربي زي ما هو */
function __(string $ar, array $vars = [], ?string $l = null): string
{
    $l ??= i18n_locale();
    $s = $l === 'ar' ? $ar : (i18n_dict($l)[$ar] ?? $ar);
    if ($vars) {
        $rep = [];
        foreach ($vars as $k => $v) $rep['{' . $k . '}'] = (string) $v;
        $s = strtr($s, $rep);
    }
    return $s;
}

/** echo + escape */
function _e(string $ar, array $vars = []): void
{
    echo htmlspecialchars(__($ar, $vars), ENT_QUOTES, 'UTF-8');
}

/** نص بحسب اللغة لقيمة جاية من الإعدادات: القيمة الإنجليزية لو اتكتبت ← ترجمة القيمة العربية ← العربي */
function __v(string $arValue, string $enValue = ''): string
{
    if (!i18n_is_en()) return $arValue;
    return trim($enValue) !== '' ? $enValue : __($arValue);
}

/** نصوص الـ JavaScript: window.I18N + __t() — بس النصوص المطلوبة (مش القاموس كله) */
function i18n_js(array $keys = []): string
{
    $map = [];
    if (i18n_is_en()) {
        $d = i18n_dict('en');
        foreach ($keys as $k) if (isset($d[$k])) $map[$k] = $d[$k];
    }
    return '<script>window.I18N=Object.assign(window.I18N||{},' . json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ');'
        . 'window.SPREAD_LANG=' . json_encode(i18n_locale()) . ';'
        . 'window.__t=window.__t||function(s,v){var o=(window.I18N&&window.I18N[s])||s;if(v)for(var k in v)o=o.split("{"+k+"}").join(v[k]);return o;};</script>';
}

/**
 * لينك تغيير اللغة: يرجّع لنفس الصفحة (مسار محلي بس)
 * $endpoint = مسار lang.php (المنصة: url('lang.php') · الموقع: SITE_BASE . '/lang.php' عن طريق المنصة)
 */
function i18n_switch_url(string $endpoint, string $to, ?string $back = null): string
{
    $back ??= (string) ($_SERVER['REQUEST_URI'] ?? '/');
    return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query(['l' => $to, 'back' => i18n_safe_back($back)]);
}

/** مسار الرجوع بعد تغيير اللغة: محلي بس (مافيش لينكات لبرّه) ومن غير ?lang= القديم */
function i18n_safe_back(string $back): string
{
    $back = trim($back);
    if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || str_contains($back, '\\') || preg_match('/[\x00-\x1F]/', $back)) return '/';
    $p = parse_url($back);
    if ($p === false || isset($p['host']) || isset($p['scheme'])) return '/';
    $path = $p['path'] ?? '/';
    $q = [];
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        unset($q['lang']);
    }
    return $path . ($q ? '?' . http_build_query($q) : '') . (isset($p['fragment']) ? '#' . rawurlencode(rawurldecode($p['fragment'])) : '');
}

/** زرار ع | EN (HTML) — $endpoint زي i18n_switch_url */
function i18n_switcher(string $endpoint, string $class = 'lang-sw'): string
{
    $cur = i18n_locale();
    $to = $cur === 'en' ? 'ar' : 'en';
    $label = I18N_LOCALES[$to]['short'];
    return '<a class="' . htmlspecialchars($class, ENT_QUOTES) . '" href="' . htmlspecialchars(i18n_switch_url($endpoint, $to), ENT_QUOTES) . '" hreflang="' . $to . '" lang="' . $to . '"'
        . ' title="' . htmlspecialchars(I18N_LOCALES[$to]['label'], ENT_QUOTES) . '" data-lang="' . $to . '">' . $label . '</a>';
}
