<?php
/** إظهار/إخفاء الأقسام وتعديل عناوينها */
require_once __DIR__ . '/auth.php';
sa_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
        s_decode_b64();
    foreach (($_POST['sec'] ?? []) as $id => $d) {
        s_run('UPDATE site_sections SET title = ?, subtitle = ?, is_visible = ?, sort_order = ? WHERE id = ?', [
            mb_substr(trim((string) ($d['title'] ?? '')), 0, 255) ?: null,
            mb_substr(trim((string) ($d['subtitle'] ?? '')), 0, 1000) ?: null,
            !empty($d['vis']) ? 1 : 0,
            (int) ($d['ord'] ?? 0),
            (int) $id,
        ]);
    }
    s_flash('success', 'تم حفظ الأقسام ✓');
    s_redirect('site-admin/sections.php');
}

$rows = s_all('SELECT * FROM site_sections ORDER BY sort_order, id');
$__t = 'الأقسام والعناوين';
include __DIR__ . '/layout.php';
?>
<div class="card" style="background:rgba(15,60,201,.06);border-color:rgba(15,60,201,.2)">
  تحكّم في إظهار كل قسم في الصفحة الرئيسية وعنوانه. شيل العلامة عشان تخفي القسم بالكامل.
</div>
<form method="POST" data-safe-post>
  <?= s_csrf_field() ?>
  <?php foreach ($rows as $r): ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <h3 style="margin:0"><?= e($r['label_ar']) ?> <span class="chip" style="font-size:10px"><?= e($r['section_key']) ?></span></h3>
        <label style="display:flex;gap:8px;align-items:center;font-weight:600;cursor:pointer">
          <input type="checkbox" name="sec[<?= (int) $r['id'] ?>][vis]" value="1" <?= $r['is_visible'] ? 'checked' : '' ?> style="width:auto">
          <span>ظاهر في الموقع</span>
        </label>
      </div>
      <div class="row">
        <div class="f"><label>عنوان القسم</label>
          <input type="text" name="sec[<?= (int) $r['id'] ?>][title]" value="<?= e($r['title']) ?>"></div>
        <div class="f"><label>الترتيب</label>
          <input type="number" name="sec[<?= (int) $r['id'] ?>][ord]" value="<?= (int) $r['sort_order'] ?>"></div>
        <div class="f" style="grid-column:1/-1"><label>الوصف تحت العنوان</label>
          <input type="text" name="sec[<?= (int) $r['id'] ?>][subtitle]" value="<?= e($r['subtitle']) ?>"></div>
      </div>
    </div>
  <?php endforeach; ?>
  <button class="btn">💾 حفظ كل الأقسام</button>
</form>
<?php include __DIR__ . '/layout-end.php'; ?>
