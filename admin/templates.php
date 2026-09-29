<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!empty($_POST['save_template'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'prompt_snippet' => trim($_POST['prompt_snippet'] ?? ''),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'order_num' => (int) ($_POST['order_num'] ?? 0),
        ];
        if ($data['name'] && $data['prompt_snippet']) {
            if ($id) {
                db_run('UPDATE content_templates SET name=?, description=?, prompt_snippet=?, is_active=?, order_num=? WHERE id=?',
                    [$data['name'], $data['description'], $data['prompt_snippet'], $data['is_active'], $data['order_num'], $id]);
                admin_log('update_template', 'template', $id, $data['name']);
            } else {
                $id = db_insert_row('content_templates', $data);
                admin_log('create_template', 'template', $id, $data['name']);
            }
            flash_set('success', 'تم حفظ القالب ✓');
        } else {
            flash_set('danger', 'الاسم ونص القالب مطلوبان');
        }
        redirect('admin/templates.php');
    }

    if (!empty($_POST['delete_template'])) {
        $id = (int) $_POST['delete_template'];
        db_run('DELETE FROM content_templates WHERE id = ?', [$id]);
        admin_log('delete_template', 'template', $id, '');
        flash_set('success', 'تم حذف القالب');
        redirect('admin/templates.php');
    }
}

$templates = db_all('SELECT * FROM content_templates ORDER BY order_num ASC, id ASC');
$editing = null;
if (!empty($_GET['edit'])) {
    $editing = db_one('SELECT * FROM content_templates WHERE id = ?', [(int) $_GET['edit']]);
}

$active = 'templates';
$page_title = 'قوالب المحتوى';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <?php if (is_file(__DIR__ . '/../templates/admin-topbar.php')) include __DIR__ . '/../templates/admin-topbar.php'; ?>

        <div class="page-head">
            <h1>قوالب المحتوى 📚</h1>
            <div class="sub">مكتبة قوالب جاهزة تظهر للمستخدمين عند التوليد</div>
        </div>

        <?= render_flash() ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:20px" id="tpl-grid">
            <div class="card">
                <div class="card-head"><h3>القوالب (<?= count($templates) ?>)</h3></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>#</th><th>الاسم</th><th>الوصف</th><th>الحالة</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($templates as $t): ?>
                            <tr>
                                <td><?= $t['order_num'] ?></td>
                                <td><b><?= e($t['name']) ?></b></td>
                                <td class="text-mute" style="font-size:12px"><?= e($t['description']) ?></td>
                                <td>
                                    <?php if ($t['is_active']): ?>
                                        <span class="chip chip-mint">مفعل</span>
                                    <?php else: ?>
                                        <span class="chip">موقوف</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap">
                                    <a href="?edit=<?= $t['id'] ?>" class="btn ghost sm">تعديل</a>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('حذف القالب؟')">
                                        <?= csrf_field() ?>
                                        <button name="delete_template" value="<?= $t['id'] ?>" class="btn ghost sm danger">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h3><?= $editing ? 'تعديل قالب' : 'قالب جديد' ?></h3></div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $editing['id'] ?? 0 ?>">

                    <div class="field">
                        <label>اسم القالب</label>
                        <input type="text" name="name" class="input" required value="<?= e($editing['name'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>وصف قصير</label>
                        <input type="text" name="description" class="input" value="<?= e($editing['description'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label>نص القالب (يُضاف للبرومبت)</label>
                        <textarea name="prompt_snippet" class="textarea" rows="6" required><?= e($editing['prompt_snippet'] ?? '') ?></textarea>
                    </div>
                    <div class="field" style="display:flex;gap:16px;align-items:center">
                        <label style="display:flex;gap:6px;align-items:center;margin:0">
                            <input type="checkbox" name="is_active" value="1" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>> مفعل
                        </label>
                        <input type="number" name="order_num" class="input" style="width:90px" value="<?= $editing['order_num'] ?? 0 ?>" title="الترتيب">
                    </div>
                    <button name="save_template" value="1" class="btn full">حفظ القالب</button>
                    <?php if ($editing): ?>
                        <a href="<?= url('admin/templates.php') ?>" class="btn ghost full mt-10">إلغاء التعديل</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </main>
</div>
<style>@media (max-width:980px){#tpl-grid{grid-template-columns:1fr !important}}</style>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
