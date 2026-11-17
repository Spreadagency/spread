<?php
/** شريط «عرض البداية» في الرئيسية (الشكلين) — المتغير: $__tr من trial_status() */
?>
<?php if (($__tr['state'] ?? 'none') !== 'none' && empty($GLOBALS['__trial_css'])): $GLOBALS['__trial_css'] = 1; ?>
<style>.dh-trial { margin: 0 0 14px; padding: 10px 14px; border-radius: 12px; background: rgba(124,109,242,.12); font-size: 13px; line-height: 1.8; } .dh-trial.ended { background: rgba(240,170,40,.14); } .dh-trial a { font-weight: 700; margin-inline-start: 6px; white-space: nowrap; }</style>
<?php endif; ?>
<?php if (($__tr['state'] ?? '') === 'active'): ?>
    <div class="dh-trial" data-trial="active">🎁 <b><?= e(__('عرض البداية')) ?></b> ·
        <?= e(__('فاضل {n} يوم', ['n' => (int) $__tr['days_left']])) ?> ·
        <?= e(__('{u} من {t} محتوى', ['u' => min($__tr['contents']['used'], $__tr['contents']['total']), 't' => $__tr['contents']['total']])) ?> ·
        <?= e(__('{u} من {t} تصميم', ['u' => min($__tr['designs']['used'], $__tr['designs']['total']), 't' => $__tr['designs']['total']])) ?>
        <a href="<?= url('packages.php') ?>"><?= e(__('اشترك وشيل العلامة المائية ←')) ?></a></div>
<?php elseif (($__tr['state'] ?? '') === 'ended'): ?>
    <div class="dh-trial ended" data-trial="ended">⏳ <?= e(__('التجربة المجانية خلصت — محتواك محفوظ وتقدر تشوفه. اشترك علشان تكمّل إنتاج محتوى وتصميمات.')) ?>
        <a href="<?= url('packages.php') ?>"><?= e(__('شوف الباقات ←')) ?></a></div>
<?php endif; ?>
