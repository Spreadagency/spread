<?php
/**
 * Spread AI — مركز الجودة: البلاغات (المرحلة 5)
 * بلاغات «⚑ بلّغ عن مشكلة» + تحليل Claude (التصنيف · الخطورة · السبب · الحل) + صورة الشاشة + السياق
 * الإجراءات: تغيير الحالة · الموافقة على مسودة رد Claude (بعد التعديل) وإرسالها · رفضها · رد يدوي
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-center.php';

require_admin();
require_admin_can('view_users');

$ready = aic_platform_has('user_issues');
$hasAn = aic_has('issue_analysis');
$hasRd = aic_has('reply_drafts');
$adminId = (int) (current_admin()['id'] ?? 0);
$back = fn(int $iid = 0) => redirect('admin/ai-issues.php' . ($_POST['return'] ?? '') . ($iid ? '#i' . $iid : ''));

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = (string) ($_POST['action'] ?? '');
    $iid = (int) ($_POST['issue_id'] ?? 0);
    $_POST['return'] = preg_match('/^\?[A-Za-z0-9_=&%.\-]*$/', (string) ($_POST['return'] ?? '')) ? $_POST['return'] : '';
    $issue = $iid ? db_one('SELECT id, user_id, status FROM user_issues WHERE id = ?', [$iid]) : null;
    if (!$issue) { flash_set('danger', 'البلاغ مش موجود'); $back(); }

    if ($action === 'status') {
        $st = (string) ($_POST['status'] ?? '');
        if (!isset(AIC_ISSUE_STATUS[$st])) { flash_set('danger', 'حالة مش صحيحة'); $back($iid); }
        db_run('UPDATE user_issues SET status = ?, resolved_at = ? WHERE id = ?', [$st, $st === 'resolved' ? date('Y-m-d H:i:s') : null, $iid]);
        admin_log('issue_status', 'user_issue', $iid, $st);
        flash_set('success', 'حالة البلاغ #' . $iid . ': ' . AIC_ISSUE_STATUS[$st][1]);
        $back($iid);
    }

    if ($action === 'reply_send') {
        $body = trim((string) ($_POST['body'] ?? ''));
        $channel = ($_POST['channel'] ?? '') === 'whatsapp' ? 'whatsapp' : 'inbox';
        $did = (int) ($_POST['draft_id'] ?? 0);
        if (mb_strlen($body) < 5) { flash_set('danger', 'الرد قصير جدًا'); $back($iid); }
        $body = mb_substr($body, 0, 3000);
        if ($did) {
            $d = $hasRd ? db_one('SELECT id, status FROM ' . mcp_t('reply_drafts') . ' WHERE id = ? AND issue_id = ?', [$did, $iid]) : null;
            if (!$d || !in_array($d['status'], ['draft', 'approved'], true)) { flash_set('danger', 'المسودة دي اتبعتت أو اترفضت قبل كده'); $back($iid); }
        }
        try {
            require_once __DIR__ . '/../includes/Notifier.php';
            // واتساب = صندوق الرسايل + واتساب (عن طريق n8n) · غير كده صندوق الرسايل بس
            Notifier::direct((int) $issue['user_id'], 'رد على بلاغك #' . $iid, $body, $channel === 'whatsapp' ? 'both' : 'inapp', null, null, $adminId);
        } catch (\Throwable $e) {
            error_log('[ai-issues] reply #' . $iid . ': ' . $e->getMessage());
            flash_set('danger', 'الرد ماتبعتش: ' . $e->getMessage());
            $back($iid);
        }
        if ($hasRd) {
            if ($did) {
                db_run('UPDATE ' . mcp_t('reply_drafts') . ' SET body = ?, channel = ?, status = "sent", reviewed_at = NOW(), reviewed_by = ? WHERE id = ?', [$body, $channel, $adminId, $did]);
            } else {
                db_run('INSERT INTO ' . mcp_t('reply_drafts') . ' (issue_id, body, channel, status, reviewed_at, reviewed_by) VALUES (?,?,?,"sent",NOW(),?)', [$iid, $body, $channel, $adminId]);
            }
        }
        $newSt = !empty($_POST['resolve']) ? 'resolved' : (in_array($issue['status'], ['new', 'analyzing', 'analyzed'], true) ? 'in_progress' : $issue['status']);
        db_run('UPDATE user_issues SET status = ?, resolved_at = ? WHERE id = ?', [$newSt, $newSt === 'resolved' ? date('Y-m-d H:i:s') : null, $iid]);
        admin_log('issue_reply', 'user_issue', $iid, json_encode(['draft' => $did ?: null, 'channel' => $channel], JSON_UNESCAPED_UNICODE));
        flash_set('success', 'الرد اتبعت للعميل ✓' . ($newSt === 'resolved' ? ' والبلاغ اتقفل' : ''));
        $back($iid);
    }

    if ($action === 'reply_reject' && $hasRd) {
        $did = (int) ($_POST['draft_id'] ?? 0);
        db_run('UPDATE ' . mcp_t('reply_drafts') . ' SET status = "rejected", reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND issue_id = ? AND status IN ("draft","approved")', [$adminId, $did, $iid]);
        admin_log('issue_reply_reject', 'user_issue', $iid, (string) $did);
        flash_set('success', 'المسودة اترفضت');
        $back($iid);
    }
    $back($iid);
}

/* ── الفلاتر ── */
$f = [
    'status' => isset(AIC_ISSUE_STATUS[$_GET['status'] ?? '']) || ($_GET['status'] ?? '') === 'all' ? (string) $_GET['status'] : 'open',
    'cat' => isset(AIC_USER_CAT[$_GET['cat'] ?? '']) ? (string) $_GET['cat'] : '',
    'sev' => isset(AIC_SEVERITY[$_GET['sev'] ?? '']) ? (string) $_GET['sev'] : '',
    'aicat' => isset(AIC_AI_CAT[$_GET['aicat'] ?? '']) ? (string) $_GET['aicat'] : '',
    'q' => (int) ($_GET['q'] ?? 0),
];
$where = ['1=1'];
$params = [];
if ($f['q']) { $where[] = 'i.id = ?'; $params[] = $f['q']; }
elseif ($f['status'] === 'open') $where[] = 'i.status NOT IN ("resolved","wont_fix")';
elseif ($f['status'] !== 'all') { $where[] = 'i.status = ?'; $params[] = $f['status']; }
if ($f['cat']) { $where[] = 'i.category_user = ?'; $params[] = $f['cat']; }
$joinAn = $hasAn ? 'LEFT JOIN ' . mcp_t('issue_analysis') . ' a ON a.issue_id = i.id' : '';
if ($hasAn && $f['sev']) { $where[] = 'a.ai_severity = ?'; $params[] = $f['sev']; }
if ($hasAn && $f['aicat']) { $where[] = 'a.ai_category = ?'; $params[] = $f['aicat']; }
$W = implode(' AND ', $where);
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = $ready ? (int) a2_scalar("SELECT COUNT(*) FROM user_issues i $joinAn WHERE $W", $params) : 0;
$rows = $ready ? a2_all('SELECT i.*, u.name user_name' . ($hasAn ? ', a.ai_category, a.ai_severity, a.root_cause, a.fix, a.related_issue_ids, a.analyzed_at' : '') . "
                         FROM user_issues i LEFT JOIN users u ON u.id = i.user_id $joinAn WHERE $W
                         ORDER BY " . ($hasAn ? 'FIELD(a.ai_severity, "critical","high","medium","low") = 0, FIELD(a.ai_severity, "critical","high","medium","low"), ' : '') . 'i.id DESC
                         LIMIT 20 OFFSET ' . (($page - 1) * 20), $params) : [];
$drafts = [];
if ($rows && $hasRd) {
    $ids = implode(',', array_map(fn($r) => (int) $r['id'], $rows));
    foreach (a2_all('SELECT * FROM ' . mcp_t('reply_drafts') . " WHERE issue_id IN ($ids) ORDER BY id DESC") as $d) $drafts[(int) $d['issue_id']][] = $d;
}
$counts = $ready ? array_column(a2_all('SELECT status, COUNT(*) n FROM user_issues GROUP BY status'), 'n', 'status') : [];
$openN = array_sum(array_map(fn($k) => (int) ($counts[$k] ?? 0), ['new', 'analyzing', 'analyzed', 'in_progress']));
$qs = function (array $over = []) use ($f) {
    $q = array_filter(array_merge(['status' => $f['status'] !== 'open' ? $f['status'] : '', 'cat' => $f['cat'], 'sev' => $f['sev'], 'aicat' => $f['aicat'], 'q' => $f['q'] ?: ''], $over), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};
$ret = $qs(['page' => $page > 1 ? $page : '']);
$ctxLbl = ['page' => 'الصفحة', 'url' => 'الصفحة', 'ua' => 'المتصفح', 'browser' => 'المتصفح', 'viewport' => 'المقاس', 'screen' => 'المقاس', 'prompt_version_id' => 'إصدار البرومبت', 'provider' => 'المزود', 'model' => 'الموديل'];

$active = 'ai-issues';
$page_title = 'البلاغات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>مركز الجودة: البلاغات</h1>
                <div class="sub">بلاغات «⚑ بلّغ عن مشكلة» وتحليل Claude ومسودات الردود — مفيش رد بيوصل للعميل غير لما توافق</div>
            </div>
            <?= aic_tabs('ai-issues') ?>
        </div>

        <?php if (!$ready): ?>
            <div class="alert warning">جدول البلاغات لسه مش موجود — شغّل ترحيل «2026_mcp_foundation» من <a href="<?= e(url('admin/migrations.php')) ?>">الترحيلات</a>.</div>
        <?php else: ?>
        <?php if (!$hasAn || !$hasRd): ?><?= aic_setup_notice() ?><?php endif; ?>

        <div class="a2-row" style="gap:6px;flex-wrap:wrap;margin-bottom:10px">
            <a class="chip <?= $f['status'] === 'open' ? 'chip-primary' : 'chip-line' ?>" href="<?= e($qs(['status' => ''])) ?>">مفتوحة <?= $openN ?></a>
            <?php foreach (AIC_ISSUE_STATUS as $sk => [$sc, $sl]): ?>
                <a class="chip <?= $f['status'] === $sk ? 'chip-primary' : 'chip-line' ?>" href="<?= e($qs(['status' => $sk])) ?>"><?= e($sl) ?> <?= (int) ($counts[$sk] ?? 0) ?></a>
            <?php endforeach; ?>
            <a class="chip <?= $f['status'] === 'all' ? 'chip-primary' : 'chip-line' ?>" href="<?= e($qs(['status' => 'all'])) ?>">الكل <?= array_sum(array_map('intval', $counts)) ?></a>
        </div>

        <form class="card" method="get" style="margin-bottom:14px">
            <?php if ($f['status'] !== 'open'): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
            <div class="a2-row" style="gap:8px;flex-wrap:wrap">
                <select class="input" name="cat" style="width:auto"><option value="">نوع البلاغ (العميل)</option><?php foreach (AIC_USER_CAT as $ck => $cl): ?><option value="<?= $ck ?>" <?= $f['cat'] === $ck ? 'selected' : '' ?>><?= e($cl) ?></option><?php endforeach; ?></select>
                <?php if ($hasAn): ?>
                <select class="input" name="aicat" style="width:auto"><option value="">تصنيف Claude</option><?php foreach (AIC_AI_CAT as $ck => $cl): ?><option value="<?= $ck ?>" <?= $f['aicat'] === $ck ? 'selected' : '' ?>><?= e($cl) ?></option><?php endforeach; ?></select>
                <select class="input" name="sev" style="width:auto"><option value="">الخطورة</option><?php foreach (AIC_SEVERITY as $sk => [, $sl]): ?><option value="<?= $sk ?>" <?= $f['sev'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select>
                <?php endif; ?>
                <input class="input" type="number" min="1" name="q" value="<?= $f['q'] ?: '' ?>" placeholder="رقم البلاغ" style="width:120px">
                <button class="btn sm" type="submit">عرض</button>
                <?php if ($f['cat'] || $f['sev'] || $f['aicat'] || $f['q']): ?><a class="btn sm ghost" href="<?= e($qs(['cat' => '', 'sev' => '', 'aicat' => '', 'q' => ''])) ?>">مسح</a><?php endif; ?>
            </div>
        </form>

        <?php if (!$rows): ?><div class="card"><div class="a2-empty">مفيش بلاغات بالفلاتر دي 🎉</div></div><?php endif; ?>

        <?php foreach ($rows as $r): $iid = (int) $r['id']; $ctx = json_decode((string) $r['context'], true) ?: []; $ds = $drafts[$iid] ?? [];
            $rel = $hasAn ? (json_decode((string) ($r['related_issue_ids'] ?? ''), true) ?: []) : []; ?>
        <div class="card" id="i<?= $iid ?>" style="margin-bottom:12px">
            <div class="a2-h" style="gap:8px">
                <h3>#<?= $iid ?> · <?= e(AIC_USER_CAT[$r['category_user']] ?? $r['category_user']) ?></h3>
                <?= aic_chip(AIC_ISSUE_STATUS, $r['status']) ?>
                <?php if ($hasAn && $r['ai_severity']): ?><?= aic_chip(AIC_SEVERITY, $r['ai_severity']) ?><span class="chip chip-line"><?= e(AIC_AI_CAT[$r['ai_category']] ?? $r['ai_category']) ?></span><?php endif; ?>
                <span class="a2-grow"></span>
                <span class="a2-muted" style="font-size:12.5px"><?= e($r['created_at']) ?> · <a href="<?= e(url('admin/user-view.php?id=' . (int) $r['user_id'])) ?>"><?= e($r['user_name'] ?? ('#' . $r['user_id'])) ?></a></span>
            </div>

            <div class="split split-2" style="align-items:flex-start">
                <div>
                    <div style="white-space:pre-wrap;line-height:1.9;font-size:14px;background:#F6F8FB;border-radius:12px;padding:10px 12px"><?= e((string) $r['description']) ?></div>
                    <div class="a2-muted" style="font-size:12.5px;margin-top:6px;line-height:1.8">
                        <?php if ($r['page_url']): ?>الصفحة: <span dir="ltr"><?= e(mb_substr((string) $r['page_url'], 0, 90)) ?></span><br><?php endif; ?>
                        <?php if ($r['content_id']): ?>المنشور: <a href="<?= e(url('admin/content-view.php?id=' . (int) $r['content_id'])) ?>">#<?= (int) $r['content_id'] ?></a><?= $r['design_id'] ? ' · التصميم #' . (int) $r['design_id'] : '' ?><br><?php endif; ?>
                        <?php foreach ($ctxLbl as $ck => $cl): if (!empty($ctx[$ck]) && is_scalar($ctx[$ck])): ?><?= e($cl) ?>: <span dir="auto"><?= e(mb_substr((string) $ctx[$ck], 0, 120)) ?></span><br><?php endif; endforeach; ?>
                    </div>
                    <?php if ($ctx): ?>
                        <details style="margin-top:4px"><summary style="cursor:pointer;font-size:13px">السياق كامل (آخر الأحداث)</summary>
                            <pre dir="ltr" style="white-space:pre-wrap;background:#F6F8FB;border-radius:10px;padding:8px;font-size:11.5px;max-height:260px;overflow:auto"><?= e(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                        </details>
                    <?php endif; ?>
                    <?php if ($r['screenshot_path']): $shot = url('admin/ai-issue-shot.php?id=' . $iid); ?>
                        <a href="<?= e($shot) ?>" target="_blank" title="فتح الصورة"><img src="<?= e($shot) ?>" alt="صورة الشاشة" loading="lazy" style="max-width:100%;max-height:220px;border-radius:10px;border:1px solid #E6EDF5;margin-top:8px"></a>
                    <?php endif; ?>
                    <form method="post" class="a2-row" style="gap:6px;margin-top:10px">
                        <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="issue_id" value="<?= $iid ?>"><input type="hidden" name="return" value="<?= e($ret) ?>">
                        <select class="input" name="status" style="width:auto"><?php foreach (AIC_ISSUE_STATUS as $sk => [, $sl]): ?><option value="<?= $sk ?>" <?= $r['status'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select>
                        <button class="btn sm ghost">غيّر الحالة</button>
                    </form>
                </div>
                <div>
                    <?php if ($hasAn && $r['analyzed_at']): ?>
                        <div style="background:#EEF2FF;border-radius:12px;padding:10px 12px;margin-bottom:10px;font-size:13.5px;line-height:1.85">
                            <b>🤖 تحليل Claude</b> <span class="a2-muted" style="font-size:11.5px"><?= e($r['analyzed_at']) ?></span>
                            <?php if ($r['root_cause']): ?><div><b>السبب:</b> <span style="white-space:pre-wrap"><?= e((string) $r['root_cause']) ?></span></div><?php endif; ?>
                            <?php if ($r['fix']): ?><div><b>الحل:</b> <span style="white-space:pre-wrap"><?= e((string) $r['fix']) ?></span></div><?php endif; ?>
                            <?php if ($rel): ?><div><b>بلاغات مرتبطة:</b> <?php foreach ($rel as $x): ?><a href="<?= e($qs(['q' => (int) $x, 'status' => 'all'])) ?>">#<?= (int) $x ?></a> <?php endforeach; ?></div><?php endif; ?>
                        </div>
                    <?php elseif ($hasAn): ?>
                        <div class="a2-muted" style="font-size:13px;margin-bottom:10px">لسه ماتحلّلش — Claude بيحلّل البلاغات الجديدة في المراجعة اليومية.</div>
                    <?php endif; ?>

                    <?php foreach ($ds as $d): ?>
                        <?php if (in_array($d['status'], ['draft', 'approved'], true)): ?>
                            <form method="post" data-safe-post style="border:1px solid #E6EDF5;border-radius:12px;padding:10px;margin-bottom:8px">
                                <?= csrf_field() ?><input type="hidden" name="issue_id" value="<?= $iid ?>"><input type="hidden" name="draft_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="return" value="<?= e($ret) ?>">
                                <b style="font-size:13px">✍️ مسودة رد من Claude</b> <span class="a2-muted" style="font-size:11.5px"><?= e($d['created_at']) ?></span>
                                <textarea class="input" name="body" rows="4" style="margin:6px 0;line-height:1.8"><?= e((string) $d['body']) ?></textarea>
                                <div class="a2-row" style="gap:6px;flex-wrap:wrap">
                                    <select class="input" name="channel" style="width:auto"><option value="inbox" <?= $d['channel'] === 'inbox' ? 'selected' : '' ?>>صندوق الرسايل</option><option value="whatsapp" <?= $d['channel'] === 'whatsapp' ? 'selected' : '' ?>>صندوق الرسايل + واتساب</option></select>
                                    <label style="font-size:12.5px;display:inline-flex;gap:5px;align-items:center"><input type="checkbox" name="resolve" value="1"> اقفل البلاغ</label>
                                    <button class="btn sm" name="action" value="reply_send" data-confirm="الرد هيتبعت للعميل — متأكد؟">موافقة وإرسال</button>
                                    <button class="btn sm ghost" name="action" value="reply_reject" formnovalidate>رفض</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div style="border:1px dashed #E6EDF5;border-radius:12px;padding:8px 10px;margin-bottom:8px;font-size:13px">
                                <?= $d['status'] === 'sent' ? '<span class="chip chip-mint">اتبعت</span>' : '<span class="chip chip-line">اترفض</span>' ?>
                                <span class="a2-muted" style="font-size:11.5px"><?= e((string) $d['reviewed_at']) ?> · <?= $d['channel'] === 'whatsapp' ? 'واتساب' : 'صندوق الرسايل' ?></span>
                                <div style="white-space:pre-wrap;line-height:1.8;margin-top:4px"><?= e((string) $d['body']) ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <details <?= $ds ? '' : 'open' ?>>
                        <summary style="cursor:pointer;font-size:13px;font-weight:600">رد يدوي</summary>
                        <form method="post" data-safe-post style="margin-top:6px">
                            <?= csrf_field() ?><input type="hidden" name="action" value="reply_send"><input type="hidden" name="issue_id" value="<?= $iid ?>"><input type="hidden" name="return" value="<?= e($ret) ?>">
                            <textarea class="input" name="body" rows="3" required minlength="5" placeholder="اكتب الرد للعميل بالمصري…" style="line-height:1.8"></textarea>
                            <div class="a2-row" style="gap:6px;margin-top:6px;flex-wrap:wrap">
                                <select class="input" name="channel" style="width:auto"><option value="inbox">صندوق الرسايل</option><option value="whatsapp">صندوق الرسايل + واتساب</option></select>
                                <label style="font-size:12.5px;display:inline-flex;gap:5px;align-items:center"><input type="checkbox" name="resolve" value="1"> اقفل البلاغ</label>
                                <button class="btn sm" data-confirm="الرد هيتبعت للعميل — متأكد؟">إرسال</button>
                            </div>
                        </form>
                    </details>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($total > 20): $pages = (int) ceil($total / 20); ?>
            <div class="a2-row" style="margin-top:12px;justify-content:center">
                <?php if ($page > 1): ?><a class="btn sm ghost" href="<?= e($qs(['page' => $page - 1])) ?>">السابق</a><?php endif; ?>
                <span class="a2-muted">صفحة <?= $page ?> من <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn sm ghost" href="<?= e($qs(['page' => $page + 1])) ?>">التالي</a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
