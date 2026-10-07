<?php
/**
 * Spread AI v2 — الأدمن: سجل استدعاءات الـ AI
 * بيوريك البرومبت الكامل والصور اللي اتبعت لكل منشور/فكرة/تصميم
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/ai-log.php';

require_admin();
require_admin_can('ai_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'cleanup') {
        ai_log_cleanup();
        admin_log('cleanup_ai_logs', 'settings');
        flash_set('success', 'تم حذف السجلات الأقدم من المدة المحددة');
    }
    if ($a === 'toggle') {
        set_setting('ai_logging_enabled', get_setting('ai_logging_enabled', '1') === '1' ? '0' : '1');
        flash_set('success', 'تم تحديث حالة التسجيل');
    }
    if ($a === 'purge') {
        db_run('TRUNCATE TABLE ai_request_logs');
        admin_log('purge_ai_logs', 'settings');
        flash_set('success', 'تم مسح كل السجلات');
    }
    redirect('admin/ai-logs.php');
}

/* ═══ عرض سجل واحد بالتفصيل ═══ */
$viewId = (int) ($_GET['view'] ?? 0);
$log = $viewId ? db_one('SELECT * FROM ai_request_logs WHERE id = ?', [$viewId]) : null;

$kindLabels = [
    'content' => ['✎', 'منشور'],  'ideas'  => ['💡', 'أفكار خطة'],
    'design'  => ['🎨', 'تصميم بوست'], 'studio' => ['✨', 'استوديو'],
    'logo'    => ['◈', 'لوجو'],   'brand'  => ['📋', 'ملخص براند'],
    'visual_identity' => ['🖌', 'هوية بصرية'], 'text' => ['📝', 'نص'],
];

