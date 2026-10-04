<?php
/**
 * Spread AI — حملة جديدة (المرحلة 10 · زرار ＋)
 * بريف سريع قبل ما الحملة تتعمل: الاسم · الهدف · الجمهور · المنصات · الميزانية · المدة · متطلبات المحتوى
 * بيعمل الحملة بنفس جدول campaigns وبيفتح «Campaign Workflow» الحالي (campaign.php) من المرحلة الأولى.
 *   • الهدف والموضوع ← نفس أعمدة الحملة
 *   • باقي البريف ← notes (اللي الـ AI بيقراها في توليد الأفكار) + prefs_json.brief
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/lifecycle.php';
require_once __DIR__ . '/../includes/campaign-flow.php';

require_login();
$user = current_user();
$uid = (int) $user['id'];

if (get_setting('campaigns_enabled', '1') !== '1' || !ui_v2_enabled()) {
    redirect('content-plan.php');
}

$goals = campaign_goals();
$platforms = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'tiktok' => 'تيك توك', 'linkedin' => 'لينكدإن', 'x' => 'X (تويتر)'];
$durations = ['7' => 'أسبوع', '14' => 'أسبوعين', '30' => 'شهر', '60' => 'شهرين', '90' => '3 شهور'];
// عدد الأفكار المقترح حسب المدة (بيتعدّل جوه الحملة)
$ideasFor = ['7' => 5, '14' => 8, '30' => 12, '60' => 16, '90' => 20];

$in = ['title' => '', 'goal' => '', 'audience' => '', 'platforms' => ['facebook', 'instagram'], 'budget' => '', 'duration' => '30', 'requirements' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $in['title'] = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
    $in['goal'] = array_key_exists((string) ($_POST['goal'] ?? ''), $goals) ? (string) $_POST['goal'] : '';
    $in['audience'] = mb_substr(trim((string) ($_POST['audience'] ?? '')), 0, 300);
    $in['platforms'] = array_values(array_intersect(array_keys($platforms), (array) ($_POST['platforms'] ?? [])));
    $in['budget'] = trim((string) ($_POST['budget'] ?? ''));
    $in['duration'] = array_key_exists((string) ($_POST['duration'] ?? ''), $durations) ? (string) $_POST['duration'] : '30';
    $in['requirements'] = mb_substr(trim((string) ($_POST['requirements'] ?? '')), 0, 1500);

    $open = (int) (db_one('SELECT COUNT(*) n FROM campaigns WHERE user_id = ? AND status = "active"', [$uid])['n'] ?? 0);
    if ($open >= 30) $errors[] = 'عندك 30 حملة نشطة — خلّص أو أرشف واحدة الأول';
    if (mb_strlen($in['title']) < 2) $errors[] = 'اكتب اسم الحملة';
    if ($in['goal'] === '') $errors[] = 'اختار هدف الحملة';
    if (!$in['platforms']) $errors[] = 'اختار منصة واحدة على الأقل';
    if ($in['budget'] !== '' && (!is_numeric($in['budget']) || (float) $in['budget'] < 0 || (float) $in['budget'] > 100000000)) $errors[] = 'الميزانية لازم تكون رقم';

    if (!$errors) {
        $budget = $in['budget'] === '' ? null : round((float) $in['budget'], 2);
        $lines = [];
        if ($in['audience'] !== '') $lines[] = 'الجمهور المستهدف: ' . $in['audience'];
        $lines[] = 'المنصات: ' . implode('، ', array_map(fn($p) => $platforms[$p], $in['platforms']));
        if ($budget !== null) $lines[] = 'الميزانية: ' . number_format($budget, 0) . ' جنيه';
        $lines[] = 'مدة الحملة: ' . $durations[$in['duration']];
        if ($in['requirements'] !== '') $lines[] = 'متطلبات المحتوى: ' . $in['requirements'];

        // منصة الجدولة الافتراضية للحملة (النشر المباشر فيسبوك/إنستجرام بس)
        $meta = array_values(array_intersect(['facebook', 'instagram'], $in['platforms']));
        $prefs = [
            'platform' => count($meta) === 2 ? 'both' : ($meta[0] ?? 'facebook'),
            'brief' => ['audience' => $in['audience'], 'platforms' => $in['platforms'], 'budget' => $budget,
                        'duration_days' => (int) $in['duration'], 'requirements' => $in['requirements']],
        ];
        $id = db_insert(
            'INSERT INTO campaigns (user_id, title, goal, topic, ideas_count, notes, period_month, prefs_json) VALUES (?,?,?,?,?,?,?,?)',
            [$uid, $in['title'], $in['goal'], $in['requirements'] !== '' ? mb_substr($in['requirements'], 0, 300) : null,
             $ideasFor[$in['duration']], implode("\n", $lines), date('Y-m'), json_encode($prefs, JSON_UNESCAPED_UNICODE)]
        );
        redirect('campaign.php?id=' . $id);
    }
}

$active = 'campaigns';
$page_title = 'حملة جديدة';
include __DIR__ . '/../templates/header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div class="page-head">
            <a href="<?= url('campaigns.php') ?>" class="text-mute" style="font-size:13px">← حملاتي</a>
            <h1>حملة جديدة 🚀</h1>
            <div class="sub">بريف سريع — والـ AI يبني عليه أفكار الحملة ومحتواها وتصميماتها بأسلوب براندك.</div>
        </div>

        <?php foreach ($errors as $err): ?><div class="alert danger" role="alert"><?= e($err) ?></div><?php endforeach; ?>

        <form method="POST" class="card cn-form" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="cn-title">اسم الحملة <span class="req">*</span></label>
                <input id="cn-title" class="input" name="title" required maxlength="150" value="<?= e($in['title']) ?>" placeholder="مثلًا: عروض الصيف" autofocus>
            </div>

            <fieldset class="field cn-fs">
                <legend>الهدف <span class="req">*</span></legend>
                <div class="cn-chips">
                    <?php foreach ($goals as $k => [$label, $emoji]): ?>
                        <label class="cn-chip"><input type="radio" name="goal" value="<?= e($k) ?>" <?= $in['goal'] === $k ? 'checked' : '' ?> required><span><?= $emoji ?> <?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="field">
                <label for="cn-aud">الجمهور المستهدف</label>
                <input id="cn-aud" class="input" name="audience" maxlength="300" value="<?= e($in['audience']) ?>" placeholder="مثلًا: ستات 25–40 في القاهرة مهتمين بالعناية بالبشرة">
                <div class="field-help">سيبه فاضي ونستخدم جمهور براندك من Brand Brain.</div>
            </div>

            <fieldset class="field cn-fs">
                <legend>المنصات <span class="req">*</span></legend>
                <div class="cn-chips">
                    <?php foreach ($platforms as $k => $label): ?>
                        <label class="cn-chip"><input type="checkbox" name="platforms[]" value="<?= e($k) ?>" <?= in_array($k, $in['platforms'], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="field-row">
                <div class="field">
                    <label for="cn-budget">الميزانية (جنيه) <small class="sub">اختياري</small></label>
                    <input id="cn-budget" class="input" name="budget" type="number" min="0" step="1" inputmode="numeric" dir="ltr" value="<?= e($in['budget']) ?>" placeholder="5000">
                </div>
                <div class="field">
                    <label for="cn-dur">المدة</label>
                    <select id="cn-dur" class="input" name="duration">
                        <?php foreach ($durations as $k => $label): ?>
                            <option value="<?= $k ?>" <?= $in['duration'] === (string) $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="cn-req">متطلبات المحتوى</label>
                <textarea id="cn-req" class="textarea" name="requirements" rows="4" maxlength="1500" placeholder="العرض · الخدمات اللي عايز تركّز عليها · نوع المنشورات (صور، ريلز، كاروسيل) · أي حاجة لازم تتقال"><?= e($in['requirements']) ?></textarea>
            </div>

            <div class="cn-act">
                <button type="submit" class="btn lg">ابدأ الحملة ←</button>
                <a href="<?= url('campaigns.php') ?>" class="btn ghost">إلغاء</a>
            </div>
        </form>
    </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
