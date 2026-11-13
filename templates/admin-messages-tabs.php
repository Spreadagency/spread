<?php
/**
 * Spread AI — شريط «الرسائل» الموحد (خريطة التطوير · المرحلة 3)
 * الوارد · القوالب والإعدادات (الواتساب) · البث والإعلانات — نفس الصفحات القديمة ولينكاتها شغالة، ده تنقّل بس.
 * Variables: $msgTab = inbox | whatsapp | announcements
 */
require_once __DIR__ . '/../includes/messaging.php';
$__mt = $msgTab ?? '';
$__open = msg_unhandled_count();
$__tabs = [
    'inbox' => ['admin/inbox.php', 'الوارد' . ($__open > 0 ? ' (' . $__open . ')' : ''), 'view_users'],
    'whatsapp' => ['admin/whatsapp.php', 'القوالب والواتساب', 'view_users'],
    'announcements' => ['admin/announcements.php', 'البث والإعلانات', 'site_settings'],
];
?>
<div class="a2-row" style="gap:6px;flex-wrap:wrap;margin-bottom:14px" role="tablist" aria-label="الرسائل">
    <?php foreach ($__tabs as $__k => [$__href, $__label, $__perm]):
        if ($__perm !== null && !admin_can($__perm)) continue; ?>
        <a class="btn sm <?= $__k === $__mt ? '' : 'ghost' ?>" role="tab" aria-selected="<?= $__k === $__mt ? 'true' : 'false' ?>" href="<?= url($__href) ?>"><?= e($__label) ?></a>
    <?php endforeach; ?>
</div>
