<?php
/**
 * الترجمة الإنجليزي لمحتوى الموقع — الصفحة الرئيسية لوحدها · كل صفحة داخلية لوحدها
 *
 * الصفحة بترسم الرئيسية (أو الصفحة الداخلية) جوه السيرفر بالإنجليزي وبتجمع كل نص عربي ظاهر فيها
 * (العناوين · الأقسام · الخدمات · الأسئلة · الأسعار · البلوكات …) ← جدول: العربي | الإنجليزي.
 * الإنجليزي اللي بيتكتب هنا بيتحفظ في site_translations وبيظهر للزائر اللي فاتح الموقع بالإنجليزي.
 * فاضي = الترجمة الجاهزة (لو فيه) وإلا النص العربي زي ما هو.
 * مابيغيّرش أي محتوى عربي — العربي بيتعدّل من صفحاته زي ما هو.
 */
require_once __DIR__ . '/auth.php';
sa_require_perm('homepage');
require_once dirname(__DIR__) . '/site/home.php';
require_once dirname(__DIR__) . '/site/blocks.php';

$ready = s_tr_ensure();
$pages = s_all("SELECT id, slug, title FROM site_pages ORDER BY sort_order, id");
$tab = (string) ($_GET['tab'] ?? 'home');
$slug = preg_replace('/[^a-z0-9\-_]/i', '', (string) ($_GET['p'] ?? ''));
$page = $tab === 'page' ? ($slug !== '' ? s_one('SELECT * FROM site_pages WHERE slug = ?', [$slug]) : null) : null;
if ($tab === 'page' && !$page && $pages) {
    $page = s_one('SELECT * FROM site_pages WHERE id = ?', [(int) $pages[0]['id']]);
    $slug = (string) ($page['slug'] ?? '');
}
$scope = $tab === 'page' ? 'page:' . $slug : 'home';

/**
 * كل النصوص العربي اللي بتظهر في الصفحة دي — بنرسمها بالإنجليزي جوه الـ buffer ونجمع اللي عدّى على __()
 * @return string[] بالترتيب اللي ظهرت بيه
 */
function tr_collect(string $tab, ?array $page): array
{
    $GLOBALS['__i18n_seen'] = [];
    $prev = i18n_locale();
    i18n_set('en');
    ob_start();
    try {
        $ctx = s_home_ctx();
        extract($ctx, EXTR_SKIP);
        if ($tab === 'home') {
            // عنوان الصفحة ووصفها في جوجل والمشاركة
            foreach (['seo_home_title', 'seo_home_description', 'tagline'] as $__k) if (s_setting($__k) !== '') __(s_setting($__k));
            foreach (s_home_visible_order() as $key) echo s_render_section($key, $ctx);
            // الهيدر والفوتر (بيظهروا في كل الصفحات — بيتترجموا هنا مرة واحدة)
            $nav = [['الرئيسية', '#'], ['الخدمات', '#'], ['التصميمات', '#'], ['إزاي بيشتغل', '#'], ['الأسعار', '#'], ['اصنع منشورك', '#']];
            $mnav = $nav;
            foreach (s_menu_pages() as $pg) $nav[] = [$pg['title'], '#'];
            $homeHref = '#';
            $ctaHref = '#';
            include dirname(__DIR__) . '/site/parts/site-header.php';
            include dirname(__DIR__) . '/site/parts/site-footer.php';
        } elseif ($page) {
            foreach (['title', 'subtitle', 'seo_title', 'seo_description', 'og_title', 'og_description'] as $__k) if (trim((string) ($page[$__k] ?? '')) !== '') __((string) $page[$__k]);
            echo s_render_blocks(s_page_blocks($page));
            if ((int) ($page['show_cta'] ?? 1) === 1) echo s_render_section('cta', $ctx);
        }
    } catch (\Throwable $e) {
        error_log('[site-i18n] collect: ' . $e->getMessage());
    }
    ob_end_clean();
    i18n_set($prev);
    $keys = array_keys($GLOBALS['__i18n_seen']);
    unset($GLOBALS['__i18n_seen']);
    // من غير التكرار الفاضي وأي حاجة أرقام بس
    return array_values(array_filter($keys, fn($k) => trim((string) $k) !== '' && preg_match('/\p{Arabic}/u', (string) $k)));
}

