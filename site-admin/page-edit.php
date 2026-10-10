<?php
/**
 * Page Builder — إنشاء/تعديل صفحة من غير كود
 *   الأقسام (بلوكات): إضافة · تعديل · حذف · نسخ · فوق/تحت (وسحب) · إخفاء/إظهار
 *   الحالة: منشورة · مسودة · مخفية — معاينة قبل النشر — SEO كامل (Title · Description · OG · Canonical · noindex)
 *   تغيير الـ slug لصفحة منشورة بيعمل تحويل 301 تلقائي من الرابط القديم
 */
require_once __DIR__ . '/auth.php';
sa_require_perm('pages');
require_once dirname(__DIR__) . '/site/blocks.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$page = $id ? s_one('SELECT * FROM site_pages WHERE id = ?', [$id]) : null;
if ($id && !$page) { s_flash('danger', 'الصفحة مش موجودة'); s_redirect('site-admin/pages.php'); }

/** قراءة الفورم → بيانات الصفحة (مشتركة مع المعاينة) */
function sa_page_from_post(?array $page): array
{
    $st = in_array($_POST['status'] ?? '', ['published', 'draft', 'hidden'], true) ? $_POST['status'] : 'draft';
    $u = function (string $k): ?string {
        $v = trim((string) ($_POST[$k] ?? ''));
        if ($v === '') return null;
        if (!preg_match('~^(https?://|/)~i', $v)) $v = 'https://' . ltrim($v, '/');
        return mb_substr($v, 0, 700);
    };
    $t = fn(string $k, int $max) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max) ?: null;
    return [
        'title' => mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 250),
        'slug' => strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string) ($_POST['slug'] ?? '')), '-_')),
        'subtitle' => $t('subtitle', 500),
        'status' => $st,
        'show_in_menu' => !empty($_POST['show_in_menu']) ? 1 : 0,
        'show_cta' => !empty($_POST['show_cta']) ? 1 : 0,
        'sort_order' => (int) ($_POST['sort_order'] ?? ($page['sort_order'] ?? 0)),
        'seo_title' => $t('seo_title', 255), 'seo_description' => $t('seo_description', 500),
        'og_title' => $t('og_title', 255), 'og_description' => $t('og_description', 500),
        'og_image' => $u('og_image'), 'featured_image' => $u('featured_image'), 'canonical_url' => $u('canonical_url'),
        'noindex' => !empty($_POST['noindex']) ? 1 : 0,
        'blocks' => s_blocks_clean((string) ($_POST['blocks_json'] ?? '[]')),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    $d = sa_page_from_post($page);
    $back = 'site-admin/page-edit.php' . ($id ? '?id=' . $id : '');
    if ($page && $page['is_builtin']) $d['slug'] = $page['slug']; // الصفحات الأساسية: الرابط ثابت
    if ($d['title'] === '' || $d['slug'] === '') { s_flash('danger', 'العنوان والرابط (slug) مطلوبين'); $_SESSION['sa_page_draft'] = $_POST; s_redirect($back); }
    // صفحة جديدة أو slug اتغيّر: مايتعارضش مع فولدر حقيقي أو صفحة في المنصة (الصفحات الأساسية زي services بتفضل بالرابط ?p=)
    if (mb_strlen($d['slug']) > 120 || (($page['slug'] ?? '') !== $d['slug'] && s_slug_conflicts($d['slug']))) { s_flash('danger', 'الرابط «' . $d['slug'] . '» محجوز للنظام — اختار اسم تاني'); $_SESSION['sa_page_draft'] = $_POST; s_redirect($back); }
    if (s_one('SELECT id FROM site_pages WHERE slug = ? AND id <> ?', [$d['slug'], $id])) { s_flash('danger', 'الرابط ده مستخدم في صفحة تانية'); $_SESSION['sa_page_draft'] = $_POST; s_redirect($back); }

    $blocksJson = json_encode($d['blocks'], JSON_UNESCAPED_UNICODE);
    $active = $d['status'] === 'draft' ? 0 : 1;
    $cols = ['title' => $d['title'], 'slug' => $d['slug'], 'subtitle' => $d['subtitle'], 'status' => $d['status'], 'is_active' => $active, 'show_in_menu' => $d['show_in_menu'],
             'show_cta' => $d['show_cta'], 'sort_order' => $d['sort_order'], 'seo_title' => $d['seo_title'], 'seo_description' => $d['seo_description'],
             'og_title' => $d['og_title'], 'og_description' => $d['og_description'], 'og_image' => $d['og_image'], 'featured_image' => $d['featured_image'],
             'canonical_url' => $d['canonical_url'], 'noindex' => $d['noindex'], 'blocks_json' => $blocksJson];

    if ($page) {
        // المحتوى بقى في البلوكات (الـ HTML القديم اتنقل كبلوك نص لما الصفحة اتفتحت في المحرر)
        $cols['content_html'] = null;
        $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($cols)));
        s_run("UPDATE site_pages SET {$set}, published_at = COALESCE(published_at, IF(? = 'published', NOW(), NULL)) WHERE id = ?", array_merge(array_values($cols), [$d['status'], $id]));
        if ($page['slug'] !== $d['slug']) {
            s_run('DELETE FROM site_redirects WHERE from_slug = ?', [$d['slug']]);
            s_run('INSERT INTO site_redirects (from_slug, to_slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE to_slug = VALUES(to_slug)', [$page['slug'], $d['slug']]);
            s_run('UPDATE site_redirects SET to_slug = ? WHERE to_slug = ?', [$d['slug'], $page['slug']]);
        }
        $was = (string) ($page['status'] ?? 'published');
        if ($was !== 'published' && $d['status'] === 'published') sa_log('publish', 'pages', 'نشر صفحة «' . $d['title'] . '»', $id);
        elseif ($was === 'published' && $d['status'] !== 'published') sa_log('unpublish', 'pages', 'إلغاء نشر صفحة «' . $d['title'] . '» (' . ($d['status'] === 'draft' ? 'مسودة' : 'مخفية') . ')', $id);
        sa_log('update', 'pages', 'تعديل صفحة «' . $d['title'] . '» (' . count($d['blocks']) . ' قسم)' . ($page['slug'] !== $d['slug'] ? ' — الرابط اتغيّر من /' . $page['slug'] . ' لـ /' . $d['slug'] . ' (تحويل 301)' : ''), $id);
        s_flash('success', $d['status'] === 'published' ? 'اتحفظت ومنشورة ✓' : 'اتحفظت كـ ' . ($d['status'] === 'draft' ? 'مسودة' : 'صفحة مخفية') . ' ✓');
    } else {
        if (!$cols['sort_order']) $cols['sort_order'] = (int) (s_one('SELECT COALESCE(MAX(sort_order),0)+1 n FROM site_pages')['n'] ?? 1);
        $cols['is_builtin'] = 0;
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $id = s_insert('INSERT INTO site_pages (' . implode(', ', array_map(fn($c) => "`{$c}`", array_keys($cols))) . ", created_at, published_at) VALUES ({$ph}, NOW(), " . ($d['status'] === 'published' ? 'NOW()' : 'NULL') . ')', array_values($cols));
        if (!$id) { s_flash('danger', 'تعذّر إنشاء الصفحة'); $_SESSION['sa_page_draft'] = $_POST; s_redirect($back); }
        sa_log('create', 'pages', 'إنشاء صفحة «' . $d['title'] . '» (/' . $d['slug'] . ')', $id);
        if ($d['status'] === 'published') sa_log('publish', 'pages', 'نشر صفحة «' . $d['title'] . '»', $id);
        s_flash('success', 'الصفحة اتعملت ✓' . ($d['status'] === 'published' ? ' ومنشورة' : ''));
    }
    s_redirect('site-admin/page-edit.php?id=' . $id . (($_POST['after'] ?? '') === 'preview' ? '&preview=1' : ''));
}

