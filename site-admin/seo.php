<?php
/** SEO — عنوان ووصف الرئيسية · صورة المشاركة الافتراضية · التتبّع · فحص SEO كل الصفحات · Sitemap */
require_once __DIR__ . '/auth.php';
sa_require_perm('seo');

$keys = [
    'seo_home_title'       => ['عنوان الرئيسية (Meta Title)', 'text', 'فاضي = «اسم الموقع — السطر التعريفي». الأفضل أقل من 60 حرف.'],
    'seo_home_description' => ['وصف الرئيسية (Meta Description)', 'textarea', 'الأفضل بين 120 و 160 حرف.'],
    'seo_title_suffix'     => ['لاحقة عناوين الصفحات', 'text', 'بتتضاف بعد عنوان كل صفحة داخلية. فاضي = « — اسم الموقع».'],
    'seo_gsc_verify'       => ['Google Search Console (كود التحقق)', 'text', 'القيمة بس من وسم google-site-verification.'],
    'seo_ga_id'            => ['Google Analytics 4 (Measurement ID)', 'text', 'شكل G-XXXXXXX — اختياري.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    $changed = [];
    foreach ($keys as $k => [$l, $t]) {
        if (!isset($_POST[$k])) continue;
        $v = trim((string) $_POST[$k]);
        if ($k === 'seo_ga_id' && $v !== '' && !preg_match('/^G-[A-Z0-9]{4,16}$/i', $v)) { s_flash('danger', 'كود Google Analytics لازم يكون بالشكل G-XXXXXXX'); continue; }
        if ($k === 'seo_gsc_verify') $v = preg_replace('/[^A-Za-z0-9_\-]/', '', $v);
        if ($v !== s_setting($k)) $changed[] = $l;
        s_set($k, mb_substr($v, 0, 500));
    }
    $ni = !empty($_POST['seo_noindex_site']) ? '1' : '0';
    if ($ni !== s_setting('seo_noindex_site', '0')) $changed[] = 'منع الفهرسة';
    s_set('seo_noindex_site', $ni);
    if (!empty($_FILES['og']['name'])) {
        $up = s_upload($_FILES['og'], 'og');
        if ($up['ok']) { s_set('seo_og_image', SITE_UPLOAD_URL . '/' . $up['path']); $changed[] = 'صورة المشاركة'; }
        else s_flash('danger', $up['error']);
    } elseif (isset($_POST['og_url'])) {
        $u = trim((string) $_POST['og_url']);
        if ($u !== s_setting('seo_og_image')) { s_set('seo_og_image', mb_substr($u, 0, 700)); $changed[] = 'صورة المشاركة'; }
    }
    if ($changed) sa_log('settings', 'seo', 'تعديل إعدادات SEO: ' . implode('، ', $changed));
    if (empty($_SESSION['site_flash'])) s_flash('success', 'تم حفظ إعدادات SEO ✓');
    s_redirect('site-admin/seo.php');
}

$pages = s_all("SELECT id, slug, title, subtitle, seo_title, seo_description, og_image, featured_image, noindex, status FROM site_pages ORDER BY sort_order, id");
$og = s_setting('seo_og_image');
$__t = 'SEO';
include __DIR__ . '/layout.php';
echo sa_page_head('search', 'SEO', 'SEO Manager', 'إزاي الموقع بيظهر في جوجل وفي المشاركة على السوشيال — الإعدادات العامة وفحص سريع لكل صفحة.',
    sa_btn('sitemap.xml', 'sec', s_url('sitemap.xml'), 'external', ['target' => '_blank', 'rel' => 'noopener']));
?>
<div class="ad-split wide-side">
  <form method="POST" enctype="multipart/form-data" data-safe-post class="ad-card sa-in">
    <?= s_csrf_field() ?>
    <div class="ad-card-h"><h3>الإعدادات العامة</h3></div>
    <div class="ad-form one">
      <?php foreach ($keys as $k => [$l, $t, $h]) echo sa_field(['name' => $k, 'label' => $l, 'type' => $t, 'hint' => $h, 'rows' => 3, 'dir' => in_array($k, ['seo_ga_id', 'seo_gsc_verify'], true) ? 'ltr' : ''], s_setting($k)); ?>
      <div class="ad-f">
        <span class="ad-label">صورة المشاركة الافتراضية (OG Image)</span>
        <div class="ad-drop<?= $og ? ' has' : '' ?>" data-drop>
          <span class="ad-drop-pv"><?= $og ? '<img src="' . e($og) . '" alt="">' : sa_icon('image', 22) ?></span>
          <span class="ad-drop-t"><b data-drop-name><?= $og ? 'الصورة الحالية' : 'لسه مفيش ملف' ?></b><small>1200×630 — بتظهر لما حد يشارك لينك الموقع</small></span>
          <input type="file" name="og" accept="image/*" aria-label="صورة المشاركة">
        </div>
        <div class="ad-drop-x"><input class="ad-in sm" type="text" dir="ltr" name="og_url" value="<?= e($og) ?>" placeholder="أو لينك صورة" data-media-target>
          <button type="button" class="ad-btn ad-soft sm" data-media-pick><?= sa_icon('folder', 16) ?><span>المكتبة</span></button></div>
      </div>
      <div class="ad-swrow"><span><span class="ad-label">منع فهرسة الموقع كله (noindex)</span><span class="ad-hint" style="display:block">للتجربة بس — خليه مقفول على الموقع الحقيقي.</span></span>
        <?= sa_switch('seo_noindex_site', s_setting('seo_noindex_site', '0') === '1', 'مفعّل') ?></div>
    </div>
    <div style="margin-top:18px"><?= sa_btn('حفظ', 'pri lg', null, 'check', ['type' => 'submit']) ?></div>
  </form>

  <aside class="ad-card sa-in">
    <div class="ad-card-h"><h3>معاينة جوجل — الرئيسية</h3></div>
    <div class="pb-serp"><b><?= e(s_setting('seo_home_title') ?: s_setting('site_name', 'Spread AI') . ' — ' . s_setting('tagline')) ?></b>
      <small><?= e(preg_replace('~^https?://~', '', SITE_URL)) ?></small>
      <p><?= e(mb_substr(s_setting('seo_home_description') ?: s_setting('tagline'), 0, 160)) ?></p></div>
    <p class="ad-hint" style="margin:12px 0 0">الـ Sitemap بيتعمل تلقائيًا من الصفحات المنشورة — قدّمه في Search Console: <span dir="ltr"><?= e(rtrim(SITE_URL, '/')) ?>/sitemap.xml</span></p>
  </aside>
</div>

<h2 class="ad-h2" style="margin-top:26px">فحص SEO الصفحات <small><?= count($pages) ?> صفحة</small></h2>
<div class="ad-table-w"><table class="ad-table">
  <thead><tr><th>الصفحة</th><th>Meta Title</th><th>Meta Description</th><th>صورة المشاركة</th><th>الفهرسة</th><th style="text-align:left">إجراءات</th></tr></thead>
  <tbody>
  <?php foreach ($pages as $p):
    $t = (string) ($p['seo_title'] ?: $p['title']); $d = (string) ($p['seo_description'] ?: $p['subtitle']);
    $tl = mb_strlen($t); $dl = mb_strlen($d); ?>
    <tr>
      <td class="t-first" data-l="الصفحة"><span class="t-main"><?= e($p['title']) ?></span><span class="t-sub" dir="ltr" style="text-align:right">/<?= e($p['slug']) ?></span></td>
      <td data-l="العنوان"><?= sa_chip($tl ? $tl . ' حرف' : 'ناقص', $tl === 0 ? 'danger' : ($tl > 60 ? 'warn' : 'ok')) ?><?= $p['seo_title'] ? '' : ' <span class="ad-hint">من عنوان الصفحة</span>' ?></td>
      <td data-l="الوصف"><?= sa_chip($dl ? $dl . ' حرف' : 'ناقص', $dl === 0 ? 'danger' : ($dl < 70 || $dl > 160 ? 'warn' : 'ok')) ?></td>
      <td data-l="الصورة"><?= ($p['og_image'] || $p['featured_image']) ? sa_chip('موجودة', 'ok') : sa_chip('الافتراضية', 'off') ?></td>
      <td class="t-chip" data-l="الفهرسة"><?= $p['noindex'] || $p['status'] !== 'published' ? sa_chip('noindex', 'warn') : sa_chip('index', 'ok') ?></td>
      <td class="ad-acts"><div class="ad-acts-in"><a class="ad-ib" href="page-edit.php?id=<?= (int) $p['id'] ?>#seo" aria-label="تعديل SEO" title="تعديل SEO"><?= sa_icon('edit', 17) ?></a>
        <a class="ad-ib" href="<?= e(s_page_url($p['slug'])) ?>" target="_blank" rel="noopener" aria-label="فتح الصفحة" title="فتح"><?= sa_icon('external', 17) ?></a></div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php include __DIR__ . '/layout-end.php'; ?>
