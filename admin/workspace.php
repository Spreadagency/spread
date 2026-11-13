<?php
/**
 * Spread AI — الأدمن: «مساحة العميل» (خريطة التطوير · المرحلة 2)
 *   admin/workspace.php?id=العميل [&tab=content|brand|notes|report] [&m=YYYY-MM]
 * 5 تبويبات: المحتوى والموافقات · الهوية · الرسائل · ملاحظات ومهام · تقرير الشهر
 * الدخول بحساب العميل نفسه زي ما هو (admin/impersonate.php) — من هنا لينك ليه بس.
 * الصلاحية: assistant = حساباته بس · manager و super = الكل. مفيش أي حاجة هنا بتلمس الكريدت أو الباقات.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/managed-accounts.php';
require_once __DIR__ . '/../includes/content-formats.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/rate-limit.php';

require_admin();
require_admin_can('view_users');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$user = db_one('SELECT id, name, email, phone FROM users WHERE id = ?', [$id]);
if (!$user) {
    flash_set('danger', 'العميل مش موجود');
    redirect('admin/users.php');
}
if (!ma_ready()) {
    flash_set('warning', 'شغّل ترحيل 2026_managed_accounts.sql من «فحص التحديث» الأول');
    redirect('admin/my-accounts.php');
}
$acct = ma_get($id);
if (!$acct) {
    flash_set('warning', 'العميل ده مش من «عملاء بنديرهم» — دوس ☆ جنبه في صفحة العملاء الأول');
    redirect('admin/user-view.php?id=' . $id);
}
if (!ma_can_access($id)) {
    http_response_code(403);
    exit('<div style="font-family:system-ui;direction:rtl;text-align:center;padding:60px"><h2>🚫 غير مسموح</h2><p>الحساب ده مع مسؤول تاني.</p>'
        . '<p><a href="' . htmlspecialchars(url('admin/my-accounts.php')) . '">حساباتي</a></p></div>');
}
$me = current_admin();
$senior = in_array(($me['role'] ?? 'super'), ['manager', 'super'], true);
$tabs = ['content' => 'المحتوى والموافقات', 'brand' => 'الهوية', 'messages' => 'الرسائل', 'notes' => 'ملاحظات ومهام', 'report' => 'تقرير الشهر'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'content';
$back = fn(string $t) => 'admin/workspace.php?id=' . $id . '&tab=' . $t;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_settings') {
        if (!$senior) {
            flash_set('danger', 'تعيين المسؤول ومستوى الخدمة للمدير بس');
        } else {
            $mgr = (int) ($_POST['manager_admin_id'] ?? 0) ?: null;
            ma_update($id, $mgr, (string) ($_POST['service_level'] ?? 'approval'), (int) ($_POST['monthly_posts'] ?? 0));
            admin_log('managed_account_update', 'user', $id, ['manager' => $mgr, 'level' => $_POST['service_level'] ?? '', 'monthly' => (int) ($_POST['monthly_posts'] ?? 0)]);
            flash_set('success', 'اتحفظ ✓');
        }
        redirect($back('content'));
    }
    if ($action === 'send_approval') {
        $n = ma_send_for_approval($id, (array) ($_POST['ids'] ?? []), (int) $me['id']);
        if ($n > 0) {
            admin_log('managed_send_approval', 'user', $id, $n . ' منشور');
            flash_set('success', 'اتبعت ' . $n . ' منشور لموافقة العميل ✓ — ابعتله لينك الموافقة اللي تحت.');
        } else {
            flash_set('warning', 'اختار منشورات لسه ماتنشرتش ومش مستنية موافقة');
        }
        redirect($back('content'));
    }
    if ($action === 'note_add') {
        $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 1000);
        $kind = ($_POST['kind'] ?? '') === 'task' ? 'task' : 'note';
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['due_date'] ?? '')) ? $_POST['due_date'] : null;
        if ($body === '') {
            flash_set('warning', 'اكتب الملاحظة أو المهمة');
        } else {
            db_insert('INSERT INTO managed_notes (user_id, admin_id, kind, body, due_date) VALUES (?,?,?,?,?)', [$id, (int) $me['id'], $kind, $body, $kind === 'task' ? $due : null]);
            flash_set('success', $kind === 'task' ? 'المهمة اتضافت ✓' : 'الملاحظة اتضافت ✓');
        }
        redirect($back('notes'));
    }
    if ($action === 'message_send') {
        $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 1500);
        if ($body === '') {
            flash_set('warning', 'اكتب الرسالة');
        } elseif (!rate_limit('ma_msg', 'a' . (int) $me['id'], 30, 600)) {
            flash_set('warning', 'رسائل كتير بسرعة — استنى شوية');
        } else {
            try {
                $mid = Notifier::direct($id, 'رسالة من فريق ' . (function_exists('site_name') ? site_name() : APP_NAME), $body, 'both', null, null, (int) $me['id']);
                // الرد على العميل بيقفل رسائله المفتوحة في صندوق الوارد
                if (msg_ready()) db_run('UPDATE message_inbound SET handled_at = NOW(), handled_by = ? WHERE user_id = ? AND handled_at IS NULL', [(int) $me['id'], $id]);
                admin_log('managed_message', 'user', $id, 'message #' . $mid);
                $st = (string) (db_one('SELECT wa_status FROM messages WHERE id = ?', [$mid])['wa_status'] ?? '');
                flash_set('success', 'الرسالة اتبعتت ✓' . (in_array($st, ['skipped', 'none'], true) ? ' (جوه المنصة بس — الواتساب مش متاح للعميل ده)' : ''));
            } catch (\Throwable $e) {
                flash_set('danger', 'الرسالة ماتبعتتش: ' . $e->getMessage());
            }
        }
        redirect($back('messages'));
    }
    if ($action === 'inbound_done') {
        $in = msg_ready() ? db_one('SELECT * FROM message_inbound WHERE id = ? AND user_id = ?', [(int) ($_POST['inbound_id'] ?? 0), $id]) : null;
        if ($in) msg_mark_handled((int) $in['id'], (int) $me['id']);
        redirect($back('messages'));
    }
    if ($action === 'report_send') {
        $st = ma_month_stats($id, $_POST['m'] ?? null);
        $sent = Notifier::notify($id, 'monthly_report', ['month' => $st['ym'], 'made' => $st['made'], 'published' => $st['published']], ['url' => 'content-history.php']);
        if ($sent) admin_log('managed_report_send', 'user', $id, $st['ym']);
        flash_set($sent ? 'success' : 'warning', $sent ? 'التقرير اتبعت للعميل ✓' : 'التقرير ماتبعتش (اتبعت من أقل من نص ساعة، أو القالب مقفول)');
        redirect($back('report') . '&m=' . $st['ym']);
    }
    if ($action === 'task_done') {
        db_run('UPDATE managed_notes SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ? AND user_id = ? AND kind = "task"', [(int) ($_POST['note_id'] ?? 0), $id]);
        redirect($back('notes'));
    }
    redirect($back($tab));
}

$stats = ma_month_stats($id, $_GET['m'] ?? null);
$approvalLink = ma_approval_link($id);
$admins = $senior ? db_all('SELECT id, name, role FROM admin_users WHERE status = "active" ORDER BY name') : [];
$pubLabels = ['published' => 'اتنشر', 'scheduled' => 'مجدول', 'pending' => 'في الطابور', 'processing' => 'بيتنشر', 'failed' => 'فشل', 'cancelled' => 'اتلغى', 'draft' => ''];

$active = 'my-accounts';
$page_title = 'مساحة العميل — ' . $user['name'];
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>⭐ <?= e($user['name']) ?></h1>
                <div class="sub">
                    <?= $acct['manager_admin_id'] ? 'المسؤول: ' . e((string) (db_one('SELECT name FROM admin_users WHERE id = ?', [(int) $acct['manager_admin_id']])['name'] ?? '—')) : 'من غير مسؤول' ?>
                    · <?= e(MA_LEVELS[$acct['service_level']] ?? '') ?>
                    · الشهر ده <?= (int) $stats['made'] ?><?= (int) $acct['monthly_posts'] > 0 ? ' من ' . (int) $acct['monthly_posts'] : '' ?> بوست
                </div>
            </div>
            <div class="a2-row" style="gap:8px;flex-wrap:wrap">
                <a class="btn ghost" href="<?= url('admin/my-accounts.php') ?>">← حساباتي</a>
                <a class="btn ghost" href="<?= url('admin/user-view.php?id=' . $id) ?>">ملف العميل</a>
                <?php if (admin_can('impersonate')): ?><a class="btn" href="<?= url('admin/impersonate.php?user_id=' . $id) ?>">👁 ادخل بحسابه</a><?php endif; ?>
            </div>
        </div>

        <?= render_flash() ?>

        <div class="a2-row" style="gap:6px;flex-wrap:wrap;margin-bottom:14px" role="tablist">
            <?php foreach ($tabs as $k => $l): ?>
                <a class="btn sm <?= $k === $tab ? '' : 'ghost' ?>" role="tab" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>" href="<?= url($back($k)) ?>"><?= e($l) ?>
                    <?php if ($k === 'content' && $stats['pending']): ?> (<?= (int) $stats['pending'] ?>)<?php endif; ?></a>
            <?php endforeach; ?>
        </div>

<?php if ($tab === 'content'):
    $rows = db_all('SELECT c.id, c.generated_text, c.format, c.created_at, c.scheduled_at, c.publish_status, c.approval_status, c.approval_note, c.acted_by_admin_id
                    FROM contents c WHERE c.user_id = ? ORDER BY c.id DESC LIMIT 60', [$id]); ?>
        <?php if ($senior): ?>
        <form method="POST" class="card" style="padding:14px;margin-bottom:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save_settings">
            <div class="a2-row" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
                <label style="flex:1;min-width:160px">مسؤول الحساب
                    <select name="manager_admin_id" class="input">
                        <option value="0">— من غير —</option>
                        <?php foreach ($admins as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === (int) $acct['manager_admin_id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (<?= e(admin_role_label($a['role'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="flex:1;min-width:160px">مستوى الخدمة
                    <select name="service_level" class="input">
                        <?php foreach (MA_LEVELS as $k => $l): ?><option value="<?= $k ?>" <?= $k === $acct['service_level'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label style="width:150px">بوستات في الشهر
                    <input type="number" name="monthly_posts" class="input" min="0" max="500" value="<?= (int) $acct['monthly_posts'] ?>">
                </label>
                <button class="btn" type="submit">حفظ</button>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($acct['service_level'] === 'approval'): ?>
        <div class="card" style="padding:14px;margin-bottom:14px">
            <b>لينك موافقة العميل</b> <span class="text-mute" style="font-size:12px">— صالح 72 ساعة · بيفتح صفحة الموافقات بس (مش دخول للحساب)</span>
            <div class="a2-row" style="gap:8px;margin-top:8px">
                <input class="input" dir="ltr" readonly id="ap-link" value="<?= e($approvalLink) ?>" onclick="this.select()">
                <button type="button" class="btn ghost" onclick="var i=document.getElementById('ap-link');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='اتنسخ ✓'">نسخ</button>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="send_approval">
            <div class="card-head">
                <h3>آخر المنشورات</h3>
                <?php if ($acct['service_level'] === 'approval'): ?><button class="btn" type="submit">ابعت المختار لموافقة العميل</button><?php endif; ?>
            </div>
            <?php if (!$rows): ?>
                <div class="empty" style="padding:24px;text-align:center">مفيش منشورات لسه — «ادخل بحسابه» واعمل أول منشور.</div>
            <?php else: ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th style="width:36px"></th><th>المنشور</th><th>الشكل</th><th>الموافقة</th><th>النشر</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $c):
                    $ap = (string) ($c['approval_status'] ?? '');
                    $sendable = in_array($ap, ['', 'draft', 'changes'], true) && !in_array((string) $c['publish_status'], ['published', 'processing'], true); ?>
                    <tr>
                        <td><?php if ($sendable && $acct['service_level'] === 'approval'): ?><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="اختار المنشور"><?php endif; ?></td>
                        <td style="max-width:420px">
                            <?= e(mb_substr(trim((string) $c['generated_text']), 0, 110)) ?>…
                            <div class="text-mute" style="font-size:11.5px"><?= e(substr((string) $c['created_at'], 0, 16)) ?><?= $c['acted_by_admin_id'] ? ' · عمله الفريق' : ' · عمله العميل' ?></div>
                            <?php if ($ap === 'changes' && $c['approval_note']): ?><div style="font-size:12px;color:#7a5b00;margin-top:4px">طلب العميل: <?= e($c['approval_note']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e(content_format_label(content_format_key($c['format'] ?? 'post'))) ?></td>
                        <td><?php if ($ap !== ''): ?><span class="chip <?= $ap === 'approved' ? 'chip-primary' : '' ?>"><?= e(['draft' => 'لسه ماتبعتش', 'pending' => 'مستني العميل', 'approved' => 'موافق ✓', 'changes' => 'تعديل مطلوب'][$ap] ?? $ap) ?></span><?php else: ?><span class="text-mute">—</span><?php endif; ?></td>
                        <td><?= e($pubLabels[(string) $c['publish_status']] ?? (string) $c['publish_status']) ?><?= $c['scheduled_at'] ? '<div class="text-mute" style="font-size:11.5px">' . e(substr((string) $c['scheduled_at'], 0, 16)) . '</div>' : '' ?></td>
                        <td><a class="btn sm ghost" href="<?= url('admin/content-view.php?id=' . (int) $c['id']) ?>">عرض</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </form>

<?php elseif ($tab === 'brand'):
    $bp = db_one('SELECT * FROM brand_profiles WHERE user_id = ? LIMIT 1', [$id]) ?: []; ?>
        <div class="card" style="padding:16px">
            <div class="card-head"><h3>هوية <?= e((string) ($bp['business_name'] ?? $user['name'])) ?></h3>
                <a class="btn ghost" href="<?= url('admin/user-brand.php?user_id=' . $id) ?>">تعديل الهوية</a></div>
            <?php foreach (['industry' => 'المجال', 'description' => 'الوصف', 'audience' => 'الجمهور', 'services' => 'الخدمات', 'tone' => 'النبرة', 'keywords_avoid' => 'كلمات ممنوعة'] as $k => $l):
                if (trim((string) ($bp[$k] ?? '')) === '') continue; ?>
                <div style="margin-top:12px"><div class="text-mute" style="font-size:12px"><?= $l ?></div><div style="white-space:pre-wrap;line-height:1.8"><?= e(mb_substr((string) $bp[$k], 0, 1200)) ?></div></div>
            <?php endforeach; ?>
            <?php if (!$bp): ?><div class="empty">العميل لسه ماعملش هوية.</div><?php endif; ?>
        </div>

<?php elseif ($tab === 'messages'):
    $conv = msg_conversation($id, 80);
    $waWhy = wa_block_reason(wa_user($id) ?: []);
    $waLabels = ['sent' => 'وصل واتساب', 'pending' => 'في طابور الواتساب', 'processing' => 'بيتبعت', 'failed' => 'فشل الواتساب', 'skipped' => 'جوه المنصة بس', 'none' => 'جوه المنصة']; ?>
        <?php if ($waWhy !== ''): ?>
            <div class="alert warning">الواتساب مش هيوصل للعميل ده دلوقتي (<?= e(['wa_disabled' => 'الواتساب مقفول في المنصة', 'opted_out' => 'العميل وقّف رسائل الواتساب', 'not_verified' => 'رقمه مش متحقق', 'no_phone' => 'مفيش رقم'][$waWhy] ?? $waWhy) ?>) — الرسالة هتظهرله جوه المنصة.</div>
        <?php endif; ?>
        <div class="card" style="padding:14px;margin-bottom:14px;max-height:520px;overflow:auto" id="ma-conv">
            <?php if (!$conv): ?><div class="empty" style="padding:20px;text-align:center">مفيش رسائل مع العميل ده لسه.</div><?php endif; ?>
            <?php foreach ($conv as $m): $in = $m['dir'] === 'in'; ?>
                <div style="display:flex;justify-content:<?= $in ? 'flex-start' : 'flex-end' ?>;margin-bottom:10px">
                    <div style="max-width:78%;border-radius:14px;padding:10px 12px;background:<?= $in ? 'var(--surface-2, #f3f3f8)' : 'rgba(124,109,242,.14)' ?>">
                        <div style="white-space:pre-wrap;line-height:1.7;font-size:14px;overflow-wrap:anywhere"><?= e($m['body']) ?></div>
                        <div class="text-mute" style="font-size:11px;margin-top:4px">
                            <?= e(substr((string) $m['at'], 0, 16)) ?> ·
                            <?php if ($in): ?>
                                العميل<?= $m['action_result'] !== '' ? ' · ' . e($m['action_result']) : '' ?>
                                <?php if (!$m['handled']): ?>
                                    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="inbound_done"><input type="hidden" name="inbound_id" value="<?= (int) $m['id'] ?>">
                                        <button class="btn sm ghost" type="submit">اتعامل معاها ✓</button></form>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= $m['type'] === 'direct' ? 'الفريق' : 'تلقائي' ?> · <?= e($waLabels[$m['wa_status']] ?? $m['wa_status']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="POST" class="card" style="padding:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="message_send">
            <textarea name="body" class="textarea" rows="3" maxlength="1500" required placeholder="اكتب رسالة للعميل — هتوصله جوه المنصة وعلى الواتساب لو متاح"></textarea>
            <div class="a2-row" style="gap:8px;margin-top:8px;flex-wrap:wrap">
                <button class="btn" type="submit">ابعت</button>
                <?php if ($acct['service_level'] === 'approval' && $stats['pending']): ?>
                    <button class="btn ghost" type="button" onclick="this.form.body.value='أهلًا 👋 فيه <?= (int) $stats['pending'] ?> منشور مستني موافقتك: <?= e($approvalLink) ?>'">حط لينك الموافقة</button>
                <?php endif; ?>
            </div>
        </form>
        <script>(function(){var c=document.getElementById('ma-conv');if(c)c.scrollTop=c.scrollHeight;})();</script>

<?php elseif ($tab === 'notes'):
    $notes = db_all('SELECT n.*, a.name admin_name FROM managed_notes n LEFT JOIN admin_users a ON a.id = n.admin_id WHERE n.user_id = ? ORDER BY (n.kind = "task" AND n.done_at IS NULL) DESC, n.id DESC LIMIT 100', [$id]); ?>
        <form method="POST" class="card" style="padding:14px;margin-bottom:14px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note_add">
            <textarea name="body" class="textarea" rows="2" maxlength="1000" required placeholder="ملاحظة عن العميل، أو مهمة لازم تتعمل…"></textarea>
            <div class="a2-row" style="gap:10px;margin-top:8px;flex-wrap:wrap;align-items:center">
                <label><input type="radio" name="kind" value="note" checked> ملاحظة</label>
                <label><input type="radio" name="kind" value="task"> مهمة</label>
                <label>ميعاد المهمة <input type="date" name="due_date" class="input" style="width:auto;display:inline-block"></label>
                <button class="btn" type="submit">إضافة</button>
            </div>
        </form>
        <div class="card">
            <?php if (!$notes): ?><div class="empty" style="padding:24px;text-align:center">مفيش ملاحظات أو مهام لسه.</div><?php endif; ?>
            <?php foreach ($notes as $n): $isTask = $n['kind'] === 'task'; ?>
                <div style="padding:12px 16px;border-bottom:1px solid var(--line, #eee);display:flex;gap:10px;align-items:flex-start">
                    <?php if ($isTask): ?>
                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="task_done"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                            <button class="btn sm ghost" type="submit" title="<?= $n['done_at'] ? 'رجّعها مفتوحة' : 'خلصت' ?>"><?= $n['done_at'] ? '☑' : '☐' ?></button></form>
                    <?php else: ?><span style="padding:4px 8px">📝</span><?php endif; ?>
                    <div style="flex:1;min-width:0">
                        <div style="white-space:pre-wrap;line-height:1.7;<?= $n['done_at'] ? 'text-decoration:line-through;opacity:.6' : '' ?>"><?= e($n['body']) ?></div>
                        <div class="text-mute" style="font-size:11.5px"><?= e((string) ($n['admin_name'] ?? '')) ?> · <?= e(substr((string) $n['created_at'], 0, 16)) ?><?= $isTask && $n['due_date'] ? ' · الميعاد ' . e($n['due_date']) : '' ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

<?php else: /* report */
    $prev = date('Y-m', strtotime($stats['ym'] . '-01 -1 month'));
    $next = date('Y-m', strtotime($stats['ym'] . '-01 +1 month')); ?>
        <div class="card" style="padding:18px" id="ma-report">
            <div class="card-head">
                <h3>تقرير <?= e($user['name']) ?> — <span dir="ltr"><?= e($stats['ym']) ?></span></h3>
                <div class="a2-row no-print" style="gap:6px">
                    <a class="btn sm ghost" href="<?= url($back('report') . '&m=' . $prev) ?>">‹ الشهر اللي قبله</a>
                    <?php if ($next <= date('Y-m')): ?><a class="btn sm ghost" href="<?= url($back('report') . '&m=' . $next) ?>">اللي بعده ›</a><?php endif; ?>
                    <button type="button" class="btn sm" onclick="window.print()">طباعة / PDF</button>
                    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="report_send"><input type="hidden" name="m" value="<?= e($stats['ym']) ?>">
                        <button class="btn sm ghost" type="submit">ابعته للعميل</button></form>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-top:8px">
                <?php foreach ([['المنشورات اللي اتعملت', $stats['made'] . ((int) $acct['monthly_posts'] > 0 ? ' من ' . (int) $acct['monthly_posts'] : '')], ['عملها الفريق', $stats['by_team']], ['اتنشر', $stats['published']],
                               ['مجدول', $stats['scheduled']], ['وافق عليها العميل', $stats['approved']], ['مستنية موافقة', $stats['pending']], ['تعديل مطلوب', $stats['changes']], ['فشل نشر', $stats['failed']]] as [$l, $v]): ?>
                    <div style="border:1px solid var(--line, #eee);border-radius:12px;padding:12px"><div class="text-mute" style="font-size:12px"><?= $l ?></div><div style="font-size:22px;font-weight:700"><?= e((string) $v) ?></div></div>
                <?php endforeach; ?>
            </div>
            <p class="text-mute" style="font-size:12px;margin-top:14px">الأرقام للمنشورات اللي اتعملت في الشهر ده.</p>
        </div>
        <style>@media print { .sidebar, .topbar, .page-head .a2-row, [role="tablist"], .no-print { display: none !important; } .main { margin: 0 !important; padding: 0 !important; } }</style>
<?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