// فورم رجع بخطأ: نرجّع اللي اتكتب
$draft = $_SESSION['sa_page_draft'] ?? null;
unset($_SESSION['sa_page_draft']);
$v = $page ?? ['title' => '', 'slug' => '', 'subtitle' => '', 'status' => 'draft', 'show_in_menu' => 1, 'show_cta' => 1, 'sort_order' => 0, 'is_builtin' => 0];
$blocks = $page ? s_page_blocks($page) : [];
if ($draft) {
    $dd = sa_page_from_post($page);
    $v = array_merge($v, array_diff_key($dd, ['blocks' => 1]));
    $blocks = $dd['blocks'];
}
$types = s_block_types();
$jsTypes = [];
foreach ($types as $k => [$l, $ic, $desc, $fields]) {
    $jsTypes[$k] = ['label' => $l, 'icon' => sa_icon($ic, 20), 'desc' => $desc, 'fields' => array_map(fn($f) => ['k' => $f[0], 'l' => $f[1], 't' => $f[2], 'o' => $f[3] ?? new stdClass()], $fields)];
}
$publicUrl = $page ? s_page_url($page['slug'], true) : '';
$st = (string) ($v['status'] ?? 'published');

$__t = $page ? 'تعديل: ' . $page['title'] : 'صفحة جديدة';
include __DIR__ . '/layout.php';
echo sa_page_head('file', $page ? $page['title'] : 'إضافة صفحة جديدة', 'Page Builder', '',
    ($page ? sa_btn('معاينة المحفوظ', 'sec', s_url('site/page.php?p=' . urlencode($page['slug']) . '&preview=1'), 'eye', ['target' => '_blank', 'rel' => 'noopener']) : '') . sa_btn('كل الصفحات', 'ghost', 'pages.php', 'chev-r'));
