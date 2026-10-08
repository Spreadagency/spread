<?php
declare(strict_types=1);

/*
 * Loaded first by every entry point (public pages, API endpoints, cron).
 */

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', __DIR__);
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('ORIGINALS_PATH', STORAGE_PATH . '/uploads/originals');
define('RESULTS_PATH', STORAGE_PATH . '/uploads/results');
define('LOG_PATH', STORAGE_PATH . '/logs');

$configFile = APP_PATH . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing app/config.php — copy app/config.sample.php and fill it in.');
}
$GLOBALS['APP_CONFIG'] = require $configFile;

function config(string $key, $default = null)
{
    $value = $GLOBALS['APP_CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

if (strlen((string) config('app_key')) < 32 || str_starts_with((string) config('app_key'), 'CHANGE_ME')) {
    http_response_code(500);
    exit('Set a real app_key in app/config.php.');
}

// Errors go to storage/logs, never to visitors (unless debug is on).
error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php-error.log');

require APP_PATH . '/db.php';
require APP_PATH . '/helpers.php';
require APP_PATH . '/settings.php';
require APP_PATH . '/csrf.php';

spl_autoload_register(static function (string $class): void {
    $file = APP_PATH . '/services/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set(Settings::get('timezone', 'Africa/Cairo') ?: 'Africa/Cairo');

set_exception_handler(static function (Throwable $e): void {
    log_error('Uncaught ' . get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
    if (headers_sent()) {
        return;
    }
    if (is_api_request()) {
        json_response(['error' => 'حصلت مشكلة في السيرفر، جرّب تاني بعد شوية.'], 500);
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>خطأ</title><p style="font-family:sans-serif;text-align:center;margin-top:20vh" dir="rtl">حصلت مشكلة مؤقتة، جرّب تاني بعد شوية.</p>';
});

if (PHP_SAPI !== 'cli') {
    start_session();
}
