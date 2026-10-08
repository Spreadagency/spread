<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
session_write_close();

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$robots = rtrim((string) Settings::get('robots_txt'));
if (!preg_match('/^\s*sitemap:/mi', $robots)) {
    $robots .= "\nSitemap: " . url('sitemap.xml');
}
echo $robots, "\n";
