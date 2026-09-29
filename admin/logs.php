<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$total = (int) (db_one('SELECT COUNT(*) AS c FROM admin_logs')['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));

$logs = db_all(
    'SELECT l.*, a.name AS admin_name FROM admin_logs l
     LEFT JOIN admin_users a ON l.admin_user_id = a.id
     ORDER BY l.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
);

$active = 'logs';
$page_title = 'سجل الأنشطة';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <div style="flex:1"></div>
            <span class="badge-admin">ADMIN</span>
        </div>

        <?= render_flash() ?>

        <div class="page-head">
            <h1>سجل الأنشطة <span class="count">• <?= $total ?></span></h1>
            <div class="sub">كل عمليات الإدارة المسجّلة</div>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>الإدارة</th>
                        <th>الإجراء</th>
                        <th>الكيان</th>
                        <th>المعرف</th>
                        <th>التفاصيل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td style="font-size:11.5px;color:var(--mute);white-space:nowrap"><?= e(fmt_date($l['created_at'], true)) ?></td>
                            <td>
                                <b style="font-size:12px"><?= e($l['admin_name'] ?? '?') ?></b>
                            </td>
                            <td><span class="chip chip-primary"><?= e($l['action']) ?></span></td>
                            <td style="font-size:12px"><?= e($l['entity_type'] ?? '—') ?></td>
                            <td style="font-size:12px;color:var(--mute)">#<?= e($l['entity_id'] ?? '—') ?></td>
                            <td style="font-size:11.5px;color:var(--ink-2);max-width:340px;overflow:hidden;text-overflow:ellipsis"><?= e(str_limit($l['meta'] ?? '', 100)) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--mute);padding:40px">لا يوجد سجل بعد</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $qs = $_GET;
                for ($p = 1; $p <= min($totalPages, 20); $p++):
                    $qs['page'] = $p;
                ?>
                    <a href="?<?= http_build_query($qs) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
