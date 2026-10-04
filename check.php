<?php
/**
 * Spread AI v2 — أداة التشخيص
 * افتحها من المتصفح: https://your-site.com/check.php
 * ⚠️ امسح الملف ده بعد ما تخلص فحص — فيه معلومات عن السيرفر
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

function row(string $name, bool $ok, string $detail = ''): void
{
    echo '<tr><td>' . htmlspecialchars($name) . '</td><td style="font-size:18px">' . ($ok ? '✅' : '❌') . '</td><td>' . htmlspecialchars($detail) . '</td></tr>';
}

echo '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>تشخيص Spread AI</title>
<style>body{font-family:Tahoma,Arial;background:#f6f5fb;padding:24px;color:#222}h1{font-size:22px}h2{font-size:16px;margin-top:26px;color:#4a3db8}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px #0001}
td{padding:9px 12px;border-bottom:1px solid #eee;font-size:14px}tr td:first-child{font-weight:bold;width:34%}</style></head><body>';
echo '<h1>🔍 تشخيص Spread AI v2</h1>';

// ─── 1) PHP ───
echo '<h2>1) بيئة PHP</h2><table>';
row('إصدار PHP (مطلوب 8.0+)', PHP_VERSION_ID >= 80000, PHP_VERSION);
foreach (['pdo_mysql' => 'قاعدة البيانات', 'curl' => 'استدعاءات الـ AI', 'gd' => 'العلامة المائية', 'zip' => 'قراءة ملفات Word', 'openssl' => 'تشفير المفاتيح', 'mbstring' => 'النصوص العربية', 'fileinfo' => 'فحص الملفات'] as $ext => $why) {
    row("امتداد {$ext} ({$why})", extension_loaded($ext), extension_loaded($ext) ? 'موجود' : 'مش مفعّل — فعّله من إعدادات PHP في الاستضافة');
}
echo '</table>';

// ─── 2) الملفات ───
echo '<h2>2) ملفات التحديث</h2><table>';
$files = [
    'includes/config.php', 'includes/smart-ai.php', 'includes/crypto.php', 'includes/source-extract.php',
    'includes/plan-functions.php', 'includes/watermark.php', 'includes/credits.php',
    'services/AIService.php', 'services/SmartRouter.php', 'services/ProviderFactory.php',
    'services/Adapters/OpenRouterAdapter.php', 'services/Adapters/OpenAIAdapter.php', 'services/Adapters/GeminiAdapter.php',
    'services/Contracts/AIProviderContract.php',
    'public/content-plan.php', 'public/plan-view.php', 'public/studio.php', 'public/sources.php',
    'admin/ai-management.php', 'admin/approvals.php', 'admin/watermark.php', 'admin/media-library.php', 'admin/user-brand.php',
    'assets/js/main.js',
];
$missing = 0;
foreach ($files as $f) {
    $ok = is_file(__DIR__ . '/' . $f);
    if (!$ok) $missing++;
    row($f, $ok, $ok ? '' : '⚠️ الملف مش مرفوع — ارفعه من الحزمة');
}
echo '</table>';
if ($missing) {
    echo '<p style="background:#fde8e8;padding:12px;border-radius:8px">❌ <b>' . $missing . ' ملف ناقص</b> — ده غالبًا سبب المشكلة. ارفع الحزمة كاملة بما فيها مجلد <b>services/</b> في نفس مستوى includes/.</p>';
}

// ─── 3) config ───
echo '<h2>3) الإعدادات</h2><table>';
$configOk = is_file(__DIR__ . '/includes/config.php');
if ($configOk) {
    require_once __DIR__ . '/includes/config.php';
    row('ENCRYPTION_KEY معرّف', defined('ENCRYPTION_KEY') && strlen((string) constant('ENCRYPTION_KEY')) >= 64, defined('ENCRYPTION_KEY') ? 'موجود' : 'ناقص — انسخ config.php من الحزمة أو أضف المفتاح');
    row('APP_URL', defined('APP_URL'), defined('APP_URL') ? constant('APP_URL') : '');
}
echo '</table>';

// ─── 4) قاعدة البيانات ───
echo '<h2>4) قاعدة البيانات</h2><table>';
$dbOk = false;
try {
    require_once __DIR__ . '/includes/db.php';
    db()->query('SELECT 1');
    $dbOk = true;
    row('الاتصال بقاعدة البيانات', true, DB_NAME);
} catch (Throwable $e) {
    row('الاتصال بقاعدة البيانات', false, 'فشل: راجع بيانات DB في config.php — ' . $e->getMessage());
}

if ($dbOk) {
    $tables = ['ai_providers', 'ai_models', 'smart_routing_rules', 'ai_job_logs', 'brand_sources',
               'content_plans', 'plan_ideas', 'media_library', 'user_media_selections'];
    foreach ($tables as $t) {
        $exists = (bool) db()->query("SHOW TABLES LIKE '{$t}'")->fetch();
        row("جدول {$t}", $exists, $exists ? '' : '⚠️ نفّذ sql/upgrade-all-phases.sql');
    }
    $cols = [
        ['contents', 'design_direction', 'فكرة التصميم'],
        ['users', 'approval_status', 'الموافقات'],
        ['credit_wallets', 'expires_at', 'صلاحية الكريدت'],
    ];
    foreach ($cols as [$t, $c, $why]) {
        try {
            $exists = (bool) db()->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'")->fetch();
        } catch (Throwable $e) { $exists = false; }
        row("عمود {$t}.{$c} ({$why})", $exists, $exists ? '' : '⚠️ نفّذ sql/upgrade-all-phases.sql');
    }
    foreach (['use_smart_router', 'watermark_enabled', 'manual_approval', 'manual_pay_enabled'] as $k) {
        try {
            $v = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1');
            $v->execute([$k]);
            $r = $v->fetch();
            row("إعداد {$k}", (bool) $r, $r ? 'القيمة: ' . $r['setting_value'] : '⚠️ نفّذ SQL');
        } catch (Throwable $e) {
            row("إعداد {$k}", false, $e->getMessage());
        }
    }
    try {
        $c = (int) db()->query('SELECT COUNT(*) c FROM ai_models')->fetch()['c'];
        row('موديلات AI متسجلة', $c > 0, $c . ' موديل');
    } catch (Throwable $e) { row('موديلات AI', false, ''); }
}
echo '</table>';

// ─── 5) مجلدات الكتابة ───
echo '<h2>5) صلاحيات الكتابة</h2><table>';
foreach (['storage/uploads/designs', 'storage/uploads/sources', 'storage/uploads/library', 'storage/uploads/branding', 'storage/uploads/tmp', 'storage/logs'] as $d) {
    $abs = __DIR__ . '/' . $d;
    if (!is_dir($abs)) @mkdir($abs, 0755, true);
    row($d, is_dir($abs) && is_writable($abs), is_writable($abs) ? 'قابل للكتابة' : 'اضبط الصلاحيات 755');
}
echo '</table>';

// ─── 6) smart-ai bridge ───
echo '<h2>6) الـ Smart Router</h2><table>';
try {
    require_once __DIR__ . '/includes/credits.php';
    require_once __DIR__ . '/includes/smart-ai.php';
    row('تحميل طبقة الـ Router', true, defined('SMART_AI_MISSING') ? '⚠️ ملفات services ناقصة — شغال بالوضع القديم' : 'كامل');
    if (function_exists('smart_ai_enabled')) {
        row('حالة التفعيل', true, smart_ai_enabled() ? 'مفعّل ✓' : 'متوقف (فعّله من إدارة الـ AI بعد إضافة مفتاح API)');
    }
} catch (Throwable $e) {
    row('تحميل طبقة الـ Router', false, $e->getMessage());
}
echo '</table>';

echo '<p style="margin-top:24px;background:#fff8e1;padding:12px;border-radius:8px">⚠️ <b>مهم:</b> امسح ملف check.php من السيرفر بعد ما تخلص الفحص.</p>';
echo '</body></html>';
