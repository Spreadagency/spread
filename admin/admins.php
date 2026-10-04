<?php
/**
 * Spread AI v2 — الأدمن: فريق الإدارة والصلاحيات
 * 3 أدوار: مساعد شخصي · إدارة حسابات · أدمن كامل
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
require_admin_can('manage_admins');

$me = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 150);
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $role = in_array($_POST['role'] ?? '', ['super', 'manager', 'assistant'], true) ? $_POST['role'] : 'assistant';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash_set('danger', 'بيانات ناقصة — الباسورد لازم 8 حروف على الأقل');
            redirect('admin/admins.php');
        }
        if (db_one('SELECT id FROM admin_users WHERE email = ?', [$email])) {
            flash_set('danger', 'الإيميل ده مستخدم بالفعل');
            redirect('admin/admins.php');
        }
        db_insert(
            'INSERT INTO admin_users (name, email, password, role, status) VALUES (?, ?, ?, ?, "active")',
            [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]
        );
        admin_log('add_admin', 'admin', null, $email . ' / ' . $role);
        flash_set('success', 'تم إضافة ' . $name . ' كـ ' . admin_role_label($role));
        redirect('admin/admins.php');
    }

    if ($action === 'set_role') {
        $id = (int) $_POST['admin_id'];
        $role = in_array($_POST['role'] ?? '', ['super', 'manager', 'assistant'], true) ? $_POST['role'] : 'assistant';
        if ($id === (int) $me['id']) {
            flash_set('danger', 'مينفعش تغيّر دورك بنفسك');
            redirect('admin/admins.php');
        }
        db_run('UPDATE admin_users SET role = ? WHERE id = ?', [$role, $id]);
        admin_log('set_admin_role', 'admin', $id, $role);
        flash_set('success', 'تم تغيير الدور ✓');
        redirect('admin/admins.php');
    }

    if ($action === 'toggle') {
        $id = (int) $_POST['admin_id'];
        if ($id === (int) $me['id']) {
            flash_set('danger', 'مينفعش توقف حسابك');
            redirect('admin/admins.php');
        }
        db_run('UPDATE admin_users SET status = IF(status = "active", "inactive", "active") WHERE id = ?', [$id]);
        flash_set('success', 'تم تحديث الحالة');
        redirect('admin/admins.php');
    }
}

$admins = db_all('SELECT * FROM admin_users ORDER BY id');
$roles = ['assistant' => '🤝 مساعد شخصي', 'manager' => '💳 إدارة الحسابات', 'super' => '👑 أدمن كامل'];

// آخر جلسات المساعدة
$recentImp = [];
try {
    $recentImp = db_all(
        'SELECT il.*, a.name AS admin_name, u.name AS user_name
         FROM impersonation_logs il
         LEFT JOIN admin_users a ON a.id = il.admin_id
         LEFT JOIN users u ON u.id = il.user_id
         ORDER BY il.id DESC LIMIT 12'
    );
} catch (\Throwable $e) {
}

$active = 'admins';
$page_title = 'فريق الإدارة';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>فريق الإدارة 👥</h1>
            <div class="sub">أضف مساعدين وحدد صلاحية كل واحد</div>
        </div>

        <?= render_flash() ?>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>الأدوار والصلاحيات</h3></div>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>الصلاحية</th><th>🤝 مساعد شخصي</th><th>💳 إدارة الحسابات</th><th>👑 أدمن كامل</th></tr></thead>
                <tbody>
                    <?php foreach ([
                        'الدخول بحساب العميل ومساعدته' => ['✓', '✓', '✓'],
                        'إنشاء محتوى وأفكار وتصميمات للعميل' => ['✓', '✓', '✓'],
                        'إضافة صور لمعرض الإلهام' => ['✓', '✓', '✓'],
                        'شحن كريدت للعميل' => ['✕', '✓', '✓'],
                        'الموافقة على الحسابات والباقات' => ['✕', '✓', '✓'],
                        'حذف عميل نهائيًا' => ['✕', '✕', '✓'],
                        'إعدادات الموقع والـ AI وفريق الإدارة' => ['✕', '✕', '✓'],
                    ] as $perm => $vals): ?>
                        <tr>
                            <td><?= e($perm) ?></td>
                            <?php foreach ($vals as $v): ?>
                                <td style="color:<?= $v === '✓' ? '#2a7d5f' : '#c0392b' ?>;font-weight:700"><?= $v ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>إضافة عضو للفريق</h3></div>
            <form method="POST" data-safe-post>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="field-row">
                    <div class="field"><label>الاسم</label><input type="text" name="name" class="input" required></div>
                    <div class="field"><label>الإيميل</label><input type="email" name="email" class="input" dir="ltr" required data-no-encode="1"></div>
                    <div class="field"><label>كلمة المرور</label><input type="password" name="password" class="input" dir="ltr" minlength="8" required autocomplete="new-password" data-no-encode="1"></div>
                    <div class="field"><label>الدور</label>
                        <select name="role" class="input">
                            <?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'assistant' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn">＋ إضافة</button>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h3>الأعضاء (<?= count($admins) ?>)</h3></div>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>العضو</th><th>الدور</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($admins as $a): ?>
                    <tr style="<?= $a['status'] === 'active' ? '' : 'opacity:.55' ?>">
                        <td>
                            <b><?= e($a['name']) ?></b><?= (int) $a['id'] === (int) $me['id'] ? ' <span class="chip" style="font-size:10px">أنت</span>' : '' ?>
                            <div class="sub" style="font-size:11px"><?= e($a['email']) ?></div>
                        </td>
                        <td>
                            <?php if ((int) $a['id'] === (int) $me['id']): ?>
                                <span class="chip chip-primary"><?= admin_role_label($a['role'] ?? 'super') ?></span>
                            <?php else: ?>
                                <form method="POST" style="display:flex;gap:5px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_role">
                                    <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                                    <select name="role" class="input" style="padding:6px;font-size:12px">
                                        <?php foreach ($roles as $k => $v): ?>
                                            <option value="<?= $k ?>" <?= ($a['role'] ?? 'super') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn sm" style="padding:6px 10px">✓</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><span class="chip <?= $a['status'] === 'active' ? 'chip-primary' : '' ?>"><?= $a['status'] === 'active' ? 'نشط' : 'موقوف' ?></span></td>
                        <td>
                            <?php if ((int) $a['id'] !== (int) $me['id']): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                                    <button class="btn sm"><?= $a['status'] === 'active' ? 'إيقاف' : 'تفعيل' ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($recentImp): ?>
        <div class="card" style="margin-top:20px">
            <div class="card-head"><h3>👁 آخر جلسات المساعدة</h3></div>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>الأدمن</th><th>العميل</th><th>بدأت</th><th>انتهت</th></tr></thead>
                <tbody>
                <?php foreach ($recentImp as $l): ?>
                    <tr>
                        <td><?= e($l['admin_name'] ?? '—') ?></td>
                        <td><?= e($l['user_name'] ?? '—') ?></td>
                        <td class="sub" style="font-size:12px"><?= e(fmt_date($l['started_at'], true)) ?></td>
                        <td class="sub" style="font-size:12px"><?= $l['ended_at'] ? e(fmt_date($l['ended_at'], true)) : '<span style="color:#a06c1e">لسه شغالة</span>' ?></td>
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
