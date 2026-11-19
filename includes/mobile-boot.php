<?php
/**
 * Spread AI — بوابة تطبيق الموبايل (لازم تتحمّل قبل includes/config.php)
 *
 * التطبيق مابيستخدمش كوكيز: كل طلب فيه توكن الجهاز في الهيدر
 *   Authorization: Bearer spm_…     أو     X-Spread-Token: spm_…   (لو Apache شال Authorization)
 * الملف ده بيجهّز جلسة PHP قبل ما config.php يبدأها:
 *   • مفيش كوكيز خالص (لا بنقرا ولا بنبعت) — علشان الجلسة ماتختلطش بجلسة المتصفح
 *   • رقم الجلسة = HMAC(التوكن) ← نفس الجهاز = نفس الجلسة (والجهاز بيظهر في «الجلسات النشطة»)
 *   • طلب من غير توكن (دخول · تسجيل) ← جلسة مؤقتة بتتمسح في آخر الطلب
 * بعد كده includes/mobile.php بيتحقق من التوكن ويحط user_id في الجلسة — وباقي الكود القديم بيشتغل زي ما هو.
 */

if (!defined('SPREAD_MOBILE')) {
    define('SPREAD_MOBILE', true);
}

/** توكن الجهاز من الهيدر (أو null) */
function mobile_bearer(): ?string
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
        $tok = $m[1];
    } else {
        $tok = (string) ($_SERVER['HTTP_X_SPREAD_TOKEN'] ?? '');
    }
    return preg_match('/^sp[mc]_[A-Za-z0-9_\-]{40,80}$/', $tok) ? $tok : null;
}

/** رقم جلسة PHP الخاص بالتوكن (حروف وأرقام بس) */
function mobile_sid(string $token): string
{
    return 'spm' . substr(hash_hmac('sha256', $token, 'spread-mobile-session-v1'), 0, 48);
}

(function () {
    // التطبيق مش متصفح: مفيش كوكيز داخلة ولا طالعة
    $_COOKIE = [];
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_strict_mode', '0');   // رقم الجلسة جاي من التوكن — مش من المتصفح
        ini_set('session.cache_limiter', 'nocache');
        $tok = mobile_bearer();
        if ($tok !== null) {
            session_id(mobile_sid($tok));
            $GLOBALS['__mobile_ephemeral'] = false;
        } else {
            session_id(mobile_sid('eph' . bin2hex(random_bytes(24))));
            $GLOBALS['__mobile_ephemeral'] = true;
        }
    }
    // جلسة مؤقتة (من غير توكن) ← تتمسح في آخر الطلب علشان مايفضلش ملفات جلسات
    register_shutdown_function(function () {
        if (empty($GLOBALS['__mobile_ephemeral'])) return;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
    });
})();
