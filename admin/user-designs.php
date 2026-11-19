<?php
/**
 * Spread AI — الأدمن: تصميمات عميل — عرض وتنزيل (كلها أو المختار) في ملف ZIP
 *   admin/user-designs.php?id=العميل [&campaign=] [&from=&to=] [&src=posts|studio]
 * التصميمات: تصميمات المنشورات (content_designs) + الاستوديو (studio_designs). الملفات من storage مباشرة.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
require_admin_can('view_users');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$user = db_one('SELECT id, name, email FROM users WHERE id = ?', [$id]);
if (!$user) {
    flash_set('danger', 'العميل مش موجود');
    redirect('admin/users.php');
}
$campaign = (int) ($_GET['campaign'] ?? $_POST['campaign'] ?? 0);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? $_POST['from'] ?? '')) ? (string) ($_GET['from'] ?? $_POST['from']) : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? $_POST['to'] ?? '')) ? (string) ($_GET['to'] ?? $_POST['to']) : '';
$src = in_array($_GET['src'] ?? $_POST['src'] ?? '', ['posts', 'studio'], true) ? (string) ($_GET['src'] ?? $_POST['src']) : '';

/** التصميمات بالفلاتر — مفتاح كل واحد: p{id} (منشور) أو s{id} (استوديو) */
function ud_list(int $uid, int $campaign, string $from, string $to, string $src): array
{
    $out = [];
    if ($src !== 'studio') {
        $w = ['d.user_id = ?'];
        $p = [$uid];
        if ($campaign) { $w[] = 'c.campaign_id = ?'; $p[] = $campaign; }
        if ($from) { $w[] = 'DATE(d.created_at) >= ?'; $p[] = $from; }
        if ($to) { $w[] = 'DATE(d.created_at) <= ?'; $p[] = $to; }
        foreach (db_all('SELECT d.id, d.image_path, d.slide_no, d.created_at, d.content_id, c.campaign_id, cp.title camp, LEFT(c.generated_text, 80) txt
                         FROM content_designs d LEFT JOIN contents c ON c.id = d.content_id LEFT JOIN campaigns cp ON cp.id = c.campaign_id
                         WHERE ' . implode(' AND ', $w) . ' ORDER BY d.created_at DESC, d.id DESC LIMIT 2000', $p) as $r) {
            $out['p' . $r['id']] = ['key' => 'p' . $r['id'], 'path' => (string) $r['image_path'], 'at' => (string) $r['created_at'], 'camp' => (string) ($r['camp'] ?? ''),
                'label' => 'منشور #' . (int) $r['content_id'] . ($r['slide_no'] ? ' · شريحة ' . (int) $r['slide_no'] : ''), 'txt' => (string) ($r['txt'] ?? ''),
                'name' => 'post-' . (int) $r['content_id'] . ($r['slide_no'] ? '-slide' . (int) $r['slide_no'] : '') . '-' . (int) $r['id']];
        }
    }
    if ($src !== 'posts' && !$campaign) {
        $w = ['user_id = ?'];
        $p = [$uid];
        if ($from) { $w[] = 'DATE(created_at) >= ?'; $p[] = $from; }
        if ($to) { $w[] = 'DATE(created_at) <= ?'; $p[] = $to; }
        try {
            foreach (db_all('SELECT id, image_path, created_at, LEFT(COALESCE(design_idea, content_text, ""), 80) txt FROM studio_designs WHERE ' . implode(' AND ', $w)
                            . ' AND image_path IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 2000', $p) as $r) {
                $out['s' . $r['id']] = ['key' => 's' . $r['id'], 'path' => (string) $r['image_path'], 'at' => (string) $r['created_at'], 'camp' => '',
                    'label' => 'الاستوديو #' . (int) $r['id'], 'txt' => (string) ($r['txt'] ?? ''), 'name' => 'studio-' . (int) $r['id']];
            }
        } catch (\Throwable $e) {}
    }
    // الملفات اللي مش موجودة (أو برّه storage) مابتظهرش أصلًا
    $out = array_filter($out, fn($it) => ud_file($it['path']) !== null);
    uasort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
    return $out;
}

/** المسار الحقيقي للملف جوه storage (ومايطلعش برّه) */
function ud_file(string $rel): ?string
{
    $base = realpath(defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__) . '/storage');
    $f = $base ? realpath($base . '/' . ltrim($rel, '/')) : false;
    return $f && $base && str_starts_with($f, $base . DIRECTORY_SEPARATOR) && is_file($f) ? $f : null;
}

$all = ud_list($id, $campaign, $from, $to, $src);

