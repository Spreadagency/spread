<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
session_write_close();

$loc = Settings::get('canonical_url', '') ?: url();
$last = (string) (q_value('SELECT MAX(updated_at) FROM settings') ?: date('Y-m-d'));

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= e($loc) ?></loc>
    <lastmod><?= e(substr($last, 0, 10)) ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
