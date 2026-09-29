<?php
/**
 * ⚠️ ده ملف نموذجي — انسخه باسم config.php وعدّل البيانات.
 * الحزم الجديدة بترفع الملف ده بس، وما بتلمسش config.php بتاعك أبدًا.
 */
/**
 * Spread AI — Configuration File
 * 
 * IMPORTANT: Update these values before deploying to production
 */

// ─── Error reporting (development) ─────────────────────────
// Set to false in production
define('APP_DEBUG', false);

if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ─── Site / URL settings ───────────────────────────────────
define('APP_NAME', 'Spread AI');
define('APP_TAGLINE', 'منصة المحتوى بالذكاء الاصطناعي');

// Base URL — set this to your domain (no trailing slash)
// Example: 'https://yourdomain.com' or 'https://yourdomain.com/spread-ai'
define('APP_URL', 'https://ai.spreadagency.net');

// ─── Database ──────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'اسم_قاعدة_البيانات');
define('DB_USER', 'اسم_المستخدم');
define('DB_PASS', 'الباسورد');
define('DB_CHARSET', 'utf8mb4');

// ─── Paths ─────────────────────────────────────────────────
define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOADS_PATH', STORAGE_PATH . '/uploads');
define('LOGS_PATH', STORAGE_PATH . '/logs');

define('UPLOADS_URL', APP_URL . '/storage/uploads');

// ─── Session ───────────────────────────────────────────────
define('SESSION_LIFETIME', 60 * 60 * 24 * 7); // 7 days

// ─── Email ─────────────────────────────────────────────────
// MAIL_DRIVER: 'mail' = send real emails via PHP mail()
//              'log'  = write emails to storage/logs/mail.log (dev only)
define('MAIL_DRIVER', 'mail');
define('MAIL_FROM', 'no-reply@ai.spreadagency.net');
define('MAIL_FROM_NAME', 'Spread AI');

// ─── AI / OpenAI / OpenRouter ─────────────────────────────
// Get your key from https://platform.openai.com or https://openrouter.ai
define('AI_PROVIDER', 'openrouter'); // 'openai' or 'openrouter'
define('AI_API_KEY', ''); // Leave empty to use mock content generation
define('AI_MODEL', 'openai/gpt-4o-mini');
define('AI_API_URL_OPENAI', 'https://api.openai.com/v1/chat/completions');
define('AI_API_URL_OPENROUTER', 'https://openrouter.ai/api/v1/chat/completions');

// Timeouts (seconds) — keep TOTAL under shared-hosting max_execution_time (~30s)
define('AI_CONNECT_TIMEOUT', 5);
define('AI_TIMEOUT', 25);

// ─── Credits / Costs ───────────────────────────────────────
define('STARTER_CREDITS', 10); // Credits given to new users on signup
define('COST_GENERATE', 1);
define('COST_REGENERATE', 1);
define('COST_DESIGN', 2);

// ─── File uploads ──────────────────────────────────────────
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB
define('MAX_BRAND_IMAGES', 10);
// SVG removed: SVG files can contain scripts (stored XSS risk)
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// ─── Verification token expiry ─────────────────────────────
define('VERIFY_TOKEN_TTL', 60 * 60 * 24); // 24 hours
define('RESET_TOKEN_TTL', 60 * 60 * 1);   // 1 hour

// ─── Security ──────────────────────────────────────────────
// Change this to a long random string in production
define('CSRF_SECRET', 'spread-ai-' . md5('change-me-please-' . __FILE__));

// ─── Timezone ──────────────────────────────────────────────
date_default_timezone_set('Africa/Cairo');

// ─── Start session ─────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', SESSION_LIFETIME);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ─── Encryption (API keys في قاعدة البيانات — المرحلة 1) ───
define('ENCRYPTION_KEY', 'c6ca6640185aa169ab69c798e451f137b2100a180765bc950f35e76155432afb');
