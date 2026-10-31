<?php
// آخر نشاط للعميل (تذكير «وحشتنا» في الكرون اليومي) — مرة كل 5 دقايق بالكتير
if (!empty($_SESSION['user_id']) && (int) ($_SESSION['wa_active_at'] ?? 0) < time() - 300) {
    require_once __DIR__ . '/../includes/Notifier.php';
    wa_touch_active((int) $_SESSION['user_id']);
}
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) { include __DIR__ . '/v2/topbar.php'; return; }
/**
 * Spread AI — Client Topbar
 */
$user = current_user();
$balance = user_credits();
try { $__unread = (int) (db_one('SELECT COUNT(*) n FROM user_notifications WHERE user_id = ? AND read_at IS NULL', [(int) ($user['id'] ?? 0)])['n'] ?? 0); } catch (\Throwable $e) { $__unread = 0; }
?>
<style>
.v1-bell { position: relative; text-decoration: none; display: inline-grid; place-items: center; }
.v1-dot { position: absolute; top: -4px; inset-inline-end: -4px; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 999px; font-style: normal;
    font-size: 11px; font-weight: 700; color: #fff; background: #E0526A; display: grid; place-items: center; border: 2px solid #fff; }
</style>
<div class="topbar">
    <button class="icon-btn menu-toggle" onclick="toggleSidebar()" aria-label="القائمة">☰</button>

    <div class="search">
        <span style="color:var(--mute)">⌕</span>
        <input placeholder="ابحث في محتواك..." onkeydown="if(event.key==='Enter') location.href='<?= url('content-history.php') ?>?q='+encodeURIComponent(this.value)">
    </div>

    <div class="topbar-right">
        <a href="<?= url(get_setting('plan_page_unified', '1') === '1' ? 'my-plan.php?tab=balance' : 'credits.php') ?>" class="chip chip-primary" style="padding:8px 14px">
            <span class="cr">◇ <?= $balance ?> كريدت</span><span class="cr-alt">◔ استخدمت <?= function_exists('plan_usage') ? (int) plan_usage((int) (current_user()['id'] ?? 0))['pct'] : 0 ?>%</span>
        </a>
        <span data-inbox data-dot="v1-dot" data-badge="<?= $__unread ?>" data-unread="<?= $__unread ?>">
            <?php if (!function_exists('get_setting') || get_setting('issues_enabled', '1') === '1'): ?><button type="button" class="icon-btn" data-report title="بلّغ عن مشكلة" aria-label="بلّغ عن مشكلة">⚑</button><?php endif; ?>
            <a href="<?= url('notifications.php') ?>" class="icon-btn v1-bell" data-inbox-btn aria-label="الرسايل والإشعارات">🔔<?php if ($__unread > 0): ?><em class="v1-dot"><?= $__unread ?></em><?php endif; ?></a>
        </span>
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
<?php include __DIR__ . '/inbox-poll.php'; ?>
