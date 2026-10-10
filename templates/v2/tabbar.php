<?php
/**
 * Spread AI v2 — الشريط العائم للموبايل (المرحلة 10)
 * الرئيسية · حملاتي · [＋] · المحتويات · الهوية
 *   • «الهوية» = مركز البراند (Brand Brain: الهوية · الخدمات · الجمهور · النبرة · الألوان · اللوجو · التواصل · السوشيال · المستندات · الإلهام)
 *   • الإعدادات خرجت من الشريط ← قائمة الحساب (الصورة فوق)
 *   • ＋ بيفتح قائمة: حملة جديدة · تصميم جديد · منشور جديد
 */
require_once __DIR__ . '/../../includes/ui-v2.php';
$__section = ui_section_of($active ?? '');
$__t = function (string $sec, string $icon, string $label, string $href) use ($__section) {
    $on = $__section === $sec;
    return '<a href="' . e(url($href)) . '" class="' . ($on ? 'on' : '') . '"' . ($on ? ' aria-current="page"' : '') . '>'
        . ui_icon($icon, 22) . '<span>' . e($label) . '</span></a>';
};
$__campOn = ui_campaigns_on();
$__quick = [
    [$__campOn ? 'campaign-new.php' : 'content-plan.php', 'megaphone', $__campOn ? 'حملة جديدة' : 'خطة محتوى جديدة', 'الاسم · الهدف · الجمهور · المنصات · الميزانية · المدة', 'qa-camp'],
    ['design-studio.php', 'image', 'تصميم جديد', 'النوع · المقاس · الستايل · الهوية · صور مرجعية', 'qa-design'],
    ['create-content.php', 'edit', 'منشور جديد', 'النوع · المنصة · الطول · النبرة · اللهجة', 'qa-post'],
];
?>
<nav class="v2-dock" aria-label="التنقل السريع">
    <?= $__t('dashboard', 'home', 'الرئيسية', 'dashboard.php') ?>
    <?= $__t('campaigns', 'calendar', 'حملاتي', $__campOn ? 'campaigns.php' : 'content-plan.php') ?>
    <button type="button" class="v2-fab" id="v2-fab" aria-label="إنشاء جديد" aria-haspopup="true" aria-expanded="false" aria-controls="v2-quick">
        <?= ui_icon('plus', 28) ?>
    </button>
    <?= $__t('contents', 'folder', 'المحتويات', 'content-history.php') ?>
    <?= $__t('brand', 'brain', 'الهوية', 'brand-brain.php') ?>
</nav>

<div class="v2-quick" id="v2-quick" hidden>
    <div class="v2-quick-bg" data-close></div>
    <div class="v2-quick-sheet" role="dialog" aria-modal="true" aria-labelledby="v2-quick-t">
        <div class="v2-quick-head">
            <b id="v2-quick-t">عايز تعمل إيه؟</b>
            <button type="button" class="v2-quick-x" data-close aria-label="إغلاق"><?= ui_icon('x', 20) ?></button>
        </div>
        <?php foreach ($__quick as [$href, $icon, $label, $hint, $cls]): ?>
            <a href="<?= e(url($href)) ?>" class="v2-quick-item <?= $cls ?>">
                <span class="v2-quick-ic"><?= ui_icon($icon, 22) ?></span>
                <span class="v2-quick-txt"><b><?= e($label) ?></b><small><?= e($hint) ?></small></span>
                <?= ui_icon('chevron', 18) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<script>
(function () {
  var fab = document.getElementById('v2-fab'), box = document.getElementById('v2-quick');
  if (!fab || !box) return;
  function open() {
    box.hidden = false;
    requestAnimationFrame(function () { box.classList.add('open'); });
    fab.setAttribute('aria-expanded', 'true');
    document.documentElement.classList.add('v2-quick-lock');
    var first = box.querySelector('.v2-quick-item');
    if (first) first.focus();
  }
  function close(back) {
    if (box.hidden) return;
    box.classList.remove('open');
    fab.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('v2-quick-lock');
    setTimeout(function () { box.hidden = true; }, 180);
    if (back) fab.focus();
  }
  fab.addEventListener('click', function () { box.hidden ? open() : close(true); });
  box.addEventListener('click', function (e) { if (e.target.closest('[data-close]')) close(true); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(true); });
  // رجوع من صفحة تانية (bfcache) — القائمة تبقى مقفولة
  window.addEventListener('pageshow', function () { close(false); });
})();
</script>
