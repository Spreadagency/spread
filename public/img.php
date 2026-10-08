<?php
declare(strict_types=1);

/**
 * Serves images stored outside the web root.
 *   img.php?g=ID&k=before|after|og&e=EXP&s=SIG   signed, expiring (visitor + admin)
 *   img.php?r=SHARE_TOKEN&k=after|og                shared result page (token = capability)
 */
require dirname(__DIR__) . '/app/bootstrap.php';
session_write_close();

function deny(int $code = 404): never
{
    http_response_code($code);
    header('Cache-Control: no-store');
    exit;
}

$kind = (string) ($_GET['k'] ?? '');
if (!in_array($kind, ['before', 'after', 'og'], true)) {
    deny();
}

if (isset($_GET['r'])) {
    $token = (string) $_GET['r'];
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || $kind === 'before' && !Settings::bool('share_show_before')) {
        deny();
    }
    $gen = q_row("SELECT * FROM generations WHERE share_token = ? AND status = 'done'", [$token]);
    $public = true;
} else {
    $id = (int) ($_GET['g'] ?? 0);
    $exp = (int) ($_GET['e'] ?? 0);
    $sig = (string) ($_GET['s'] ?? '');
    if ($exp < time() || !sign_valid($id . ':' . $kind . ':' . $exp, 'img', $sig)) {
        deny(403);
    }
    $gen = q_row('SELECT * FROM generations WHERE id = ?', [$id]);
    $public = false;
}
if (!$gen) {
    deny();
}

$path = match ($kind) {
    'before' => $gen['original_path'] ? ORIGINALS_PATH . '/' . $gen['original_path'] : null,
    'after' => $gen['result_path'] ? RESULTS_PATH . '/' . $gen['result_path'] : null,
    'og' => $gen['result_path'] ? ImageService::ogPath($gen['result_path']) : null,
};
if ($kind === 'og' && $path && !is_file($path) && $gen['result_path']) {
    ImageService::shareCard(RESULTS_PATH . '/' . $gen['result_path'], $path); // rebuild on demand
}
if (!$path || str_contains($path, '..') || !is_file($path)) {
    deny();
}

$mime = ImageService::mime($path);
if (!in_array($mime, ['image/jpeg', 'image/webp', 'image/png'], true)) {
    deny();
}
$etag = '"' . md5($path . filemtime($path)) . '"';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . ($public ? 'public, max-age=86400' : 'private, max-age=3600'));
header('ETag: ' . $etag);
if (isset($_GET['dl'])) {
    header('Content-Disposition: attachment; filename="slim-simulation.' . ($mime === 'image/webp' ? 'webp' : 'jpg') . '"');
}
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
readfile($path);