$keys = $ready ? tr_collect($tab, $page) : [];
$byHash = [];
foreach ($keys as $k) $byHash[sha1($k)] = $k;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    s_check_csrf();
    s_decode_b64();
    $me = sa_admin();
    $n = 0;
    foreach ((array) ($_POST['en'] ?? []) as $h => $v) {
        $h = (string) $h;
        if (!isset($byHash[$h])) continue;   // نص مش في الصفحة دي
        $v = trim(str_replace("\r\n", "\n", (string) $v));
        if ($v === '') {
            if (s_run('DELETE FROM site_translations WHERE src_hash = ?', [$h])) $n++;
            continue;
        }
        $v = mb_substr($v, 0, 20000);
        s_run('INSERT INTO site_translations (src_hash, src, en, scope, updated_by) VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE en = VALUES(en), scope = VALUES(scope), updated_by = VALUES(updated_by)',
            [$h, $byHash[$h], $v, $scope, (int) ($me['id'] ?? 0) ?: null]);
        $n++;
    }
    sa_log('settings', 'translations', 'الترجمة الإنجليزي — ' . ($scope === 'home' ? 'الصفحة الرئيسية' : 'صفحة ' . $slug));
    s_flash('success', 'اتحفظت الترجمة ✓');
    s_redirect('site-admin/translations.php?tab=' . urlencode($tab) . ($tab === 'page' ? '&p=' . urlencode($slug) : ''));
}

// الحالة الحالية لكل نص: مكتوب من هنا · ترجمة جاهزة (ملفات المنصة) · ناقص
$db = [];
if ($ready && $byHash) {
    $ph = implode(',', array_fill(0, count($byHash), '?'));
    foreach (s_all("SELECT src_hash, en FROM site_translations WHERE src_hash IN ($ph)", array_keys($byHash)) as $r) $db[$r['src_hash']] = (string) $r['en'];
}
i18n_set('en');
$fileDict = [];
foreach (glob(dirname(__DIR__) . '/lang/en/*.php') ?: [] as $f) { $part = include $f; if (is_array($part)) $fileDict = $part + $fileDict; }
i18n_set('ar');
$rows = [];
$missing = 0;
foreach ($byHash as $h => $src) {
    $mine = $db[$h] ?? '';
    $ready_ = $fileDict[$src] ?? '';
    if ($mine === '' && $ready_ === '') $missing++;
    $rows[] = ['h' => $h, 'src' => $src, 'en' => $mine, 'ready' => $ready_];
}

$__t = 'الترجمة الإنجليزي';
include __DIR__ . '/layout.php';
$__siteLink = $tab === 'home' ? s_url('index.php') . '?lang=en' : s_page_url($slug) . (str_contains(s_page_url($slug), '?') ? '&' : '?') . 'lang=en';
echo sa_page_head('globe', 'الترجمة الإنجليزي', 'English content',
    'كل نص بيظهر في الصفحة بالعربي، وجنبه الإنجليزي اللي هيشوفه الزائر لما يدوس على علم إنجلترا. الصفحة الرئيسية لوحدها، وكل صفحة داخلية لوحدها.',
    sa_btn('شوف الصفحة بالإنجليزي', 'sec', $__siteLink, 'external', ['target' => '_blank', 'rel' => 'noopener']));
