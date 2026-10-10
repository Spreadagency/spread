<?php
/** المستخدمين والصلاحيات — حسابات لوحة الموقع + مصفوفة الصلاحيات حسب الدور */
require_once __DIR__ . '/auth.php';
sa_require_perm('users');

$me = sa_admin();
$isSuper = sa_role($me) === 'super_admin';
$roles = sa_roles();
$sections = sa_perm_sections();

$superCount = fn() => (int) (s_one("SELECT COUNT(*) c FROM site_admins WHERE role = 'super_admin' AND status = 'active'")['c'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    s_check_csrf();
    s_decode_b64();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = isset($roles[$_POST['role'] ?? '']) ? (string) $_POST['role'] : 'editor';
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $pass = (string) ($_POST['password'] ?? '');
        $existing = $id ? s_one('SELECT * FROM site_admins WHERE id = ?', [$id]) : null;
        $back = 'site-admin/users.php' . ($id ? '?edit=' . $id : '?new=1');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { s_flash('danger', 'الاسم وإيميل صحيح مطلوبين'); s_redirect($back); }
        if (!$id && strlen($pass) < 8) { s_flash('danger', 'كلمة المرور 8 حروف على الأقل'); s_redirect($back); }
        if ($id && $pass !== '' && strlen($pass) < 8) { s_flash('danger', 'كلمة المرور 8 حروف على الأقل'); s_redirect($back); }
        if (s_one('SELECT id FROM site_admins WHERE email = ? AND id <> ?', [$email, $id])) { s_flash('danger', 'الإيميل ده مستخدم في حساب تاني'); s_redirect($back); }
        // منع تصعيد الصلاحيات: Super Admin بس اللي يدي أو يشيل دور Super Admin
        if (!$isSuper && ($role === 'super_admin' || ($existing && $existing['role'] === 'super_admin'))) { s_flash('danger', 'الـ Super Admin بس اللي يقدر يدير حسابات Super Admin'); s_redirect('site-admin/users.php'); }
        if ($existing && (int) $existing['id'] === (int) $me['id'] && ($role !== $existing['role'] || $status !== 'active')) { s_flash('danger', 'مينفعش تغيّر دورك أو توقف حسابك انت'); s_redirect($back); }
        if ($existing && $existing['role'] === 'super_admin' && ($role !== 'super_admin' || $status !== 'active') && $superCount() <= 1) { s_flash('danger', 'لازم يفضل Super Admin واحد على الأقل'); s_redirect($back); }

        if ($existing) {
            s_run('UPDATE site_admins SET name = ?, email = ?, role = ?, status = ? WHERE id = ?', [$name, $email, $role, $status, $id]);
            if ($pass !== '') s_run('UPDATE site_admins SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            sa_log('update', 'users', 'تعديل حساب «' . $name . '» (' . $roles[$role][0] . ')' . ($pass !== '' ? ' + تغيير كلمة المرور' : ''), $id);
            s_flash('success', 'تم حفظ الحساب ✓');
        } else {
            $nid = s_insert('INSERT INTO site_admins (name, email, password, status, role) VALUES (?,?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $status, $role]);
            sa_log('create', 'users', 'إضافة مستخدم «' . $name . '» بدور ' . $roles[$role][0], $nid ?: null);
            s_flash('success', 'اتضاف المستخدم ✓ — ابعتله الإيميل وكلمة المرور');
        }
        s_redirect('site-admin/users.php');
    }

    if ($action === 'delete') {
        $u = s_one('SELECT * FROM site_admins WHERE id = ?', [$id]);
        if (!$u) s_redirect('site-admin/users.php');
        if ((int) $u['id'] === (int) $me['id']) { s_flash('danger', 'مينفعش تحذف حسابك'); s_redirect('site-admin/users.php'); }
        if ($u['role'] === 'super_admin' && (!$isSuper || $superCount() <= 1)) { s_flash('danger', 'مينفعش تحذف الحساب ده'); s_redirect('site-admin/users.php'); }
        s_run('DELETE FROM site_admins WHERE id = ?', [$id]);
        sa_log('delete', 'users', 'حذف المستخدم «' . $u['name'] . '»', $id);
        s_flash('success', 'اتحذف المستخدم');
        s_redirect('site-admin/users.php');
    }

    if ($action === 'matrix' && $isSuper) {
        $m = [];
        foreach (array_keys($roles) as $r) {
            if ($r === 'super_admin') continue;
            $m[$r] = array_values(array_intersect(array_keys((array) ($_POST['perm'][$r] ?? [])), array_keys($sections)));
        }
        s_set('roles_matrix', json_encode($m, JSON_UNESCAPED_UNICODE));
        sa_log('role', 'users', 'تعديل مصفوفة الصلاحيات حسب الدور');
        s_flash('success', 'الصلاحيات اتحفظت ✓ — بتتطبق من أول صفحة يفتحوها');
        s_redirect('site-admin/users.php#matrix');
    }
    if ($action === 'matrix_reset' && $isSuper) {
        s_set('roles_matrix', '');
        sa_log('role', 'users', 'رجوع الصلاحيات للافتراضي');
        s_flash('success', 'رجعت الصلاحيات الافتراضية ✓');
        s_redirect('site-admin/users.php#matrix');
    }
}

$rows = s_all('SELECT * FROM site_admins ORDER BY role = "super_admin" DESC, id');
$editing = !empty($_GET['edit']) ? s_one('SELECT * FROM site_admins WHERE id = ?', [(int) $_GET['edit']]) : null;
$matrix = sa_matrix();
$roleOpts = [];
foreach ($roles as $k => [$l, $d]) if ($isSuper || $k !== 'super_admin') $roleOpts[$k] = $l . ' — ' . $d;

$__t = 'المستخدمين والصلاحيات';
include __DIR__ . '/layout.php';
echo sa_page_head('shield', 'المستخدمين والصلاحيات', 'Admin Users & Roles', 'مين يقدر يدخل لوحة الموقع، ودوره، والأقسام اللي يقدر يديرها.');
echo sa_search_bar('ابحث في مستخدمين الأدمن...', count($rows), 'مستخدم', sa_btn('إضافة مستخدم', 'pri', '?new=1', 'plus', ['data-open-drawer' => 'crud']));
?>
<div class="ad-table-w"><table class="ad-table">
  <thead><tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>آخر دخول</th><th>الحالة</th><th style="text-align:left">إجراءات</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $u): $self = (int) $u['id'] === (int) $me['id']; $canEdit = $isSuper || $u['role'] !== 'super_admin'; ?>
    <tr data-row class="<?= $u['status'] === 'active' ? '' : 'off' ?>">
      <td class="t-first" data-l="الاسم"><span class="t-main"><?= e($u['name']) ?><?= $self ? ' <span class="ad-hint">(أنت)</span>' : '' ?></span></td>
      <td data-l="البريد" dir="ltr" style="text-align:right"><?= e($u['email']) ?></td>
      <td data-l="الدور"><?= sa_chip($roles[sa_role($u)][0], $u['role'] === 'super_admin' ? 'violet' : 'info') ?></td>
      <td data-l="آخر دخول" class="t-num"><?= e(sa_ago($u['last_login_at'] ?? null)) ?></td>
      <td class="t-chip" data-l="الحالة"><?= sa_status_chip($u['status']) ?></td>
      <td class="ad-acts"><div class="ad-acts-in">
        <?php if ($canEdit): ?><a class="ad-ib" href="?edit=<?= (int) $u['id'] ?>" aria-label="تعديل" title="تعديل"><?= sa_icon('edit', 17) ?></a><?php endif; ?>
        <?php if (!$self && $canEdit): ?><form method="POST" data-confirm="هتحذف حساب «<?= e($u['name']) ?>» من لوحة الموقع — متأكد؟"><?= s_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="ad-ib del" aria-label="حذف" title="حذف"><?= sa_icon('trash', 17) ?></button></form><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<section class="ad-card sa-in" id="matrix" style="margin-top:22px">
  <div class="ad-card-h"><h3>الصلاحيات حسب الدور</h3>
    <?php if (!$isSuper): ?><span class="ad-hint">التعديل للـ Super Admin بس</span><?php endif; ?></div>
  <form method="POST">
    <?= s_csrf_field() ?><input type="hidden" name="action" value="matrix">
    <div class="ad-perm-w ad-scroll"><table class="ad-table ad-perm">
      <thead><tr><th>القسم</th><?php foreach ($roles as $k => [$l]): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($sections as $sk => $sl): ?>
        <tr><td style="font-weight:600"><?= e($sl) ?></td>
          <?php foreach ($roles as $rk => [$rl]): $on = $rk === 'super_admin' || in_array($sk, $matrix[$rk] ?? [], true); ?>
            <td><label class="ad-cb"><input type="checkbox" name="perm[<?= e($rk) ?>][<?= e($sk) ?>]" value="1" <?= $on ? 'checked' : '' ?> <?= ($rk === 'super_admin' || !$isSuper) ? 'disabled' : '' ?> aria-label="<?= e($rl . ' — ' . $sl) ?>"><span><?= sa_icon('check', 15, 2.6) ?></span></label></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($isSuper): ?>
      <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap"><?= sa_btn('حفظ الصلاحيات', 'pri', null, 'check', ['type' => 'submit']) ?>
        <button type="submit" name="action" value="matrix_reset" class="ad-btn ad-sec">الافتراضي</button></div>
    <?php endif; ?>
  </form>
  <p class="ad-hint" style="margin:12px 0 0">لوحة التحكم الرئيسية متاحة لكل الأدوار. الـ Super Admin دايمًا عنده كل الصلاحيات.</p>
</section>

<?= sa_drawer_open('crud', $editing ? 'تعديل «' . $editing['name'] . '»' : 'إضافة مستخدم', (bool) $editing || !empty($_GET['new'])) ?>
  <?= s_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
  <div class="ad-form one">
    <?= sa_field(['name' => 'name', 'label' => 'الاسم', 'req' => true, 'max' => 150], $editing['name'] ?? '') ?>
    <?= sa_field(['name' => 'email', 'label' => 'البريد الإلكتروني', 'type' => 'email', 'req' => true], $editing['email'] ?? '') ?>
    <?= sa_field(['name' => 'role', 'label' => 'الدور', 'type' => 'select', 'options' => $roleOpts], $editing['role'] ?? 'editor') ?>
    <?= sa_field(['name' => 'status', 'label' => 'الحالة', 'type' => 'select', 'options' => ['active' => 'مفعّل', 'inactive' => 'متوقف']], $editing['status'] ?? 'active') ?>
    <?= sa_field(['name' => 'password', 'label' => $editing ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور', 'type' => 'password', 'req' => !$editing, 'hint' => '8 حروف على الأقل.' . ($editing ? ' سيبها فاضية علشان تفضل زي ما هي.' : '')]) ?>
  </div>
<?= sa_drawer_close($editing ? 'حفظ' : 'إضافة') ?>
<?php include __DIR__ . '/layout-end.php'; ?>
