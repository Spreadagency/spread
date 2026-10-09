<?php
declare(strict_types=1);

/** SEO: title, description, keywords, canonical, OG, schema toggles, robots, favicon. Live previews in admin.js. */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

admin_require('view');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('edit');
    try {
        foreach (['og_image' => 2400, 'favicon' => 512] as $field => $max) {
            if ($path = store_site_image($field, $max)) {
                Settings::set($field, $path);
            }
        }
        if (!empty($_POST['og_image__clear'])) {
            Settings::set('og_image', '');
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        redirect('seo.php');
    }
    if (($c = trim((string) ($_POST['canonical_url'] ?? ''))) !== '' && !preg_match('#^https?://#', $c)) {
        flash('err', 'الرابط الأساسي لازم يبدأ بـ https://');
        redirect('seo.php');
    }
    $changed = save_settings_from_post(
        ['site_title', 'meta_description', 'meta_keywords', 'canonical_url', 'locale', 'og_title', 'og_description', 'robots_txt'],
        ['schema_physician', 'schema_clinic', 'schema_faq']
    );
    admin_log('seo_update', implode(', ', $changed));
    flash('ok', 'اتحفظت إعدادات SEO');
    redirect('seo.php');
}

$host = parse_url(Settings::get('canonical_url', '') ?: url(), PHP_URL_HOST) ?: 'slim.example.com';
$ogImg = Settings::get('og_image', '') ?: (string) Settings::get('doctor_photo');
admin_page_start('SEO', 'seo.php');
?>
<form method="post" enctype="multipart/form-data" class="stack" style="gap:24px" id="seoForm">
<?= csrf_field() ?>
<div class="page-head"><div><p>العنوان والوصف وشكل اللينك في جوجل وواتساب وفيسبوك</p></div><?= save_bar() ?></div>
<div class="grid g-1-1" style="align-items:start">
  <div class="stack" style="gap:24px">
    <section class="card"><div class="card-h"><h2>الأساسيات</h2></div><div class="card-b form-grid">
      <?= f_text('site_title', 'عنوان الصفحة (Title)', Settings::get('site_title'), ['full' => 1, 'counter' => 60, 'hint' => 'الأفضل من 50 لـ 60 حرف']) ?>
      <?= f_textarea('meta_description', 'الوصف (Meta description)', Settings::get('meta_description'), ['full' => 1, 'counter' => 160, 'hint' => 'الأفضل من 120 لـ 160 حرف']) ?>
      <?= f_text('meta_keywords', 'الكلمات المفتاحية', Settings::get('meta_keywords', ''), ['full' => 1, 'hint' => 'افصل بينهم بفاصلة']) ?>
      <?= f_text('canonical_url', 'الرابط الأساسي (Canonical)', Settings::get('canonical_url', ''), ['ltr' => 1, 'placeholder' => url()]) ?>
      <?= f_select('locale', 'اللغة / المنطقة', (string) Settings::get('locale', 'ar_EG'), ['ar_EG' => 'ar_EG — عربي مصر', 'ar_AR' => 'ar_AR — عربي']) ?>
    </div></section>
    <section class="card"><div class="card-h"><div><h2>المشاركة (Open Graph)</h2><p>بيظهر لما حد يبعت لينك الصفحة على واتساب أو فيسبوك</p></div></div><div class="card-b form-grid">
      <?= f_text('og_title', 'عنوان المشاركة', Settings::get('og_title', ''), ['full' => 1, 'placeholder' => Settings::get('site_title')]) ?>
      <?= f_textarea('og_description', 'وصف المشاركة', Settings::get('og_description', ''), ['full' => 1, 'rows' => 2]) ?>
      <div class="full"><?= f_image('og_image', 'صورة المشاركة', (string) Settings::get('og_image', ''), '1200×630 بكسل · JPG أو PNG. لو مفيش، بتتستخدم صورة الدكتور.') ?>
      <?php if (Settings::get('og_image', '') && AdminAuth::can('edit')): ?><label class="row small muted"><input type="checkbox" class="cbx" name="og_image__clear" value="1"> شيل الصورة المرفوعة</label><?php endif; ?></div>
      <div class="full"><?= f_image('favicon', 'أيقونة المتصفح (Favicon)', (string) Settings::get('favicon', ''), 'PNG مربعة 512×512. لو مفيش، بيتستخدم اللوجو.') ?></div>
    </div></section>
    <section class="card"><div class="card-h"><div><h2>Schema (JSON-LD)</h2><p>بيساعد جوجل يفهم إن الصفحة لدكتور وعيادة</p></div></div><div class="list">
      <div class="li"><div class="grow"><b class="ltr mono">Physician</b><small>بيانات الدكتور: الاسم، التخصص، الصورة</small></div><?= f_toggle('schema_physician', '', Settings::bool('schema_physician')) ?></div>
      <div class="li"><div class="grow"><b class="ltr mono">MedicalClinic</b><small>الفروع كعيادات (من محتوى الصفحة ← الفروع)</small></div><?= f_toggle('schema_clinic', '', Settings::bool('schema_clinic')) ?></div>
      <div class="li"><div class="grow"><b class="ltr mono">FAQPage</b><small>الأسئلة الشائعة تظهر في نتايج البحث</small></div><?= f_toggle('schema_faq', '', Settings::bool('schema_faq')) ?></div>
    </div></section>
    <section class="card"><div class="card-h"><h2>robots.txt و sitemap</h2><a class="status ok" href="../sitemap.xml" target="_blank" rel="noopener"><i></i>sitemap.xml</a></div><div class="card-b stack">
      <?= f_textarea('robots_txt', 'robots.txt', Settings::get('robots_txt'), ['code' => 1, 'rows' => 6, 'hint' => 'سطر الـ Sitemap بيتضاف تلقائي لو مش موجود. صفحات المشاركة <span class="ltr mono">/r/*</span> عليها noindex دايمًا.']) ?>
      <a class="small" href="../robots.txt" target="_blank" rel="noopener">افتح robots.txt الحالي ↗</a>
    </div></section>
  </div>
  <div class="stack sticky-col" style="gap:24px">
    <section class="card"><div class="card-h"><h2>معاينة جوجل</h2></div><div class="card-b">
      <div class="serp"><div class="u"><span class="fav"><img src="<?= e(asset((string) (Settings::get('favicon', '') ?: Settings::get('logo')))) ?>" alt=""></span><div><div style="font-size:14px"><?= e(Settings::get('doctor_name')) ?></div><div class="ltr" style="color:#4d5156">https://<?= e($host) ?></div></div></div>
      <div class="t1" data-preview-of="f_site_title"></div><div class="d" data-preview-of="f_meta_description"></div></div></div></section>
    <section class="card"><div class="card-h"><h2>معاينة المشاركة</h2><div class="seg" data-og-switch><button type="button" class="on" data-og="fb">فيسبوك</button><button type="button" data-og="wa">واتساب</button></div></div><div class="card-b" data-og-box>
      <div class="og-wrap"><div class="og"><div class="img og-photo"><img src="<?= e(asset($ogImg)) ?>" alt="" data-og-img></div><div class="b"><small class="ltr"><?= e($host) ?></small><b data-preview-of="f_og_title" data-fallback="f_site_title"></b><p data-preview-of="f_og_description" data-fallback="f_meta_description"></p></div></div>
      <p class="small wa-link ltr">https://<?= e($host) ?></p></div>
    </div></section>
  </div>
</div>
</form>
<?php
admin_page_end();
