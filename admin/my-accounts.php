<?php
/**
 * Spread AI — الأدمن: «حساباتي» (خريطة التطوير · المرحلة 2)
 * كارت لكل عميل بنديره: المطلوب الشهر ده مقابل اللي اتعمل · مستني موافقة · تعديل مطلوب · فشل نشر · رصيد قليل · مهام مفتوحة
 *   assistant: حساباته بس · manager و super: حساباتهم أو الكل (?all=1)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/managed-accounts.php';

require_admin();
require_admin_can('view_users');

$me = current_admin();
$senior = in_array(($me['role'] ?? 'super'), ['manager', 'super'], true);
$ready = ma_ready();
$mine = $ready ? ma_overview((int) $me['id']) : [];
// المدير اللي مالوش حسابات باسمه بيشوف الكل من الأول
$all = $senior && (isset($_GET['all']) || !$mine);
$rows = $all && $ready ? ma_overview(null) : $mine;

$active = 'my-accounts';
$page_title = 'حساباتي';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>حساباتي ⭐ <span class="chip chip-primary"><?= count($rows) ?></span></h1>
                <div class="sub">العملاء اللي الفريق بيدير حساباتهم — المطلوب الشهر ده واللي محتاج تدخّل</div>
            </div>
            <?php if ($senior && $ready): ?>
                <a class="btn ghost" href="<?= url('admin/my-accounts.php' . ($all ? '' : '?all=1')) ?>"><?= $all ? 'حساباتي بس' : 'كل الحسابات' ?></a>
            <?php endif; ?>
        </div>

        <?= render_flash() ?>

        <?php if (!$ready): ?>
            <div class="alert warning">شغّل ترحيل <b dir="ltr">2026_managed_accounts.sql</b> من «فحص التحديث» الأول — لحد ما يتشغّل علامة ⭐ القديمة شغالة زي ما هي.</div>
        <?php elseif (!$rows): ?>
            <div class="card"><div class="empty" style="padding:28px;text-align:center">
                مفيش حسابات <?= $all ? 'مُدارة' : 'باسمك' ?> لسه. من «العملاء» دوس ☆ جنب العميل، وبعدين افتح «مساحة العميل» وعيّن المسؤول.
                <div style="margin-top:12px"><a class="btn" href="<?= url('admin/users.php') ?>">العملاء ←</a></div>
            </div></div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
            <?php foreach ($rows as $r):
                $uid = (int) $r['user_id'];
                $target = (int) $r['monthly_posts'];
                $pct = $target > 0 ? min(100, (int) round($r['made'] * 100 / $target)) : 0; ?>
                <div class="card" style="padding:16px">
                    <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
                        <div style="min-width:0">
                            <b style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['name']) ?></b>
                            <span class="text-mute" style="font-size:12px"><?= $r['manager_name'] ? 'المسؤول: ' . e($r['manager_name']) : 'من غير مسؤول' ?></span>
                        </div>
                        <span class="chip"><?= $r['service_level'] === 'auto' ? 'نشر مباشر' : 'بموافقة' ?></span>
                    </div>
                    <div style="margin:12px 0 6px;font-size:13px">
                        الشهر ده: <b><?= (int) $r['made'] ?></b><?= $target > 0 ? ' من ' . $target . ' بوست' : ' بوست' ?> · اتنشر <?= (int) $r['published'] ?>
                    </div>
                    <?php if ($target > 0): ?>
                        <div style="height:6px;border-radius:3px;background:var(--line, #e6e6ef);overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:var(--primary, #4a3db8)"></div></div>
                    <?php endif; ?>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin:12px 0">
                        <?php if ($r['pending']): ?><span class="chip chip-primary">مستني موافقة <?= (int) $r['pending'] ?></span><?php endif; ?>
                        <?php if ($r['changes']): ?><span class="chip" style="background:#fff3cd;color:#7a5b00">تعديل مطلوب <?= (int) $r['changes'] ?></span><?php endif; ?>
                        <?php if ($r['failed']): ?><span class="chip" style="background:#fde2e1;color:#b3261e">فشل نشر <?= (int) $r['failed'] ?></span><?php endif; ?>
                        <?php if ($r['low_credit']): ?><span class="chip" style="background:#fde2e1;color:#b3261e">رصيد قليل (<?= (int) $r['balance'] ?>)</span><?php endif; ?>
                        <?php if ($r['tasks']): ?><span class="chip">مهام <?= (int) $r['tasks'] ?></span><?php endif; ?>
                        <?php if (!$r['pending'] && !$r['changes'] && !$r['failed'] && !$r['low_credit'] && !$r['tasks']): ?><span class="text-mute" style="font-size:12px">مفيش حاجة مستنياك ✓</span><?php endif; ?>
                    </div>
                    <a class="btn full" href="<?= url('admin/workspace.php?id=' . $uid) ?>">مساحة العميل ←</a>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
