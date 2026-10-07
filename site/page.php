<?php
/**
 * Spread AI — راوتر الصفحات الداخلية (نفس الـ Design System بتاع الرئيسية)
 *   /slug  أو  site/page.php?p=slug  (الاتنين شغالين — .htaccess بيحوّل /slug لهنا)
 *   الحالة: منشورة (ظاهرة) · مخفية (بالرابط بس · noindex · مش في القائمة) · مسودة (للأدمن بس بالمعاينة)
 *   المحتوى: بلوكات الـ Page Builder · أو محتوى الصفحة الأساسية (احنا مين · الخدمات · …)
 *   slug اتغيّر؟ ← تحويل 301 للجديد (site_redirects)
 */
require_once __DIR__ . '/blocks.php';

$slug = strtolower(preg_replace('/[^a-z0-9\-_]/i', '', (string) ($_GET['p'] ?? '')));
if ($slug === '') {
    s_redirect('index.php');
}

$admin = s_admin_can('pages');
$isPreview = $admin && isset($_GET['preview']);
$page = s_one('SELECT * FROM site_pages WHERE slug = ?', [$slug]);
$status = $page ? (string) ($page['status'] ?? ($page['is_active'] ? 'published' : 'draft')) : '';

// الرابط القديم اتغيّر ← الجديد
if (!$page) {
    $rd = s_one('SELECT to_slug FROM site_redirects WHERE from_slug = ?', [$slug]);
    if ($rd && $rd['to_slug'] !== $slug) {
        header('Location: ' . s_page_url($rd['to_slug']), true, 301);
        exit;
    }
}

$visible = $page && ($status === 'published' || $status === 'hidden') && (int) $page['is_active'] === 1;
// صفحة المعاينة من المحرر (بلوكات لسه متحفظتش) — بتتبعت من site-admin/page-preview.php
if (isset($__previewPage) && is_array($__previewPage)) {
    $page = $__previewPage;
    $status = (string) ($page['status'] ?? 'draft');
    $visible = true;
    $isPreview = true;
}

$ctx0 = s_home_ctx([]);
extract($ctx0, EXTR_SKIP);
$homeHref = s_url('index.php');
$nav = [['الرئيسية', $homeHref]];
foreach (s_menu_pages() as $pg) $nav[] = [$pg['title'], s_page_url($pg['slug']), $pg['slug'] === 'create-post', $pg['slug'] === $slug];
$ctaHref = $trialOn ? s_page_url('create-post') : $regUrl;
if (!s_one("SELECT id FROM site_pages WHERE slug = 'create-post' AND is_active = 1")) $ctaHref = $trialOn ? $homeHref . '#s-create' : $regUrl;

if (!$page || (!$visible && !$isPreview)) {
    http_response_code(404);
    $meta = ['title' => 'الصفحة غير موجودة — ' . $siteName, 'description' => '', 'noindex' => true];
    include __DIR__ . '/parts/site-head.php';
    echo '<body class="hv2">';
    include __DIR__ . '/parts/site-header.php';
    echo '<main id="main" class="pg-main"><section class="pg-head"><div class="h-dots" aria-hidden="true"></div><div class="pg-head-in"><span class="ws-chip">404</span>'
        . '<h1>الصفحة دي مش موجودة</h1><p>ممكن تكون اتنقلت أو اتشالت — جرّب من الرئيسية.</p><div class="pg-acts" style="justify-content:center">'
        . '<a href="' . e($homeHref) . '" class="ws-btn ws-pri">رجوع للرئيسية' . s_icon('arrow', 18, 2.2) . '</a></div></div></section></main>';
    include __DIR__ . '/parts/site-footer.php';
    echo '<script src="' . e(s_url('site-assets/js/home-v2.js')) . '?v=' . (@filemtime(dirname(__DIR__) . '/site-assets/js/home-v2.js') ?: 1) . '" defer></script></body></html>';
    exit;
}

