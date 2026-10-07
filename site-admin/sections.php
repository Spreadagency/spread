<?php
/** أقسام المحتوى — عنوان ووصف كل قسم في الرئيسية + الإظهار والترتيب */
require_once __DIR__ . '/auth.php';
sa_require_perm('homepage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    $changed = [];
    foreach (($_POST['sec'] ?? []) as $id => $d) {
        $old = s_one('SELECT * FROM site_sections WHERE id = ?', [(int) $id]);
        $new = [
            mb_substr(trim((string) ($d['title'] ?? '')), 0, 255) ?: null,
            mb_substr(trim((string) ($d['subtitle'] ?? '')), 0, 1000) ?: null,
            !empty($d['vis']) ? 1 : 0,
            (int) ($d['ord'] ?? 0),
        ];
        if ($old && ([(string) $old['title'], (string) $old['subtitle'], (int) $old['is_visible'], (int) $old['sort_order']] != [(string) $new[0], (string) $new[1], $new[2], $new[3]])) {
            $changed[] = $old['label_ar'];
        }
        s_run('UPDATE site_sections SET title = ?, subtitle = ?, is_visible = ?, sort_order = ? WHERE id = ?', array_merge($new, [(int) $id]));
    }
    if ($changed) sa_log('update', 'homepage', 'تعديل أقسام الرئيسية: ' . implode('، ', $changed));
    s_flash('success', 'تم حفظ الأقسام ✓');
    s_redirect('site-admin/sections.php');
}

$rows = s_all('SELECT * FROM site_sections ORDER BY sort_order, id');
$__t = 'أقسام المحتوى';
include __DIR__ . '/layout.php';
echo sa_page_head('puzzle', 'أقسام المحتوى', 'Sections', 'عنوان ووصف كل قسم في الصفحة الرئيسية. الترتيب والإظهار كمان من «الصفحة الرئيسية» بالسحب.',
    sa_btn('ترتيب الأقسام', 'soft', 'homepage.php', 'drag'));
?>
<form method="POST" data-safe-post>
  <?= s_csrf_field() ?>
  <div class="ad-grid ad-g2">
  <?php foreach ($rows as $r): ?>
    <section class="ad-card sa-in" id="sec-<?= (int) $r['id'] ?>">
      <div class="ad-card-h">
        <h3><?= e($r['label_ar']) ?> <?= sa_chip($r['section_key'], 'off') ?></h3>
        <?= sa_switch('sec[' . (int) $r['id'] . '][vis]', (bool) $r['is_visible'], 'ظاهر') ?>
      </div>
      <div class="ad-form">
        <?= sa_field(['name' => 'sec[' . (int) $r['id'] . '][title]', 'label' => 'عنوان القسم', 'wide' => true], $r['title']) ?>
        <?= sa_field(['name' => 'sec[' . (int) $r['id'] . '][subtitle]', 'label' => 'الوصف تحت العنوان', 'type' => 'textarea', 'rows' => 2], $r['subtitle']) ?>
        <?= sa_field(['name' => 'sec[' . (int) $r['id'] . '][ord]', 'label' => 'الترتيب', 'type' => 'number'], (int) $r['sort_order']) ?>
      </div>
    </section>
  <?php endforeach; ?>
  </div>
  <div class="ad-sticky-bar"><?= sa_btn('حفظ كل الأقسام', 'pri lg', null, 'check', ['type' => 'submit']) ?></div>
</form>
<?php include __DIR__ . '/layout-end.php'; ?>
