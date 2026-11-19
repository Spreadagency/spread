<?php
/**
 * Spread AI — إعدادات البيئة المحلية بس (includes/config.php و site/config.php) من الملفات النموذجية
 *
 *   php tests/dev-config.php
 *
 * مابيلمسش أي ملف موجود. قاعدة البيانات المحلية: spread_app / spread_ai / spread_site بالمستخدم spread / spreadpass.
 * APP_DEBUG = true وبريد «log» — يعني مستحيل الملف ده ينفع على الموقع الحقيقي.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);

$cfg = $root . '/includes/config.php';
if (!is_file($cfg)) {
    $s = (string) file_get_contents($root . '/includes/config.sample.php');
    $s = strtr($s, [
        "define('APP_DEBUG', false);" => "define('APP_DEBUG', true);",
        "define('APP_URL', 'https://ai.spreadagency.net');" => "define('APP_URL', 'http://localhost:8080');",
        "define('DB_NAME', 'اسم_قاعدة_البيانات');" => "define('DB_NAME', 'spread_app');",
        "define('DB_USER', 'اسم_المستخدم');" => "define('DB_USER', 'spread');",
        "define('DB_PASS', 'الباسورد');" => "define('DB_PASS', 'spreadpass');",
        "define('MAIL_DRIVER', 'mail');" => "define('MAIL_DRIVER', 'log');",
        'PUT_YOUR_OWN_64_HEX_CHARS_HERE' => bin2hex(random_bytes(32)),
        "define('MCP_AI_DB_NAME', '');" => "define('MCP_AI_DB_NAME', 'spread_ai');",
        "define('INTERNAL_API_TOKEN', '');" => "define('INTERNAL_API_TOKEN', '" . bin2hex(random_bytes(32)) . "');",
    ]);
    if (!str_contains($s, "'spread_app'")) { fwrite(STDERR, "config.sample.php اتغيّر — حدّث tests/dev-config.php\n"); exit(1); }
    file_put_contents($cfg, $s);
    echo "includes/config.php ✓\n";
}

$site = $root . '/site/config.php';
if (!is_file($site) && is_file($root . '/site/config.sample.php')) {
    $s = strtr((string) file_get_contents($root . '/site/config.sample.php'), [
        "define('SITE_DB_NAME', 'spreadagency_site');" => "define('SITE_DB_NAME', 'spread_site');",
        "define('SITE_DB_USER', 'spreadagency_site');" => "define('SITE_DB_USER', 'spread');",
        "'CHANGE_ME'" => "'spreadpass'",
        'https://ai.spreadagency.net' => 'http://localhost:8080',
    ]);
    file_put_contents($site, $s);
    echo "site/config.php ✓ (جديد — لازم site-schema.sql يتحمّل)\n";
    exit(10);
}
