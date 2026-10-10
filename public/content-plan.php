<?php
/**
 * Spread AI v2 — خطط المحتوى (Phase 3)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/plan-functions.php';

require_login();

$user = current_user();
$brand = user_brand();

if (!$brand || empty($brand['business_name'])) {
    flash_set('warning', 'كمّل بيانات هويتك الأساسية الأول علشان نبني خطة على بيزنسك');
    redirect('brand-profile.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_plan') {
        $title = trim($_POST['title'] ?? '');
        $goal = in_array($_POST['goal'] ?? '', ['awareness', 'sales', 'engagement', 'trust', 'mixed'], true) ? $_POST['goal'] : 'mixed';
        $count = max(5, min((int) get_setting('plan_max_ideas', 30), (int) ($_POST['ideas_count'] ?? 20)));
        if ($title === '') {
            $title = 'خطة ' . fmt_date(date('Y-m-d'));
        }
        $planId = db_insert(
            'INSERT INTO content_plans (user_id, brand_profile_id, title, goal, ideas_count, notes) VALUES (?, ?, ?, ?, ?, ?)',
            [$user['id'], $brand['id'], mb_substr($title, 0, 180), $goal, $count, mb_substr(trim($_POST['notes'] ?? ''), 0, 1000)]
        );
        redirect('plan-view.php?id=' . $planId);
    }

    if ($action === 'delete_plan') {
        $pid = (int) ($_POST['plan_id'] ?? 0);
        $p = db_one('SELECT id FROM content_plans WHERE id = ? AND user_id = ?', [$pid, $user['id']]);
        if ($p) {
            db_run('DELETE FROM content_plans WHERE id = ?', [$pid]);
            flash_set('success', 'تم حذف الخطة (المحتوى المنتَج فضل محفوظ في «المحتوى السابق»)');
        }
        redirect('content-plan.php');
    }
}

$plans = db_all('SELECT * FROM content_plans WHERE user_id = ? ORDER BY id DESC', [$user['id']]);
$ideasCost = (int) get_setting('plan_ideas_cost', 2);
$produceCost = cost_for('content_generation_cost');
$balance = user_credits();

$goalLabels = ['awareness' => 'وعي بالبراند', 'sales' => 'مبيعات', 'engagement' => 'تفاعل', 'trust' => 'بناء ثقة', 'mixed' => 'مزيج متوازن'];
$statusLabels = [
    'draft'       => ['مسودة', 'chip'],
    'ideas_ready' => ['أفكار جاهزة للاختيار', 'chip chip-primary'],
    'producing'   => ['جاري الإنتاج', 'chip chip-primary'],
    'done'        => ['✓ مكتملة', 'chip chip-primary'],
];

$active = 'plan';
$page_title = 'خطة المحتوى';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1>خطة المحتوى 🗓</h1>
            <div class="sub">الـ AI يقترح أفكار كثيرة → تختار اللي يعجبك → ينتج البوستات بفكرة تصميم لكل واحد → تتوزع على تقويم</div>
        </div>

        <?= render_flash() ?>

        <div class="split split-r plan-cols">

            <!-- إنشاء خطة -->
            <div class="card">
                <div class="card-head">
                    <h3>خطة جديدة</h3>
                    <span class="chip chip-primary cr">◇ <?= $ideasCost ?> كريدت للأفكار</span>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_plan">
                    <div class="field">
                        <label>اسم الخطة</label>
                        <input type="text" name="title" class="input" placeholder="مثال: خطة أغسطس 2026">
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label>هدف الخطة</label>
                            <select name="goal" class="input">
                                <?php foreach ($goalLabels as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= $k === 'mixed' ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>عدد الأفكار</label>
                            <select name="ideas_count" class="input">
                                <option value="10">10 أفكار</option>
                                <option value="15">15 فكرة</option>
                                <option value="20" selected>20 فكرة</option>
                                <option value="30">30 فكرة</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label>توجيهات (اختياري)</label>
                        <textarea name="notes" class="textarea" rows="2" placeholder="مثال: ركّز على خدمة تنظيف الأسنان، وفيه عرض الشهر ده على التبييض"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">＋ إنشاء الخطة</button>
                </form>
                <p class="sub" style="margin-top:10px;font-size:12px">
                    <span class="cr">التكلفة: <?= $ideasCost ?> كريدت للأفكار كلها، وبعدين <?= $produceCost ?> كريدت لكل بوست تختار إنتاجه — رصيدك: <b><?= $balance ?></b> ◇</span>
                    <span class="cr-alt">كل بوست تختار إنتاجه بيتحسب من حصة «المنشورات» في باقة الشهر.</span>
                </p>
            </div>

            <!-- الخطط -->
            <div class="card">
                <div class="card-head"><h3>خططي (<?= count($plans) ?>)</h3></div>
                <?php if (!$plans): ?>
                    <p class="sub" style="padding:10px 0">لسه مفيش خطط — أنشئ أول خطة 👈</p>
                <?php else: ?>
                    <?php foreach ($plans as $p):
                        $st = plan_stats((int) $p['id']);
                        [$stLabel, $stClass] = $statusLabels[$p['status']] ?? ['—', 'chip'];
                    ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 0;border-bottom:1px dashed #eee">
                            <div>
                                <a href="<?= url('plan-view.php?id=' . $p['id']) ?>"><b><?= e($p['title']) ?></b></a>
                                <span class="<?= $stClass ?>" style="margin-inline-start:6px"><?= $stLabel ?></span>
                                <div class="sub" style="font-size:12px;margin-top:4px">
                                    <?= $goalLabels[$p['goal']] ?? '' ?> ·
                                    <?= $st['total'] ?> فكرة · <?= $st['selected'] + $st['produced'] ?> مختارة · <?= $st['produced'] ?> منتَجة ·
                                    <span class="cr"><?= (int) $p['credits_used'] ?> ◇ · </span><?= time_ago($p['created_at']) ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:6px;flex-shrink:0">
                                <a href="<?= url('plan-view.php?id=' . $p['id']) ?>" class="btn btn-sm">فتح ←</a>
                                <form method="POST" onsubmit="return confirm('حذف الخطة؟ (البوستات المنتَجة بتفضل محفوظة)')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_plan">
                                    <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="color:#c0392b">🗑</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
