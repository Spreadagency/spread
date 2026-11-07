<?php
/**
 * Spread AI — مركز الجودة: طلبات التعديل (MCP المرحلة 6)
 * اللي Claude اقترحه أو كتبه كمسودة — مفيش حاجة بتتطبّق غير من هنا:
 *   الاقتراحات: موافقة (للتنفيذ) · اتنفّذ · رجعناه · تأجيل · رفض بملاحظة (Claude بيشوفها ومايعيدوش)
 *   مسودات البرومبت: الفرق قدام المفعّل · «🧪 جرّب على منشور» (قبل/بعد من غير ما يلمس حاجة) · موافقة كتجربة A/B بنسبة أو مفعّل · رفض
 *   مسودات الاستايل: موافقة (النسخة القديمة بتتحفظ) · رفض
 *   التجارب: آخر نتايج التوليد التجريبي
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-center.php';
require_once __DIR__ . '/../includes/prompt-review.php';

require_admin();
require_admin_can('ai_settings');
$adminId = (int) (current_admin()['id'] ?? 0);
$tab = in_array($_GET['tab'] ?? '', ['changes', 'prompts', 'styles', 'sandbox'], true) ? $_GET['tab'] : 'prompts';
$ready = pr_ready();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $a = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $r = ['ok' => false, 'error' => 'عملية مش معروفة'];
    if ($a === 'cr_review') $r = pr_review_change($id, (string) ($_POST['to'] ?? ''), $adminId, $note);
    elseif ($a === 'prompt_approve') $r = pr_approve_prompt_draft($id, ($_POST['mode'] ?? '') === 'live' ? 'live' : 'testing', (int) ($_POST['pct'] ?? 20), $adminId, $note);
    elseif ($a === 'prompt_reject') $r = pr_reject_draft('prompt', $id, $adminId, $note);
    elseif ($a === 'style_approve') $r = pr_approve_style_draft($id, $adminId, $note);
    elseif ($a === 'style_reject') $r = pr_reject_draft('style', $id, $adminId, $note);
    elseif ($a === 'sandbox') {
        require_once __DIR__ . '/../includes/ai.php';
        $d = pr_prompt_draft($id);
        $post = (int) ($_POST['post_id'] ?? 0) ?: ($d ? pr_sample_post((string) $d['prompt_type']) : null);
        $r = !$d ? ['ok' => false, 'error' => 'المسودة مش موجودة'] : (!$post ? ['ok' => false, 'error' => 'مفيش منشور نجرّب عليه — اكتب رقم منشور'] : pr_sandbox($post, (string) $d['prompt_type'], (string) $d['body'], $id));
        if ($r['ok']) $_SESSION['pr_sandbox_last'] = (int) $r['run_id'];
    }
    flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? match ($a) {
        'prompt_approve' => ($_POST['mode'] ?? '') === 'live' ? 'اتفعّل الإصدار الجديد ✓ — الرجوع من «تجارب A/B»' : 'اتعمل إصدار تجربة ✓ — تابعه من «تجارب A/B»',
        'style_approve' => 'اتطبّق الاستايل ✓' . (!empty($r['new']) ? ' (اتعمل مقفول — فعّله من مكتبة الـ Presets)' : ' — النسخة القديمة اتحفظت مقفولة'),
        'sandbox' => 'التجربة خلصت ✓ — قبل/بعد تحت (فاضل ' . (int) ($r['left_today'] ?? 0) . ' النهارده)',
        default => 'اتحفظ ✓',
    } : ($r['error'] ?? 'حصلت مشكلة'));
    redirect('admin/ai-changes.php?tab=' . ($a === 'cr_review' ? 'changes' : (str_starts_with($a, 'style') ? 'styles' : 'prompts')) . ($id ? '#d' . $id : ''));
}

$counts = $ready ? [
    'changes' => (int) (db_one('SELECT COUNT(*) n FROM ' . mcp_t('change_requests') . ' WHERE status = "pending"')['n'] ?? 0),
    'prompts' => (int) (db_one('SELECT COUNT(*) n FROM ' . mcp_t('prompt_drafts') . ' WHERE status = "draft"')['n'] ?? 0),
    'styles' => (int) (db_one('SELECT COUNT(*) n FROM ' . mcp_t('style_preset_drafts') . ' WHERE status = "draft"')['n'] ?? 0),
] : [];
$crF = in_array($_GET['s'] ?? '', array_keys(PR_CR_STATUSES), true) ? $_GET['s'] : 'pending';
$lastSandbox = (int) ($_SESSION['pr_sandbox_last'] ?? 0);

$active = 'ai-changes';
$page_title = 'طلبات التعديل';
include __DIR__ . '/../templates/admin-header.php';
?>
<style>
.pr-diff { font: 12.5px/1.75 ui-monospace, Menlo, Consolas, monospace; border: 1px solid #E6EDF5; border-radius: 12px; overflow: auto; max-height: 420px; direction: auto; background: #FBFCFE; }
.pr-diff > div { padding: 0 10px; white-space: pre-wrap; word-break: break-word; }
.pr-diff i { display: inline-block; width: 16px; font-style: normal; opacity: .6; }
.pr-add { background: #E6F7EE; color: #0B5D3B; } .pr-del { background: #FDECEA; color: #8A2219; text-decoration: line-through; text-decoration-color: rgba(138, 34, 25, .35); }
.pr-eq { color: #5B6478; } .pr-skip { color: #8391A6; text-align: center; font-family: inherit; padding: 2px; background: #F1F4F8; }
.pr-card { border: 1px solid #E6EDF5; border-radius: 18px; padding: 16px; margin-bottom: 14px; background: #fff; }
.pr-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; }
.pr-ba { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .pr-ba > div { background: #F7FAFD; border-radius: 12px; padding: 10px; white-space: pre-wrap; font-size: 13px; line-height: 1.8; max-height: 360px; overflow: auto; }
@media (max-width: 760px) { .pr-ba { grid-template-columns: 1fr; } }
</style>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div><h1>طلبات التعديل</h1><div class="sub">اللي Claude اقترحه أو كتبه كمسودة — مفيش حاجة بتتطبّق على المنصة غير لما توافق هنا</div></div>
            <?= aic_tabs('ai-changes') ?>
        </div>
        <?php if (!$ready): ?><?= aic_setup_notice() ?><?php else: ?>

        <nav class="a2-tabs" aria-label="الأنواع">
            <?php foreach (['prompts' => 'مسودات البرومبت', 'changes' => 'الاقتراحات', 'styles' => 'مسودات الاستايل', 'sandbox' => 'التوليد التجريبي'] as $k => $l): ?>
                <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($l) ?><?= !empty($counts[$k]) ? ' <span class="chip chip-amber" style="padding:0 7px">' . $counts[$k] . '</span>' : '' ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'prompts'):
            $rows = db_all('SELECT * FROM ' . mcp_t('prompt_drafts') . ' ORDER BY status = "draft" DESC, id DESC LIMIT 40');
            $runs = [];
            foreach (db_all('SELECT * FROM ' . mcp_t('sandbox_runs') . ' WHERE prompt_draft_id IS NOT NULL ORDER BY id DESC LIMIT 100') as $x) $runs[(int) $x['prompt_draft_id']][] = $x;
        ?>
            <p class="a2-muted">«🧪 جرّب» بيكتب منشور حقيقي تاني بالمسودة ويوريك قبل/بعد — المنشور مابيتغيّرش ومفيش كريدت من العميل (فاضل <b><?= pr_sandbox_left() ?></b> تجربة النهارده).</p>
            <?php if (!$rows): ?><div class="a2-empty">مفيش مسودات برومبت لسه — Claude بيكتبها بأداة spread_draft_prompt</div><?php endif; ?>
            <?php foreach ($rows as $d): $live = pr_live_text((string) $d['prompt_type']); $open = $d['status'] === 'draft'; ?>
                <div class="pr-card" id="d<?= (int) $d['id'] ?>">
                    <div class="a2-h"><h3>#<?= (int) $d['id'] ?> · <span dir="ltr"><?= e($d['prompt_type']) ?></span></h3>
                        <span class="chip <?= $open ? 'chip-amber' : ($d['status'] === 'approved' ? 'chip-mint' : 'chip-line') ?>"><?= $open ? 'مستنية مراجعة' : ($d['status'] === 'approved' ? 'اتوافق (إصدار #' . (int) $d['applied_prompt_id'] . ')' : 'اترفض') ?></span>
                        <span class="a2-muted"><?= e(time_ago((string) $d['created_at'])) ?></span></div>
                    <p style="margin:6px 0 10px;line-height:1.8"><b>السبب:</b> <?= nl2br(e((string) $d['reason'])) ?></p>
                    <?php if ($d['review_note']): ?><p class="a2-muted">ملاحظتك: <?= e((string) $d['review_note']) ?></p><?php endif; ?>
                    <details <?= $open ? 'open' : '' ?>><summary style="cursor:pointer;margin-bottom:6px">الفرق قدام المفعّل دلوقتي (<span style="color:#8A2219">− المفعّل</span> · <span style="color:#0B5D3B">+ المسودة</span>)</summary>
                        <?= pr_diff_html($live, (string) $d['body']) ?></details>
                    <?php foreach (array_slice($runs[(int) $d['id']] ?? [], 0, 2) as $sr): ?>
                        <details style="margin-top:10px" <?= (int) $sr['id'] === $lastSandbox ? 'open' : '' ?>><summary style="cursor:pointer">🧪 تجربة على منشور #<?= (int) $sr['content_id'] ?> — <?= e(time_ago((string) $sr['created_at'])) ?></summary>
                            <div class="pr-ba" style="margin-top:8px"><div><b>قبل (المنشور الحقيقي)</b><br><?= e((string) $sr['before_text']) ?></div><div><b>بعد (بالمسودة)</b><br><?= e((string) $sr['after_text']) ?></div></div></details>
                    <?php endforeach; ?>
                    <?php if ($open): ?>
                    <div class="pr-row" style="margin-top:12px">
                        <form method="POST" class="pr-row" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="sandbox"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <label class="field" style="margin:0"><small>رقم منشور (فاضي = آخر واحد)</small><input type="number" name="post_id" class="input" style="width:150px" min="1"></label>
                            <button class="btn ghost sm" <?= pr_sandbox_left() ? '' : 'disabled' ?>>🧪 جرّب على منشور</button></form>
                        <form method="POST" class="pr-row" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="prompt_approve"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <label class="field" style="margin:0"><small>الموافقة كـ</small><select name="mode" class="input"><option value="testing">تجربة A/B</option><option value="live">مفعّل لكل العملاء</option></select></label>
                            <label class="field" style="margin:0"><small>نسبة التجربة %</small><input type="number" name="pct" class="input" style="width:90px" min="1" max="90" value="20"></label>
                            <input type="text" name="note" class="input" placeholder="ملاحظة (اختياري)" maxlength="500" style="width:200px">
                            <button class="btn sm" onclick="return confirm('موافقة على المسودة؟')">✓ موافقة</button></form>
                        <form method="POST" class="pr-row" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="prompt_reject"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <input type="text" name="note" class="input" placeholder="سبب الرفض (Claude بيشوفه)" maxlength="500" required style="width:220px"><button class="btn ghost sm">رفض</button></form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        <?php elseif ($tab === 'changes'):
            $rows = db_all('SELECT * FROM ' . mcp_t('change_requests') . ' WHERE status = ? ORDER BY FIELD(priority, "critical","high","medium","low"), id DESC LIMIT 60', [$crF]);
        ?>
            <div class="a2-seg" style="margin-bottom:12px;flex-wrap:wrap"><?php foreach (PR_CR_STATUSES as $k => [$l]): ?><a href="?tab=changes&s=<?= $k ?>" class="<?= $crF === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></div>
            <p class="a2-muted">الاقتراح بيتنفّذ يدوي (كود · إعداد · قاعدة محتوى) — الموافقة هنا بتعني «هننفّذه»، وبعدها «اتنفّذ» أو «رجعناه». الرفض لازم سبب، وClaude مش هيعيد نفس الاقتراح من غير دليل جديد.</p>
            <?php if (!$rows): ?><div class="a2-empty">مفيش اقتراحات هنا</div><?php endif; ?>
            <?php foreach ($rows as $c): $ev = json_decode((string) $c['evidence'], true); ?>
                <div class="pr-card" id="d<?= (int) $c['id'] ?>">
                    <div class="a2-h"><h3>#<?= (int) $c['id'] ?> · <?= e((string) $c['target']) ?></h3>
                        <span class="chip chip-line"><?= e((string) $c['type']) ?></span>
                        <span class="chip <?= in_array($c['priority'], ['critical', 'high'], true) ? 'chip-coral' : 'chip-line' ?>"><?= e((string) $c['priority']) ?></span>
                        <span class="chip <?= PR_CR_STATUSES[$c['status']][1] ?>"><?= e(PR_CR_STATUSES[$c['status']][0]) ?></span>
                        <span class="a2-muted"><?= e(time_ago((string) $c['created_at'])) ?></span></div>
                    <p style="margin:6px 0;line-height:1.8"><b>السبب:</b> <?= nl2br(e((string) $c['reason'])) ?></p>
                    <?php if ($c['expected_impact']): ?><p style="margin:6px 0"><b>الأثر المتوقع:</b> <?= e((string) $c['expected_impact']) ?></p><?php endif; ?>
                    <?php if (is_array($ev) && $ev): ?><details><summary style="cursor:pointer">الأدلة</summary><pre style="white-space:pre-wrap;font-size:12px;background:#F7FAFD;border-radius:10px;padding:10px;direction:ltr;text-align:left"><?= e(json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></details><?php endif; ?>
                    <?php if ($c['diff']): ?><details open><summary style="cursor:pointer;margin:6px 0">التعديل المقترح</summary><div class="pr-diff"><?php foreach (preg_split('/\R/u', (string) $c['diff']) as $l): $f = mb_substr($l, 0, 1); ?><div class="<?= $f === '+' ? 'pr-add' : ($f === '-' ? 'pr-del' : 'pr-eq') ?>"><?= e($l === '' ? ' ' : $l) ?></div><?php endforeach; ?></div></details><?php endif; ?>
                    <?php if ($c['review_note']): ?><p class="a2-muted">ملاحظتك: <?= e((string) $c['review_note']) ?></p><?php endif; ?>
                    <form method="POST" class="pr-row" style="margin-top:10px" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="cr_review"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <input type="text" name="note" class="input" placeholder="ملاحظة (لازم مع الرفض)" maxlength="500" style="flex:1;min-width:200px">
                        <?php foreach (($c['status'] === 'pending' || $c['status'] === 'postponed' ? ['approved' => '✓ موافقة', 'postponed' => '⏸ تأجيل', 'rejected' => 'رفض'] : ($c['status'] === 'approved' ? ['applied' => '✓ اتنفّذ', 'rejected' => 'رفض'] : ($c['status'] === 'applied' ? ['reverted' => '↩ رجعناه'] : []))) as $to => $l): ?>
                            <button class="btn <?= $to === 'rejected' || $to === 'postponed' || $to === 'reverted' ? 'ghost ' : '' ?>sm" name="to" value="<?= $to ?>"><?= e($l) ?></button>
                        <?php endforeach; ?>
                    </form>
                </div>
            <?php endforeach; ?>

        <?php elseif ($tab === 'styles'):
            $rows = db_all('SELECT * FROM ' . mcp_t('style_preset_drafts') . ' ORDER BY status = "draft" DESC, id DESC LIMIT 40');
        ?>
            <?php if (!$rows): ?><div class="a2-empty">مفيش مسودات استايل</div><?php endif; ?>
            <?php foreach ($rows as $d): $p = $d['preset_id'] ? db_one('SELECT name, kind, data FROM style_presets WHERE id = ?', [(int) $d['preset_id']]) : null; $open = $d['status'] === 'draft'; ?>
                <div class="pr-card" id="d<?= (int) $d['id'] ?>">
                    <div class="a2-h"><h3>#<?= (int) $d['id'] ?> · <?= $p ? 'تعديل «' . e($p['name']) . '»' : 'استايل جديد' ?></h3>
                        <span class="chip <?= $open ? 'chip-amber' : ($d['status'] === 'approved' ? 'chip-mint' : 'chip-line') ?>"><?= $open ? 'مستنية مراجعة' : ($d['status'] === 'approved' ? 'اتطبّق' : 'اترفض') ?></span></div>
                    <p style="margin:6px 0;line-height:1.8"><b>السبب:</b> <?= nl2br(e((string) $d['reason'])) ?></p>
                    <?= pr_diff_html($p ? (string) json_encode(json_decode((string) $p['data'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '', (string) json_encode(json_decode((string) $d['payload'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?>
                    <?php if ($open): ?>
                    <div class="pr-row" style="margin-top:10px">
                        <form method="POST" class="pr-row" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="style_approve"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="btn sm">✓ موافقة وتطبيق</button></form>
                        <form method="POST" class="pr-row" data-safe-post><?= csrf_field() ?><input type="hidden" name="action" value="style_reject"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><input type="text" name="note" class="input" placeholder="سبب الرفض" required maxlength="500"><button class="btn ghost sm">رفض</button></form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        <?php else:
            $rows = db_all('SELECT * FROM ' . mcp_t('sandbox_runs') . ' ORDER BY id DESC LIMIT 30');
        ?>
            <p class="a2-muted">كل تجربة: منشور حقيقي اتكتب تاني بمسودة أو إصدار — المنشور الأصلي زي ما هو، والتكلفة متسجّلة «تجربة» في تكاليف الـ AI. فاضل <b><?= pr_sandbox_left() ?></b> النهارده (الحد من <a href="<?= url('admin/ai-quality-settings.php') ?>">إعدادات الجودة</a>).</p>
            <?php if (!$rows): ?><div class="a2-empty">مفيش تجارب لسه</div><?php endif; ?>
            <?php foreach ($rows as $sr): ?>
                <div class="pr-card"><div class="a2-h"><h3>منشور #<?= (int) $sr['content_id'] ?></h3><span class="a2-muted"><?= $sr['prompt_draft_id'] ? 'مسودة #' . (int) $sr['prompt_draft_id'] : 'إصدار #' . (int) $sr['prompt_version_id'] ?> · <?= e(time_ago((string) $sr['created_at'])) ?></span></div>
                    <div class="pr-ba"><div><b>قبل</b><br><?= e((string) $sr['before_text']) ?></div><div><b>بعد</b><br><?= e((string) $sr['after_text']) ?></div></div></div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
