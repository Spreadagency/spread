<?php
/**
 * Spread AI — الأدمن: المساعدين (المرحلة 7)
 * الإعدادات (تشغيل · الكريدت لكل رسالة · رسوم تنفيذ الوكيل · الحد اليومي) · دليل المنصة اللي «الدعم» بيجاوب منه
 * مهام الوكيل (المرحلة · الحالة · السبب · الحملة) · تذاكر الدعم بتروح «البلاغات» في مركز الجودة.
 * مين الباقات اللي فيها كل مساعد بيتحدد من «الباقات».
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/assistants.php';

require_admin();
require_admin_can('manage_packages');

if (!as_ready()) {
    flash_set('warning', 'شغّل ترحيل 2026_assistants الأول (فحص التحديث)');
    redirect('admin/migrations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'settings') {
        set_setting('assistant_support_enabled', !empty($_POST['assistant_support_enabled']) ? '1' : '0');
        set_setting('assistant_agent_enabled', !empty($_POST['assistant_agent_enabled']) ? '1' : '0');
        set_setting('assistant_support_cost', (string) max(0, min(100, (int) ($_POST['assistant_support_cost'] ?? 0))));
        set_setting('assistant_agent_msg_cost', (string) max(0, min(100, (int) ($_POST['assistant_agent_msg_cost'] ?? 0))));
        set_setting('assistant_agent_fee', (string) max(0, min(1000, (int) ($_POST['assistant_agent_fee'] ?? 0))));
        set_setting('assistant_agent_daily', (string) max(1, min(50, (int) ($_POST['assistant_agent_daily'] ?? 3))));
        admin_log('assistant_settings', 'settings', null, 'support=' . (!empty($_POST['assistant_support_enabled']) ? 1 : 0) . ' agent=' . (!empty($_POST['assistant_agent_enabled']) ? 1 : 0));
        flash_set('success', 'اتحفظ ✓');
    } elseif ($act === 'kb') {
        $kb = trim(mb_substr((string) ($_POST['assistant_support_kb'] ?? ''), 0, 12000));
        set_setting('assistant_support_kb', $kb);
        admin_log('assistant_kb', 'settings', null, mb_strlen($kb) . ' chars');
        flash_set('success', $kb === '' ? 'رجع للدليل الافتراضي ✓' : 'اتحفظ الدليل ✓');
    }
    redirect('admin/assistants.php');
}

$f = in_array($_GET['f'] ?? '', ['open', 'done', 'failed', 'all'], true) ? $_GET['f'] : 'open';
$where = match ($f) {
    'open' => 'r.status IN ("pending","running","paused")',
    'done' => 'r.status = "done"',
    'failed' => 'r.status IN ("failed","cancelled")',
    default => '1',
};
$runs = db_all("SELECT r.*, u.name user_name FROM agent_runs r JOIN users u ON u.id = r.user_id WHERE {$where} ORDER BY r.updated_at DESC LIMIT 100");
$counts = [];
foreach (db_all('SELECT status, COUNT(*) n FROM agent_runs GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
$week = db_one('SELECT
    (SELECT COUNT(*) FROM assistant_threads WHERE kind = "support" AND updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) support_chats,
    (SELECT COUNT(*) FROM user_issues WHERE category_user = "support_chat" AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) tickets,
    (SELECT COUNT(*) FROM agent_runs WHERE campaign_id IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) runs');
$pkgAgent = db_all('SELECT name FROM credit_packages WHERE assistant_agent = 1 AND is_active = 1 ORDER BY id');
$statusLbl = ['pending' => ['مستنية موافقة', 'chip-line'], 'running' => ['شغالة', 'chip-primary'], 'paused' => ['واقفة', 'chip-amber'], 'done' => ['خلصت', 'chip-mint'], 'failed' => ['فشلت', 'chip-coral'], 'cancelled' => ['اتلغت', 'chip-line']];
$kbNow = (string) get_setting('assistant_support_kb', '');

$active = 'assistants';
$page_title = 'المساعدين';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>🤖 المساعدين</h1>
            <div class="sub">«🛟 الدعم» بيحل مشاكل العميل من بيانات حسابه أو يفتح تذكرة · «🤖 الوكيل» بينفّذ حملة كاملة في المحرك بعد موافقة العميل. الموديل لكل مساعد من «الموديلات والـ Router» (assistant_support · assistant_agent).</div>
        </div>
        <?= render_flash() ?>

        <div class="kpis">
            <div class="kpi"><div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)">🛟</div><div><b><?= (int) $week['support_chats'] ?></b><small>محادثة دعم (7 أيام)</small></div></div>
            <a class="kpi" href="<?= url('admin/ai-issues.php?cat=support_chat') ?>" style="text-decoration:none;color:inherit"><div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)">📨</div><div><b><?= (int) $week['tickets'] ?></b><small>تذكرة للفريق (7 أيام)</small></div></a>
            <div class="kpi"><div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)">🤖</div><div><b><?= (int) $week['runs'] ?></b><small>مهمة للوكيل (7 أيام)</small></div></div>
            <a class="kpi" href="?f=open" style="text-decoration:none;color:inherit"><div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)">⏸</div><div><b><?= (int) ($counts['paused'] ?? 0) ?></b><small>مهام واقفة مستنية العميل</small></div></a>
        </div>

        <div class="split split-2" style="margin-top:16px">
            <div class="card">
                <div class="card-head"><h3>⚙️ الإعدادات</h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><input type="checkbox" name="assistant_support_enabled" value="1" <?= get_setting('assistant_support_enabled', '1') === '1' ? 'checked' : '' ?>> 🛟 الدعم شغال (لكل الباقات اللي فيها الدعم)</label>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><input type="checkbox" name="assistant_agent_enabled" value="1" <?= get_setting('assistant_agent_enabled', '1') === '1' ? 'checked' : '' ?>> 🤖 الوكيل شغال (للباقات اللي فيها الوكيل)</label>
                    <div class="field-row">
                        <div class="field"><label>كريدت لكل رسالة دعم</label><input type="number" name="assistant_support_cost" class="input" min="0" max="100" value="<?= (int) get_setting('assistant_support_cost', '0') ?>"><div class="field-help">0 = مجاني</div></div>
                        <div class="field"><label>كريدت لكل رسالة للوكيل</label><input type="number" name="assistant_agent_msg_cost" class="input" min="0" max="100" value="<?= (int) get_setting('assistant_agent_msg_cost', '0') ?>"></div>
                    </div>
                    <div class="field-row">
                        <div class="field"><label>رسوم تنفيذ المهمة (كريدت)</label><input type="number" name="assistant_agent_fee" class="input" min="0" max="1000" value="<?= (int) get_setting('assistant_agent_fee', '2') ?>"><div class="field-help">فوق سعر الخطة والكتابة العادي · بترجع لو العميل لغى قبل ما الخطة تتعتمد</div></div>
                        <div class="field"><label>أقصى مهام في اليوم للعميل</label><input type="number" name="assistant_agent_daily" class="input" min="1" max="50" value="<?= (int) get_setting('assistant_agent_daily', '3') ?>"></div>
                    </div>
                    <button class="btn sm">💾 حفظ</button>
                </form>
                <p class="field-help" style="margin-top:10px">الباقات اللي فيها الوكيل: <?= $pkgAgent ? e(implode('، ', array_column($pkgAgent, 'name'))) : '<b>ولا باقة</b> — فعّله من <a href="' . url('admin/packages.php') . '">الباقات</a>' ?></p>
            </div>

            <div class="card">
                <div class="card-head"><h3>📘 دليل المنصة للدعم</h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="kb">
                    <textarea name="assistant_support_kb" class="textarea" rows="12" maxlength="12000" placeholder="فاضي = الدليل الافتراضي"><?= e($kbNow !== '' ? $kbNow : as_kb()) ?></textarea>
                    <div class="field-help">«الدعم» بيجاوب من الدليل ده + بيانات حساب العميل بس (مابيخترعش أسعار أو مزايا). امسحه كله واحفظ ← يرجع للافتراضي.</div>
                    <button class="btn sm" style="margin-top:8px">💾 حفظ الدليل</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top:16px">
            <div class="card-head" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <h3 style="margin-inline-end:auto">مهام الوكيل</h3>
                <?php foreach (['open' => 'المفتوحة', 'done' => 'خلصت', 'failed' => 'فشلت/اتلغت', 'all' => 'الكل'] as $k => $l): ?>
                    <a href="?f=<?= $k ?>" class="chip <?= $f === $k ? 'chip-primary' : 'chip-line' ?>"><?= e($l) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (!$runs): ?><p class="sub" style="text-align:center;padding:20px">مفيش مهام هنا.</p><?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>#</th><th>العميل</th><th>المهمة</th><th>المرحلة</th><th>الحالة</th><th>رسوم</th><th>آخر تحديث</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $r): $s = $statusLbl[$r['status']] ?? [$r['status'], 'chip-line']; $log = json_decode((string) $r['log_json'], true) ?: []; ?>
                    <tr>
                        <td class="num"><?= (int) $r['id'] ?></td>
                        <td><a href="<?= url('admin/user-view.php?id=' . (int) $r['user_id']) ?>"><?= e($r['user_name']) ?></a></td>
                        <td><b><?= e($r['title']) ?></b><?php if ($r['campaign_id']): ?> <span class="text-mute">· حملة #<?= (int) $r['campaign_id'] ?></span><?php endif; ?>
                            <?php if ($r['note']): ?><div class="sub" style="font-size:11.5px"><?= e($r['note']) ?></div><?php endif; ?>
                            <?php if ($log): ?><details><summary style="cursor:pointer;font-size:11.5px">السجل (<?= count($log) ?>)</summary><?php foreach (array_slice($log, -10) as $l): ?><div style="font-size:11.5px"><span class="text-mute"><?= e((string) $l['at']) ?></span> <?= e((string) $l['msg']) ?></div><?php endforeach; ?></details><?php endif; ?></td>
                        <td><?= e(AS_STAGES[$r['stage']] ?? 'الموافقة') ?></td>
                        <td><span class="chip <?= $s[1] ?>"><?= e($s[0]) ?></span></td>
                        <td class="num"><?= (int) $r['fee_credits'] ?></td>
                        <td class="text-mute" style="font-size:12px"><?= e(time_ago((string) $r['updated_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
