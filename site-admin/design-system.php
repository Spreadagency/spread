<?php
/** الهوية البصرية (Design System) — ألوان وخط وشكل الأزرار والظلال والاستدارة للموقع كله، بمعاينة حيّة */
require_once __DIR__ . '/auth.php';
sa_require_perm('design');

$D = s_design_defaults();
$fields = [
    'ds_primary'    => ['Primary', 'color'],
    'ds_secondary'  => ['Secondary', 'color'],
    'ds_accent'     => ['Accent', 'color'],
    'ds_background' => ['Background', 'color'],
    'ds_ink'        => ['Text', 'color'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    if (!empty($_POST['reset'])) {
        foreach (array_keys($D) as $k) s_set($k, '');
        sa_log('settings', 'design', 'رجوع الهوية البصرية للإعدادات الافتراضية');
        s_flash('success', 'رجعت للهوية الافتراضية ✓');
        s_redirect('site-admin/design-system.php');
    }
    $changed = [];
    foreach ($fields as $k => [$l]) {
        $v = trim((string) ($_POST[$k] ?? ''));
        if ($v !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) continue;
        if (strtolower($v) === strtolower($D[$k])) $v = '';
        if ($v !== s_setting($k)) $changed[] = $l;
        s_set($k, $v);
    }
    $font = in_array($_POST['ds_font'] ?? '', ['Readex Pro', 'IBM Plex Sans Arabic'], true) ? $_POST['ds_font'] : $D['ds_font'];
    $btn = in_array($_POST['ds_buttons'] ?? '', ['gradient', 'solid'], true) ? $_POST['ds_buttons'] : $D['ds_buttons'];
    $sh = in_array($_POST['ds_shadow'] ?? '', ['soft', 'none', 'strong'], true) ? $_POST['ds_shadow'] : $D['ds_shadow'];
    $rad = max(4, min(36, (int) ($_POST['ds_radius'] ?? $D['ds_radius'])));
    foreach (['ds_font' => $font, 'ds_buttons' => $btn, 'ds_shadow' => $sh, 'ds_radius' => (string) $rad] as $k => $v) {
        $v = $v === $D[$k] ? '' : $v;
        if ($v !== s_setting($k)) $changed[] = $k;
        s_set($k, $v);
    }
    if ($changed) sa_log('settings', 'design', 'تعديل الهوية البصرية: ' . implode('، ', $changed));
    s_flash('success', 'الهوية اتحفظت وظاهرة في الموقع ✓');
    s_redirect('site-admin/design-system.php');
}

$cur = s_design();
$__t = 'الهوية البصرية';
include __DIR__ . '/layout.php';
echo sa_page_head('palette', 'الهوية البصرية', 'Design System', 'الـ Design System بتاع الموقع — نفس لغة Spread AI، وتقدر تتحكم فيها من هنا. أي تغيير بيتطبق على كل صفحات الموقع.');
?>
<form method="POST" data-safe-post class="ad-split wide-side" id="ds-form">
  <?= s_csrf_field() ?>
  <section class="ad-card sa-in">
    <div class="ad-card-h"><h3>الألوان</h3></div>
    <div class="ad-form">
      <?php foreach ($fields as $k => [$l]): ?>
        <?= sa_field(['name' => $k, 'label' => $l, 'type' => 'color', 'default' => $D[$k]], $cur[$k]) ?>
      <?php endforeach; ?>
    </div>
    <div class="ad-form" style="margin-top:20px">
      <?= sa_field(['name' => 'ds_font', 'label' => 'خط العناوين', 'type' => 'select', 'options' => ['Readex Pro' => 'Readex Pro', 'IBM Plex Sans Arabic' => 'IBM Plex Sans Arabic']], $cur['ds_font']) ?>
      <?= sa_field(['name' => 'ds_buttons', 'label' => 'شكل الأزرار', 'type' => 'select', 'options' => ['gradient' => 'تدرّج', 'solid' => 'لون واحد']], $cur['ds_buttons']) ?>
      <?= sa_field(['name' => 'ds_shadow', 'label' => 'الظلال', 'type' => 'select', 'options' => ['soft' => 'ناعم', 'strong' => 'واضح', 'none' => 'بدون']], $cur['ds_shadow']) ?>
      <div class="ad-f"><label class="ad-label" for="ds-r">Border Radius · <b id="ds-rv"><?= (int) $cur['ds_radius'] ?>px</b></label>
        <input id="ds-r" type="range" name="ds_radius" min="4" max="36" value="<?= (int) $cur['ds_radius'] ?>" style="width:100%;accent-color:#0A6FD8;height:46px"></div>
    </div>
    <p class="ad-hint" style="margin:14px 0 0">الخطوط المعتمدة: IBM Plex Sans Arabic للنصوص و Readex Pro للعناوين — نفس هوية Spread AI.</p>
  </section>
  <aside style="display:flex;flex-direction:column;gap:14px;position:sticky;top:calc(var(--top) + 20px)">
    <span class="ad-label">معاينة مباشرة</span>
    <div id="ds-prev" class="ad-card" style="padding:24px">
      <span class="ds-chip" style="display:inline-flex;height:26px;padding:0 12px;border-radius:999px;font-size:12px;font-weight:700;align-items:center">جديد</span>
      <h3 class="ds-h" style="margin:12px 0 6px;font-size:22px">عنوان كارت على الموقع</h3>
      <p style="margin:0 0 16px;font-size:13.5px;color:#4A5468">كده هتبان الكروت والأزرار بالإعدادات الحالية.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><span class="ds-pri" style="display:inline-flex;align-items:center;height:46px;padding:0 20px;color:#fff;font-weight:600">ابدأ الآن</span>
        <span class="ds-gh" style="display:inline-flex;align-items:center;height:46px;padding:0 20px;font-weight:600;border:1.5px solid">اعرف أكتر</span></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px" aria-hidden="true">
      <span class="ds-sw1" style="height:40px;border-radius:12px"></span><span class="ds-sw2" style="height:40px;border-radius:12px"></span><span class="ds-sw3" style="height:40px;border-radius:12px"></span>
    </div>
    <div style="display:flex;gap:10px"><?= sa_btn('حفظ الهوية', 'pri wide', null, 'check', ['type' => 'submit']) ?>
      <button type="submit" name="reset" value="1" class="ad-btn ad-sec" formnovalidate data-no-encode="1" onclick="return confirm('ترجع للهوية الافتراضية؟')">الافتراضي</button></div>
  </aside>
</form>
<script>
(function () {
  var f = document.getElementById('ds-form'), p = document.getElementById('ds-prev');
  function v(n) { var el = f.querySelector('[name="' + n + '"]'); return el ? (el.value || el.placeholder) : ''; }
  function paint() {
    var pri = v('ds_primary'), sec = v('ds_secondary'), acc = v('ds_accent'), r = v('ds_radius') + 'px';
    var grad = v('ds_buttons') === 'solid' ? pri : 'linear-gradient(100deg,' + sec + ' -40%,' + pri + ' 70%)';
    var sh = { soft: '0 18px 44px rgba(40,80,160,.10)', strong: '0 24px 54px rgba(12,40,90,.22)', none: 'none' }[v('ds_shadow')];
    p.style.borderRadius = r; p.style.boxShadow = sh; p.style.background = '#fff';
    p.querySelector('.ds-h').style.fontFamily = '"' + v('ds_font') + '",sans-serif'; p.querySelector('.ds-h').style.color = v('ds_ink');
    p.querySelector('.ds-chip').style.background = sec; p.querySelector('.ds-chip').style.color = '#0B1526';
    var b = p.querySelector('.ds-pri'); b.style.background = grad; b.style.borderRadius = r; b.style.boxShadow = sh === 'none' ? 'none' : '0 10px 22px rgba(10,111,216,.25)';
    var g = p.querySelector('.ds-gh'); g.style.borderColor = acc; g.style.color = acc; g.style.borderRadius = r;
    document.querySelector('.ds-sw1').style.background = pri; document.querySelector('.ds-sw2').style.background = sec; document.querySelector('.ds-sw3').style.background = acc;
    document.getElementById('ds-rv').textContent = r;
    p.parentNode.parentNode.style.setProperty('--x', '1');
  }
  f.addEventListener('input', paint); f.addEventListener('change', paint); paint();
})();
</script>
<?php include __DIR__ . '/layout-end.php'; ?>
