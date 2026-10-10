<?php
/**
 * Spread AI — تشخيص الاتصال بقاعدة البيانات
 * ارفع الملف ده وافتحه في المتصفح — بيقولك بالظبط إيه المشكلة.
 * ⚠️ امسحه بعد ما تخلص.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$rows = [];
$fatal = [];

function row(string $label, bool $ok, string $detail = '', bool $warn = false): void
{
    global $rows;
    $rows[] = ['l' => $label, 's' => $ok ? 'ok' : ($warn ? 'warn' : 'no'), 'd' => $detail];
}

/* ─── 1) ملفات الإعداد ─── */
$cfgPlat = __DIR__ . '/includes/config.php';
$cfgSite = __DIR__ . '/site/config.php';

row('ملف إعدادات المنصة includes/config.php', is_file($cfgPlat), is_file($cfgPlat) ? 'موجود' : 'ناقص! لازم ترفعه');
row('ملف إعدادات الموقع site/config.php', is_file($cfgSite), is_file($cfgSite) ? 'موجود' : 'الموقع التعريفي مش متركّب (عادي لو مش عايزه)', !is_file($cfgSite));

if (!is_file($cfgPlat)) {
    $fatal[] = 'ملف includes/config.php مش موجود — ارفعه.';
}

/* ─── 2) قراءة بيانات المنصة بدون تحميل الملف ─── */
$plat = ['host' => '', 'name' => '', 'user' => '', 'pass' => '', 'charset' => 'utf8mb4'];
if (is_file($cfgPlat)) {
    $src = (string) file_get_contents($cfgPlat);
    foreach ([['DB_HOST', 'host'], ['DB_NAME', 'name'], ['DB_USER', 'user'], ['DB_PASS', 'pass'], ['DB_CHARSET', 'charset']] as [$c, $k]) {
        if (preg_match("/define\(\s*'" . $c . "'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m)) {
            $plat[$k] = stripcslashes($m[1]);
        }
    }
    row('قراءة بيانات الاتصال من config', $plat['name'] !== '' && $plat['user'] !== '',
        'قاعدة: ' . ($plat['name'] ?: '؟') . ' · مستخدم: ' . ($plat['user'] ?: '؟')
        . ' · باسورد: ' . ($plat['pass'] !== '' ? 'موجود (' . strlen($plat['pass']) . ' حرف)' : '⚠ فاضي'));

    // مفتاح التشفير
    $hasKey = (bool) preg_match("/define\(\s*'ENCRYPTION_KEY'\s*,\s*'([0-9a-fA-F]{64,})'/", $src);
    row('ENCRYPTION_KEY موجود', $hasKey, $hasKey ? 'تمام' : 'ناقص — التوكنات والمفاتيح مش هتتحفظ', !$hasKey);
}

/* ─── 3) اختبار الاتصال الفعلي ─── */
$connOk = false;
if ($plat['name'] !== '') {
    // (أ) هل السيرفر نفسه شغال؟
    try {
        new PDO('mysql:host=' . ($plat['host'] ?: 'localhost'), $plat['user'], $plat['pass'], [PDO::ATTR_TIMEOUT => 6]);
        row('الوصول لسيرفر MySQL بالمستخدم', true, 'المستخدم والباسورد صح');
        $serverOk = true;
    } catch (PDOException $e) {
        $serverOk = false;
        $code = $e->getCode();
        $msg = $e->getMessage();
        $hint = 'خطأ غير معروف';
        if (str_contains($msg, '1045') || $code === 1045) {
            $hint = '🔴 اسم المستخدم أو الباسورد غلط — ده أشهر سبب. راجع cPanel ← MySQL Databases';
        } elseif (str_contains($msg, '2002') || str_contains($msg, 'refused')) {
            $hint = '🔴 السيرفر مش راد — جرب DB_HOST = 127.0.0.1 بدل localhost';
        }
        row('الوصول لسيرفر MySQL بالمستخدم', false, $hint . ' | ' . mb_substr($msg, 0, 120));
        $fatal[] = $hint;
    }

    // (ب) هل القاعدة نفسها موجودة ومسموح بيها؟
    if (!empty($serverOk)) {
        try {
            $pdo = new PDO(
                'mysql:host=' . ($plat['host'] ?: 'localhost') . ';dbname=' . $plat['name'] . ';charset=' . ($plat['charset'] ?: 'utf8mb4'),
                $plat['user'], $plat['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 6]
            );
            $connOk = true;
            row('الاتصال بقاعدة «' . $plat['name'] . '»', true, 'شغال ✓');

            $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
            row('عدد الجداول', $n > 10, $n . ' جدول' . ($n < 10 ? ' — قليل جدًا! نفّذ ملفات sql/' : ''));

            foreach (['users', 'contents', 'settings', 'credit_wallets'] as $t) {
                $ex = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$t}'")->fetchColumn();
                row('جدول ' . $t, $ex > 0, $ex ? 'موجود' : 'ناقص — نفّذ sql/schema.sql');
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $hint = mb_substr($msg, 0, 140);
            if (str_contains($msg, '1049')) {
                $hint = '🔴 القاعدة «' . $plat['name'] . '» مش موجودة أو المستخدم مش مربوط بيها في cPanel';
            }
            row('الاتصال بقاعدة «' . $plat['name'] . '»', false, $hint);
            $fatal[] = $hint;
        }
    }
}

/* ─── 4) قاعدة الموقع التعريفي (منفصلة) ─── */
if (is_file($cfgSite)) {
    $ssrc = (string) file_get_contents($cfgSite);
    $s = [];
    foreach ([['SITE_DB_HOST', 'host'], ['SITE_DB_NAME', 'name'], ['SITE_DB_USER', 'user'], ['SITE_DB_PASS', 'pass']] as [$c, $k]) {
        $s[$k] = preg_match("/define\(\s*'" . $c . "'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $ssrc, $m) ? stripcslashes($m[1]) : '';
    }
    $sameDb = $s['name'] !== '' && $s['name'] === $plat['name'];
    row('قاعدة الموقع منفصلة عن المنصة', !$sameDb,
        $sameDb ? '🔴 الاتنين على نفس القاعدة! لازم تفصلهم' : 'قاعدة الموقع: ' . ($s['name'] ?: 'غير مضبوطة'));
    if ($s['name'] !== '' && $s['pass'] !== 'CHANGE_ME') {
        try {
            new PDO('mysql:host=' . ($s['host'] ?: 'localhost') . ';dbname=' . $s['name'] . ';charset=utf8mb4', $s['user'], $s['pass'], [PDO::ATTR_TIMEOUT => 6]);
            row('الاتصال بقاعدة الموقع', true, 'شغال ✓');
        } catch (PDOException $e) {
            row('الاتصال بقاعدة الموقع', false, mb_substr($e->getMessage(), 0, 120) . ' (ده مش بيأثر على المنصة)', true);
        }
    } else {
        row('الاتصال بقاعدة الموقع', false, 'لسه مش متضبطة — عدّل site/config.php', true);
    }
}

/* ─── 5) البيئة ─── */
row('نسخة PHP', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);
row('امتداد PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'موجود' : '🔴 ناقص — كلّم الاستضافة');
foreach (['curl', 'mbstring', 'gd', 'openssl'] as $x) {
    row('امتداد ' . $x, extension_loaded($x), extension_loaded($x) ? 'موجود' : 'ناقص', $x === 'gd');
}
$st = __DIR__ . '/storage';
row('مجلد storage قابل للكتابة', is_dir($st) && is_writable($st), $st);

/* ─── 6) الصفحات ─── */
foreach (['public/login.php' => 'صفحة الدخول', 'public/register.php' => 'صفحة التسجيل', 'public/dashboard.php' => 'لوحة العميل', 'index.php' => 'الموقع التعريفي'] as $f => $lbl) {
    row('ملف ' . $lbl, is_file(__DIR__ . '/' . $f), $f, $f === 'index.php');
}

$okCount = count(array_filter($rows, fn($r) => $r['s'] === 'ok'));
$noCount = count(array_filter($rows, fn($r) => $r['s'] === 'no'));
?><!DOCTYPE html><html lang="ar" dir="rtl"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>تشخيص قاعدة البيانات</title>
<style>
body{margin:0;background:#F5F2EC;font-family:system-ui,'Segoe UI',Tahoma,sans-serif;padding:22px;line-height:1.85;color:#0B0B0F}
.box{max-width:840px;margin:0 auto;background:#fff;border-radius:18px;padding:26px;box-shadow:0 14px 40px -26px rgba(0,0,0,.35)}
h1{font-size:21px;margin:0 0 6px}
table{width:100%;border-collapse:collapse;font-size:14px;margin-top:14px}
td{padding:9px 6px;border-bottom:1px solid #eee;vertical-align:top}
td:first-child{width:32px;font-size:17px}
.d{color:#666;font-size:12.5px;overflow-wrap:anywhere}
.sum{padding:14px 16px;border-radius:12px;margin-bottom:14px;font-weight:600}
.bad{background:#fdecea;color:#a3312a}.good{background:#e8f7f1;color:#0f7a5f}
.fix{background:#fff8e6;border:1px solid #f0c36d;border-radius:12px;padding:14px 16px;margin-top:16px;font-size:13.5px}
code{background:#f2f0ec;padding:2px 6px;border-radius:5px;font-size:12.5px}
</style></head><body><div class="box">
<h1>🩺 تشخيص قاعدة البيانات</h1>
<p class="d">الملف ده بيفحص الاتصال ويقولك السبب بالظبط — <b>امسحه بعد ما تخلص</b>.</p>

<?php if ($noCount === 0): ?>
  <div class="sum good">✅ كل الفحوصات عدّت (<?= $okCount ?>) — الاتصال سليم.</div>
<?php else: ?>
  <div class="sum bad">🔴 فيه <?= $noCount ?> مشكلة — التفاصيل تحت.</div>
<?php endif; ?>

<table><?php foreach ($rows as $r): ?>
<tr>
  <td><?= $r['s'] === 'ok' ? '✅' : ($r['s'] === 'warn' ? '⚠️' : '❌') ?></td>
  <td><b><?= htmlspecialchars($r['l']) ?></b><div class="d"><?= htmlspecialchars($r['d']) ?></div></td>
</tr>
<?php endforeach; ?></table>

<?php if ($fatal): ?>
<div class="fix">
  <b>🔧 إزاي تصلّحها:</b>
  <ol style="margin:8px 20px 0;padding:0">
    <?php foreach (array_unique($fatal) as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
    <li>افتح <code>includes/config.php</code> وتأكد إن <code>DB_NAME</code> و<code>DB_USER</code> و<code>DB_PASS</code>
        مطابقين للي في <b>cPanel ← MySQL Databases</b> بالظبط.</li>
    <li>في cPanel أسماء القواعد والمستخدمين بيبدأوا باسم الحساب، مثال:
        <code>spreadagency_aicontent</code> و<code>spreadagency_ai</code>.</li>
    <li>تأكد إن المستخدم <b>مربوط بالقاعدة</b> ("Add User To Database") وليه <b>ALL PRIVILEGES</b>.</li>
  </ol>
</div>
<?php endif; ?>

<div class="fix" style="background:#eef4ff;border-color:#9db8ff">
  <b>💡 مهم جدًا:</b> ملف <code>includes/config.php</code> فيه بيانات قاعدة بياناتك.
  <b>متستبدلوش أبدًا</b> لما ترفع تحديثات — الحزم الجديدة بترفع <code>includes/config.sample.php</code> بدلًا منه.
</div>
</div></body></html>