if (!$isPreview) s_track_view('/' . $slug);
$blocks = s_page_blocks($page);
$first = null;
foreach ($blocks as $b) if (empty($b['hidden'])) { $first = $b; break; }
$heroFirst = $first && $first['type'] === 'hero';

$suffix = s_setting('seo_title_suffix') !== '' ? s_setting('seo_title_suffix') : ' — ' . $siteName;
$canonical = trim((string) ($page['canonical_url'] ?? '')) ?: s_page_url($slug, true);
$meta = [
    'title' => (trim((string) ($page['seo_title'] ?? '')) ?: $page['title']) . $suffix,
    'description' => trim((string) ($page['seo_description'] ?? '')) ?: (string) ($page['subtitle'] ?: s_setting('tagline')),
    'og_title' => (string) ($page['og_title'] ?? ''),
    'og_description' => (string) ($page['og_description'] ?? ''),
    'og_image' => (string) (($page['og_image'] ?? '') ?: ($page['featured_image'] ?? '')),
    'canonical' => $canonical,
    'noindex' => !empty($page['noindex']) || $status !== 'published' || $isPreview,
];
$__editUrl = !empty($page['id']) ? s_url('site-admin/page-edit.php?id=' . (int) $page['id']) : '';
$__stLabel = ['draft' => 'مسودة مش منشورة', 'hidden' => 'مخفية (بالرابط بس)', 'published' => 'منشورة'][$status] ?? '';
$__previewNote = $isPreview ? 'معاينة — ' . $__stLabel : ($status === 'hidden' ? 'الصفحة مخفية — بتظهر بالرابط بس' : '');
$jsV = @filemtime(dirname(__DIR__) . '/site-assets/js/home-v2.js') ?: 1;

include __DIR__ . '/parts/site-head.php';
?>
<body class="hv2">
<a href="#main" class="sr-only">تخطّي للمحتوى</a>
<?php if ($isPreview): ?><div class="pg-preview" role="status">👁 دي معاينة للأدمن بس — <?= e($__previewNote) ?><?php if ($__editUrl): ?> · <a href="<?= e($__editUrl) ?>">رجوع للتعديل</a><?php endif; ?></div><?php endif; ?>
<?php include __DIR__ . '/parts/site-header.php'; ?>

<main id="main" class="pg-main">
  <?php if (!$heroFirst): $fi = s_link((string) ($page['featured_image'] ?? '')); ?>
  <section class="pg-head">
    <div class="h-dots" aria-hidden="true"></div>
    <div class="pg-head-in">
      <nav class="pg-crumbs" aria-label="مسار الصفحة"><a href="<?= e($homeHref) ?>">الرئيسية</a><?= s_icon('arrow', 13, 2) ?><span aria-current="page"><?= e($page['title']) ?></span></nav>
      <h1><?= e($page['title']) ?></h1>
      <?php if (!empty($page['subtitle'])): ?><p><?= e($page['subtitle']) ?></p><?php endif; ?>
    </div>
    <?php if ($fi !== ''): ?><div class="pg-head-img"><img src="<?= e($fi) ?>" alt="" loading="eager"></div><?php endif; ?>
  </section>
  <?php endif; ?>

  <?= s_render_blocks($blocks) ?>

  <?php if ((int) ($page['show_cta'] ?? 1) === 1): ?>
    <?= s_render_section('cta', $ctx0) ?>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/parts/site-footer.php'; ?>
<?php include __DIR__ . '/parts/admin-bar.php'; ?>
<script>window.SPREAD_HOME = <?= json_encode(s_trial_js_config($regUrl, $loginUrl), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(s_url('site-assets/js/home-v2.js')) ?>?v=<?= $jsV ?>" defer></script>
<script>
/* روابط أقسام الرئيسية (#s-…) لو القسم مش في الصفحة دي ← الرئيسية على نفس القسم */
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('a[href^="#s-"]');
  if (a && !document.getElementById(a.getAttribute('href').slice(1))) { e.preventDefault(); location.href = <?= json_encode($homeHref) ?> + a.getAttribute('href'); }
});
</script>
</body>
</html>
