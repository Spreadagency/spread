<?php
/** الصفحة الرئيسية — ترتيب الأقسام بالسحب · إظهار/إخفاء · رابط تعديل محتوى كل قسم · معاينة الترتيب */
require_once __DIR__ . '/auth.php';
sa_require_perm('homepage');
require_once dirname(__DIR__) . '/site/home.php';
s_home_upgrade();

/** كل قسم: [English, صفحة تعديل المحتوى, ستايل المعاينة] */
function sa_home_map(): array
{
    return [
        'hero' => ['Hero', 'slides.php', 'hero'], 'ribbon' => ['Marquee', 'settings.php?tab=header', 'tl'], 'trial' => ['Create Post', 'create-post.php', 'bl'],
        'brands' => ['Brands', 'brands.php', ''], 'promos' => ['Offers', 'promos.php', 'tl'], 'problems' => ['Problems', 'problems.php', 'dk'],
        'about' => ['Solutions', 'solutions.php', ''], 'steps' => ['How It Works', 'steps.php', 'bl'], 'services' => ['Features', 'services.php', ''],
        'gallery' => ['Product Showcase', 'gallery.php', 'dk'], 'pricing' => ['Pricing', 'packages.php', ''], 'testimonials' => ['Testimonials', 'testimonials.php', 'tl'],
        'faq' => ['FAQ', 'faq.php', ''], 'cta' => ['CTA', 'settings.php?tab=cta', 'dk'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sa_check_csrf_json();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reorder_ajax') {
        foreach (array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))) as $i => $id) {
            s_run('UPDATE site_sections SET sort_order = ? WHERE id = ?', [$i + 1, $id]);
        }
        sa_log('reorder', 'homepage', 'إعادة ترتيب أقسام الصفحة الرئيسية');
        sa_json(['ok' => true, 'message' => 'الترتيب اتحفظ وظاهر في الموقع ✓']);
    }
    if ($action === 'toggle_ajax') {
        $id = (int) ($_POST['id'] ?? 0);
        $r = s_one('SELECT * FROM site_sections WHERE id = ?', [$id]);
        if (!$r) sa_json(['ok' => false, 'error' => 'القسم مش موجود']);
        $on = !empty($_POST['on']) ? 1 : 0;
        s_run('UPDATE site_sections SET is_visible = ? WHERE id = ?', [$on, $id]);
        sa_log('toggle', 'homepage', ($on ? 'إظهار' : 'إخفاء') . ' قسم «' . $r['label_ar'] . '» في الرئيسية', $id);
        sa_json(['ok' => true, 'message' => $on ? 'القسم ظاهر في الرئيسية ✓' : 'القسم اتخفى من الرئيسية']);
    }
    sa_json(['ok' => false, 'error' => 'إجراء غير معروف'], 400);
}

$rows = s_all('SELECT * FROM site_sections ORDER BY sort_order, id');
$map = sa_home_map();
$visible = count(array_filter($rows, fn($r) => (int) $r['is_visible'] === 1));

$__t = 'الصفحة الرئيسية';
include __DIR__ . '/layout.php';
echo sa_page_head('layout', 'الصفحة الرئيسية', 'Homepage', 'رتّب أقسام الصفحة الرئيسية بالسحب، أظهر أو اخفي أي قسم، ودوس ✎ علشان تعدّل محتواه — التغييرات بتظهر في الموقع على طول.',
    sa_btn('العناوين والأوصاف', 'soft', 'sections.php', 'type') . sa_btn('معاينة الموقع', 'sec', s_url('index.php'), 'eye', ['target' => '_blank', 'rel' => 'noopener']));
?>
<div class="ad-split">
  <div>
    <div class="ad-rows" data-sortable>
      <?php foreach ($rows as $i => $r): [$en, $edit] = $map[$r['section_key']] ?? [$r['section_key'], 'sections.php']; ?>
        <div class="ad-rowc sa-in<?= $r['is_visible'] ? '' : ' off' ?>" data-id="<?= (int) $r['id'] ?>" data-key="<?= e($r['section_key']) ?>">
          <span class="ad-ib ad-grab ad-only-d" data-grab title="اسحب لإعادة الترتيب" aria-hidden="true"><?= sa_icon('grip', 17) ?></span>
          <span class="n" data-n><?= $i + 1 ?></span>
          <span class="tt"><b><?= e($r['label_ar']) ?></b><small dir="ltr" style="text-align:right"><?= e($en) ?></small></span>
          <?= sa_switch('v' . (int) $r['id'], (bool) $r['is_visible'], '', ['data-toggle-id' => (string) (int) $r['id'], 'aria-label' => 'إظهار «' . $r['label_ar'] . '»']) ?>
          <div class="ad-acts-in">
            <button type="button" class="ad-ib" data-move="up" aria-label="لفوق" title="لفوق"><?= sa_icon('up', 17) ?></button>
            <button type="button" class="ad-ib" data-move="down" aria-label="لتحت" title="لتحت"><?= sa_icon('down', 17) ?></button>
            <a class="ad-ib" href="<?= e($edit) ?>" title="تعديل المحتوى" aria-label="تعديل محتوى «<?= e($r['label_ar']) ?>»"><?= sa_icon('edit', 17) ?></a>
            <a class="ad-ib" href="sections.php#sec-<?= (int) $r['id'] ?>" title="العنوان والوصف" aria-label="عنوان «<?= e($r['label_ar']) ?>»"><?= sa_icon('type', 17) ?></a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="ad-foot-hint"><?= sa_icon('grip', 14) ?>اسحب القسم من المقبض لإعادة الترتيب، أو استخدم الأسهم — الترتيب بيتحفظ تلقائيًا.</p>
  </div>

  <aside class="ad-card" style="position:sticky;top:calc(var(--top) + 20px)">
    <div class="ad-card-h"><h3>معاينة الترتيب</h3><a class="ad-btn ad-soft sm" href="<?= e(s_url('index.php')) ?>" target="_blank" rel="noopener"><?= sa_icon('external', 14) ?>افتح الموقع</a></div>
    <div class="ad-mini-site" id="mini-site">
      <?php foreach ($rows as $r): $sty = $map[$r['section_key']][2] ?? ''; ?>
        <span class="<?= e($sty) ?><?= $r['is_visible'] ? '' : ' off' ?>" data-key="<?= e($r['section_key']) ?>"><?= e($r['label_ar']) ?></span>
      <?php endforeach; ?>
    </div>
    <p class="ad-hint" style="margin:10px 0 0"><b id="vis-n"><?= $visible ?></b> ظاهر من <?= count($rows) ?> — الأقسام اللي مالهاش محتوى بتستخبى تلقائيًا للزوار.</p>
  </aside>
</div>
<script>
(function () {
  var mini = document.getElementById('mini-site');
  function sync() {
    var rows = document.querySelectorAll('[data-sortable] > [data-key]'), n = 0;
    rows.forEach(function (r) {
      var k = r.getAttribute('data-key'), s = mini.querySelector('[data-key="' + k + '"]');
      var on = r.querySelector('input[type=checkbox]').checked; if (on) n++;
      if (s) { s.classList.toggle('off', !on); mini.appendChild(s); }
    });
    document.getElementById('vis-n').textContent = n;
  }
  window.saAfterReorder = sync; window.saAfterToggle = sync;
})();
</script>
<?php include __DIR__ . '/layout-end.php'; ?>
