<?php
/**
 * Spread AI v2 — الأدمن: عارض البرومبت المُرسل للـ API
 * ?type=content&id=N   → برومبت كتابة المنشور
 * ?type=design&id=N    → برومبت تصميم من بوست
 * ?type=studio&id=N    → برومبت تصميم من الاستوديو
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
require_admin_can('view_content');

$type = in_array($_GET['type'] ?? '', ['content', 'design', 'studio'], true) ? $_GET['type'] : 'content';
$id = (int) ($_GET['id'] ?? 0);

$prompt = '';
$meta = [];
$title = '';
$imageUrl = null;
$refs = [];          // ⑦-ج الصور المرجعية اللي اتبعتت مع التصميم
$contentBlock = '';  // المحتوى اللي التصميم اتعمل له (نص الشريحة في الكاروسيل)
$backUrl = url('admin/content-history.php');

if ($type === 'content') {
    $row = db_one('SELECT c.*, u.name AS user_name FROM contents c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$id]);
    if ($row) {
        $title = 'برومبت كتابة المنشور #' . $id;
        $prompt = (string) ($row['final_prompt'] ?? '');
        $meta = [
            'العميل' => $row['user_name'],
            'الموديل' => $row['model'] ?: '—',
            'التوكنات' => 'داخل: ' . (int) $row['tokens_in'] . ' · خارج: ' . (int) $row['tokens_out'],
            'النوع / المنصة' => ($row['content_type'] ?? '') . ' · ' . ($row['platform'] ?? ''),
            'اللهجة / النبرة' => ($row['dialect'] ?? '') . ' · ' . ($row['tone'] ?? ''),
            'الكريدت' => (int) $row['credits_used'],
            'التاريخ' => fmt_date($row['created_at'], true),
        ];
        $backUrl = url('admin/content-view.php?id=' . $id);
    }
} elseif ($type === 'design') {
    $row = db_one('SELECT d.*, u.name AS user_name FROM content_designs d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id]);
    if ($row) {
        $title = 'برومبت التصميم #' . $id;
        $prompt = (string) ($row['prompt'] ?? '');
        $imageUrl = url('storage/' . $row['image_path']);
        require_once __DIR__ . '/../includes/content-formats.php';
        $co = db_one('SELECT * FROM contents WHERE id = ?', [$row['content_id']]) ?: [];
        $fmt = content_format_key($co['format'] ?? 'post');
        $meta = [
            'العميل' => $row['user_name'],
            'الموديل' => $row['model'] ?: '—',
            'نوع المحتوى' => content_format_label($fmt) . (!empty($row['slide_no']) ? ' · شريحة ' . (int) $row['slide_no'] . ' من ' . (int) ($co['slides_count'] ?? 0) : ''),
            'المقاس' => $row['ratio'] ?? '1:1',
            'الكريدت' => (int) $row['credits_used'],
            'مرتبط بالمنشور' => '#' . (int) $row['content_id'],
            'التاريخ' => fmt_date($row['created_at'], true),
        ];
        foreach ((array) json_decode((string) ($row['refs_json'] ?? ''), true) as $r) {
            if (!is_array($r)) continue;
            $label = ['logo' => 'اللوجو', 'personal' => 'صورة العميل / المنتج', 'brand' => 'صورة من البراند', 'style' => 'مرجع ستايل', 'edit' => 'التصميم اللي اتعدّل'][$r['role'] ?? ''] ?? '';
            if ($label === '' && str_starts_with((string) ($r['role'] ?? ''), 'carousel_slide_')) $label = 'مرجع الكاروسيل: شريحة ' . substr($r['role'], 15);
            $refs[] = ['label' => $label ?: (string) ($r['role'] ?? ''), 'url' => !empty($r['path']) ? url('storage/' . $r['path']) : null, 'src' => $r['url'] ?? null];
        }
        if ($fmt === 'carousel' && !empty($row['slide_no'])) {
            $sl = content_slides($co)[(int) $row['slide_no'] - 1] ?? null;
            if ($sl) $contentBlock = 'شريحة ' . $sl['n'] . "\nالعنوان: " . $sl['title'] . "\nالنص: " . $sl['text'] . ($sl['design'] !== '' ? "\nفكرة التصميم: " . $sl['design'] : '');
        } elseif ($co) {
            $contentBlock = (string) $co['generated_text'];
        }
        $backUrl = url('admin/content-view.php?id=' . (int) $row['content_id']);
    }
} else {
    $row = db_one('SELECT s.*, u.name AS user_name FROM studio_designs s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$id]);
    if ($row) {
        $title = 'برومبت تصميم الاستوديو #' . $id;
        $prompt = (string) ($row['prompt'] ?? '');
        $imageUrl = url('storage/' . $row['image_path']);
        $meta = [
            'العميل' => $row['user_name'],
            'الموديل' => $row['model'] ?: '—',
            'المقاس' => $row['ratio'] ?? '1:1',
            'الوضع' => $row['mode'] ?? '',
            'الكريدت' => (int) $row['credits_used'],
            'التاريخ' => fmt_date($row['created_at'], true),
        ];
        $backUrl = url('admin/designs-gallery.php');
    }
}

if (!$title) {
    flash_set('danger', 'العنصر غير موجود');
    redirect('admin/dashboard.php');
}

$active = 'content';
$page_title = 'البرومبت المُرسل';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div style="margin-bottom:12px">
            <a href="<?= url('admin/ai-logs.php') ?>" class="btn ghost sm">🔬 السجل الفعلي للاستدعاءات (اللي اتبعت فعلًا)</a>
        </div>
        <div class="page-head">
            <h1><?= e($title) ?> 🔍</h1>
            <div class="sub">ده النص الكامل اللي اتبعت للـ API بالحرف</div>
        </div>

        <?= render_flash() ?>

        <div class="split split-r">
            <div>
                <div class="card">
                    <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                        <h3>البرومبت الكامل</h3>
                        <div style="display:flex;gap:6px">
                            <span class="chip"><?= number_format(mb_strlen($prompt)) ?> حرف</span>
                            <button type="button" class="btn ghost sm" onclick="copyPrompt(this)">📋 نسخ</button>
                        </div>
                    </div>
                    <?php if ($prompt === ''): ?>
                        <p class="sub">البرومبت مش متسجل للعنصر ده (اتعمل قبل تفعيل حفظ البرومبت).</p>
                    <?php else: ?>
                        <pre id="prompt-box" style="background:var(--surface-2);padding:16px;border-radius:12px;
                             white-space:pre-wrap;overflow-wrap:anywhere;font-size:12.5px;line-height:1.9;
                             max-height:70vh;overflow-y:auto;margin:0"><?= e($prompt) ?></pre>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <?php if ($imageUrl): ?>
                    <div class="card" style="margin-bottom:16px">
                        <div class="card-head"><h3>النتيجة</h3></div>
                        <img src="<?= e($imageUrl) ?>" class="zoomable" style="width:100%;border-radius:10px;cursor:zoom-in" alt="">
                    </div>
                <?php endif; ?>
                <?php if ($type === 'design'): ?>
                    <div class="card" style="margin-bottom:16px">
                        <div class="card-head"><h3>الصور المرجعية (<?= count($refs) ?>)</h3></div>
                        <?php if (!$refs): ?><p class="sub">مفيش صور مرجعية اتبعتت — أو التصميم اتعمل قبل تسجيلها.</p><?php endif; ?>
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">
                            <?php foreach ($refs as $r): ?>
                                <figure style="margin:0;background:var(--surface-2);border-radius:10px;padding:6px;text-align:center">
                                    <?php if ($r['url']): ?><img src="<?= e($r['url']) ?>" class="zoomable" style="width:100%;aspect-ratio:1;object-fit:contain;border-radius:8px;cursor:zoom-in" alt=""><?php endif; ?>
                                    <?php if (!$r['url'] && $r['src']): ?><small dir="ltr" style="word-break:break-all"><?= e($r['src']) ?></small><?php endif; ?>
                                    <figcaption style="font-size:11.5px;margin-top:4px"><?= e($r['label']) ?></figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($contentBlock !== ''): ?>
                    <div class="card" style="margin-bottom:16px">
                        <div class="card-head"><h3>المحتوى</h3></div>
                        <div style="white-space:pre-wrap;font-size:12.5px;line-height:1.8;background:var(--surface-2);border-radius:10px;padding:10px"><?= e(mb_substr($contentBlock, 0, 1500)) ?></div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-head"><h3>التفاصيل</h3></div>
                    <div class="table-wrap">
                    <table class="table">
                        <tbody>
                        <?php foreach ($meta as $k => $v): ?>
                            <tr>
                                <td class="sub" style="font-size:12px"><?= e($k) ?></td>
                                <td style="font-size:12.5px" dir="auto"><b><?= e((string) $v) ?></b></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <a href="<?= e($backUrl) ?>" class="btn ghost full" style="margin-top:12px">← رجوع</a>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
function copyPrompt(btn) {
    const el = document.getElementById('prompt-box');
    if (!el) return;
    const t = el.innerText;
    const done = () => { const o = btn.textContent; btn.textContent = '✓ اتنسخ'; setTimeout(() => btn.textContent = o, 1500); };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(t).then(done).catch(() => fb(t, done));
    } else { fb(t, done); }
    function fb(text, cb) {
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); cb(); } catch (e) {}
        document.body.removeChild(ta);
    }
}
</script>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
