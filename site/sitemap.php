<?php
/** sitemap.xml — الرئيسية + الصفحات المنشورة اللي مش noindex */
require_once __DIR__ . '/functions.php';
header('Content-Type: application/xml; charset=utf-8');
$base = rtrim(SITE_URL, '/');
$rows = s_setting('seo_noindex_site', '0') === '1' ? [] : s_all("SELECT slug, updated_at FROM site_pages WHERE is_active = 1 AND COALESCE(status, 'published') = 'published' AND COALESCE(noindex, 0) = 0 ORDER BY sort_order, id");
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '  <url><loc>' . htmlspecialchars($base . '/', ENT_XML1) . '</loc><priority>1.0</priority></url>' . "\n";
foreach ($rows as $r) {
    echo '  <url><loc>' . htmlspecialchars(s_page_url($r['slug'], true), ENT_XML1) . '</loc>'
        . ($r['updated_at'] ? '<lastmod>' . date('Y-m-d', strtotime($r['updated_at'])) . '</lastmod>' : '') . '</url>' . "\n";
}
echo '</urlset>';
