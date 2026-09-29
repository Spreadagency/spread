<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$q = trim($_GET['q'] ?? '');
$platform = $_GET['platform'] ?? '';
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR c.generated_text LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($platform) { $where .= ' AND c.platform = ?'; $params[] = $platform; }
if ($type) { $where .= ' AND c.content_type = ?'; $params[] = $type; }
if ($status) { $where .= ' AND c.status = ?'; $params[] = $status; }

$total = (int) (db_one("SELECT COUNT(*) AS c FROM contents c JOIN users u ON c.user_id = u.id WHERE $where", $params)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));

$contents = db_all(
    "SELECT c.*, u.name AS user_name, u.email AS user_email,
            (SELECT l.model FROM ai_usage_log l WHERE l.reference_id = c.id AND l.action = 'generate' ORDER BY l.id DESC LIMIT 1) AS gen_model,
            (SELECT COUNT(*) FROM content_designs d WHERE d.content_id = c.id) AS designs_count
     FROM contents c JOIN users u ON c.user_id = u.id
     WHERE $where ORDER BY c.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

$active = 'content';
$page_title = 'سجل المنشورات';
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
            <h1>سجل المنشورات <span class="count">• <?= $total ?></span></h1>
            <div class="sub">كل المنشورات اللي تم إنشاؤها على المنصة</div>
        </div>

        <div class="filters">
            <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;width:100%">
                <input type="text" name="q" class="filter-input" placeholder="بحث في الاسم/الإيميل/النص..." value="<?= e($q) ?>" style="flex:1;min-width:240px">
                <select name="platform" class="filter-input">
                    <option value="">كل المنصات</option>
                    <option value="facebook" <?= $platform === 'facebook' ? 'selected' : '' ?>>فيسبوك</option>
                    <option value="instagram" <?= $platform === 'instagram' ? 'selected' : '' ?>>إنستجرام</option>
                    <option value="both" <?= $platform === 'both' ? 'selected' : '' ?>>الاثنين</option>
                </select>
                <select name="type" class="filter-input">
                    <option value="">كل الأنواع</option>
                    <?php foreach (['introductory' => 'تعريفي', 'marketing' => 'تسويقي', 'educational' => 'تعليمي', 'engaging' => 'تفاعلي', 'offer' => 'عرض', 'trend' => 'ترند'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn sm">بحث</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المستخدم</th>
                        <th>النوع</th>
                        <th>المنصة</th>
                        <th>الموديل</th>
                        <th>🎨</th>
                        <th>برومبت</th>
                        <th>الكريدت</th>
                        <th>الحالة</th>
                        <th>التاريخ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contents as $c): ?>
                        <tr>
                            <td>#<?= $c['id'] ?></td>
                            <td>
                                <a href="<?= url('admin/user-view.php?id=' . $c['user_id']) ?>" style="text-decoration:none;color:inherit">
                                    <b style="font-size:12.5px"><?= e($c['user_name']) ?></b>
                                    <div class="text-mute" style="font-size:11px"><?= e($c['user_email']) ?></div>
                                </a>
                            </td>
                            <td><span class="chip chip-primary"><?= e(content_type_label($c['content_type'])) ?></span></td>
                            <td><span class="chip chip-line"><?= e(platform_label($c['platform'])) ?></span></td>
                            <td class="sub" dir="ltr" style="font-size:11px;text-align:left"><?= e($c['gen_model'] ? (mb_strlen($c['gen_model']) > 22 ? '…' . mb_substr($c['gen_model'], -20) : $c['gen_model']) : '—') ?></td>
                            <td><?= (int) $c['designs_count'] ?: '—' ?></td>
                            <td><a href="<?= url('admin/prompt-view.php?type=content&id=' . (int) $c['id']) ?>" title="البرومبت الكامل المُرسل للـ API">🔍</a></td>
                            <td><?= $c['credits_used'] ?> ◇</td>
                            <td><span class="chip <?= e(status_chip($c['status'])) ?>"><?= e(status_label($c['status'])) ?></span></td>
                            <td style="font-size:11.5px;color:var(--mute)"><?= e(time_ago($c['created_at'])) ?></td>
                            <td><a href="<?= url('admin/content-view.php?id=' . $c['id']) ?>" class="btn ghost sm">عرض</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($contents)): ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--mute);padding:40px">لا توجد منشورات مطابقة</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $qs = $_GET;
                for ($p = 1; $p <= $totalPages; $p++):
                    $qs['page'] = $p;
                ?>
                    <a href="?<?= http_build_query($qs) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
