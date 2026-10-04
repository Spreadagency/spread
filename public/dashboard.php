<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();

// الشكل الجديد: الرئيسية من التصميم — بتتفرّع قبل استعلامات الرئيسية القديمة عشان مايتعملوش على الفاضي
if (function_exists('ui_v2_enabled') && ui_v2_enabled()) {
    require_once __DIR__ . '/../includes/dashboard-v2.php';
    require_once __DIR__ . '/../includes/uploader.php';
    $d = dashboard_v2_data($user);
    $active = 'dashboard';
    $page_title = 'الرئيسية';
    include __DIR__ . '/../templates/header.php';
    echo '<div class="app">';
    include __DIR__ . '/../templates/sidebar.php';
    echo '<main class="main">';
    include __DIR__ . '/../templates/topbar.php';
    include __DIR__ . '/../templates/v2/dashboard.php';
    echo '</main></div>';
    include __DIR__ . '/../templates/footer.php';
    exit;
}
$brand = user_brand();
$stats = user_stats((int) $user['id']);

// Recent contents
$recent = db_all(
    'SELECT id, content_type, platform, status, credits_used, created_at, generated_text
     FROM contents WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
    [$user['id']]
);

// Brand image count
$brandImageCount = $brand
    ? db_count('SELECT COUNT(*) FROM brand_images WHERE brand_profile_id = ?', [$brand['id']])
    : 0;

// Greeting based on time
$hour = (int) date('H');
$greeting = $hour < 12 ? 'صباح الخير' : ($hour < 18 ? 'مساء الخير' : 'مساء النور');

// Brand setup percentage
$brandFields = ['business_name', 'industry', 'description', 'audience', 'tone'];
$filledFields = 0;
foreach ($brandFields as $f) {
    if (!empty($brand[$f])) $filledFields++;
}
$brandSetup = (int) round(($filledFields / count($brandFields)) * 100);

// ─── رحلة البداية (الأونبوردنج) ───
$hasBrand   = $brand && !empty($brand['business_name']) && $brandSetup >= 60;
$hasPlan    = (function () use ($user) { try { return db_count('SELECT COUNT(*) FROM content_plans WHERE user_id = ?', [$user['id']]) > 0; } catch (\Throwable $e) { return false; } })();
$hasContent = ($stats['total_content'] ?? 0) > 0;
$hasDesign  = (function () use ($user) { try { return (db_count('SELECT COUNT(*) FROM content_designs WHERE user_id = ?', [$user['id']]) + db_count('SELECT COUNT(*) FROM studio_designs WHERE user_id = ?', [$user['id']])) > 0; } catch (\Throwable $e) { return false; } })();
$onboardSteps = [
    ['done' => $hasBrand,   'title' => 'اصنع هويتك',        'desc' => 'عرّف الـ AI ببيزنسك: الاسم، الجمهور، الألوان، اللوجو — أو سيب مساعد الهوية يسألك ويظبطها',  'url' => 'brand-profile.php',  'btn' => '◈ ابدأ هويتك', 'alt_url' => 'brand-agent.php', 'alt_btn' => '🤖 بالمساعد الذكي'],
    ['done' => $hasPlan,    'title' => 'اعمل خطة إعلانية',  'desc' => 'الـ AI يقترح أفكار شهرية متنوعة، تختار منها، ويتوزعوا على تقويم',                             'url' => 'content-plan.php',   'btn' => '🗓 خطة جديدة'],
    ['done' => $hasContent, 'title' => 'اكتب إعلانك',       'desc' => 'ولّد منشور كامل بهاشتاجات وCTA — بأسلوب ولهجة براندك',                                        'url' => 'create-content.php', 'btn' => '✎ اكتب أول إعلان'],
    ['done' => $hasDesign,  'title' => 'اصنع تصميمك',       'desc' => 'تصميم بألوان هويتك ولوجوك — من البوست أو من استوديو التصميم',                                'url' => 'design-studio.php',  'btn' => '✨ افتح الاستوديو'],
];
$doneCount = count(array_filter($onboardSteps, fn ($x) => $x['done']));
$showOnboarding = $doneCount < 4;

