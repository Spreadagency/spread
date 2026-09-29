<?php
/**
 * فحص: هل mod_security بيمنع رجوع جوجل؟
 *
 * افتح الصفحة دي واضغط الزرار. الزرار بيفتح نفس الصفحة
 * بنفس البارامترات اللي جوجل بيبعتها بالظبط.
 *   ✅ الصفحة فتحت  → المسار سليم والدخول بجوجل هيشتغل
 *   ❌ طلع 403      → mod_security لسه مانع — شوف الحل تحت
 *
 * ⚠️ امسح الملف ده بعد ما تخلص.
 */
$isTest = isset($_GET['scope']);
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['SCRIPT_NAME'] ?? '');

// نفس شكل رد جوجل بالظبط
$sim = $base . '?' . http_build_query([
    'state'  => bin2hex(random_bytes(24)),
    'iss'    => 'https://accounts.google.com',
    'code'   => '4/0ATsMZqCuqjMuazPUHbaXZ6ufKHKHAZsIpCKBjDWTIZXcfEWtdORkFnzbNw2BFJDO3l2j3Q',
    'scope'  => 'email profile https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email openid',
    'authuser' => '1',
    'prompt' => 'consent',
]);
?><!DOCTYPE html><html lang="ar" dir="rtl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>فحص mod_security</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F2EC;
     font-family:system-ui,'Segoe UI',Tahoma,sans-serif;padding:20px;line-height:1.9;color:#0B0B0F}
.box{background:#fff;border-radius:18px;padding:28px;max-width:640px;width:100%;
     box-shadow:0 16px 44px -28px rgba(0,0,0,.32)}
h1{font-size:20px;margin:0 0 6px}
.ok{background:#e8f7f1;color:#0f7a5f;padding:14px 17px;border-radius:12px;font-weight:700;margin-bottom:16px}
.btn{display:inline-block;background:#0F3CC9;color:#fff;padding:12px 22px;border-radius:11px;
     text-decoration:none;font-weight:600;margin-top:6px}
code{background:#f2f0ec;padding:2px 7px;border-radius:5px;font-size:12.5px;word-break:break-all}
ol{margin:10px 20px;padding:0}li{margin-bottom:9px}
.sub{color:#6B6862;font-size:13.5px}
</style></head><body><div class="box">

<?php if ($isTest): ?>
    <div class="ok">✅ الفحص عدّى — mod_security مش مانع المسار ده</div>
    <p>الصفحة فتحت ومعاها نفس البارامترات اللي جوجل بيبعتها بالظبط:</p>
    <ul class="sub">
        <li>عدد الروابط الخارجية في الـ scope: <b><?= substr_count((string) $_GET['scope'], 'https://') ?></b></li>
        <li>الـ code فيه سلاش: <b><?= str_contains((string) ($_GET['code'] ?? ''), '/') ? 'أيوه' : 'لأ' ?></b></li>
        <li>طول الـ query: <b><?= strlen((string) ($_SERVER['QUERY_STRING'] ?? '')) ?></b> حرف</li>
    </ul>
    <p style="margin-top:16px"><b>يبقى الدخول بجوجل المفروض يشتغل دلوقتي.</b> جرّبه — ولو لسه بيقع، المشكلة مش mod_security.</p>
    <p class="sub">🗑 امسح ملف <code>ms-test.php</code> دلوقتي.</p>

<?php else: ?>
    <h1>🔍 فحص mod_security لمسار جوجل</h1>
    <p class="sub">الزرار ده هيفتح نفس الصفحة بنفس البارامترات اللي جوجل بيبعتها بالظبط.</p>
    <a class="btn" href="<?= htmlspecialchars($sim, ENT_QUOTES) ?>">▶ ابدأ الفحص</a>

    <hr style="margin:22px 0;border:0;border-top:1px solid #eee">
    <p><b>لو طلع Error 403:</b> يبقى mod_security هو السبب. الحل بالترتيب:</p>
    <ol>
        <li>تأكد إن ملف <code>.htaccess</code> موجود جوه مجلد <code>public/auth/</code></li>
        <li>من <b>cPanel ← ModSecurity</b> اقفله <b>للدومين ده بس</b> ودوس فحص تاني</li>
        <li>لو لسه، كلّم الاستضافة وقولهم بالنص:
            <br><span class="sub">«القاعدة 931130 بتمنع رد OAuth من جوجل على مسار
            <code>/auth/google-callback.php</code> — ممكن تستثنوا المسار ده؟»</span></li>
    </ol>
    <p class="sub" style="margin-top:14px">لو الصفحة فتحت عادي ومن غير 403 → المشكلة مش من mod_security.</p>
<?php endif; ?>

</div></body></html>