/* ─── التنزيل (ZIP) ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pick = ($_POST['mode'] ?? '') === 'all' ? array_keys($all) : array_values(array_intersect(array_keys($all), (array) ($_POST['keys'] ?? [])));
    if (!$pick) {
        flash_set('warning', 'اختار تصميم واحد على الأقل');
        redirect('admin/user-designs.php?' . http_build_query(array_filter(['id' => $id, 'campaign' => $campaign ?: null, 'from' => $from, 'to' => $to, 'src' => $src])));
    }
    if (!class_exists('ZipArchive')) {
        flash_set('danger', 'إضافة ZipArchive مش متفعّلة على السيرفر — فعّلها من cPanel ← Select PHP Version ← zip');
        redirect('admin/user-designs.php?id=' . $id);
    }
    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'udz');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $n = 0;
    foreach ($pick as $k) {
        $it = $all[$k];
        $f = ud_file($it['path']);
        if (!$f) continue;
        $dir = $it['camp'] !== '' ? preg_replace('/[\/\\\\:*?"<>|]+/u', '-', mb_substr($it['camp'], 0, 60)) . '/' : '';
        $zip->addFile($f, $dir . $it['name'] . '.' . strtolower(pathinfo($f, PATHINFO_EXTENSION) ?: 'png'));
        $n++;
    }
    $zip->close();
    if (!$n) {
        @unlink($tmp);
        flash_set('danger', 'ملفات التصميمات المختارة مش موجودة على السيرفر');
        redirect('admin/user-designs.php?id=' . $id);
    }
    admin_log('download_user_designs', 'user', $id, $n . ' تصميم');
    while (ob_get_level() > 0) ob_end_clean();
    $fn = 'تصميمات-' . preg_replace('/[^\p{L}\p{N}_-]+/u', '-', (string) $user['name']) . '-' . date('Y-m-d') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="designs-' . $id . '.zip"; filename*=UTF-8\'\'' . rawurlencode($fn));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

$camps = db_all('SELECT DISTINCT cp.id, cp.title FROM campaigns cp JOIN contents c ON c.campaign_id = cp.id JOIN content_designs d ON d.content_id = c.id WHERE cp.user_id = ? ORDER BY cp.id DESC LIMIT 200', [$id]);
$page_title = 'تصميمات العميل';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <a href="<?= url('admin/user-view.php?id=' . $id) ?>" class="a2-muted" style="font-size:13px">← ملف العميل</a>
                <h1>تصميمات <?= e($user['name']) ?> <span class="a2-muted" style="font-size:16px">· <?= count($all) ?></span></h1>
                <div class="sub">نزّل كل التصميمات أو اختار اللي عايزه — بيتنزلوا ZIP (متقسمين بالحملة)</div>
            </div>
            <a class="btn soft" href="<?= url('admin/user-content-export.php?id=' . $id . ($campaign ? '&campaign=' . $campaign : '')) ?>">📊 المحتوى Excel</a>
        </div>

        <form method="GET" class="card a2-row" style="gap:8px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="id" value="<?= $id ?>">
            <select name="campaign" class="input" style="max-width:260px">
                <option value="">كل الحملات</option>
                <?php foreach ($camps as $cp): ?><option value="<?= (int) $cp['id'] ?>" <?= $campaign === (int) $cp['id'] ? 'selected' : '' ?>><?= e(mb_substr((string) $cp['title'], 0, 50)) ?></option><?php endforeach; ?>
            </select>
            <select name="src" class="input" style="max-width:170px">
                <option value="">المنشورات + الاستوديو</option>
                <option value="posts" <?= $src === 'posts' ? 'selected' : '' ?>>المنشورات بس</option>
                <option value="studio" <?= $src === 'studio' ? 'selected' : '' ?>>الاستوديو بس</option>
            </select>
            <label class="a2-muted">من <input type="date" name="from" class="input" value="<?= e($from) ?>"></label>
            <label class="a2-muted">لـ <input type="date" name="to" class="input" value="<?= e($to) ?>"></label>
            <button class="btn">عرض</button>
        </form>

        <form method="POST" class="card" id="udForm">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="campaign" value="<?= $campaign ?: '' ?>">
            <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="src" value="<?= e($src) ?>">
            <div class="a2-row" style="gap:8px;margin-bottom:12px;flex-wrap:wrap">
                <label class="a2-row" style="gap:6px"><input type="checkbox" id="udAll" onchange="document.querySelectorAll('.ud-k').forEach(x => x.checked = this.checked); udCount()"> اختار الكل</label>
                <span class="a2-muted" id="udN">0 مختار</span>
                <span style="flex:1"></span>
                <button class="btn" name="mode" value="pick" type="submit">⬇ نزّل المختار</button>
                <button class="btn soft" name="mode" value="all" type="submit" <?= $all ? '' : 'disabled' ?>>⬇ نزّل الكل (<?= count($all) ?>)</button>
            </div>
            <?php if (!$all): ?><div class="a2-empty">مفيش تصميمات بالفلاتر دي</div><?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">
                <?php foreach ($all as $it): ?>
                <label style="display:block;border:1px solid var(--line);border-radius:12px;overflow:hidden;cursor:pointer;background:var(--card, #fff)">
                    <img src="<?= e(url('storage/' . $it['path'])) ?>" alt="" loading="lazy" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block">
                    <span style="display:block;padding:6px 8px;font-size:12px;line-height:1.5">
                        <input type="checkbox" class="ud-k" name="keys[]" value="<?= e($it['key']) ?>" onchange="udCount()">
                        <b><?= e($it['label']) ?></b><br>
                        <span class="a2-muted"><?= e(substr($it['at'], 0, 10)) ?><?= $it['camp'] !== '' ? ' · ' . e(mb_substr($it['camp'], 0, 24)) : '' ?></span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </form>
        <script>function udCount(){var n=document.querySelectorAll('.ud-k:checked').length;document.getElementById('udN').textContent=n+' مختار';}</script>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
