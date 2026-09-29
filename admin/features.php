<?php
/**
 * Spread AI v2 — الأدمن: صلاحيات النشر الاجتماعي (Feature Flags)
 * الوضع العام + تفعيل/تعطيل لكل مستخدم + حد الصفحات
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
require_admin_can('features');
$admin = current_admin();
$FKEY = 'social_publishing';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_mode') {
        $mode = in_array($_POST['mode'] ?? '', ['off', 'on', 'allowlist'], true) ? $_POST['mode'] : 'allowlist';
        db_run('UPDATE feature_flags SET default_mode = ? WHERE feature_key = ?', [$mode, $FKEY]);
        admin_log('feature_mode', 'feature', null, $FKEY . '=' . $mode);
        flash_set('success', 'تم تغيير الوضع العام — التأثير فوري على الكل');
        redirect('admin/features.php');
    }

    if ($action === 'toggle_user') {
        $uid = (int) $_POST['uid'];
        $row = db_one('SELECT * FROM user_feature_access WHERE user_id = ? AND feature_key = ?', [$uid, $FKEY]);
        if ($row) {
            db_run('UPDATE user_feature_access SET is_enabled = 1 - is_enabled, granted_by = ?, granted_at = NOW() WHERE id = ?', [$admin['id'], $row['id']]);
            $newState = !$row['is_enabled'];
        } else {
            db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled, granted_by) VALUES (?, ?, 1, ?)', [$uid, $FKEY, $admin['id']]);
            $newState = true;
        }
        // سحب الصلاحية → إلغاء البوستات المجدولة فورًا
        if (!$newState) {
            db_run('UPDATE contents SET publish_status = "cancelled", publish_error = "أُلغي: الصلاحية اتسحبت" WHERE user_id = ? AND publish_status IN ("pending","processing") AND connection_id IS NOT NULL', [$uid]);
        }
        admin_log('feature_toggle', 'user', $uid, $FKEY . '=' . ($newState ? 'on' : 'off'));
        flash_set('success', $newState ? 'تم التفعيل ✓' : 'تم التعطيل — والبوستات المجدولة اتلغت');
        redirect('admin/features.php' . (isset($_POST['q']) ? '?q=' . urlencode($_POST['q']) : ''));
    }

    if ($action === 'set_max_pages') {
        $uid = (int) $_POST['uid'];
        $max = max(1, min(20, (int) $_POST['max_pages']));
        $row = db_one('SELECT id FROM user_feature_access WHERE user_id = ? AND feature_key = ?', [$uid, $FKEY]);
        if ($row) {
            db_run('UPDATE user_feature_access SET max_pages = ? WHERE id = ?', [$max, $row['id']]);
        } else {
            db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled, max_pages, granted_by) VALUES (?, ?, 1, ?, ?)', [$uid, $FKEY, $max, $admin['id']]);
        }
        flash_set('success', 'حد الصفحات اتحدّث');
        redirect('admin/features.php' . (isset($_POST['q']) ? '?q=' . urlencode($_POST['q']) : ''));
    }
}

$flag = db_one('SELECT * FROM feature_flags WHERE feature_key = ?', [$FKEY]);
$mode = $flag['default_mode'] ?? 'allowlist';

$q = trim($_GET['q'] ?? '');
$params = [];
$where = '1';
if ($q !== '') {
    $where = '(u.name LIKE ? OR u.email LIKE ?)';
    $params = ["%{$q}%", "%{$q}%"];
}
$users = db_all(
    "SELECT u.id, u.name, u.email,
            fa.is_enabled, fa.max_pages, fa.expires_at,
            (SELECT COUNT(*) FROM social_connections sc WHERE sc.user_id = u.id) AS pages_count,
            (SELECT COUNT(*) FROM social_connections sc WHERE sc.user_id = u.id AND sc.status = 'active') AS pages_active
     FROM users u
     LEFT JOIN user_feature_access fa ON fa.user_id = u.id AND fa.feature_key = '{$FKEY}'
     WHERE {$where}
     ORDER BY u.id DESC LIMIT 100",
    $params
);

$modeLabels = ['off' => '⛔ مقفولة للجميع', 'on' => '🌍 مفتوحة للجميع', 'allowlist' => '✅ بالسماح فقط (الافتراضي)'];

$active = 'features';
$page_title = 'صلاحيات النشر';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>صلاحيات النشر الاجتماعي 🚦</h1>
            <div class="sub">اتحكم مين يشوف وحدة النشر التلقائي ويستخدمها — سحب الصلاحية بيلغي البوستات المجدولة فورًا</div>
        </div>

        <?= render_flash() ?>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>الوضع العام للوحدة</h3></div>
            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_mode">
                <select name="mode" class="input" style="max-width:280px">
                    <?php foreach ($modeLabels as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $mode === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn sm">حفظ الوضع</button>
                <span class="sub" style="font-size:12px">«مقفولة» = kill switch فوري لكل المنصة · «بالسماح» = تفعّل لكل عميل بنفسك تحت</span>
            </form>
        </div>

        <div class="card">
            <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <h3>المستخدمين</h3>
                <form method="GET" style="display:flex;gap:6px">
                    <input type="text" name="q" class="input" placeholder="بحث بالاسم أو الإيميل" value="<?= e($q) ?>" style="max-width:220px">
                    <button class="btn sm">بحث</button>
                </form>
            </div>

            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>المستخدم</th>
                        <th>الحالة</th>
                        <th>حد الصفحات</th>
                        <th>المربوط</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u):
                    $enabled = $mode === 'on' ? ($u['is_enabled'] === null || $u['is_enabled']) : (bool) $u['is_enabled'];
                ?>
                    <tr>
                        <td>
                            <b><?= e($u['name']) ?></b>
                            <div class="sub" style="font-size:11px"><?= e($u['email']) ?></div>
                        </td>
                        <td>
                            <span class="chip <?= $enabled ? 'chip-primary' : '' ?>" style="<?= $enabled ? '' : 'opacity:.6' ?>">
                                <?= $enabled ? '✓ مفعّلة' : '✕ معطّلة' ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" style="display:flex;gap:4px;align-items:center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_max_pages">
                                <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                                <input type="hidden" name="q" value="<?= e($q) ?>">
                                <input type="number" name="max_pages" class="input" value="<?= (int) ($u['max_pages'] ?? 1) ?>" min="1" max="20" style="width:64px;padding:6px">
                                <button class="btn sm" style="padding:6px 10px">✓</button>
                            </form>
                        </td>
                        <td>
                            <?= (int) $u['pages_count'] ?> صفحة
                            <?php if ($u['pages_count'] > $u['pages_active']): ?>
                                <span class="sub" style="color:#c0392b;font-size:11px">(<?= $u['pages_count'] - $u['pages_active'] ?> معطلة)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_user">
                                <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                                <input type="hidden" name="q" value="<?= e($q) ?>">
                                <button class="btn sm <?= $enabled ? '' : 'btn-primary' ?>" <?= $enabled ? 'onclick="return confirm(\'تعطيل الوحدة للمستخدم ده؟ بوستاته المجدولة هتتلغي.\')"' : '' ?>>
                                    <?= $enabled ? 'تعطيل' : 'تفعيل' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
                </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
