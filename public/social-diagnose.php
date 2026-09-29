<?php
/**
 * Spread AI v2 — فحص شامل لوحدة الربط والنشر
 * افتح الصفحة دي وانت مسجّل دخول عشان تعرف بالظبط إيه اللي واقف
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/social.php';

require_login();
$user = current_user();

// إصلاح تلقائي عند الطلب
$fixed = [];
if (($_GET['fix'] ?? '') === '1') {
    $fixed = social_ensure_schema();
}

$rows = [];
$fail = 0;
$warn = 0;

function chk(string $label, $ok, string $detail = '', bool $warnOnly = false): void
{
    global $rows, $fail, $warn;
    $state = $ok ? 'ok' : ($warnOnly ? 'warn' : 'fail');
    if (!$ok) {
        $warnOnly ? $warn++ : $fail++;
    }
    $rows[] = ['label' => $label, 'state' => $state, 'detail' => $detail];
}

/* ─── 1) البيئة ─── */
chk('نسخة PHP 8.0+', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);
chk('امتداد cURL', function_exists('curl_init'), 'مطلوب للاتصال بفيسبوك');
chk('امتداد OpenSSL', function_exists('openssl_encrypt'), 'مطلوب لتشفير التوكنات');
chk('امتداد mbstring', function_exists('mb_substr'), 'مطلوب للنصوص العربية');
chk('الجلسات شغالة', session_status() === PHP_SESSION_ACTIVE, 'session_id: ' . (session_id() ?: 'مفيش'));

// كتابة الجلسة فعليًا
$_SESSION['__diag_probe'] = 'ok';
chk('الجلسة بتحفظ قيم', ($_SESSION['__diag_probe'] ?? '') === 'ok', 'لو فشلت، الـ state هيضيع بين الخطوتين');

/* ─── 2) التشفير ─── */
$keyOk = defined('ENCRYPTION_KEY') && strlen((string) constant('ENCRYPTION_KEY')) >= 64;
chk('ENCRYPTION_KEY موجود', $keyOk, $keyOk ? 'طوله ' . strlen((string) constant('ENCRYPTION_KEY')) . ' حرف' : 'ناقص في includes/config.php — ولّده: php -r "echo bin2hex(random_bytes(32));"');

$encOk = false;
$encMsg = '';
try {
    $t = social_token_encrypt('probe-value-123');
    $encOk = social_token_decrypt($t) === 'probe-value-123';
    $encMsg = $encOk ? 'تشفير وفك تشفير سليم' : 'فك التشفير رجّع قيمة مختلفة';
} catch (\Throwable $e) {
    $encMsg = $e->getMessage();
}
chk('تشفير/فك تشفير التوكنات', $encOk, $encMsg);

/* ─── 3) قاعدة البيانات ─── */
$schema = social_schema_status();
chk('جداول الوحدة كاملة', $schema['ok'],
    $schema['ok'] ? 'كل الجداول والأعمدة موجودة'
    : 'ناقص: ' . implode('، ', array_merge($schema['tables'], $schema['columns'])) . ' — اضغط «إصلاح تلقائي» تحت');

// اختبار كتابة فعلي
$writeOk = false;
$writeMsg = '';
if (!$schema['tables']) {
    try {
        db_run('INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, page_avatar_url, access_token_enc, status)
                VALUES (?, "facebook", ?, ?, ?, ?, "error")',
            [$user['id'], '__diag_' . time(), 'اختبار كتابة', 'https://scontent.xx.fbcdn.net/v/' . str_repeat('x', 850), 'diag']);
        db_run('DELETE FROM social_connections WHERE provider_page_id LIKE "__diag_%"');
        $writeOk = true;
        $writeMsg = 'الحفظ شغال — بما فيه رابط صورة طويل (880 حرف)';
    } catch (\Throwable $e) {
        $writeMsg = $e->getMessage();
        if (str_contains($e->getMessage(), '1406') || str_contains($e->getMessage(), 'Data too long')) {
            $writeMsg .= ' — اضغط «إصلاح تلقائي» تحت عشان يوسّع الأعمدة';
        }
    }
} else {
    $writeMsg = 'الجدول نفسه ناقص';
}

// طول عمود رابط الصورة (سبب شائع لفشل الربط)
$avatarLen = social_column_limit('social_connections', 'page_avatar_url', 0);
chk('طول عمود رابط صورة الصفحة', $avatarLen >= 1000,
    $avatarLen . ' حرف' . ($avatarLen >= 1000 ? ' (كافي)' : ' — قليل! روابط فيسبوك بتوصل 600+ حرف. اضغط «إصلاح تلقائي»'));
