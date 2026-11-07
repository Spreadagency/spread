<?php
/**
 * صورة شاشة البلاغ — للأدمن بس (مجلد storage/uploads/issues مقفول قدام الناس بـ .htaccess)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
require_admin_can('view_users');

$id = (int) ($_GET['id'] ?? 0);
$row = $id ? db_one('SELECT screenshot_path FROM user_issues WHERE id = ?', [$id]) : null;
$rel = (string) ($row['screenshot_path'] ?? '');
$base = realpath(__DIR__ . '/../storage/uploads/issues');
$abs = $rel !== '' && !str_contains($rel, '..') ? realpath(__DIR__ . '/../storage/' . ltrim($rel, '/')) : false;
if (!$base || !$abs || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR) || !is_file($abs)) {
    http_response_code(404);
    exit('مفيش صورة');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: '';
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    http_response_code(415);
    exit;
}
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');
readfile($abs);
