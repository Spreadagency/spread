<?php
/** شريط صغير للأدمن بس (مش ظاهر للزوار): رجوع للوحة · تعديل الصفحة الحالية */
$__a = s_current_admin();
if (!$__a) return;
?>
<div class="adm-bar" role="region" aria-label="أدوات الأدمن">
  <a href="<?= e(s_url('site-admin/dashboard.php')) ?>"><?= s_icon('layers', 16) ?>لوحة الموقع</a>
  <?php if (!empty($__editUrl)): ?><a href="<?= e($__editUrl) ?>"><?= s_icon('pen', 16) ?>تعديل الصفحة</a><?php endif; ?>
  <?php if (!empty($__previewNote)): ?><span><?= e($__previewNote) ?></span><?php endif; ?>
</div>