?>
<style>
.tr-tabs { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 0 0 14px; }
.tr-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px 0; border-top: 1px solid #E6EDF5; }
.tr-row:first-child { border-top: 0; }
.tr-src { font-size: 14px; line-height: 1.8; white-space: pre-wrap; overflow-wrap: anywhere; }
.tr-src small { display: block; color: #7a879a; font-size: 12px; margin-top: 4px; }
.tr-row textarea { width: 100%; min-height: 44px; font: inherit; font-size: 14px; line-height: 1.6; padding: 8px 10px; border-radius: 10px; border: 1px solid #D9E3EE; resize: vertical; direction: ltr; text-align: left; }
.tr-row.miss textarea { border-color: #F3C96B; background: #FFFBEF; }
.tr-filter { display: flex; gap: 10px; align-items: center; font-size: 13px; }
@media (max-width: 760px) { .tr-row { grid-template-columns: 1fr; } }
</style>
<?php if (!$ready): ?>
  <div class="ad-card"><p>جدول الترجمة ماتعملش — صلاحيات قاعدة بيانات الموقع مش سامحة بإنشاء جدول. شغّل <span dir="ltr">CREATE TABLE site_translations</span> يدوي (الكود في site/functions.php → s_tr_ensure).</p></div>
<?php else: ?>
<div class="tr-tabs">
  <?= sa_btn('الصفحة الرئيسية', $tab === 'home' ? 'pri' : 'ghost', s_url('site-admin/translations.php?tab=home'), 'layout') ?>
  <?= sa_btn('الصفحات الداخلية', $tab === 'page' ? 'pri' : 'ghost', s_url('site-admin/translations.php?tab=page'), 'file') ?>
  <?php if ($tab === 'page' && $pages): ?>
    <form method="GET" style="display:flex;gap:6px;align-items:center"><input type="hidden" name="tab" value="page">
      <select class="ad-in sm" name="p" onchange="this.form.submit()" aria-label="الصفحة">
        <?php foreach ($pages as $pg): ?><option value="<?= e($pg['slug']) ?>" <?= $pg['slug'] === $slug ? 'selected' : '' ?>><?= e($pg['title']) ?></option><?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
  <span style="flex:1"></span>
  <?= sa_chip(count($rows) . ' نص', 'info') ?> <?= sa_chip($missing ? $missing . ' من غير ترجمة' : 'كله مترجم ✓', $missing ? 'warn' : 'ok') ?>
</div>

<?php if ($tab === 'page' && !$page): ?>
  <div class="ad-card"><p>مفيش صفحات داخلية لسه.</p></div>
<?php else: ?>
<form method="POST" data-safe-post class="ad-card sa-in">
  <?= s_csrf_field() ?>
  <div class="tr-filter"><label><input type="checkbox" id="tr-only"> الناقص بس</label>
    <span class="ad-hint">فاضي = الترجمة الجاهزة اللي تحت الخانة (لو فيه)، وإلا بيظهر العربي.</span></div>
  <div id="tr-list">
  <?php foreach ($rows as $r): $long = mb_strlen($r['src']) > 90 || str_contains($r['src'], "\n") || str_contains($r['src'], '<'); ?>
    <div class="tr-row<?= $r['en'] === '' && $r['ready'] === '' ? ' miss' : '' ?>">
      <div class="tr-src" dir="rtl"><?= e($r['src']) ?></div>
      <div>
        <textarea name="en[<?= e($r['h']) ?>]" rows="<?= $long ? 4 : 1 ?>" placeholder="<?= e($r['ready'] !== '' ? $r['ready'] : 'English…') ?>" aria-label="English"><?= e($r['en']) ?></textarea>
        <?php if ($r['ready'] !== '' && $r['en'] === ''): ?><div class="ad-hint" dir="ltr" style="text-align:left">Ready translation in use ✓</div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p>مفيش نصوص عربي في الصفحة دي.</p><?php endif; ?>
  </div>
  <div style="margin-top:16px;display:flex;gap:8px"><?= sa_btn('حفظ الترجمة', 'pri lg', null, 'check', ['type' => 'submit']) ?></div>
</form>
<?php endif; ?>
<?php endif; ?>
<script>
(function () {
  var cb = document.getElementById('tr-only'); if (!cb) return;
  cb.addEventListener('change', function () {
    document.querySelectorAll('.tr-row').forEach(function (r) { r.hidden = cb.checked && !r.classList.contains('miss'); });
  });
})();
</script>
<?php include __DIR__ . '/layout-end.php'; ?>