chk('الكتابة في جدول الاتصالات', $writeOk, $writeMsg);

/* ─── 4) إعدادات ميتا ─── */
chk('App ID مضبوط', meta_app_id() !== '', meta_app_id() ?: 'فاضي — الأدمن ← التكاملات');
$secretOk = meta_app_secret() !== '';
chk('App Secret مضبوط ويتفك', $secretOk, $secretOk ? 'طوله ' . strlen(meta_app_secret()) . ' حرف' : 'فاضي أو فشل فك تشفيره');
chk('رابط Callback', true, social_callback_url() . ' ← لازم يكون مطابق حرفيًا في إعدادات Facebook Login', true);
chk('رابط الموقع HTTPS', str_starts_with(strtolower((string) APP_URL), 'https://'), (string) APP_URL . ' — فيسبوك بيرفض redirect_uri غير HTTPS', !str_starts_with(strtolower((string) APP_URL), 'https://'));

/* ─── 5) الصلاحية ─── */
$allowed = feature_allows((int) $user['id']);
chk('الميزة مفعّلة لحسابك', $allowed, $allowed ? 'حد الصفحات: ' . feature_max_pages((int) $user['id']) : 'الأدمن ← صلاحيات النشر ← فعّلها لحسابك');

/* ─── 6) الاتصال بفيسبوك ─── */
$pingOk = false;
$pingMsg = '';
if (function_exists('curl_init')) {
    $ch = curl_init(graph_base());
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_NOBODY => true]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $pingOk = $code > 0;
    $pingMsg = $pingOk ? 'وصل لـ ' . graph_base() . ' (HTTP ' . $code . ')' : 'فشل: ' . $err . ' — السيرفر مش قادر يخرج نت';
}
chk('الاتصال بسيرفرات فيسبوك', $pingOk, $pingMsg);

/* ─── 7) اللوج ─── */
$logFile = STORAGE_PATH . '/logs/social-errors.log';
$logLines = [];
if (is_file($logFile)) {
    $all = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $logLines = array_slice($all, -12);
}
chk('مجلد التخزين قابل للكتابة', is_writable(STORAGE_PATH), STORAGE_PATH);

$page_title = 'فحص الربط والنشر';
$active = 'social-accounts';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>فحص الربط والنشر 🩺</h1>
            <div class="sub">
                <?php if ($fail === 0): ?>
                    ✅ كل الفحوصات عدّت — الربط المفروض يشتغل
                <?php else: ?>
                    ❌ فيه <?= $fail ?> مشكلة لازم تتحل — التفاصيل تحت
                <?php endif; ?>
            </div>
        </div>

        <?php if ($fixed): ?>
            <div class="card" style="background:var(--mint-soft,#eefaf4);margin-bottom:16px">
                <b>✓ تم الإصلاح التلقائي:</b>
                <ul style="margin:8px 22px 0"><?php foreach ($fixed as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width:820px">
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th style="width:34px"></th><th>الفحص</th><th>التفاصيل</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="font-size:17px"><?= $r['state'] === 'ok' ? '✅' : ($r['state'] === 'warn' ? '⚠️' : '❌') ?></td>
                        <td><b><?= e($r['label']) ?></b></td>
                        <td class="sub" style="font-size:12.5px;overflow-wrap:anywhere"><?= e($r['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
                <?php if (!$schema['ok'] || $fail > 0 || $avatarLen < 1000): ?>
                    <a href="?fix=1" class="btn">🔧 إصلاح تلقائي (إنشاء الناقص + توسيع الأعمدة)</a>
                <?php endif; ?>
                <a href="<?= url('social/connect.php') ?>" class="btn ghost">📘 جرّب الربط</a>
                <a href="<?= url('social-accounts.php') ?>" class="btn ghost">رجوع</a>
            </div>
        </div>

        <div class="card" style="max-width:820px;margin-top:16px">
            <div class="card-head"><h3>📄 آخر الأخطاء المسجّلة</h3></div>
            <?php if (!$logLines): ?>
                <p class="sub">مفيش أخطاء مسجّلة — تمام 👌</p>
            <?php else: ?>
                <pre dir="ltr" style="background:var(--surface-2);padding:12px;border-radius:10px;font-size:11.5px;overflow-x:auto;text-align:left;line-height:1.8"><?php
                    foreach ($logLines as $l) { echo e($l) . "\n"; }
                ?></pre>
                <p class="sub" style="font-size:12px">الملف: <code>storage/logs/social-errors.log</code></p>
            <?php endif; ?>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
