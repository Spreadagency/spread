<?php
/** معاينة صفحة من الـ Page Builder قبل الحفظ (مابيتحفظش أي حاجة) — للأدمن اللي عنده صلاحية الصفحات بس */
require_once __DIR__ . '/auth.php';
sa_require_perm('pages');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') s_redirect('site-admin/pages.php');
s_check_csrf();
s_decode_b64();
require_once dirname(__DIR__) . '/site/blocks.php';

$in = json_decode((string) ($_POST['payload'] ?? ''), true) ?: [];
$id = (int) ($_POST['id'] ?? 0);
$saved = $id ? s_one('SELECT * FROM site_pages WHERE id = ?', [$id]) : null;
$str = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
$__previewPage = [
    'id' => $id ?: null,
    'slug' => $saved['slug'] ?? (preg_replace('/[^a-z0-9\-_]/i', '', $str('slug', 120)) ?: 'preview'),
    'title' => $str('title', 250) ?: 'صفحة بدون عنوان',
    'subtitle' => $str('subtitle', 500),
    'status' => in_array($in['status'] ?? '', ['published', 'draft', 'hidden'], true) ? $in['status'] : 'draft',
    'is_active' => 1,
    'is_builtin' => (int) ($saved['is_builtin'] ?? 0),
    'featured_image' => $str('featured_image', 700),
    'show_cta' => !empty($in['show_cta']) ? 1 : 0,
    'seo_title' => $str('seo_title', 255), 'seo_description' => $str('seo_description', 500),
    'og_title' => '', 'og_description' => '', 'og_image' => '', 'canonical_url' => '', 'noindex' => 1,
    'blocks_json' => json_encode(s_blocks_clean($in['blocks'] ?? []), JSON_UNESCAPED_UNICODE) ?: '[]',
    'content_html' => '',
];
if ($__previewPage['blocks_json'] === '[]') $__previewPage['blocks_json'] = json_encode([['id' => 'empty', 'type' => 'text', 'hidden' => false, 'd' => ['title' => '', 'body' => '<p style="text-align:center">الصفحة لسه مفيهاش أقسام — ضيف أول قسم من المحرر.</p>', 'align' => 'center', 'width' => 'narrow']]], JSON_UNESCAPED_UNICODE);
$_GET['p'] = $__previewPage['slug'];
header('X-Robots-Tag: noindex');
chdir(dirname(__DIR__) . '/site');
include dirname(__DIR__) . '/site/page.php';
