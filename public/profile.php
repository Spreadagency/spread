<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'ui_pref') {
        $v = in_array($_POST['ui_pref'] ?? '', ['auto', 'v2', 'v1'], true) ? $_POST['ui_pref'] : 'auto';
        try {
            db_run('UPDATE users SET ui_pref = ? WHERE id = ?', [$v, $user['id']]);
            unset($_SESSION['ui_preview']);
            flash_set('success', $v === 'v2' ? 'فعّلنا الشكل الجديد ✨' : ($v === 'v1' ? 'رجعنا للشكل القديم' : 'هتشوف الشكل الافتراضي للمنصة'));
        } catch (\Throwable $e) {
            flash_set('danger', 'الميزة دي لسه مش متفعّلة — كلّم الدعم');
        }
        redirect('profile.php');
    }

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'الاسم مطلوب';
        } else {
            $phone = mb_substr(preg_replace('/[^0-9+]/', '', $_POST['phone'] ?? ''), 0, 30);
            db_run('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone ?: null, $user['id']]);
            flash_set('success', 'تم تحديث البيانات ✓');
            redirect('profile.php');
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $u = db_one('SELECT password, auth_provider FROM users WHERE id = ?', [$user['id']]);
        // حساب جوجل من غير كلمة مرور: بيضبط واحدة لأول مرة بدون ما نطلب القديمة
        $isFirstPassword = empty($u['password']);

        if (!$isFirstPassword && !password_verify($current, (string) $u['password'])) {
            $errors[] = 'كلمة المرور الحالية غلط';
        } elseif (strlen($new) < 6) {
            $errors[] = 'كلمة المرور الجديدة لازم تكون 6 حروف على الأقل';
        } elseif ($new !== $confirm) {
            $errors[] = 'تأكيد كلمة المرور مش مطابق';
        } else {
            db_run('UPDATE users SET password = ?, auth_provider = ? WHERE id = ?', [
                password_hash($new, PASSWORD_BCRYPT),
                $isFirstPassword ? 'both' : ($u['auth_provider'] ?? 'password'),
                $user['id'],
            ]);
            try { db_run('UPDATE users SET password_changed_at = NOW() WHERE id = ?', [$user['id']]); account_session_revoke_others((int) $user['id']); } catch (\Throwable $e) {}
            flash_set('success', $isFirstPassword
                ? 'تم ضبط كلمة المرور ✓ — تقدر تدخل بجوجل أو بكلمة المرور'
                : 'تم تغيير كلمة المرور ✓');
            redirect('profile.php');
        }
    }
}

$active = 'profile';
$page_title = 'الحساب الشخصي';

// الشكل الجديد: مركز الإعدادات (المرحلة ⑥-أ) — الحساب · الباقة · الإحالة · الحسابات المربوطة · الأمان
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    require_once __DIR__ . '/../includes/ui-v2.php';
    $page_title = 'الإعدادات';
    $use_app = true;
    include __DIR__ . '/../templates/header.php';
    echo '<div class="app">';
    include __DIR__ . '/../templates/sidebar.php';
    echo '<main class="main">';
    include __DIR__ . '/../templates/topbar.php';
    include __DIR__ . '/../templates/v2/settings.php';
    echo '</main></div>';
    include __DIR__ . '/../templates/footer.php';
    exit;
}
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>الحساب الشخصي ⚙</h1>
            <div class="sub">تحديث بيانات حسابك وكلمة المرور</div>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e($err) ?></div>
        <?php endforeach; ?>


        <?php
        $__mode = get_setting('ui_v2_mode', 'optin');
        $__pref = 'auto';
        try { $__pref = db_one('SELECT ui_pref FROM users WHERE id = ?', [$user['id']])['ui_pref'] ?? 'auto'; } catch (\Throwable $e) {}
        if ($__mode !== 'off'):
        ?>
        <div class="card" style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
                <div>
                    <div class="card-head" style="margin:0 0 4px"><h3>✨ الشكل الجديد لـ Spread AI</h3></div>
                    <p class="sub" style="margin:0;font-size:13.5px">
                        <?= ui_v2_enabled()
                            ? 'انت شغّال على الشكل الجديد دلوقتي — لو فيه حاجة مش مريحاك تقدر ترجع للقديم في أي وقت.'
                            : 'جرّب الهوية الجديدة: تصميم أنضف، وSpread AI بيوريك خطواته وهو بيفكر.' ?>
                    </p>
                </div>
                <form method="POST" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ui_pref">
                    <?php if (ui_v2_enabled()): ?>
                        <input type="hidden" name="ui_pref" value="v1">
                        <button class="btn ghost">↩ ارجع للشكل القديم</button>
                    <?php else: ?>
                        <input type="hidden" name="ui_pref" value="v2">
                        <button class="btn">✨ جرّب الشكل الجديد</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="split split-2">

            <!-- Profile -->
            <div class="card">
                <div class="card-head"><h3>البيانات الأساسية</h3></div>

                <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
                    <div class="avi xl" style="background:<?= e(color_from_string($user['email'])) ?>">
                        <?= e(initials($user['name'])) ?>
                    </div>
                    <div>
                        <b style="font-size:16px"><?= e($user['name']) ?></b>
                        <div class="text-mute" style="font-size:12.5px"><?= e($user['email']) ?></div>
                        <div class="text-mute" style="font-size:11px;margin-top:2px">عضو منذ <?= e(fmt_date($user['created_at'])) ?></div>
                    </div>
                </div>

                <form method="POST" data-safe-post>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="field">
                        <label>الاسم</label>
                        <input type="text" name="name" class="input" value="<?= e($user['name']) ?>" required>
                    </div>
                    <div class="field">
                        <label>رقم الموبايل</label>
                        <input type="tel" name="phone" class="input" dir="ltr" value="<?= e($user['phone'] ?? '') ?>" placeholder="01xxxxxxxxx">
                    </div>

                    <div class="field">
                        <label>البريد الإلكتروني</label>
                        <input type="email" class="input" value="<?= e($user['email']) ?>" disabled>
                        <div class="field-help">البريد ما يتغيرش</div>
                    </div>

                    <button class="btn">حفظ التغييرات</button>
                </form>
            </div>

            <!-- Password -->
            <div class="card">
                <div class="card-head"><h3>تغيير كلمة المرور</h3></div>

                <form method="POST" data-safe-post>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="field">
                        <label>كلمة المرور الحالية</label>
                        <input type="password" name="current_password" class="input"
                               <?= empty($user['password']) ? '' : 'required' ?>
                               <?= empty($user['password']) ? 'disabled placeholder="مش محتاجة — حسابك بجوجل"' : '' ?>>
                    </div>

                    <div class="field">
                        <label>كلمة المرور الجديدة</label>
                        <input type="password" name="new_password" class="input" required minlength="6">
                        <div class="field-help">على الأقل 6 حروف</div>
                    </div>

                    <div class="field">
                        <label>تأكيد كلمة المرور</label>
                        <input type="password" name="confirm_password" class="input" required>
                    </div>

                    <button class="btn">تغيير كلمة المرور</button>
                </form>
            </div>
        </div>
    </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
