<?php
/** Spread AI v2 — الأدمن: ترحيلات قاعدة البيانات */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/migrations.php';

require_admin();
require_admin_can('site_settings');   // المدير العام بس

$admin = current_admin();
$by = mb_substr((string) ($admin['email'] ?? 'admin'), 0, 120);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $a = $_POST['action'] ?? '';
    @set_time_limit(120);
    unset($_SESSION['a2_mig_pending']); // تنبيه الترحيلات أعلى الصفحات يتحدّث فورًا

    if ($a === 'run_pending') {
        $r = migrations_run_pending($by);
        admin_log('run_migrations', 'settings', null, implode(', ', $r['ran']));
        if ($r['ok']) {
            flash_set('success', $r['ran'] ? 'اتنفّذ: ' . implode(' · ', $r['ran']) : 'مفيش حاجة معلّقة — كله محدّث ✓');
        } else {
            flash_set('danger', 'وقف عند خطأ — ' . $r['error'] . ($r['ran'] ? ' (اتنفّذ قبله: ' . implode(' · ', $r['ran']) . ')' : ''));
        }
    }

    if ($a === 'run_one' && !empty($_POST['file'])) {
        $r = migration_run_file((string) $_POST['file'], $by);
        admin_log('run_migration', 'settings', null, (string) $_POST['file']);
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'اتنفّذ في ' . $r['ms'] . ' م.ث ✓' : 'فشل: ' . $r['error']);
    }

    // للقواعد اللي اتعملت يدويًا قبل كده من phpMyAdmin
    if ($a === 'mark_all' && migrations_table_ready()) {
        foreach (migrations_status() as $m) {
            if ($m['state'] === 'pending') migration_mark($m['file'], $by . ' (تعليم يدوي)');
        }
        admin_log('mark_migrations', 'settings');
        flash_set('success', 'اتعلّمت كل الملفات كمطبّقة');
    }
    redirect('admin/migrations.php');
}

$list = migrations_status();
$pending = count(array_filter($list, fn($m) => $m['state'] === 'pending'));
$changed = count(array_filter($list, fn($m) => $m['state'] === 'changed'));
$labels = [
    'applied' => ['✓ مطبّق', '#10A8A0'],
    'pending' => ['⏳ معلّق', '#D98A1F'],
    'changed' => ['⚠ اتغيّر بعد التطبيق', '#6E5BE0'],
    'missing' => ['✕ الملف مفقود', '#E0526A'],
];

$active = 'migrations';
$page_title = 'ترحيلات قاعدة البيانات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>ترحيلات قاعدة البيانات 🗄</h1>
            <div class="sub">بدل «شغّل الملفات بالترتيب» — المنصة بتعرف اتنفّذ إيه وناقص إيه</div>
        </div>
        <?= render_flash() ?>

        <div class="card" style="border:2px solid <?= $pending ? '#D98A1F' : '#10A8A0' ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
                <div>
                    <b style="font-size:16px"><?= $pending ? "⏳ فيه {$pending} ملف معلّق" : '✅ قاعدة البيانات محدّثة' ?></b>
                    <?php if ($changed): ?><div class="sub" style="margin-top:4px"><?= $changed ?> ملف اتعدّل بعد تطبيقه — كل الملفات آمنة لإعادة التشغيل.</div><?php endif; ?>
                    <?php if (!migrations_table_ready()): ?>
                        <div class="sub" style="margin-top:4px">جدول التتبّع لسه مش موجود — أول تشغيل هيعمله.</div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <?php if ($pending || !migrations_table_ready()): ?>
                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="run_pending">
                            <button class="btn">▶ شغّل المعلّق</button></form>
                    <?php endif; ?>
                    <?php if ($pending && migrations_table_ready()): ?>
                        <form method="POST" onsubmit="return confirm('تعلّم كل الملفات المعلّقة كمطبّقة من غير تشغيل؟\nاستخدمها بس لو كنت شغّلتها بإيدك قبل كده.')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="mark_all">
                            <button class="btn ghost">✓ اتنفّذت يدويًا قبل كده</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>الملف</th><th>الوصف</th><th>الحالة</th><th>اتنفّذ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $i => $m): [$lbl, $col] = $labels[$m['state']]; ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td dir="ltr" style="text-align:start"><code><?= e($m['file']) ?></code></td>
                        <td><?= e($m['label']) ?></td>
                        <td><b style="color:<?= $col ?>;font-size:13px"><?= $lbl ?></b></td>
                        <td class="sub" style="font-size:12px">
                            <?= $m['applied_at'] ? e(fmt_date($m['applied_at'], true)) . ' · ' . $m['ms'] . ' م.ث' : '—' ?>
                        </td>
                        <td>
                            <?php if ($m['state'] !== 'missing'): ?>
                                <form method="POST" style="margin:0"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="run_one">
                                    <input type="hidden" name="file" value="<?= e($m['file']) ?>">
                                    <button class="btn ghost sm"><?= $m['state'] === 'applied' ? '↻ إعادة' : '▶ تشغيل' ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="sub" style="font-size:12.5px;margin-top:12px">
                كل الملفات مكتوبة بحيث إعادة تشغيلها آمنة. ملف الموقع التعريفي (<code>site-schema.sql</code>) مش هنا لأن ليه قاعدة بيانات منفصلة.
            </p>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
