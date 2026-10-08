<?php
declare(strict_types=1);

/** الإعدادات: admin users, retention, privacy text, brand colors, general, activity log. Owner only. */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

$me = admin_require('owner');
const ROLES = ['owner' => 'Owner — كل الصلاحيات', 'editor' => 'Editor — من غير الإعدادات والـ API', 'viewer' => 'Viewer — قراءة بس'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('owner');
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'general':
                $tz = (string) ($_POST['timezone'] ?? 'Africa/Cairo');
                if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                    throw new RuntimeException('المنطقة الزمنية مش صحيحة.');
                }
                foreach (['color_primary', 'color_secondary', 'color_accent'] as $k) {
                    if (isset($_POST[$k]) && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $_POST[$k])) {
                        throw new RuntimeException('اللون لازم يكون بالشكل #RRGGBB');
                    }
                }
                $list = trim((string) ($_POST['admin_ip_allowlist'] ?? ''));
                if ($list !== '') {
                    $entries = array_filter(array_map('trim', explode(',', $list)));
                    foreach ($entries as $en) {
                        if (!filter_var(explode('/', $en)[0], FILTER_VALIDATE_IP)) {
                            throw new RuntimeException('عنوان IP مش صحيح: ' . $en);
                        }
                    }
                    if (!AdminAuth::ipAllowed(implode(',', $entries))) {
                        throw new RuntimeException('الـ IP بتاعك (' . client_ip() . ') مش في القائمة — كده هتقفل على نفسك.');
                    }
                    $_POST['admin_ip_allowlist'] = implode(', ', $entries);
                }
                $changed = save_settings_from_post(
                    ['timezone', 'privacy_text', 'color_primary', 'color_secondary', 'color_accent', 'admin_ip_allowlist'],
                    ['share_show_before', 'maintenance_mode'],
                    [],
                    ['retention_originals_days' => [1, 3650], 'retention_results_days' => [1, 3650]]
                );
                admin_log('settings_update', implode(', ', $changed));
                flash('ok', 'اتحفظت الإعدادات');
                break;

            case 'user_add':
                $name = trim((string) ($_POST['name'] ?? ''));
                $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
                $role = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : 'editor';
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('اكتب اسم وإيميل صحيح.');
                }
                if (q_value('SELECT 1 FROM admins WHERE email = ?', [$email])) {
                    throw new RuntimeException('الإيميل ده موجود قبل كده.');
                }
                $temp = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Kx'), '=') . random_int(10, 99);
                q('INSERT INTO admins (name, email, password_hash, role, must_change_password, created_at) VALUES (?, ?, ?, ?, 1, ?)', [$name, $email, password_hash($temp, PASSWORD_BCRYPT, ['cost' => 12]), $role, utc_now()]);
                admin_log('admin_add', "$email ($role)");
                $_SESSION['temp_password'] = [$email, $temp];
                flash('ok', 'اتضاف المستخدم. ابعتله الباسورد المؤقت (بيظهر مرة واحدة).');
                break;

            case 'user_role':
                $id = (int) ($_POST['id'] ?? 0);
                $role = array_key_exists($_POST['role'] ?? '', ROLES) ? $_POST['role'] : null;
                if ($id === (int) $me['id'] || !$role) {
                    throw new RuntimeException('مينفعش تغيّر صلاحيتك إنت.');
                }
                q('UPDATE admins SET role = ? WHERE id = ?', [$role, $id]);
                admin_log('admin_role', "#$id → $role");
                flash('ok', 'اتغيرت الصلاحية');
                break;

            case 'user_reset':
                $id = (int) ($_POST['id'] ?? 0);
                $row = q_row('SELECT email FROM admins WHERE id = ?', [$id]);
                if (!$row || $id === (int) $me['id']) {
                    throw new RuntimeException('غيّر الباسورد بتاعك من صفحة الحساب.');
                }
                $temp = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'Kx'), '=') . random_int(10, 99);
                q('UPDATE admins SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($temp, PASSWORD_BCRYPT, ['cost' => 12]), $id]);
                admin_log('admin_reset_password', $row['email']);
                $_SESSION['temp_password'] = [$row['email'], $temp];
                flash('ok', 'اتعمل باسورد مؤقت جديد.');
                break;

            case 'user_delete':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id === (int) $me['id']) {
                    throw new RuntimeException('مينفعش تمسح نفسك.');
                }
                if ((int) q_value("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND id <> ?", [$id]) === 0) {
                    throw new RuntimeException('لازم يفضل Owner واحد على الأقل.');
                }
                $email = q_value('SELECT email FROM admins WHERE id = ?', [$id]);
                q('DELETE FROM admins WHERE id = ?', [$id]);
                admin_log('admin_delete', (string) $email);
                flash('ok', 'اتمسح المستخدم');
                break;
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    redirect('settings.php' . (str_starts_with($action, 'user_') ? '#users' : ''));
}