if (!$log) {
    $kind   = $_GET['kind'] ?? '';
    $status = $_GET['status'] ?? '';
    $uid    = (int) ($_GET['user'] ?? 0);
    $q      = trim((string) ($_GET['q'] ?? ''));

    $w = ['1=1']; $p = [];
    if ($kind)   { $w[] = 'l.kind = ?';   $p[] = $kind; }
    if ($status) { $w[] = 'l.status = ?'; $p[] = $status; }
    if ($uid)    { $w[] = 'l.user_id = ?'; $p[] = $uid; }
    if ($q !== '') { $w[] = 'l.prompt LIKE ?'; $p[] = '%' . $q . '%'; }

    $rows = db_all(
        'SELECT l.*, u.name AS user_name FROM ai_request_logs l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE ' . implode(' AND ', $w) . '
         ORDER BY l.id DESC LIMIT 120', $p
    );

    $stats = db_one('SELECT COUNT(*) total, SUM(status="failed") failed,
                            AVG(duration_ms) avg_ms, SUM(images_sent) imgs
                     FROM ai_request_logs') ?: [];
}

$active = 'ai-logs';
$page_title = 'سجل استدعاءات الـ AI';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">

    <?php if ($log): /* ══════════ تفاصيل سجل ══════════ */
        $imgs = json_decode((string) $log['images_meta'], true) ?: [];
        $opts = json_decode((string) $log['options_json'], true) ?: [];
        [$ic, $kl] = $kindLabels[$log['kind']] ?? ['•', $log['kind']];
    ?>
        <div class="page-head">
            <h1><?= $ic ?> <?= e($kl) ?> — سجل #<?= (int) $log['id'] ?></h1>
            <div class="sub"><?= e(fmt_date($log['created_at'], true)) ?></div>
        </div>
        <?= render_flash() ?>

        <div style="margin-bottom:16px">
            <a href="<?= url('admin/ai-logs.php') ?>" class="btn ghost">← رجوع للسجل</a>
            <?php if ($log['reference_type'] === 'contents' && $log['reference_id']): ?>
                <a href="<?= url('admin/content-history.php?id=' . (int) $log['reference_id']) ?>" class="btn ghost">📄 المنشور</a>
            <?php endif; ?>
        </div>

        <!-- ① الـ workflow -->
        <div class="card">
            <div class="card-head"><h3>① مسار التنفيذ</h3></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:13px">
                <?php
                $steps = [
                    ['طلب العميل', $log['user_name'] ?? ('#' . $log['user_id'])],
                    ['المسار', $log['route'] === 'smart' ? 'Smart Router' : 'المسار المباشر'],
                    ['الموفر', $log['provider'] ?: '—'],
                    ['الموديل', $log['model'] ?: '—'],
                    ['النتيجة', $log['status'] === 'ok' ? 'نجح ✓' : 'فشل ✕'],
                ];
                foreach ($steps as $i => [$k, $v]):
                ?>
                    <div style="background:<?= $i === 4 ? ($log['status'] === 'ok' ? '#e8f7f1' : '#fdecea') : 'var(--surface-2)' ?>;
                                padding:9px 14px;border-radius:11px;min-width:110px">
                        <div style="font-size:11px;color:var(--mute)"><?= e($k) ?></div>
                        <b style="font-size:13px"><?= e($v) ?></b>
                    </div>
                    <?php if ($i < 4): ?><span style="color:var(--mute)">←</span><?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="table-wrap" style="margin-top:14px">
                <table class="table"><tbody>
                    <tr><td class="sub" style="width:150px">الـ Endpoint</td>
                        <td dir="ltr" style="text-align:start;font-size:12.5px"><code><?= e($log['endpoint'] ?: '—') ?></code></td></tr>
                    <tr><td class="sub">مدة التنفيذ</td><td><b><?= number_format((int) $log['duration_ms']) ?></b> ملّي ثانية</td></tr>
                    <tr><td class="sub">طول البرومبت</td><td><b><?= number_format((int) $log['prompt_chars']) ?></b> حرف</td></tr>
                    <tr><td class="sub">صور اتبعتت</td>
                        <td><b style="color:<?= (int) $log['images_sent'] > 0 ? '#2a7d5f' : '#c0392b' ?>">
                            <?= (int) $log['images_sent'] ?></b>
                            <?php if ((int) $log['images_sent'] === 0 && in_array($log['kind'], ['design', 'studio', 'logo'], true)): ?>
                                <span style="color:#c0392b;font-size:12.5px"> ⚠️ مفيش صور — الموديل مشافش اللوجو!</span>
                            <?php endif; ?>
                        </td></tr>
                    <?php if ((int) $log['tokens_in'] || (int) $log['tokens_out']): ?>
                        <tr><td class="sub">التوكنات</td><td><?= (int) $log['tokens_in'] ?> داخل · <?= (int) $log['tokens_out'] ?> خارج</td></tr>
                    <?php endif; ?>
                    <?php foreach ($opts as $k => $v): if ($v === null || $v === '') continue; ?>
                        <tr><td class="sub"><?= e($k) ?></td><td><?= e(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>

            <?php if ($log['status'] === 'failed' && $log['error']): ?>
                <div class="card" style="background:#fdecea;border-color:#e8a49c;margin:12px 0 0">
                    <b style="color:#a3312a">الخطأ:</b> <?= e($log['error']) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ② الصور اللي اتبعتت -->
        <div class="card">
            <div class="card-head"><h3>② الصور اللي وصلت للموديل (<?= count($imgs) ?>)</h3></div>
            <?php if (!$imgs): ?>
                <p class="sub">مفيش صور اتبعتت في الاستدعاء ده.
                <?php if (in_array($log['kind'], ['design', 'studio', 'logo'], true)): ?>
                    <br><span style="color:#c0392b">⚠️ ده السبب لو التصميم مش ملتزم باللوجو — راجع منتقي الصور في الصفحة.</span>
                <?php endif; ?></p>
            <?php else: ?>
                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    <?php foreach ($imgs as $im): ?>
                        <div style="border:1px solid var(--line);border-radius:12px;padding:10px;text-align:center;min-width:120px">
                            <?php if (!empty($im['thumb'])): ?>
                                <img src="<?= e($im['thumb']) ?>" alt=""
                                     style="width:88px;height:88px;object-fit:contain;background:#f4f2ee;border-radius:8px">
                            <?php else: ?>
                                <div style="width:88px;height:88px;display:grid;place-items:center;background:#f4f2ee;border-radius:8px;font-size:26px">🖼</div>
                            <?php endif; ?>
                            <div style="font-size:12px;font-weight:700;margin-top:7px"><?= e($im['role'] ?: 'صورة') ?></div>
                            <div class="sub" style="font-size:10.5px">
                                <?= e($im['mime'] ?? '') ?><?= !empty($im['bytes']) ? ' · ' . number_format(round($im['bytes'] / 1024)) . ' ك.ب' : '' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ③ البرومبت الكامل -->
        <div class="card">
            <div class="card-head">
                <h3>③ البرومبت الكامل زي ما اتبعت</h3>
                <button type="button" class="btn ghost sm" onclick="cpP(this)">📋 نسخ</button>
            </div>
            <pre id="promptBox" dir="auto" style="white-space:pre-wrap;word-break:break-word;background:var(--surface-2);
                 padding:16px;border-radius:12px;font-size:13px;line-height:1.95;max-height:620px;overflow:auto;margin:0;
                 font-family:ui-monospace,'SF Mono',Menlo,monospace"><?= e($log['prompt'] ?? '') ?></pre>
        </div>

        <?php if ($log['response_excerpt']): ?>
        <div class="card">
            <div class="card-head"><h3>④ رد الموديل</h3></div>
            <pre dir="auto" style="white-space:pre-wrap;word-break:break-word;background:var(--surface-2);
                 padding:16px;border-radius:12px;font-size:13px;line-height:1.9;max-height:420px;overflow:auto;margin:0"><?= e($log['response_excerpt']) ?></pre>
        </div>
        <?php endif; ?>

        <script>
        function cpP(b){
            const t = document.getElementById('promptBox').innerText;
            navigator.clipboard.writeText(t).then(()=>{b.textContent='✓ اتنسخ';setTimeout(()=>b.textContent='📋 نسخ',1600);});
        }
        </script>

    <?php else: /* ══════════ القائمة ══════════ */ ?>
        <div class="page-head">
            <h1>سجل استدعاءات الـ AI 🔬</h1>
            <div class="sub">شوف البرومبت والصور اللي اتبعتوا فعليًا لكل منشور وفكرة وتصميم</div>
        </div>
        <?= render_flash() ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px">
            <?php foreach ([
                ['🔬', 'كل الاستدعاءات', number_format((int) ($stats['total'] ?? 0))],
                ['✕', 'فشلت', number_format((int) ($stats['failed'] ?? 0))],
                ['⏱', 'متوسط الزمن', number_format((int) ($stats['avg_ms'] ?? 0)) . ' م.ث'],
                ['🖼', 'صور اتبعتت', number_format((int) ($stats['imgs'] ?? 0))],
            ] as [$i, $l, $v]): ?>
                <div class="card" style="margin:0;display:flex;gap:11px;align-items:center">
                    <span style="font-size:22px"><?= $i ?></span>
                    <span><b style="font-size:19px;font-family:Almarai"><?= $v ?></b>
                        <br><span style="font-size:12px;color:var(--mute)"><?= e($l) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" style="margin-bottom:16px">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
                <div class="field" style="margin:0">
                    <label>النوع</label>
                    <select name="kind" class="input">
                        <option value="">الكل</option>
                        <?php foreach ($kindLabels as $k => [$i, $l]): ?>
                            <option value="<?= $k ?>" <?= ($_GET['kind'] ?? '') === $k ? 'selected' : '' ?>><?= $i ?> <?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label>الحالة</label>
                    <select name="status" class="input">
                        <option value="">الكل</option>
                        <option value="ok"     <?= ($_GET['status'] ?? '') === 'ok' ? 'selected' : '' ?>>نجح</option>
                        <option value="failed" <?= ($_GET['status'] ?? '') === 'failed' ? 'selected' : '' ?>>فشل</option>
                    </select>
                </div>
                <div class="field" style="margin:0;flex:1;min-width:170px">
                    <label>بحث في البرومبت</label>
                    <input type="text" name="q" class="input" value="<?= e($_GET['q'] ?? '') ?>" placeholder="كلمة في البرومبت">
                </div>
                <button class="btn">🔍 فلترة</button>
                <a href="<?= url('admin/ai-logs.php') ?>" class="btn ghost">مسح</a>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h3>آخر <?= count($rows) ?> استدعاء</h3></div>
            <?php if (!$rows): ?>
                <p class="sub">مفيش استدعاءات مسجّلة — اعمل منشور أو تصميم وهيظهر هنا فورًا.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>النوع</th><th>العميل</th><th>الموديل</th><th>صور</th><th>البرومبت</th><th>الزمن</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): [$ic, $kl] = $kindLabels[$r['kind']] ?? ['•', $r['kind']]; ?>
                    <tr style="<?= $r['status'] === 'ok' ? '' : 'background:rgba(192,57,43,.05)' ?>">
                        <td><?= (int) $r['id'] ?></td>
                        <td><?= $ic ?> <?= e($kl) ?></td>
                        <td style="font-size:12.5px"><?= e($r['user_name'] ?? '#' . $r['user_id']) ?></td>
                        <td dir="ltr" style="text-align:start;font-size:11.5px"><?= e(mb_substr((string) $r['model'], 0, 24)) ?></td>
                        <td>
                            <?php $n = (int) $r['images_sent']; $needsImg = in_array($r['kind'], ['design','studio','logo'], true); ?>
                            <span style="font-weight:700;color:<?= $n > 0 ? '#2a7d5f' : ($needsImg ? '#c0392b' : 'var(--mute)') ?>">
                                <?= $n ?><?= $n === 0 && $needsImg ? ' ⚠️' : '' ?>
                            </span>
                        </td>
                        <td class="sub" style="font-size:12px;max-width:290px">
                            <?= e(mb_substr(preg_replace('/\s+/u', ' ', (string) $r['prompt']), 0, 70)) ?>…
                        </td>
                        <td class="sub" style="font-size:12px"><?= number_format((int) $r['duration_ms']) ?></td>
                        <td><span class="chip <?= $r['status'] === 'ok' ? 'chip-primary' : '' ?>"
                                  <?= $r['status'] === 'failed' ? 'style="background:#fdecea;color:#a3312a"' : '' ?>>
                            <?= $r['status'] === 'ok' ? 'نجح' : 'فشل' ?></span></td>
                        <td><a href="?view=<?= (int) $r['id'] ?>" class="btn ghost sm">🔍 افحص</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h3>⚙️ إعدادات السجل</h3></div>
            <p class="sub" style="font-size:13.5px">
                التسجيل حاليًا: <b><?= get_setting('ai_logging_enabled', '1') === '1' ? 'شغّال ✓' : 'موقوف' ?></b> ·
                مدة الاحتفاظ: <b><?= (int) get_setting('ai_log_keep_days', 30) ?> يوم</b>
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle">
                    <button class="btn ghost sm"><?= get_setting('ai_logging_enabled', '1') === '1' ? '🚫 إيقاف التسجيل' : '✔ تشغيل التسجيل' ?></button>
                </form>
                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="cleanup">
                    <button class="btn ghost sm">🧹 حذف الأقدم من المدة</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('مسح كل السجلات نهائيًا؟')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="purge">
                    <button class="btn ghost sm" style="color:#c0392b">🗑 مسح الكل</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