if (!empty($_GET['preview']) && $page) echo '<script>window.open(' . json_encode(s_url('site/page.php?p=' . urlencode($page['slug']) . '&preview=1')) . ', "_blank")</script>';
?>
<form method="POST" data-safe-post id="pb-form" class="pb" style="margin-top:18px">
  <?= s_csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <textarea name="blocks_json" id="pb-json" hidden><?= e(json_encode($blocks, JSON_UNESCAPED_UNICODE)) ?></textarea>

  <div style="display:flex;flex-direction:column;gap:18px;min-width:0">
    <section class="ad-card sa-in">
      <div class="ad-form">
        <?= sa_field(['name' => 'title', 'label' => 'عنوان الصفحة', 'req' => true, 'max' => 250, 'ph' => 'مثلًا: عن الوكالات'], $v['title']) ?>
        <div class="ad-f"><label class="ad-label" for="f-slug">الرابط (Slug) <i class="ad-req">*</i></label>
          <div style="display:flex;align-items:center;gap:6px;direction:ltr"><span class="ad-hint" style="white-space:nowrap"><?= e(preg_replace('~^https?://~', '', rtrim(SITE_URL, '/'))) ?>/</span>
          <input class="ad-in" id="f-slug" name="slug" value="<?= e($v['slug']) ?>" required pattern="[a-zA-Z0-9\-_]+" maxlength="120" placeholder="example" <?= !empty($v['is_builtin']) ? 'readonly' : '' ?> dir="ltr"></div>
          <span class="ad-hint"><?= !empty($v['is_builtin']) ? 'رابط الصفحات الأساسية ثابت.' : 'حروف إنجليزي وأرقام و - بس. لو غيّرته بعد النشر، الرابط القديم بيحوّل للجديد تلقائيًا.' ?></span></div>
        <?= sa_field(['name' => 'subtitle', 'label' => 'وصف تحت العنوان', 'type' => 'textarea', 'rows' => 2, 'wide' => true], $v['subtitle']) ?>
      </div>
    </section>

    <section aria-labelledby="pb-h">
      <h2 class="ad-h2" id="pb-h">أقسام الصفحة <small id="pb-count"></small></h2>
      <div class="pb-blocks" id="pb-list" data-pb-list></div>
      <button type="button" class="pb-add" id="pb-add" style="margin-top:10px"><?= sa_icon('plus', 18, 2.2) ?>إضافة قسم</button>
      <p class="ad-foot-hint"><?= sa_icon('grip', 14) ?>اسحب القسم لإعادة الترتيب · 👁 إخفاء/إظهار · الأقسام المخفية مش بتظهر للزوار.</p>
    </section>

    <section class="ad-card sa-in" id="seo">
      <div class="ad-card-h"><h3>SEO والمشاركة</h3><span class="ad-hint">لو سيبتها فاضية بيتاخد عنوان ووصف الصفحة</span></div>
      <div class="pb-serp" style="margin-bottom:16px" aria-label="معاينة في جوجل"><b id="serp-t"></b><small id="serp-u"></small><p id="serp-d"></p></div>
      <div class="ad-form">
        <?= sa_field(['name' => 'seo_title', 'label' => 'Meta Title', 'max' => 255, 'hint' => 'الأفضل أقل من 60 حرف.'], $v['seo_title'] ?? '') ?>
        <?= sa_field(['name' => 'canonical_url', 'label' => 'Canonical URL (اختياري)', 'type' => 'url', 'ph' => 'فاضي = رابط الصفحة'], $v['canonical_url'] ?? '') ?>
        <?= sa_field(['name' => 'seo_description', 'label' => 'Meta Description', 'type' => 'textarea', 'rows' => 2, 'hint' => 'الأفضل بين 120 و 160 حرف.'], $v['seo_description'] ?? '') ?>
        <?= sa_field(['name' => 'og_title', 'label' => 'Open Graph Title', 'max' => 255], $v['og_title'] ?? '') ?>
        <?= sa_field(['name' => 'og_description', 'label' => 'Open Graph Description', 'max' => 500], $v['og_description'] ?? '') ?>
        <div class="ad-f wide"><span class="ad-label">OG Image (صورة المشاركة)</span>
          <div class="ad-drop-x" style="margin:0"><input class="ad-in sm" type="text" dir="ltr" name="og_image" value="<?= e($v['og_image'] ?? '') ?>" placeholder="فاضي = الصورة المميزة أو الافتراضية" data-media-target>
            <button type="button" class="ad-btn ad-soft sm" data-media-pick><?= sa_icon('folder', 16) ?><span>المكتبة</span></button></div></div>
        <div class="ad-f wide"><div class="ad-swrow"><span class="ad-label">No Index — جوجل مايأرشفش الصفحة دي</span><?= sa_switch('noindex', !empty($v['noindex']), 'noindex') ?></div></div>
      </div>
    </section>
  </div>

  <aside class="pb-side">
    <section class="ad-card">
      <div class="ad-card-h"><h3>النشر</h3><?= sa_status_chip($st) ?></div>
      <div class="pb-status" role="radiogroup" aria-label="حالة الصفحة">
        <?php foreach (['published' => 'منشورة', 'draft' => 'مسودة', 'hidden' => 'مخفية'] as $k => $l): ?>
          <label><input type="radio" name="status" value="<?= $k ?>" <?= $st === $k ? 'checked' : '' ?>><span><?= e($l) ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="ad-hint" style="margin:8px 0 14px">مسودة = للأدمن بس · مخفية = بالرابط بس (noindex ومش في القائمة) · منشورة = للكل.</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div class="ad-swrow"><span class="ad-label">تظهر في القائمة</span><?= sa_switch('show_in_menu', !empty($v['show_in_menu'])) ?></div>
        <div class="ad-swrow"><span class="ad-label">قسم «جاهز نبدأ» في الآخر</span><?= sa_switch('show_cta', (int) ($v['show_cta'] ?? 1) === 1) ?></div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;margin-top:16px">
        <?= sa_btn('حفظ', 'pri lg', null, 'check', ['type' => 'submit']) ?>
        <button type="button" class="ad-btn ad-sec" id="pb-preview"><?= sa_icon('eye', 17) ?><span>معاينة التعديلات قبل الحفظ</span></button>
        <?php if ($page): ?><a class="ad-btn ad-ghost" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><?= sa_icon('external', 16) ?><span dir="ltr"><?= e('/' . $page['slug']) ?></span></a><?php endif; ?>
      </div>
    </section>
    <section class="ad-card">
      <div class="ad-card-h"><h3>الصورة المميزة</h3></div>
      <div class="ad-f"><div class="ad-drop-x" style="margin:0"><input class="ad-in sm" type="text" dir="ltr" name="featured_image" value="<?= e($v['featured_image'] ?? '') ?>" placeholder="لينك أو من المكتبة" data-media-target>
        <button type="button" class="ad-btn ad-soft sm" data-media-pick><?= sa_icon('folder', 16) ?><span>المكتبة</span></button></div>
        <span class="ad-hint">بتظهر تحت عنوان الصفحة، وبتستخدم للمشاركة لو مفيش OG Image.</span></div>
      <?= sa_field(['name' => 'sort_order', 'label' => 'الترتيب في القائمة', 'type' => 'number'], (int) ($v['sort_order'] ?? 0)) ?>
    </section>
  </aside>
