<?php
/**
 * Spread AI — الأدمن: «اليوم» (خريطة التطوير · المرحلة 5)
 * أول صفحة بعد الدخول: طابور واحد بكل اللي مستني الفريق — رسائل · دفعات · تعديلات العملاء · فشل نشر · فيديو · موافقات · بلاغات · تنبيهات.
 * كل بند بيوصّل للصفحة اللي بيتحل منها، والرسائل بتتقفل من هنا على طول. لوحة الأرقام لسه في admin/dashboard.php.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/admin-today.php';

require_admin();
$me = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'inbound_done') {
        $in = msg_ready() ? db_one('SELECT * FROM message_inbound WHERE id = ?', [(int) ($_POST['inbound_id'] ?? 0)]) : null;
        if ($in && admin_can('view_users') && msg_can_handle($in)) {
            msg_mark_handled((int) $in['id'], (int) $me['id']);
            flash_set('success', 'اتقفلت ✓');
        } else {
            flash_set('danger', 'الرسالة دي مش في حساباتك');
        }
    }
    redirect('admin/today.php');
}

$queue = admin_today_queue();
$total = array_sum(array_column($queue, 'count'));
$mine = ma_ready() && admin_can('view_users') ? ma_overview((int) $me['id']) : [];

$active = 'today';
$page_title = 'اليوم';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>أهلًا <?= e(explode(' ', trim((string) ($me['name'] ?? '')))[0]) ?> 👋</h1>
            <div class="sub"><?= $total > 0 ? 'فيه ' . $total . ' حاجة مستنياك النهارده — الأهم الأول.' : 'مفيش حاجة مستنياك دلوقتي ✓' ?></div>
        </div>

        <?= render_flash() ?>

        <?php if (!$queue): ?>
            <section class="card" style="padding:28px;text-align:center">
                <div style="font-size:34px">✓</div>
                <b>كل حاجة متقفلة</b>
                <p class="a2-muted">مفيش رسائل ولا دفعات ولا فشل نشر مستني حد.</p>
                <a class="btn ghost" href="<?= url('admin/dashboard.php') ?>">لوحة الأرقام ←</a>
            </section>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px">
        <?php foreach ($queue as $q): ?>
            <section class="card" style="padding:16px" id="q-<?= e($q['key']) ?>">
                <div class="a2-h" style="display:flex;align-items:center;gap:8px">
                    <span class="chip <?= $q['tone'] === 'bad' ? 'chip-coral' : 'chip-amber' ?>"><?= (int) $q['count'] ?></span>
                    <h3 style="margin:0;font-size:15px"><?= e($q['title']) ?></h3>
                    <span class="a2-grow" style="flex:1"></span>
                    <a class="btn sm ghost" href="<?= url($q['url']) ?>">افتح ←</a>
                </div>
                <?php foreach ($q['rows'] as $r): ?>
                    <div style="display:flex;gap:8px;align-items:center;border-top:1px solid #E6EDF5;padding:9px 0">
                        <a href="<?= url($r['url']) ?>" style="flex:1;min-width:0;text-decoration:none;color:inherit">
                            <b style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13.5px"><?= e($r['label']) ?></b>
                            <?php if (($r['sub'] ?? '') !== ''): ?><small class="a2-muted" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['sub']) ?></small><?php endif; ?>
                        </a>
                        <?php if (!empty($r['inbound_id'])): ?>
                            <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="inbound_done"><input type="hidden" name="inbound_id" value="<?= (int) $r['inbound_id'] ?>">
                                <button class="btn sm ghost" type="submit" title="اتعامل معاها">✓</button></form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($q['count'] > count($q['rows']) && $q['rows']): ?>
                    <a class="a2-muted" style="font-size:12px" href="<?= url($q['url']) ?>">و<?= (int) $q['count'] - count($q['rows']) ?> كمان ←</a>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        </div>

        <?php if ($mine): ?>
            <section class="card" style="padding:16px;margin-top:14px">
                <div class="a2-h" style="display:flex;align-items:center"><h3 style="margin:0;font-size:15px">حساباتي ⭐</h3><span style="flex:1"></span><a class="btn sm ghost" href="<?= url('admin/my-accounts.php') ?>">كلها ←</a></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العميل</th><th>الشهر ده</th><th>مستني موافقة</th><th>فشل نشر</th><th></th></tr></thead><tbody>
                <?php foreach (array_slice($mine, 0, 10) as $m): ?>
                    <tr><td><b><?= e($m['name']) ?></b></td>
                        <td class="num"><?= (int) $m['made'] ?><?= (int) $m['monthly_posts'] > 0 ? ' / ' . (int) $m['monthly_posts'] : '' ?></td>
                        <td class="num"><?= (int) $m['pending'] ?: '—' ?></td><td class="num"><?= (int) $m['failed'] ?: '—' ?></td>
                        <td><a class="btn sm ghost" href="<?= url('admin/workspace.php?id=' . (int) $m['user_id']) ?>">مساحة العميل</a></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
