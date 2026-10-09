<?php
declare(strict_types=1);

/**
 * Server health check. Run from cPanel → Terminal:
 *   php install/check.php
 * Prints what is wrong (config, DB, folders, leftover files from older uploads).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$ok = static fn (string $m) => print("  ✔ $m\n");
$bad = static function (string $m, string $fix = '') {
    print("  ✘ $m\n" . ($fix ? "    → $fix\n" : ''));
    $GLOBALS['problems'] = ($GLOBALS['problems'] ?? 0) + 1;
};

echo "PHP\n";
version_compare(PHP_VERSION, '8.1', '>=') ? $ok('PHP ' . PHP_VERSION) : $bad('PHP ' . PHP_VERSION . ' (محتاج 8.1 أو أحدث)', 'cPanel → MultiPHP Manager');
foreach (['pdo_mysql', 'gd', 'curl', 'fileinfo', 'openssl', 'mbstring'] as $ext) {
    extension_loaded($ext) ? $ok("extension $ext") : $bad("extension $ext ناقصة", 'cPanel → Select PHP Version → Extensions');
}

echo "\nFiles\n";
$stale = [
    'public/admin/index.html' => 'نسخة التصميم القديمة — امسحها',
    'public/admin/assets/admin.js.bak' => '',
];
foreach ($stale as $f => $why) {
    if (is_file("$root/$f")) {
        $bad("ملف قديم موجود: $f", $why ?: 'امسحه');
    }
}
$adminHt = @file_get_contents("$root/public/admin/.htaccess") ?: '';
if (preg_match('/^\s*Require all denied\s*$/m', $adminHt) && !str_contains($adminHt, 'Require all granted')) {
    $bad('public/admin/.htaccess القديم بيقفل لوحة التحكم (403)', 'ارفع public/admin/.htaccess الجديد أو امسح الملف القديم');
} else {
    $ok('public/admin/.htaccess');
}
is_file("$root/public/admin/index.php") ? $ok('public/admin/index.php') : $bad('public/admin/index.php مش موجود', 'ارفع فولدر public/admin كامل');

echo "\nConfig\n";
if (!is_file("$root/app/config.php")) {
    $bad('app/config.php مش موجود', 'انسخ app/config.sample.php باسم app/config.php');
} else {
    $c = require "$root/app/config.php";
    strlen((string) ($c['app_key'] ?? '')) >= 32 && !str_starts_with((string) $c['app_key'], 'CHANGE_ME') ? $ok('app_key') : $bad('app_key مش متظبط');
    !empty($c['app_url']) ? $ok('app_url = ' . $c['app_url']) : $bad('app_url فاضي', 'اكتب رابط الساب دومين https://…');
    try {
        $d = $c['db'];
        $pdo = new PDO("mysql:host={$d['host']};port=" . ($d['port'] ?? 3306) . ";dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ok('اتصال قاعدة البيانات');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(['leads', 'generations', 'events', 'settings', 'branches', 'content_items', 'admins', 'rate_limits', 'admin_logs'], $tables);
        $missing ? $bad('جداول ناقصة: ' . implode(', ', $missing), 'اعمل Import لـ install/schema.sql') : $ok('كل الجداول موجودة');
        if (!$missing) {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            $n ? $ok("$n مستخدم لوحة تحكم") : $bad('مفيش مستخدمين للوحة التحكم', 'اعمل Import لـ install/schema.sql تاني');
            $allow = (string) $pdo->query("SELECT value FROM settings WHERE `key` = 'admin_ip_allowlist'")->fetchColumn();
            if (trim($allow) !== '') {
                $bad("IP allowlist متفعّل: $allow (لو الـ IP بتاعك مش فيه هتشوف Forbidden)", "UPDATE settings SET value = '' WHERE `key` = 'admin_ip_allowlist';");
            }
        }
    } catch (Throwable $e) {
        $bad('قاعدة البيانات: ' . $e->getMessage(), 'راجع بيانات db في app/config.php');
    }
}

echo "\nFolders\n";
foreach (['storage/uploads/originals', 'storage/uploads/results', 'storage/logs', 'public/uploads/site'] as $dir) {
    is_dir("$root/$dir") && is_writable("$root/$dir") ? $ok("$dir قابل للكتابة") : $bad("$dir مش موجود أو مش قابل للكتابة", "chmod 755 $dir");
}
foreach (['public', 'public/admin', 'public/api', 'public/assets'] as $dir) {
    $perm = substr(sprintf('%o', fileperms("$root/$dir")), -3);
    in_array($perm, ['755', '750', '775'], true) ? $ok("$dir ($perm)") : $bad("$dir صلاحياته $perm", "chmod 755 $dir (والملفات 644)");
}

echo "\n" . (($GLOBALS['problems'] ?? 0) ? "⚠ فيه {$GLOBALS['problems']} مشكلة — صلّحها وشغّل الفحص تاني.\n" : "✅ كله تمام.\n");
