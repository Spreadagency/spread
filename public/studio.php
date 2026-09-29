<?php
/**
 * Spread AI v2 — الستوديو للعميل (Phase 4)
 * معرض تصميمات — يختار المفضل والـ AI يولّد على ذوقه
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';

require_login();
$user = current_user();

$maxSel = (int) get_setting('studio_max_selections', 10);
$view = ($_GET['view'] ?? '') === 'mine' ? 'mine' : 'all';
$filter = trim($_GET['cat'] ?? '');

$mySelections = array_column(
    db_all('SELECT media_id FROM user_media_selections WHERE user_id = ?', [$user['id']]),
    'media_id'
);
$myCount = count($mySelections);

if ($view === 'mine') {
    $items = db_all(
        'SELECT m.* FROM user_media_selections s JOIN media_library m ON m.id = s.media_id AND m.is_active = 1
         WHERE s.user_id = ? ORDER BY s.created_at DESC',
        [$user['id']]
    );
} else {
    $where = 'WHERE is_active = 1' . ($filter !== '' ? ' AND category = ?' : '');
    $params = $filter !== '' ? [$filter] : [];
    $items = db_all('SELECT * FROM media_library ' . $where . ' ORDER BY sort_order, id DESC', $params);
}
$cats = db_all('SELECT category, COUNT(*) AS c FROM media_library WHERE is_active = 1 GROUP BY category ORDER BY c DESC');

$active = 'studio';
$page_title = 'الستوديو';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>الستوديو 🎨</h1>
            <div class="sub">اختار التصميمات اللي تعجبك (❤) — الـ AI هياخد ذوقك في الاعتبار لما يولّد تصميماتك</div>
        </div>

        <?= render_flash() ?>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
            <div class="seg" style="max-width:340px">
                <a href="<?= url('studio.php') ?>"><button type="button" class="<?= $view === 'all' ? 'on' : '' ?>">كل التصميمات</button></a>
                <a href="<?= url('studio.php?view=mine') ?>"><button type="button" class="<?= $view === 'mine' ? 'on' : '' ?>">❤ مفضلتي (<span id="my-count"><?= $myCount ?></span>/<?= $maxSel ?>)</button></a>
            </div>
            <?php if ($view === 'all' && $cats): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a href="<?= url('studio.php') ?>" class="chip <?= $filter === '' ? 'chip-primary' : '' ?>">الكل</a>
                <?php foreach ($cats as $c): ?>
                    <a href="?cat=<?= urlencode($c['category']) ?>" class="chip <?= $filter === $c['category'] ? 'chip-primary' : '' ?>"><?= e($c['category']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$items): ?>
            <div class="card"><p class="sub"><?= $view === 'mine' ? 'لسه مختارتش تصميمات — ارجع لـ«كل التصميمات» ودوس ❤ على اللي يعجبك' : 'المكتبة لسه فاضية — هيتم إضافة تصميمات قريبًا' ?></p></div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
                <?php foreach ($items as $m):
                    $picked = in_array($m['id'], $mySelections);
                ?>
                    <div class="card" style="padding:10px;position:relative">
                        <img src="<?= e(media_display_url($m)) ?>" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px" loading="lazy" alt="<?= e($m['title']) ?>" onerror="this.closest('.card')?.style.setProperty('opacity','.3')">
                        <button class="pick-btn" data-id="<?= $m['id'] ?>" onclick="togglePick(this)"
                                style="position:absolute;top:16px;inset-inline-end:16px;border:none;border-radius:50%;width:38px;height:38px;font-size:18px;cursor:pointer;background:<?= $picked ? '#e91e63' : 'rgba(255,255,255,.9)' ?>;color:<?= $picked ? '#fff' : '#e91e63' ?>;box-shadow:0 2px 8px rgba(0,0,0,.15)">
                            <?= $picked ? '❤' : '♡' ?>
                        </button>
                        <div style="margin-top:8px">
                            <b style="font-size:14px"><?= e($m['title']) ?></b>
                            <?php if ($m['style_tags']): ?>
                                <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:5px">
                                    <?php foreach (array_slice(array_filter(array_map('trim', explode(',', $m['style_tags']))), 0, 4) as $t): ?>
                                        <span class="chip" style="font-size:11px"><?= e($t) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top:20px;background:var(--primary-soft,#f0eefb)">
            <b>💡 إزاي بيشتغل؟</b>
            <p class="sub" style="margin-top:6px">
                لما تولّد تصميم لأي بوست، الـ AI بياخد أسلوب التصميمات اللي اخترتها (الألوان، الطابع، الـ style) كمرجع —
                فالتصميمات بتطلع أقرب لذوقك. اختار من <?= min(3, $maxSel) ?> لـ <?= $maxSel ?> تصميمات تحس إنها «انت».
            </p>
        </div>

    </main>
</div>

<script>
function togglePick(btn) {
    const fd = new FormData();
    fd.append('csrf', '<?= e(csrf_token()) ?>');
    fd.append('media_id', btn.dataset.id);
    btn.disabled = true;
    fetch('<?= url('ajax/studio-select.php') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (!d.ok) { alert(d.error || 'حصل خطأ'); return; }
            const on = d.selected;
            btn.textContent = on ? '❤' : '♡';
            btn.style.background = on ? '#e91e63' : 'rgba(255,255,255,.9)';
            btn.style.color = on ? '#fff' : '#e91e63';
            document.getElementById('my-count').textContent = d.count;
        })
        .catch(() => { btn.disabled = false; });
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
