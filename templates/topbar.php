<?php
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) { include __DIR__ . '/v2/topbar.php'; return; }
/**
 * Spread AI — Client Topbar
 */
$user = current_user();
$balance = user_credits();
?>
<div class="topbar">
    <button class="icon-btn menu-toggle" onclick="toggleSidebar()" aria-label="القائمة">☰</button>

    <div class="search">
        <span style="color:var(--mute)">⌕</span>
        <input placeholder="ابحث في محتواك..." onkeydown="if(event.key==='Enter') location.href='<?= url('content-history.php') ?>?q='+encodeURIComponent(this.value)">
    </div>

    <div class="topbar-right">
        <a href="<?= url('credits.php') ?>" class="chip chip-primary" style="padding:8px 14px">
            <span class="cr">◇ <?= $balance ?> كريدت</span><span class="cr-alt">◔ استخدمت <?= function_exists('plan_usage') ? (int) plan_usage((int) (current_user()['id'] ?? 0))['pct'] : 0 ?>%</span>
        </a>
        <a href="<?= url('create-content.php') ?>" class="btn">
            ＋ منشور جديد
        </a>
        <a href="<?= url('profile.php') ?>" class="profile">
            <div class="avi" style="background: <?= e(color_from_string($user['email'])) ?>">
                <?= e(initials($user['name'] ?? $user['email'])) ?>
            </div>
            <div class="meta">
                <b><?= e(str_limit($user['name'] ?? 'مستخدم', 15)) ?></b>
                <span>عميل</span>
            </div>
        </a>
    </div>
</div>

<?= render_flash() ?>