// ─── إعلانات الأدمن ───
try {
    $announcements = db_all(
        'SELECT * FROM announcements WHERE is_active = 1
         AND (starts_at IS NULL OR starts_at <= CURDATE())
         AND (ends_at IS NULL OR ends_at >= CURDATE())
         ORDER BY sort_order, id DESC LIMIT 6'
    );
} catch (\Throwable $e) {
    $announcements = [];
}

$active = 'dashboard';
$page_title = 'الرئيسية';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>

    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <!-- Greeting Hero -->
        <div class="greet-card">
            <span class="chip chip-mint">◐ جلسة العمل نشطة</span>
            <h1><?= e($greeting) ?>، <?= e(explode(' ', $user['name'])[0] ?? $user['name']) ?> 👋</h1>
            <div class="sub">
                <?php if ($stats['this_week'] > 0): ?>
                    أنشأت <b><?= $stats['this_week'] ?></b> منشور هذا الأسبوع<span class="cr"> · رصيدك <b><?= $stats['balance'] ?></b> كريدت</span>
                <?php else: ?>
                    جاهز نبدأ نصنع محتوى مميز اليوم؟ ✨
                <?php endif; ?>
            </div>
            <div class="quick-actions">
                <a href="<?= url('create-content.php') ?>" class="btn">＋ إنشاء منشور جديد</a>
                <a href="<?= url('brand-profile.php') ?>" class="btn ghost">◈ تحديث الهوية</a>
            </div>

            <div class="deco-blobs" aria-hidden="true">
                <svg viewBox="0 0 240 170">
                    <defs>
                        <linearGradient id="g1" x1="0" x2="1" y1="0" y2="1">
                            <stop offset="0" stop-color="#b79af0"/>
                            <stop offset="1" stop-color="#7c6df2"/>
                        </linearGradient>
                    </defs>
                    <circle cx="180" cy="70" r="56" fill="url(#g1)" opacity=".18"/>
                    <circle cx="200" cy="120" r="28" fill="#f28b8b" opacity=".22"/>
                    <rect x="100" y="30" width="70" height="70" rx="18" fill="#7ccfb3" opacity=".18" transform="rotate(12 135 65)"/>
                    <circle cx="150" cy="150" r="8" fill="#f0b967"/>
                    <circle cx="220" cy="40" r="5" fill="#8ec2f0"/>
                </svg>
            </div>
        </div>

        <?php if ($announcements): ?>
        <!-- إعلانات وعروض المنصة -->
        <div class="auto-grid" style="margin-bottom:20px">
            <?php foreach ($announcements as $an):
                $href = $an['link_url'] ?: '';
                $isExt = $href && preg_match('#^https?://#', $href);
                $tag = $href ? 'a' : 'div';
            ?>
                <<?= $tag ?> <?= $href ? 'href="' . e($isExt ? $href : url($href)) . '"' . ($isExt ? ' target="_blank" rel="noopener"' : '') : '' ?>
                    class="card" style="padding:0;overflow:hidden;text-decoration:none;color:inherit;display:block">
                    <?php if ($an['image_path']): ?>
                        <img src="<?= url('storage/' . $an['image_path']) ?>" style="width:100%;display:block;max-height:180px;object-fit:cover" alt="<?= e($an['title']) ?>">
                    <?php endif; ?>
                    <div style="padding:12px 14px">
                        <b><?= e($an['title']) ?></b>
                        <?php if ($an['body']): ?><div class="sub" style="font-size:13px;margin-top:4px"><?= e($an['body']) ?></div><?php endif; ?>
                    </div>
                </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($showOnboarding): ?>
        <!-- رحلة البداية -->
        <div class="card" style="margin-bottom:20px;border:2px solid var(--primary-soft)">
            <div class="card-head" style="display:flex;justify-content:space-between;align-items:center">
                <h3>🚀 رحلتك مع المنصة — <?= $doneCount ?>/4</h3>
                <a href="<?= url('help.php') ?>" class="btn ghost sm">❓ الدليل الكامل</a>
            </div>
            <div style="background:var(--surface-2);border-radius:99px;height:8px;margin-bottom:16px;overflow:hidden">
                <div style="width:<?= $doneCount * 25 ?>%;height:100%;background:linear-gradient(90deg,var(--primary),var(--primary-2));transition:width .4s"></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                <?php foreach ($onboardSteps as $i => $st): ?>
                    <div style="border:1px solid <?= $st['done'] ? 'var(--mint-soft,#d7f0e5)' : 'var(--line)' ?>;border-radius:14px;padding:14px;<?= $st['done'] ? 'background:var(--mint-soft,#eefaf4)' : '' ?>">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                            <span style="width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:bold;<?= $st['done'] ? 'background:#2a7d5f;color:#fff' : 'background:var(--primary-soft);color:var(--primary-ink)' ?>">
                                <?= $st['done'] ? '✓' : $i + 1 ?>
                            </span>
                            <b style="font-size:14px"><?= e($st['title']) ?></b>
                        </div>
                        <p class="sub" style="font-size:12px;min-height:48px"><?= e($st['desc']) ?></p>
                        <?php if (!$st['done']): ?>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <a href="<?= url($st['url']) ?>" class="btn sm"><?= e($st['btn']) ?></a>
                                <?php if (!empty($st['alt_url'])): ?>
                                    <a href="<?= url($st['alt_url']) ?>" class="btn ghost sm"><?= e($st['alt_btn']) ?></a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="chip chip-mint">تمام ✓</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>


        <!-- KPIs -->
        <div class="kpis">
            <div class="kpi">
                <div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)">◇</div>
                <div>
                    <b class="cr"><?= $stats['balance'] ?></b><b class="cr-alt"><?= function_exists('plan_usage') ? (int) plan_usage((int) ($user['id'] ?? 0))['pct'] : 0 ?>%</b>
                    <small><span class="cr">رصيد الكريدت</span><span class="cr-alt">استهلاك الباقة</span></small>
                </div>
                <span class="trend <?= $stats['balance'] < 5 ? 'down' : 'up' ?>">
                    <?= $stats['balance'] < 5 ? 'منخفض!' : 'متاح' ?>
                </span>
            </div>

            <div class="kpi">
                <div class="ico" style="background:var(--mint-soft);color:#2a7d5f">▤</div>
                <div>
                    <b><?= $stats['total_content'] ?></b>
                    <small>إجمالي المنشورات</small>
                </div>
            </div>

            <div class="kpi">
                <div class="ico" style="background:var(--sky-soft);color:#2f6bb0">⟲</div>
                <div>
                    <b><?= $stats['regen_count'] ?></b>
                    <small>إعادة توليد</small>
                </div>
            </div>

            <div class="kpi">
                <div class="ico" style="background:var(--amber-soft);color:#a06c1e">●</div>
                <div>
                    <b class="cr"><?= $stats['total_spent'] ?></b><b class="cr-alt"><?= (int) ($stats['total_content'] ?? 0) ?></b>
                    <small class="cr">كريدت مستهلك</small><small class="cr-alt">محتوى اتعمل</small>
                </div>
            </div>
        </div>

        <!-- Three columns: Recent + Brand Setup + Quick Tips -->
        <div class="split split-flex" style="--c1:1.5fr;--c2:1fr;gap:20px;align-items:flex-start">

            <!-- Recent content -->
            <div class="card">
                <div class="card-head">
                    <h3>آخر منشوراتك <span class="count">• <?= count($recent) ?> منشور<?= count($recent) === 1 ? '' : 'ات' ?></span></h3>
                    <a href="<?= url('content-history.php') ?>" class="text-mute" style="font-size:12px">عرض الكل ←</a>
                </div>

                <?php if (empty($recent)): ?>
                    <div class="empty">
                        <div class="ico">✎</div>
                        <h3>لسه ما أنشأتش محتوى</h3>
                        <p>ابدأ أول منشور لك واتفرج على إنتاجية الذكاء الاصطناعي</p>
                        <a href="<?= url('create-content.php') ?>" class="btn">＋ إنشاء أول منشور</a>
                    </div>
                <?php else: ?>
                    <div style="display:grid;gap:10px">
                        <?php foreach ($recent as $c): ?>
                            <a href="<?= url('content-view.php?id=' . $c['id']) ?>"
                               style="display:flex;align-items:center;gap:14px;padding:14px;border-radius:14px;background:var(--surface-2);transition:all .15s;cursor:pointer"
                               onmouseover="this.style.background='var(--primary-soft)'"
                               onmouseout="this.style.background='var(--surface-2)'">
                                <div style="width:40px;height:40px;border-radius:12px;background:var(--primary-soft);color:var(--primary-ink);display:grid;place-items:center;font-size:16px;flex-shrink:0">
                                    📝
                                </div>
                                <div style="flex:1;min-width:0">
                                    <div style="display:flex;gap:6px;margin-bottom:4px;flex-wrap:wrap">
                                        <span class="chip chip-primary"><?= e(content_type_label($c['content_type'])) ?></span>
                                        <span class="chip chip-line"><?= e(platform_label($c['platform'])) ?></span>
                                        <span class="chip <?= e(status_chip($c['status'])) ?>"><?= e(status_label($c['status'])) ?></span>
                                    </div>
                                    <div style="font-size:13px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                        <?= e(str_limit(strip_tags($c['generated_text'] ?? ''), 90)) ?>
                                    </div>
                                </div>
                                <div style="text-align:start;flex-shrink:0">
                                    <div style="font-size:11px;color:var(--mute)"><?= e(time_ago($c['created_at'])) ?></div>
                                    <div style="font-size:11px;color:var(--primary-ink);margin-top:2px"><?= $c['credits_used'] ?> ◇</div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right rail -->
            <div style="display:flex;flex-direction:column;gap:18px">

                <!-- Credit ring -->
                <div class="credit-widget">
                    <?php $__crShow = !function_exists('credits_show_numbers') || credits_show_numbers(); ?>
                    <span class="label"><?= $__crShow ? 'الرصيد المتبقي' : 'المتبقي من باقة الشهر' ?></span>
                    <div class="ring">
                        <?php
                        $maxCredit = max($stats['balance'] + $stats['total_spent'], 20);
                        $percent = $__crShow ? min(100, ($stats['balance'] / $maxCredit) * 100) : 100 - (int) plan_usage((int) $user['id'])['pct'];
                        $offset = 314 - ($percent * 314 / 100);
                        ?>
                        <svg width="130" height="130" viewBox="0 0 130 130">
                            <circle cx="65" cy="65" r="50" stroke="#eceaf7" stroke-width="10" fill="none"/>
                            <circle cx="65" cy="65" r="50" stroke="#7c6df2" stroke-width="10" fill="none"
                                    stroke-dasharray="314" stroke-dashoffset="<?= $offset ?>" stroke-linecap="round"/>
                        </svg>
                        <div class="mid">
                            <div>
                                <b><?= $__crShow ? $stats['balance'] : (int) round($percent) . '%' ?></b>
                                <span><?= $__crShow ? 'كريدت متاح' : 'متبقي' ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="footnote cr">
                        كل توليد يكلف <b><?= cost_for('content_generation_cost') ?> كريدت</b>
                    </div>
                    <div style="margin-top:12px">
                        <a href="<?= url('credits.php') ?>" class="btn soft full sm">عرض السجل</a>
                    </div>
                </div>

                <!-- Brand setup card -->
                <div class="card compact">
                    <div class="card-head">
                        <h3 style="font-size:14px">حالة الهوية</h3>
                        <span class="count"><?= $brandSetup ?>%</span>
                    </div>

                    <div style="height:8px;background:var(--line);border-radius:4px;overflow:hidden;margin-bottom:14px">
                        <div style="height:100%;width:<?= $brandSetup ?>%;background:linear-gradient(90deg,var(--primary),var(--mint));border-radius:4px"></div>
                    </div>

                    <?php if ($brandSetup < 100): ?>
                        <div class="alert warning" style="font-size:12px;margin-bottom:10px">
                            ⚠ كمّل بيانات هويتك علشان جودة المحتوى تكون أعلى
                        </div>
                    <?php endif; ?>

                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:8px">
                        <span class="text-mute">صور البراند</span>
                        <span><b><?= $brandImageCount ?></b> / <?= MAX_BRAND_IMAGES ?></span>
                    </div>

                    <a href="<?= url('brand-profile.php') ?>" class="btn ghost full sm">
                        تحديث الهوية ←
                    </a>
                </div>

            </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