</form>

<!-- اختيار نوع القسم -->
<div class="ad-ov" data-drawer="pb-types" hidden>
  <div class="ad-ov-bg" data-close></div>
  <aside class="ad-drawer wide" role="dialog" aria-modal="true" aria-labelledby="pbt-t">
    <span class="ad-handle" aria-hidden="true"></span>
    <header class="ad-dh"><h2 id="pbt-t">إضافة قسم</h2><button type="button" class="ad-ib" data-close aria-label="إغلاق"><?= sa_icon('x', 20) ?></button></header>
    <div class="ad-db"><div class="pb-types" id="pb-types"></div></div>
  </aside>
</div>
<!-- تعديل قسم -->
<div class="ad-ov" data-drawer="pb-edit" hidden>
  <div class="ad-ov-bg" data-close></div>
  <aside class="ad-drawer wide" role="dialog" aria-modal="true" aria-labelledby="pbe-t">
    <span class="ad-handle" aria-hidden="true"></span>
    <header class="ad-dh"><h2 id="pbe-t">تعديل القسم</h2><button type="button" class="ad-ib" data-close aria-label="إغلاق"><?= sa_icon('x', 20) ?></button></header>
    <div class="ad-db" id="pb-fields"></div>
    <footer class="ad-df"><button type="button" class="ad-btn ad-pri wide" id="pb-done"><?= sa_icon('check', 17, 2) ?><span>تمام</span></button><button type="button" class="ad-btn ad-sec" data-close>إلغاء</button></footer>
  </aside>
