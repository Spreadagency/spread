<?php
/**
 * Spread AI v2 — الأدمن: معرض الصور المولّدة
 * كل التصميمات (من المنشورات + الاستوديو) مع الموديل اللي اتعملت بيه
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
$admin = current_admin();

$modelFilter = trim($_GET['model'] ?? '');
$sourceFilter = in_array($_GET['src'] ?? '', ['content', 'studio'], true) ? $_GET['src'] : '';
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 36;
$offset = ($page - 1) * $perPage;

// توحيد المصدرين في استعلام واحد
$where = [];
$params = [];
if ($modelFilter !== '') {
    $where[] = 'model = ?';
    $params[] = $modelFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$union = "
    (SELECT d.id, d.image_path, d.model, d.credits_used, d.created_at, d.user_id, 'content' AS src, d.content_id AS ref_id
     FROM content_designs d {$whereSql})
    UNION ALL
    (SELECT s.id, s.image_path, s.model, s.credits_used, s.created_at, s.user_id, 'studio' AS src, NULL AS ref_id
     FROM studio_designs s {$whereSql})
";
$unionParams = array_merge($params, $params);

if ($sourceFilter !== '') {
    $sql = "SELECT * FROM ({$union}) t WHERE t.src = ? ORDER BY t.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
    $rows = db_all($sql, array_merge($unionParams, [$sourceFilter]));
    $total = (int) (db_one("SELECT COUNT(*) c FROM ({$union}) t WHERE t.src = ?", array_merge($unionParams, [$sourceFilter]))['c'] ?? 0);
} else {
    $sql = "SELECT * FROM ({$union}) t ORDER BY t.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
    $rows = db_all($sql, $unionParams);
    $total = (int) (db_one("SELECT COUNT(*) c FROM ({$union}) t", $unionParams)['c'] ?? 0);
}

// أسماء المستخدمين
$userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
$usersById = [];
if ($userIds) {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    foreach (db_all("SELECT id, name FROM users WHERE id IN ({$ph})", $userIds) as $u) {
        $usersById[(int) $u['id']] = $u['name'];
    }
}

// الموديلات المتاحة للفلترة + إحصائية سريعة
$models = db_all("
    SELECT model, COUNT(*) c FROM (
        SELECT model FROM content_designs UNION ALL SELECT model FROM studio_designs
    ) m WHERE model IS NOT NULL GROUP BY model ORDER BY c DESC
");

$pages = max(1, (int) ceil($total / $perPage));
$qs = fn (array $extra) => '?' . http_build_query(array_merge(['model' => $modelFilter, 'src' => $sourceFilter], $extra));

$active = 'gallery';
$page_title = 'معرض الصور';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>معرض الصور المولّدة 🖼</h1>
            <div class="sub">كل التصميمات (<?= number_format($total) ?>) — بالموديل والمستخدم والمصدر</div>
        </div>

        <?= render_flash() ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
            <a href="designs-gallery.php" class="chip <?= $modelFilter === '' && $sourceFilter === '' ? 'chip-primary' : '' ?>">الكل</a>
            <a href="<?= e($qs(['src' => 'content', 'p' => 1])) ?>" class="chip <?= $sourceFilter === 'content' ? 'chip-primary' : '' ?>">من المنشورات</a>
            <a href="<?= e($qs(['src' => 'studio', 'p' => 1])) ?>" class="chip <?= $sourceFilter === 'studio' ? 'chip-primary' : '' ?>">من الاستوديو</a>
            <span style="width:1px;height:20px;background:var(--line)"></span>
            <?php foreach ($models as $m): ?>
                <a href="?model=<?= urlencode($m['model']) ?>" class="chip <?= $modelFilter === $m['model'] ? 'chip-primary' : '' ?>" title="<?= (int) $m['c'] ?> صورة">
                    <?= e(mb_strlen($m['model']) > 28 ? '…' . mb_substr($m['model'], -26) : $m['model']) ?> (<?= (int) $m['c'] ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$rows): ?>
            <div class="card"><p class="sub">مفيش صور بالفلتر ده لسه</p></div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px">
                <?php foreach ($rows as $r): ?>
                    <div class="card" style="padding:8px">
                        <a href="<?= url('storage/' . $r['image_path']) ?>" data-lightbox>
                            <img src="<?= url('storage/' . $r['image_path']) ?>" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px" loading="lazy" alt="">
                        </a>
                        <div style="font-size:11px;margin-top:6px;line-height:1.5">
                            <div><b><?= e($usersById[(int) $r['user_id']] ?? '—') ?></b>
                                <span class="chip" style="font-size:10px;padding:1px 7px"><?= $r['src'] === 'studio' ? '✨ استوديو' : '📝 منشور' ?></span></div>
                            <div class="sub" dir="ltr" style="text-align:left"><?= e($r['model'] ?: 'legacy') ?></div>
                            <div class="sub">
                                <a href="<?= url('admin/prompt-view.php?type=' . ($r['src'] === 'studio' ? 'studio' : 'design') . '&id=' . (int) $r['id']) ?>" title="شوف البرومبت الكامل">🔍 البرومبت</a> ·
                                <?= (int) $r['credits_used'] ?> ◇ · <?= time_ago($r['created_at']) ?>
                                <?php if ($r['src'] === 'content' && $r['ref_id']): ?>
                                    · <a href="<?= url('admin/content-view.php?id=' . (int) $r['ref_id']) ?>">البوست ←</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
            <div style="display:flex;gap:6px;justify-content:center;margin-top:20px">
                <?php for ($i = 1; $i <= min($pages, 15); $i++): ?>
                    <a href="<?= e($qs(['p' => $i])) ?>" class="chip <?= $i === $page ? 'chip-primary' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
