<?php
/**
 * Spread AI v2 — الأدمن: كل اتصالات السوشيال في المنصة
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$onlyBroken = isset($_GET['broken']);
$where = $onlyBroken ? "sc.status != 'active'" : '1';
$rows = db_all(
    "SELECT sc.*, u.name AS user_name, u.email AS user_email,
            (SELECT COUNT(*) FROM contents c WHERE c.connection_id = sc.id AND c.publish_status = 'pending') AS pending_posts,
            (SELECT COUNT(*) FROM contents c WHERE c.connection_id = sc.id AND c.publish_status = 'published') AS published_posts
     FROM social_connections sc
     JOIN users u ON u.id = sc.user_id
     WHERE {$where}
     ORDER BY sc.status != 'active' DESC, sc.id DESC LIMIT 200"
);

$statusChips = [
    'active' => ['✓ نشط', 'chip-primary'],
    'expired' => ['⚠ منتهي', ''],
    'revoked' => ['✕ ملغي', ''],
    'error' => ['⚠ خطأ', ''],
];

$active = 'social-connections';
$page_title = 'اتصالات السوشيال';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>اتصالات السوشيال 🔗</h1>
            <div class="sub">كل الصفحات المربوطة في المنصة وحالتها — لاحظ المشكلة قبل ما العميل يشتكي</div>
        </div>

        <?= render_flash() ?>

        <div style="display:flex;gap:8px;margin-bottom:16px">
            <a href="social-connections.php" class="chip <?= !$onlyBroken ? 'chip-primary' : '' ?>">الكل (<?= db_count('SELECT COUNT(*) FROM social_connections') ?>)</a>
            <a href="?broken=1" class="chip <?= $onlyBroken ? 'chip-primary' : '' ?>">⚠ المعطلة فقط (<?= db_count("SELECT COUNT(*) FROM social_connections WHERE status != 'active'") ?>)</a>
        </div>

        <?php if (!$rows): ?>
            <div class="card"><p class="sub">مفيش اتصالات<?= $onlyBroken ? ' معطلة — تمام 👌' : ' لسه' ?></p></div>
        <?php else: ?>
        <div class="card">
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>المستخدم</th>
                        <th>الصفحة</th>
                        <th>انستجرام</th>
                        <th>الحالة</th>
                        <th>بوستات</th>
                        <th>آخر تحقق</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    [$sl, $sc] = $statusChips[$r['status']] ?? ['?', ''];
                ?>
                    <tr>
                        <td>
                            <a href="<?= url('admin/user-view.php?id=' . (int) $r['user_id']) ?>"><b><?= e($r['user_name']) ?></b></a>
                            <div class="sub" style="font-size:11px"><?= e($r['user_email']) ?></div>
                        </td>
                        <td>
                            <b><?= e($r['page_name']) ?></b>
                            <div class="sub" style="font-size:11px" dir="ltr"><?= e($r['provider_page_id']) ?></div>
                        </td>
                        <td><?= $r['ig_user_id'] ? '📸 @' . e($r['ig_username'] ?: $r['ig_user_id']) : '—' ?></td>
                        <td>
                            <span class="chip <?= $sc ?>"><?= $sl ?></span>
                            <?php if ($r['last_error'] && $r['status'] !== 'active'): ?>
                                <div class="sub" style="font-size:11px;max-width:200px"><?= e(mb_substr($r['last_error'], 0, 80)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $r['published_posts'] ?> منشور · <?= (int) $r['pending_posts'] ?> منتظر</td>
                        <td class="sub" style="font-size:12px"><?= $r['last_verified_at'] ? time_ago($r['last_verified_at']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
                </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