</div>

<form method="POST" action="page-preview.php" target="_blank" id="pb-prev-form" data-safe-post hidden><?= s_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><textarea name="payload"></textarea></form>
<script>window.PB = <?= json_encode(['types' => $jsTypes, 'icons' => array_keys(s_icon_paths()), 'iconSvg' => array_map(fn($n) => s_icon($n, 18), array_combine(array_keys(s_icon_paths()), array_keys(s_icon_paths()))), 'upload' => 'media.php', 'site' => preg_replace('~^https?://~', '', rtrim(SITE_URL, '/')),
    'ic' => ['edit' => sa_icon('edit', 17), 'copy' => sa_icon('copy', 17), 'trash' => sa_icon('trash', 17), 'up' => sa_icon('up', 17), 'down' => sa_icon('down', 17),
             'eye' => sa_icon('eye', 17), 'eyeOff' => sa_icon('eye-off', 17), 'grip' => sa_icon('grip', 17), 'plus' => sa_icon('plus', 16, 2.2), 'folder' => sa_icon('folder', 16), 'upload' => sa_icon('upload', 16)]],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(s_url('site-assets/js/page-builder.js')) ?>?v=<?= @filemtime(dirname(__DIR__) . '/site-assets/js/page-builder.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/layout-end.php'; ?>
