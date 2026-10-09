<?php
/**
 * Local development only:
 *   php -S localhost:8000 -t public install/dev-router.php
 * Mimics the .htaccess rewrites.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/r/([a-f0-9]{32})/?$#', $uri, $m)) {
    $_GET['t'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/r.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/r.php';
    require __DIR__ . '/../public/r.php';
    return true;
}
$map = ['/robots.txt' => 'robots.php', '/sitemap.xml' => 'sitemap.php'];
if (isset($map[$uri])) {
    $_SERVER['SCRIPT_NAME'] = '/' . $map[$uri];
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/' . $map[$uri];
    require __DIR__ . '/../public/' . $map[$uri];
    return true;
}
return false;
