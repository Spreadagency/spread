<?php
/**
 * ⚠️ ملف نموذجي — انسخه باسم config.php وعدّل البيانات.
 */
/**
 * Spread AI — إعدادات موقع التعريف (قاعدة بيانات منفصلة تمامًا عن المنصة)
 */
declare(strict_types=1);

// ─── قاعدة بيانات الموقع (غير قاعدة المنصة) ───
define('SITE_DB_HOST', 'localhost');
define('SITE_DB_NAME', 'spreadagency_site');
define('SITE_DB_USER', 'spreadagency_site');
define('SITE_DB_PASS', 'CHANGE_ME');

// ─── الروابط ───
define('SITE_URL', 'https://ai.spreadagency.net');       // جذر الموقع
define('SITE_BASE', '');                                  // لو مركّب في مجلد فرعي: '/folder'

// روابط المنصة (الأزرار بتوديك عليها)
define('PLATFORM_LOGIN', SITE_BASE . '/login.php');
define('PLATFORM_REGISTER', SITE_BASE . '/register.php');

// مسار رفع صور الموقع
define('SITE_UPLOAD_DIR', dirname(__DIR__) . '/site-assets/uploads');
define('SITE_UPLOAD_URL', SITE_BASE . '/site-assets/uploads');

// مسار تخزين المنصة (لاستيراد التصميمات الجاهزة للمعرض)
define('PLATFORM_STORAGE_DIR', dirname(__DIR__) . '/storage');
define('PLATFORM_STORAGE_URL', SITE_BASE . '/storage');

date_default_timezone_set('Africa/Cairo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('SPREADSITE');
    session_start();
}