$users = q('SELECT id, name, email, role, must_change_password, last_login_at FROM admins ORDER BY id')->fetchAll();
$logs = q('SELECT a.*, ad.name FROM admin_logs a LEFT JOIN admins ad ON ad.id = a.admin_id ORDER BY a.id DESC LIMIT 30')->fetchAll();
$temp = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);
$zones = ['Africa/Cairo', 'Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Qatar', 'Europe/London', 'UTC'];
$curTz = (string) Settings::get('timezone', 'Africa/Cairo');
if (!in_array($curTz, $zones, true)) {
    $zones[] = $curTz;
}

admin_page_start('الإعدادات', 'settings.php');
?>
<div class="page-head"><div><p>المستخدمين، الخصوصية، مدة الاحتفاظ بالصور، وألوان البراند</p></div></div>

<?php if ($temp): ?>
<div class="banner"><span class="bi"><?= ic('key') ?></span><div class="stack-8" style="flex:1"><b style="color:var(--ink)">الباسورد المؤقت لـ <span class="ltr"><?= e($temp[0]) ?></span></b><span class="small muted">انسخه دلوقتي — مش هيظهر تاني. المستخدم هيتطلب منه يغيّره أول ما يدخل.</span><code class="cron" style="align-self:flex-start"><?= e($temp[1]) ?></code></div><button class="btn btn-secondary btn-sm" type="button" data-copy="<?= e($temp[1]) ?>"><?= ic('copy', 'sm') ?>انسخ</button></div>
<?php endif; ?>

<section class="card" id="users"><div class="card-h"><div><h2>مستخدمين لوحة التحكم</h2><p>Owner: كل حاجة · Editor: من غير الإعدادات والـ API · Viewer: قراءة بس</p></div></div>
  <div class="table-wrap"><table class="t"><thead><tr><th>الاسم</th><th>الإيميل</th><th>الصلاحية</th><th class="hide-tab">آخر دخول</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $me['id']; ?>
    <tr style="cursor:default"><td><span class="name"><span class="avatar sm"><?= e(initials($u['name'])) ?></span><?= e($u['name']) ?><?= $u['must_change_password'] ? ' <span class="badge b-contacted plain">باسورد مؤقت</span>' : '' ?></span></td>
      <td class="ltr mono"><?= e($u['email']) ?></td>
      <td><?php if ($self): ?><span class="badge b-gold plain">إنت · <?= e(ucfirst($u['role'])) ?></span><?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><select class="select" style="width:150px;height:32px" name="role" data-autosubmit aria-label="الصلاحية"><?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= e(ucfirst($k)) ?></option><?php endforeach; ?></select></form><?php endif; ?></td>
      <td class="muted hide-tab"><?= e(rel_time($u['last_login_at'])) ?></td>
      <td><?php if (!$self): ?><div class="row" style="justify-content:flex-end">
        <form method="post" data-confirm-title="باسورد مؤقت جديد؟" data-confirm-body="الباسورد الحالي هيتلغي وهيتعمل واحد مؤقت تبعته للمستخدم." data-confirm-cta="اعمل باسورد جديد" data-confirm-tone="primary"><?= csrf_field() ?><input type="hidden" name="action" value="user_reset"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-icon btn-ghost" type="submit" title="Reset password" aria-label="Reset password"><?= ic('key', 'sm') ?></button></form>
        <form method="post" data-confirm-title="تمسح <?= e($u['name']) ?>؟" data-confirm-body="مش هيقدر يدخل لوحة التحكم تاني."><?= csrf_field() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-icon btn-ghost" style="color:var(--danger)" type="submit" aria-label="امسح"><?= ic('trash', 'sm') ?></button></form>
      </div><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <form method="post" class="card-f form-inline"><?= csrf_field() ?><input type="hidden" name="action" value="user_add">
    <input class="input" name="name" placeholder="الاسم" required aria-label="الاسم">
    <input class="input" name="email" type="email" dir="ltr" placeholder="email@example.com" required aria-label="الإيميل">
    <select class="select" name="role" aria-label="الصلاحية"><?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'editor' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-primary" type="submit" data-loading><?= ic('plus', 'sm') ?>مستخدم جديد</button>
  </form>
