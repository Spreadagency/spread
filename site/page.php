<?php
/**
 * Spread AI — راوتر الصفحات الداخلية
 * ?p=slug  → صفحة مدمجة (about/services/designs/tutorials/pricing) أو صفحة HTML مخصصة
 */
require_once __DIR__ . '/functions.php';

$slug = preg_replace('/[^a-z0-9\-_]/i', '', (string) ($_GET['p'] ?? ''));
if ($slug === '') {
    s_redirect('index.php');
}

$page = s_one('SELECT * FROM site_pages WHERE slug = ? AND is_active = 1', [$slug]);
if (!$page) {
    http_response_code(404);
    $__pageTitle = 'الصفحة غير موجودة';
    include __DIR__ . '/header.php';
    echo '<section class="page-hero"><div class="wrap"><h1>٤٠٤</h1><p>الصفحة اللي بتدور عليها مش موجودة.</p>'
        . '<div style="margin-top:26px"><a href="' . e(s_url('index.php')) . '" class="btn-pill btn-blue btn-lg">رجوع للرئيسية</a></div></div></section>';
    include __DIR__ . '/footer.php';
    exit;
}

$__pageTitle = $page['title'];
$__pageDesc  = $page['subtitle'] ?: s_setting('tagline');
$loginUrl = s_setting('platform_login_url', PLATFORM_LOGIN);
$regUrl   = s_setting('platform_register_url', PLATFORM_REGISTER);

include __DIR__ . '/header.php';
?>

<section class="page-hero">
  <div class="wrap">
    <h1><?= e($page['title']) ?></h1>
    <?php if ($page['subtitle']): ?><p><?= e($page['subtitle']) ?></p><?php endif; ?>
  </div>
</section>

<?php
// محتوى HTML مخصص (لو موجود) بيتعرض فوق محتوى الصفحة المدمجة
if (trim((string) $page['content_html']) !== ''):
?>
<section class="sec" style="padding-top:0">
  <div class="wrap">
    <div class="prose rv"><?= $page['content_html'] /* HTML من الأدمن — مقصود */ ?></div>
  </div>
</section>
<?php endif; ?>

<?php
// الأقسام المدمجة حسب الـ slug
$builtin = __DIR__ . '/parts/' . $slug . '.php';
if ($page['is_builtin'] && is_file($builtin)) {
    include $builtin;
}
?>

<section class="sec">
  <div class="wrap">
    <div class="cta-box rv">
      <h2><?= e(s_setting('cta_title', 'جاهز نبدأ؟')) ?></h2>
      <p><?= e(s_setting('cta_body')) ?></p>
      <div class="cta-actions">
        <a href="<?= e($regUrl) ?>" class="btn-pill btn-light btn-lg"><span><?= e(s_setting('cta_btn', 'ابدأ مجانًا')) ?></span><span>←</span></a>
        <a href="<?= e($loginUrl) ?>" class="btn-pill btn-ghost-d btn-lg">عندي حساب</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
