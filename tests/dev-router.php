<?php
/**
 * Spread AI — راوتر للخادم المحلي (php -S) بيقلّد .htaccess: الملفات الحقيقية في الجذر زي ما هي،
 * والباقي بيتدوّر عليه جوه public/ — للتطوير المحلي بس.
 *   php -S 127.0.0.1:8080 -t . tests/dev-router.php
 */
if (PHP_SAPI !== 'cli-server') exit(1);
$root = dirname(__DIR__);
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_contains($uri, '..') || preg_match('#^/(includes|sql)/|^/storage/logs/|^/site/(config|db)\.php$#', $uri)) { http_response_code(403); exit('Forbidden'); }
if ($uri === '/' || $uri === '') return false;                       // index.php في الجذر
if (is_file($root . $uri) || (is_dir($root . $uri) && is_file(rtrim($root . $uri, '/') . '/index.php'))) return false;
if (preg_match('#^/(admin|site-admin)/?$#', $uri, $m)) { header('Location: /' . $m[1] . '/login.php'); exit; }
$p = $root . '/public' . $uri;
if (is_dir($p)) $p = rtrim($p, '/') . '/index.php';
if (!is_file($p) && is_file($p . '.php')) $p .= '.php';
if (!is_file($p)) { http_response_code(404); exit('Not found'); }
if (str_ends_with($p, '.php')) {
    $_SERVER['SCRIPT_FILENAME'] = $p;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/public' . substr($p, strlen($root . '/public'));
    chdir(dirname($p));
    require $p;
    return true;
}
$types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'json' => 'application/json'];
header('Content-Type: ' . ($types[strtolower(pathinfo($p, PATHINFO_EXTENSION))] ?? (mime_content_type($p) ?: 'application/octet-stream')));
readfile($p);
return true;
