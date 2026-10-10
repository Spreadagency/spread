<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();

// الشكل الجديد: مكتبة المحتوى من التصميم (petite-vue + /api/contents.php)
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    require_once __DIR__ . '/../includes/ui-v2.php';
    $active = 'history';
    $page_title = 'المحتويات';
    $use_app = true;
    include __DIR__ . '/../templates/header.php';
    echo '<div class="app">';
    include __DIR__ . '/../templates/sidebar.php';
    echo '<main class="main">';
    include __DIR__ . '/../templates/topbar.php';
    include __DIR__ . '/../templates/v2/contents.php';
    echo '</main></div>';
    include __DIR__ . '/../templates/footer.php';
    exit;
}

// Filters
$filters = [
    'platform' => $_GET['platform'] ?? '',
    'content_type' => $_GET['type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['from'] ?? '',
    'date_to' => $_GET['to'] ?? '',
    'q' => trim((string) ($_GET['q'] ?? '')),
];
$filters = array_filter($filters);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$total = count_user_contents((int) $user['id'], $filters);
$totalPages = max(1, (int) ceil($total / $perPage));

$contents = list_user_contents((int) $user['id'], $filters, $perPage, $offset);

// صورة الغلاف لكل بوست (المختارة أو أحدث تصميم)
require_once __DIR__ . '/../includes/social.php';
$covers = [];
if ($contents) {
    $ids = array_column($contents, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT id, content_id, image_path FROM content_designs WHERE content_id IN ({$ph}) ORDER BY id ASC", $ids) as $d) {
        $covers[(int) $d['content_id']] = $d;   // آخر واحد بيغلب
    }
    foreach ($contents as $c) {
        if (!empty($c['selected_image_id'])) {
            $sel = db_one('SELECT id, content_id, image_path FROM content_designs WHERE id = ? AND content_id = ?', [$c['selected_image_id'], $c['id']]);
            if ($sel) {
                $covers[(int) $c['id']] = $sel;
            }
        }
    }
}
$brand = user_brand();
$brandName = $brand['business_name'] ?? $user['name'];
$socialOn = feature_allows((int) $user['id']);
$myConnections = $socialOn ? user_connections((int) $user['id'], 'active') : [];

// Group by date for timeline
$grouped = [];
foreach ($contents as $c) {
    $date = date('Y-m-d', strtotime($c['created_at']));
    $grouped[$date][] = $c;
}

$active = 'history';
$page_title = 'المحتوى السابق';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head with-actions">
            <div>
                <h1>المحتوى السابق ▤</h1>
                <div class="sub">جدول زمني بكل المنشورات اللي أنشأتها</div>
            </div>
            <div>
                <a href="<?= url('create-content.php') ?>" class="btn">＋ منشور جديد</a>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;width:100%">
                <label>المنصة:</label>
                <select name="platform" class="filter-input">
                    <option value="">الكل</option>
                    <option value="facebook" <?= ($filters['platform'] ?? '') === 'facebook' ? 'selected' : '' ?>>فيسبوك</option>
                    <option value="instagram" <?= ($filters['platform'] ?? '') === 'instagram' ? 'selected' : '' ?>>إنستجرام</option>
                    <option value="both" <?= ($filters['platform'] ?? '') === 'both' ? 'selected' : '' ?>>الاثنين</option>
                </select>

                <label>النوع:</label>
                <select name="type" class="filter-input">
                    <option value="">الكل</option>
                    <?php foreach (['introductory' => 'تعريفي', 'marketing' => 'تسويقي', 'educational' => 'تعليمي', 'engaging' => 'تفاعلي', 'offer' => 'عرض', 'trend' => 'ترند'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($filters['content_type'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>

                <label>الحالة:</label>
                <select name="status" class="filter-input">
                    <option value="">الكل</option>
                    <option value="generated" <?= ($filters['status'] ?? '') === 'generated' ? 'selected' : '' ?>>تم التوليد</option>
                    <option value="edited" <?= ($filters['status'] ?? '') === 'edited' ? 'selected' : '' ?>>تم التعديل</option>
                    <option value="saved" <?= ($filters['status'] ?? '') === 'saved' ? 'selected' : '' ?>>محفوظ</option>
                </select>

                <label>من:</label>
                <input type="date" name="from" class="filter-input" value="<?= e($filters['date_from'] ?? '') ?>">

                <label>إلى:</label>
                <input type="date" name="to" class="filter-input" value="<?= e($filters['date_to'] ?? '') ?>">

                <button class="btn sm">تصفية</button>
                <?php if (!empty($filters)): ?>
                    <a href="<?= url('content-history.php') ?>" class="btn ghost sm">إعادة تعيين</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($contents)): ?>
            <div class="card">
                <div class="empty">
                    <div class="ico">▤</div>
                    <h3>لا توجد منشورات</h3>
                    <p><?= !empty($filters) ? 'لا يوجد محتوى يطابق الفلاتر المحددة' : 'لسه ما أنشأتش محتوى. ابدأ من هنا!' ?></p>
                    <a href="<?= url('create-content.php') ?>" class="btn">＋ إنشاء أول منشور</a>
                </div>
            </div>
        <?php else: ?>

            <div class="timeline">
                <?php foreach ($grouped as $date => $items): ?>
                    <?php
                    $d = strtotime($date);
                    $monthAr = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
                    ?>
                    <div class="timeline-day">
                        <div class="date">
                            <b><?= date('d', $d) ?></b>
                            <?= $monthAr[(int) date('n', $d) - 1] ?>
                        </div>
                        <div class="timeline-items">
                            <?php foreach ($items as $c): ?>
                                <?php
                                    $cover = $covers[(int) $c['id']] ?? null;
                                    $coverUrl = $cover ? url('storage/' . $cover['image_path']) : null;
                                    $fullText = trim(($c['generated_text'] ?? '') . (!empty($c['hashtags']) ? "\n\n" . $c['hashtags'] : ''));
                                    $title = $c['design_direction'] ?: mb_substr(strip_tags($c['generated_text'] ?? ''), 0, 60);
                                ?>
                                <div class="fb-post card" style="padding:0;overflow:hidden">
                                    <!-- رأس البوست -->
                                    <div style="display:flex;align-items:center;gap:10px;padding:12px 14px 8px">
                                        <div style="width:38px;height:38px;border-radius:50%;background:var(--primary-soft);color:var(--primary-ink);display:grid;place-items:center;font-weight:700;flex-shrink:0">
                                            <?= e(mb_substr($brandName, 0, 1)) ?>
                                        </div>
                                        <div style="flex:1;min-width:0">
                                            <b style="font-size:14px"><?= e($brandName) ?></b>
                                            <div class="sub" style="font-size:11px">
                                                <?= date('H:i', strtotime($c['created_at'])) ?> ·
                                                <?= e(platform_label($c['platform'])) ?> ·
                                                <?= e(content_type_label($c['content_type'])) ?>
                                                <?php if (!empty($c['publish_status']) && $c['publish_status'] === 'published'): ?>
                                                    · <span style="color:#2a7d5f">📤 منشور</span>
                                                <?php elseif (!empty($c['publish_status']) && $c['publish_status'] === 'pending'): ?>
                                                    · <span style="color:#2471c9">⏰ مجدول</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <a href="<?= url('content-view.php?id=' . $c['id']) ?>" class="btn ghost sm" style="flex-shrink:0">فتح ←</a>
                                    </div>

                                    <!-- نص البوست -->
                                    <div class="fb-text" id="txt-<?= $c['id'] ?>" style="padding:0 14px 10px;font-size:14px;line-height:1.85;white-space:pre-wrap"><?= e($fullText) ?></div>

                                    <!-- صورة الغلاف بعرض كامل (زي فيسبوك) -->
                                    <?php if ($coverUrl): ?>
                                        <div style="background:#f0f2f5;border-block:1px solid var(--line)">
                                            <img src="<?= e($coverUrl) ?>" alt="" loading="lazy"
                                                 data-filename="spread-<?= $c['id'] ?>.png"
                                                 class="zoomable"
                                                 style="width:100%;height:auto;display:block;max-height:560px;object-fit:contain;cursor:zoom-in">
                                        </div>
                                    <?php endif; ?>

                                    <!-- أزرار التحكم -->
                                    <div style="display:flex;gap:6px;padding:10px 14px;flex-wrap:wrap;border-top:1px solid var(--line)">
                                        <button type="button" class="btn ghost sm" onclick="copyPostText(<?= $c['id'] ?>, this)">📋 نسخ المحتوى</button>
                                        <?php if ($coverUrl): ?>
                                            <a class="btn ghost sm" href="<?= e($coverUrl) ?>" download="spread-<?= $c['id'] ?>.png">⬇ تحميل الصورة</a>
                                        <?php endif; ?>
                                        <?php if ($myConnections): ?>
                                            <button type="button" class="btn sm" onclick="openPublishNow(<?= $c['id'] ?>)">🚀 انشر الآن</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $qs = $_GET;
                    for ($p = 1; $p <= $totalPages; $p++):
                        $qs['page'] = $p;
                    ?>
                        <a href="?<?= http_build_query($qs) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </main>
</div>

<!-- مودال النشر الفوري -->
<?php if ($myConnections): ?>
<div id="pub-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:900;align-items:center;justify-content:center;padding:20px">
    <div class="card" style="max-width:440px;width:100%">
        <div class="card-head" style="display:flex;justify-content:space-between;align-items:center">
            <h3>🚀 نشر فوري</h3>
            <button type="button" class="btn ghost sm" onclick="closePublish()">✕</button>
        </div>
        <div class="field">
            <label>الصفحة</label>
            <select id="pub-conn" class="input">
                <?php foreach ($myConnections as $mc): ?>
                    <option value="<?= $mc['id'] ?>" data-ig="<?= $mc['ig_user_id'] ? 1 : 0 ?>">📘 <?= e($mc['page_name']) ?><?= $mc['ig_user_id'] ? ' (+IG)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>المنصة</label>
            <select id="pub-platform" class="input">
                <option value="facebook">فيسبوك</option>
                <option value="instagram">إنستجرام (يتطلب تصميم)</option>
                <option value="both">الاثنين</option>
            </select>
        </div>
        <div class="field">
            <label>طريقة النشر</label>
            <select id="pub-mode" class="input" onchange="document.getElementById('pub-when-row').style.display = this.value === 'now' ? 'none' : ''">
                <option value="now">🚀 نشر فوري الآن</option>
                <option value="schedule">📅 مجدول على فيسبوك (يتبعت دلوقتي وينشر في الموعد)</option>
                <option value="queue">⏰ مجدول على المنصة (يتبعت في الموعد نفسه)</option>
            </select>
        </div>
        <div class="field" id="pub-when-row" style="display:none">
            <label>الميعاد</label>
            <input type="datetime-local" id="pub-when" class="input">
        </div>
        <button type="button" class="btn full" id="pub-go" onclick="doPublishNow()">تنفيذ</button>
        <div id="pub-status" class="field-help" style="margin-top:8px"></div>
    </div>
</div>
<?php endif; ?>

<script>
const HCSRF = '<?= e(csrf_token()) ?>';

function copyPostText(id, btn) {
    const el = document.getElementById('txt-' + id);
    if (!el) return;
    const text = el.innerText;
    const done = () => { const o = btn.textContent; btn.textContent = '✓ اتنسخ!'; setTimeout(() => btn.textContent = o, 1600); };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
    } else {
        fallbackCopy(text, done);
    }
}
function fallbackCopy(text, done) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { alert('انسخ يدويًا'); }
    document.body.removeChild(ta);
}

let pubId = 0;
function openPublishNow(id) { pubId = id; document.getElementById('pub-modal').style.display = 'flex'; document.getElementById('pub-status').textContent = ''; }
function closePublish() { document.getElementById('pub-modal').style.display = 'none'; }

async function doPublishNow() {
    const btn = document.getElementById('pub-go');
    const st = document.getElementById('pub-status');
    btn.disabled = true; btn.textContent = '⏳ جاري التنفيذ...';
    st.textContent = 'جاري الاتصال بفيسبوك...';
    try {
        const fd = new FormData();
        fd.append('csrf', HCSRF);
        fd.append('content_id', pubId);
        fd.append('mode', document.getElementById('pub-mode').value);
        const w = document.getElementById('pub-when');
        if (w && w.value) fd.append('scheduled_at', w.value);
        fd.append('platform', document.getElementById('pub-platform').value);
        fd.append('connection_id', document.getElementById('pub-conn').value);
        const r = await fetch('<?= url('ajax/publish-direct.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        if (d.ok) {
            st.innerHTML = '✓ ' + d.msg + (d.post_url ? ' — <a href="' + d.post_url + '" target="_blank">شوف البوست ↗</a>' : '');
            setTimeout(() => { closePublish(); location.reload(); }, 2500);
        } else {
            st.textContent = '⚠️ ' + (d.error || 'فشل النشر');
        }
    } catch (e) {
        st.textContent = '⚠️ خطأ في الاتصال';
    }
    btn.disabled = false; btn.textContent = 'تنفيذ';
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
