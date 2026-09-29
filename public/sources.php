<?php
/**
 * Spread AI v2 — مستندات الهوية (Phase 2)
 * رفع PDF / DOCX / TXT / روابط / نصوص عن البيزنس — الـ AI يستخدمها في كل توليد
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/source-extract.php';

require_login();

$user = current_user();
$brand = user_brand();

if (!$brand || empty($brand['business_name'])) {
    flash_set('warning', 'كمّل بيانات هويتك الأساسية الأول');
    redirect('brand-profile.php');
}
$brandId = (int) $brand['id'];

$MAX_SOURCES = 15;
$MAX_SIZE = 20 * 1024 * 1024; // 20MB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    $count = db_count('SELECT COUNT(*) FROM brand_sources WHERE brand_profile_id = ?', [$brandId]);

    // ── رفع ملف ──
    if ($action === 'upload_file' && !empty($_FILES['file']['name'])) {
        if ($count >= $MAX_SOURCES) {
            flash_set('danger', "الحد الأقصى {$MAX_SOURCES} مصدر — احذف مصدر قديم الأول");
        } else {
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf' => 'pdf', 'docx' => 'docx', 'doc' => 'docx', 'txt' => 'txt'];
            if (!isset($allowed[$ext])) {
                flash_set('danger', 'النوع غير مدعوم — المسموح: PDF, DOCX, TXT');
            } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK || $_FILES['file']['size'] > $MAX_SIZE) {
                flash_set('danger', 'فشل الرفع أو الملف أكبر من 20 ميجا');
            } else {
                $dir = UPLOADS_PATH . '/sources';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $safe = 'src_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'doc' ? 'docx' : $ext);
                if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $safe)) {
                    $title = trim($_POST['title'] ?? '') ?: pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
                    $id = db_insert(
                        'INSERT INTO brand_sources (brand_profile_id, user_id, type, title, file_path) VALUES (?, ?, ?, ?, ?)',
                        [$brandId, $user['id'], $allowed[$ext], mb_substr($title, 0, 180), 'uploads/sources/' . $safe]
                    );
                    // استخراج فوري
                    $ex = source_extract($id);
                    flash_set($ex['ok'] ? 'success' : 'warning',
                        $ex['ok'] ? "تم الرفع واستخراج النص ✓ ({$ex['chars']} حرف)" : 'تم الرفع لكن تعذر استخراج النص: ' . ($ex['error'] ?? ''));
                } else {
                    flash_set('danger', 'فشل حفظ الملف');
                }
            }
        }
        redirect('sources.php');
    }

    // ── إضافة رابط ──
    if ($action === 'add_link') {
        $link = trim($_POST['source_url'] ?? '');
        if ($count >= $MAX_SOURCES) {
            flash_set('danger', "الحد الأقصى {$MAX_SOURCES} مصدر");
        } elseif (!preg_match('#^https?://.+#i', $link)) {
            flash_set('danger', 'أدخل رابط صحيح يبدأ بـ https://');
        } else {
            $id = db_insert(
                'INSERT INTO brand_sources (brand_profile_id, user_id, type, title, source_url) VALUES (?, ?, "link", ?, ?)',
                [$brandId, $user['id'], mb_substr(trim($_POST['title'] ?? '') ?: $link, 0, 180), mb_substr($link, 0, 500)]
            );
            $ex = source_extract($id);
            flash_set($ex['ok'] ? 'success' : 'warning',
                $ex['ok'] ? "تم جلب محتوى الصفحة ✓ ({$ex['chars']} حرف)" : 'تم الحفظ لكن تعذر جلب الصفحة');
        }
        redirect('sources.php');
    }

    // ── إضافة نص مباشر ──
    if ($action === 'add_text') {
        $text = trim($_POST['text_source'] ?? '');
        if ($count >= $MAX_SOURCES) {
            flash_set('danger', "الحد الأقصى {$MAX_SOURCES} مصدر");
        } elseif (mb_strlen($text) < 30) {
            flash_set('danger', 'اكتب نص أطول من 30 حرف');
        } else {
            db_insert(
                'INSERT INTO brand_sources (brand_profile_id, user_id, type, title, raw_text, extract_status, extracted_at)
                 VALUES (?, ?, "text", ?, ?, "extracted", NOW())',
                [$brandId, $user['id'], mb_substr(trim($_POST['title'] ?? '') ?: 'نص عن البيزنس', 0, 180), mb_substr($text, 0, 100000)]
            );
            flash_set('success', 'تم حفظ النص ✓');
        }
        redirect('sources.php');
    }

    // ── إعادة استخراج ──
    if ($action === 'reextract') {
        $id = (int) ($_POST['source_id'] ?? 0);
        $own = db_one('SELECT id FROM brand_sources WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if ($own) {
            $ex = source_extract($id);
            flash_set($ex['ok'] ? 'success' : 'danger',
                $ex['ok'] ? "تم استخراج النص ✓ ({$ex['chars']} حرف)" : 'فشل الاستخراج: ' . ($ex['error'] ?? ''));
        }
        redirect('sources.php');
    }

    // ── تفعيل/إيقاف استخدام المصدر في التوليد ──
    if ($action === 'toggle_use') {
        $id = (int) ($_POST['source_id'] ?? 0);
        db_run('UPDATE brand_sources SET use_in_prompts = 1 - use_in_prompts WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        redirect('sources.php');
    }

    // ── حذف ──
    if ($action === 'delete') {
        $id = (int) ($_POST['source_id'] ?? 0);
        $src = db_one('SELECT * FROM brand_sources WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if ($src) {
            if (!empty($src['file_path'])) {
                @unlink(STORAGE_PATH . '/' . $src['file_path']);
            }
            db_run('DELETE FROM brand_sources WHERE id = ?', [$id]);
            flash_set('success', 'تم حذف المصدر');
        }
        redirect('sources.php');
    }
}

$sources = db_all('SELECT * FROM brand_sources WHERE brand_profile_id = ? ORDER BY created_at DESC', [$brandId]);
$summaryCost = function_exists('get_setting') ? (int) get_setting('source_summary_cost', 1) : 1;

$typeLabels = ['pdf' => 'PDF', 'docx' => 'Word', 'txt' => 'نص TXT', 'link' => 'رابط', 'text' => 'نص مباشر'];
$statusLabels = [
    'pending'    => ['⏳ في الانتظار', 'chip'],
    'extracted'  => ['✓ تم الاستخراج', 'chip chip-primary'],
    'summarized' => ['★ ملخّص AI', 'chip chip-primary'],
    'failed'     => ['✕ فشل الاستخراج', 'chip'],
];

$active = 'sources';
$page_title = 'مستندات الهوية';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>مستندات الهوية 📄</h1>
            <div class="sub">ارفع بروفايل الشركة، قوائم الخدمات، الأسعار، أو أي مستند — الـ AI هيستخدمها كمصدر حقائق في كل منشور</div>
        </div>

        <?= render_flash() ?>

        <div class="split split-2" id="sources-grid">

            <!-- رفع ملف -->
            <div class="card">
                <div class="card-head"><h3>رفع ملف (PDF / Word / TXT)</h3></div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_file">
                    <div class="field">
                        <label>عنوان المستند (اختياري)</label>
                        <input type="text" name="title" class="input" placeholder="مثال: بروفايل الشركة 2026">
                    </div>
                    <div class="field">
                        <label>الملف <span class="req">*</span></label>
                        <input type="file" name="file" class="input" accept=".pdf,.docx,.doc,.txt" required>
                    </div>
                    <button type="submit" class="btn btn-primary">⬆ رفع واستخراج النص</button>
                </form>
            </div>

            <!-- رابط أو نص -->
            <div class="card">
                <div class="card-head"><h3>رابط صفحة أو نص مباشر</h3></div>
                <form method="POST" style="margin-bottom:18px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_link">
                    <div class="field">
                        <label>رابط (موقعك، صفحة عنّا، مقال...)</label>
                        <input type="url" name="source_url" class="input" placeholder="https://example.com/about">
                    </div>
                    <button type="submit" class="btn">+ إضافة الرابط</button>
                </form>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_text">
                    <div class="field">
                        <label>أو اكتب معلومات مباشرة</label>
                        <textarea name="text_source" class="textarea" rows="3" placeholder="خدماتنا، أسعارنا، قصتنا، مميزاتنا..."></textarea>
                    </div>
                    <button type="submit" class="btn">+ حفظ النص</button>
                </form>
            </div>
        </div>

        <!-- قائمة المصادر -->
        <div class="card sources-list" style="margin-top:20px">
            <div class="card-head">
                <h3>المصادر المحفوظة (<?= count($sources) ?>/15)</h3>
            </div>

            <?php if (!$sources): ?>
                <p class="sub" style="padding:10px 0">لسه مفيش مصادر — ارفع أول مستند عن بيزنسك 👆</p>
            <?php else: ?>
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>العنوان</th>
                            <th>النوع</th>
                            <th>الحالة</th>
                            <th>الحجم</th>
                            <th>يُستخدم؟</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sources as $s):
                        [$stLabel, $stClass] = $statusLabels[$s['extract_status']] ?? ['—', 'chip'];
                        $chars = mb_strlen((string) $s['raw_text']);
                    ?>
                        <tr>
                            <td>
                                <b><?= e($s['title'] ?: '—') ?></b>
                                <?php if ($s['extract_error']): ?>
                                    <div class="sub" style="color:#c0392b;font-size:12px"><?= e($s['extract_error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($typeLabels[$s['type']] ?? $s['type']) ?></td>
                            <td><span class="<?= $stClass ?>"><?= $stLabel ?></span></td>
                            <td><?= $chars ? number_format($chars) . ' حرف' : '—' ?></td>
                            <td>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_use">
                                    <input type="hidden" name="source_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm" title="تفعيل/إيقاف استخدام المصدر في التوليد">
                                        <?= $s['use_in_prompts'] ? '🟢 نشط' : '⚪ متوقف' ?>
                                    </button>
                                </form>
                            </td>
                            <td style="white-space:nowrap">
                                <?php if (in_array($s['extract_status'], ['extracted', 'failed'], true) && $s['type'] !== 'text'): ?>
                                    <form method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reextract">
                                        <input type="hidden" name="source_id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-sm">↻ إعادة استخراج</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($s['extract_status'] === 'extracted' && $chars > 1500): ?>
                                    <button type="button" class="btn btn-sm" onclick="summarizeSource(<?= $s['id'] ?>, this)"
                                            title="ملخص مضغوط يوفر التكلفة في كل توليد">
                                        ✦ تلخيص AI (<?= $summaryCost ?> ◇)
                                    </button>
                                <?php endif; ?>
                                <?php if (!empty($s['summary'])): ?>
                                    <a class="btn btn-sm" href="<?= url('source-download.php?id=' . (int) $s['id'] . '&type=summary') ?>" title="تنزيل الملخص كملف نصي">⬇ الملخص</a>
                                <?php endif; ?>
                                <?php if ($s['extract_status'] === 'extracted' && $chars > 0): ?>
                                    <a class="btn btn-sm" href="<?= url('source-download.php?id=' . (int) $s['id'] . '&type=raw') ?>" title="تنزيل النص الكامل المستخرج">⬇ النص</a>
                                <?php endif; ?>
                                <form method="POST" style="display:inline" onsubmit="return confirm('حذف المصدر نهائيًا؟')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="source_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="color:#c0392b">🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top:20px;background:var(--primary-soft,#f0eefb)">
            <b>💡 إزاي بتشتغل؟</b>
            <p class="sub" style="margin-top:6px">
                عند كل توليد محتوى، الـ AI بياخد ملخص من مصادرك النشطة كـ«حقائق موثّقة» عن بيزنسك —
                فالمحتوى بيطلع دقيق وبمعلوماتك الحقيقية بدل الكلام العام.
                المصادر الطويلة الأفضل تعمل لها «تلخيص AI» مرة واحدة: أدق وأوفر في التكلفة.
            </p>
        </div>

    </main>
</div>

<script>
function summarizeSource(id, btn) {
    btn.disabled = true;
    btn.textContent = '⏳ جاري التلخيص...';
    const fd = new FormData();
    fd.append('source_id', id);
    fd.append('csrf', '<?= e(csrf_token()) ?>');
    fetch('<?= url('ajax/summarize-source.php') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) { location.reload(); }
            else { alert(d.error || 'حصل خطأ'); btn.disabled = false; btn.textContent = '✦ تلخيص AI'; }
        })
        .catch(() => { alert('حصل خطأ في الاتصال'); btn.disabled = false; btn.textContent = '✦ تلخيص AI'; });
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