</section>

<form method="post" class="grid g-1-1" style="align-items:start"><?= csrf_field() ?><input type="hidden" name="action" value="general">
  <div class="stack" style="gap:24px">
    <section class="card" id="retention"><div class="card-h"><div><h2>الاحتفاظ بالصور</h2><p>المسح التلقائي عن طريق Cron</p></div></div><div class="card-b form-grid">
      <?= f_text('retention_originals_days', 'الصور الأصلية (يوم)', (string) Settings::int('retention_originals_days', 30), ['type' => 'number', 'ltr' => 1, 'min' => 1]) ?>
      <?= f_text('retention_results_days', 'الصور المتولدة (يوم)', (string) Settings::int('retention_results_days', 90), ['type' => 'number', 'ltr' => 1, 'min' => 1, 'hint' => 'بعدها لينك المشاركة بيعرض "اللينك مبقاش متاح"']) ?>
      <div class="full"><?= f_toggle('share_show_before', 'صفحة المشاركة تعرض "قبل" كمان', Settings::bool('share_show_before'), 'الافتراضي "بعد" بس عشان الخصوصية') ?></div>
    </div></section>
    <section class="card"><div class="card-h"><h2>عام</h2></div><div class="card-b form-grid">
      <?= f_select('timezone', 'المنطقة الزمنية', $curTz, array_combine($zones, $zones)) ?>
      <div class="field"><label>وضع الصيانة</label><div style="height:40px;display:flex;align-items:center"><?= f_toggle('maintenance_mode', 'الصفحة تعرض "راجعين قريب"', Settings::bool('maintenance_mode')) ?></div></div>
      <?= f_text('admin_ip_allowlist', 'IP allowlist للوحة التحكم (اختياري)', Settings::get('admin_ip_allowlist', ''), ['full' => 1, 'ltr' => 1, 'mono' => 1, 'placeholder' => '41.33.0.0/16, 197.55.10.4', 'hint' => 'الـ IP بتاعك دلوقتي: <span class="ltr mono">' . e(client_ip()) . '</span>']) ?>
    </div></section>
  </div>
  <div class="stack" style="gap:24px">
    <section class="card"><div class="card-h"><div><h2>ألوان البراند</h2><p>بتتطبق على الصفحة العامة كـ CSS variables</p></div></div><div class="card-b stack">
      <?php foreach (['color_primary' => ['الأساسي', '#1F73B7'], 'color_secondary' => ['الثانوي (Navy)', '#0E2B45'], 'color_accent' => ['لون التمييز (Gold)', '#C9A24B']] as $k => [$l, $def]): $v = css_color($k, $def); ?>
      <div class="color-row"><input type="color" name="<?= $k ?>" value="<?= e($v) ?>" data-color aria-label="<?= e($l) ?>"><div style="flex:1"><b style="color:var(--ink);font-size:14px"><?= e($l) ?></b><div class="ltr mono muted" style="text-align:right" data-color-label><?= e(strtoupper($v)) ?></div></div><button type="button" class="btn btn-ghost btn-sm" data-color-reset="<?= $def ?>">الافتراضي</button></div>
      <?php endforeach; ?>
    </div></section>
    <section class="card"><div class="card-h"><h2>نص سياسة الخصوصية</h2></div><div class="card-b"><?= f_textarea('privacy_text', 'كل سطر فقرة', Settings::get('privacy_text'), ['rows' => 7]) ?></div></section>
  </div>
  <div class="full-span row" style="justify-content:flex-end"><?= save_bar() ?></div>
</form>

<section class="card"><div class="card-h"><div><h2>سجل النشاط</h2><p>آخر 30 عملية في لوحة التحكم</p></div></div>
  <?php if (!$logs): ?><?= empty_block('list', 'السجل فاضي', 'أي تعديل هيتسجل هنا.') ?><?php else: ?>
  <div class="table-wrap" style="max-height:420px"><table class="t"><thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th class="hide-tab">التفاصيل</th></tr></thead><tbody>
  <?php foreach ($logs as $lg): ?><tr style="cursor:default"><td class="muted"><?= e(local_dt($lg['created_at'])) ?></td><td><?= e($lg['name'] ?: '—') ?></td><td class="ltr mono" style="text-align:right"><?= e($lg['action']) ?></td><td class="muted hide-tab note" style="max-width:380px;white-space:normal"><?= e(mb_strimwidth((string) $lg['details'], 0, 120, '…')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php
admin_page_end();
