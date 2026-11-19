<?php
/**
 * Spread AI — مركز الجودة: تجارب A/B للبرومبتات (MCP المرحلة 6)
 * لكل نوع: المفعّل قدام التجربة (منشورات · 👍👎 · إعادة التوليد · تقييم Claude) + هل العينة كفاية والمدة
 * الإجراءات: تغيير النسبة · ترقية التجربة · إيقافها · رجوع للإصدار اللي قبله بضغطة
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
$ready = pr_ready();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $a = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $ok = match ($a) {
        'traffic' => pr_set_traffic($id, (int) ($_POST['pct'] ?? 0), $adminId),
        'promote' => pr_promote($id, $adminId),
        'stop' => pr_stop_test($id, $adminId),
        'rollback' => pr_rollback((string) ($_POST['type'] ?? ''), $adminId) !== null,
        default => false,
    };
    flash_set($ok ? 'success' : 'danger', $ok ? match ($a) {
        'promote' => 'التجربة بقت المفعّل لكل العملاء ✓ — تقدر ترجع بضغطة',
        'stop' => 'التجربة اتوقفت ✓ — كل العملاء على المفعّل',
        'rollback' => 'رجعنا للإصدار اللي قبله ✓',
        default => 'اتحفظ ✓',
    } : 'مقدرناش — حدّث الصفحة');
    redirect('admin/ai-ab.php');
}

$list = $ready ? pr_ab_list() : [];
$ids = [];
foreach ($list as $x) foreach (['live', 'testing', 'previous'] as $k) if (!empty($x[$k])) $ids[] = (int) $x[$k]['id'];
$stats = $ready ? pr_stats($ids) : [];
$min = pr_setting('ab_min_sample', 30);
$days = pr_setting('ab_duration_days', 14);
$lib = function_exists('prompt_library') ? prompt_library() : [];

$active = 'ai-ab';
$page_title = 'تجارب A/B';
include __DIR__ . '/../templates/admin-header.php';
$stat = function (?array $p) use ($stats): string {
    if (!$p) return '<span class="a2-muted">—</span>';
    $s = $stats[(int) $p['id']] ?? [];
    return '<div style="line-height:1.9;font-size:13px">منشورات: <b>' . (int) ($s['posts'] ?? 0) . '</b><br>👍 ' . (int) ($s['up'] ?? 0) . ' · 👎 ' . (int) ($s['down'] ?? 0)
        . (isset($s['score']) && $s['score'] !== null ? ' <span class="a2-muted">(' . ($s['score'] >= 0 ? '+' : '') . $s['score'] . ')</span>' : '')
        . '<br>إعادة التوليد: ' . ($s['regen'] ?? '—') . '<br>تقييم Claude: ' . (isset($s['overall']) ? '<b>' . $s['overall'] . '</b>/10 <span class="a2-muted">(' . (int) $s['evaluated'] . ')</span>' : '—') . '</div>';
};
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div><h1>تجارب A/B</h1><div class="sub">البرومبت الجديد بيتجرّب على نسبة من العملاء (كل عميل بيفضل في نفس المجموعة) — والحكم بعد <?= $min ?> منشور على الأقل أو <?= $days ?> يوم</div></div>
            <?= aic_tabs('ai-ab') ?>
        </div>
        <?php if (!$ready): ?><?= aic_setup_notice() ?><?php elseif (!$list): ?>
            <div class="a2-empty">مفيش تجارب شغالة ولا تغييرات قريبة — الموافقة على مسودة برومبت من <a href="<?= url('admin/ai-changes.php') ?>">طلبات التعديل</a> بتبدأ تجربة.</div>
        <?php else: foreach ($list as $t => $x): $live = $x['live']; $test = $x['testing']; $prev = $x['previous'];
            $ls = $stats[(int) ($live['id'] ?? 0)] ?? []; $ts = $test ? ($stats[(int) $test['id']] ?? []) : [];
            $enough = $test && ($ts['posts'] ?? 0) >= $min;
            $age = $test ? (int) floor((time() - strtotime((string) ($test['approved_at'] ?: $test['created_at']))) / 86400) : 0;
            $better = $test && isset($ts['score'], $ls['score']) && $ts['score'] !== null && $ls['score'] !== null ? $ts['score'] - $ls['score'] : null; ?>
            <div class="card" style="margin-bottom:16px">
                <div class="a2-h"><h3><?= e($lib[$t][0] ?? $t) ?> <span class="a2-muted" dir="ltr" style="font-size:12px"><?= e($t) ?></span></h3>
                    <?php if ($test): ?><span class="chip <?= $enough ? 'chip-mint' : 'chip-amber' ?>"><?= $enough ? 'العينة كفاية للحكم' : 'لسه بنجمع (' . (int) ($ts['posts'] ?? 0) . ' من ' . $min . ')' ?></span>
                        <span class="a2-muted">بقالها <?= $age ?> يوم<?= $age >= $days ? ' — عدّت المدة' : '' ?></span><?php endif; ?></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th></th><th>المفعّل</th><th>التجربة</th></tr></thead><tbody>
                    <tr><td><b>الإصدار</b></td>
                        <td><?= $live ? 'v' . (int) $live['version'] . ($live['created_by_kind'] === 'ai' ? ' <span class="chip chip-line">من Claude</span>' : '') : '<span class="a2-muted">الافتراضي من الكود</span>' ?></td>
                        <td><?= $test ? 'v' . (int) $test['version'] . ' · <b>' . (int) $test['traffic_percent'] . '%</b> من العملاء' : '<span class="a2-muted">مفيش تجربة</span>' ?></td></tr>
                    <tr><td><b>الأرقام</b></td><td><?= $stat($live) ?></td><td><?= $stat($test) ?></td></tr>
                    <?php if ($test && $test['reason']): ?><tr><td><b>ليه</b></td><td colspan="2" style="font-size:13px;line-height:1.8"><?= nl2br(e(mb_substr((string) $test['reason'], 0, 600))) ?></td></tr><?php endif; ?>
                </tbody></table></div>
                <?php if ($better !== null && $enough): ?><p style="margin:8px 0;color:<?= $better >= 0 ? '#0B8F83' : '#C2362E' ?>"><?= $better >= 0 ? '▲ التجربة أحسن في تقييم العملاء بـ ' . abs($better) : '▼ التجربة أضعف في تقييم العملاء بـ ' . abs($better) ?></p><?php endif; ?>
                <?php if ($test): ?>
                    <details style="margin:8px 0"><summary style="cursor:pointer">الفرق بين المفعّل والتجربة</summary><?= pr_diff_html($live ? (string) $live['prompt_text'] : pr_live_text($t), (string) $test['prompt_text']) ?></details>
                <?php endif; ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
                    <?php if ($test): ?>
                        <form method="POST" style="display:flex;gap:6px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="traffic"><input type="hidden" name="id" value="<?= (int) $test['id'] ?>">
                            <label class="field" style="margin:0"><small>النسبة %</small><input type="number" name="pct" class="input" min="0" max="90" value="<?= (int) $test['traffic_percent'] ?>" style="width:90px"></label><button class="btn ghost sm">حفظ</button></form>
                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="promote"><input type="hidden" name="id" value="<?= (int) $test['id'] ?>"><button class="btn sm" onclick="return confirm('<?= $enough ? 'ترقية التجربة لكل العملاء؟' : 'العينة لسه مش كفاية — ترقّي برضه؟' ?>')">⬆ رقّي التجربة</button></form>
                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="stop"><input type="hidden" name="id" value="<?= (int) $test['id'] ?>"><button class="btn ghost sm" onclick="return confirm('إيقاف التجربة؟')">⏹ وقّف التجربة</button></form>
                    <?php endif; ?>
                    <?php if ($prev && $live): ?>
                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="rollback"><input type="hidden" name="type" value="<?= e($t) ?>"><button class="btn ghost sm" onclick="return confirm('الرجوع لـ v<?= (int) $prev['version'] ?>؟')">↩ رجوع لـ v<?= (int) $prev['version'] ?></button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
