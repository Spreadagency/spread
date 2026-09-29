<?php
/**
 * Spread AI — الباقات (المرحلة 10)
 * كل باقة: الاسم · السعر · الكريدت · المدة · المميزات · الهدية · الحالة · زرار الاشتراك
 * الاشتراك مابيضيفش كريدت — بيفتح صفحة الدفع وبيعمل «طلب دفع» يراجعه الأدمن.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

require_login();
$user = current_user();
$uid = (int) $user['id'];

$packages = billing_packages();
$crShow = !function_exists('credits_show_numbers') || credits_show_numbers();
$pu = function_exists('plan_usage') ? plan_usage($uid) : null;
$curPkg = $pu && empty($pu['plan']['synthetic']) ? (int) ($pu['plan']['package_id'] ?? 0) : 0;
$pending = billing_ready() ? db_all('SELECT id, plan_name, status, final_amount, created_at, info_request FROM payment_requests
                                     WHERE user_id = ? AND status IN ("pending","under_review") ORDER BY id DESC', [$uid]) : [];
$pendingPkgs = billing_ready() ? array_column(db_all('SELECT package_id FROM payment_requests WHERE user_id = ? AND status IN ("pending","under_review")', [$uid]), 'package_id') : [];
$paymobReady = get_setting('paymob_api_key', '') && get_setting('paymob_iframe_id', '');
$hasMethods = (bool) billing_methods();

$active = 'packages';
$page_title = 'الباقات';
include __DIR__ . '/../templates/header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <div class="page-head">
            <h1>الباقات</h1>
            <div class="sub">اختار الباقة المناسبة — بعد التحويل ارفع الإيصال، وأول ما نتأكد الباقة بتتفعّل والكريدت يوصل محفظتك.</div>
        </div>

        <?php if ($pu): ?>
        <div class="card bl-current">
            <div>
                <small class="sub">باقتك الحالية</small>
                <b><?= e($pu['plan']['name']) ?></b>
                <span class="sub"><?= !empty($pu['plan']['synthetic']) ? '' : 'لحد ' . e($pu['ends_label']) . ' · باقي ' . (int) $pu['days_left'] . ' يوم' ?></span>
            </div>
            <div class="bl-current-num">
                <?php if ($crShow): ?><b><?= (int) credits_balance($uid) ?></b><small>كريدت في محفظتك</small>
                <?php else: ?><b><?= (int) $pu['pct'] ?>%</b><small>استهلاك الباقة</small><?php endif; ?>
            </div>
            <a href="<?= url('payments.php') ?>" class="btn ghost sm">طلبات الدفع ←</a>
        </div>
        <?php endif; ?>

        <?php foreach ($pending as $p): ?>
            <div class="alert <?= $p['info_request'] ? 'warning' : 'info' ?> bl-pending">
                <?= $p['info_request'] ? '⚠️ محتاجين منك معلومة بخصوص' : '⏳' ?> طلب «<?= e($p['plan_name']) ?>» #<?= (int) $p['id'] ?> — <?= e(billing_status_label($p['status'])) ?>
                <a href="<?= url('payments.php?id=' . (int) $p['id']) ?>"><?= $p['info_request'] ? 'رد دلوقتي ←' : 'تفاصيل الطلب ←' ?></a>
            </div>
        <?php endforeach; ?>

        <?php if (!$packages): ?>
            <div class="card"><p class="sub">مفيش باقات متاحة حاليًا — كلّم الدعم.</p></div>
        <?php else: ?>
        <div class="bl-plans">
            <?php foreach ($packages as $p):
                $feats = billing_features($p);
                $isCur = $curPkg === (int) $p['id'];
                $isPend = in_array((string) $p['id'], array_map('strval', $pendingPkgs), true);
                $q = function_exists('plan_quotas_decode') ? array_filter(plan_quotas_decode($p['quotas_json'] ?? null)) : [];
            ?>
            <article class="card bl-plan<?= !empty($p['is_featured']) ? ' featured' : '' ?><?= $isCur ? ' current' : '' ?>" id="pkg-<?= (int) $p['id'] ?>">
                <div class="bl-plan-top">
                    <b class="bl-plan-name"><?= e($p['name']) ?></b>
                    <?php if ($isCur): ?><em class="bl-tag ok">باقتك الحالية</em>
                    <?php elseif ($isPend): ?><em class="bl-tag warn">طلبك بيتراجع</em>
                    <?php elseif (!empty($p['badge'])): ?><em class="bl-tag"><?= e($p['badge']) ?></em><?php endif; ?>
                </div>
                <div class="bl-price"><b><?= number_format((float) $p['price_egp'], 0) ?></b> <span>جنيه</span></div>
                <div class="bl-meta">
                    <?php if ($crShow): ?><span>◇ <b><?= (int) $p['credits'] ?></b> كريدت</span><?php endif; ?>
                    <span>⏳ <b><?= (int) ($p['validity_days'] ?? 30) ?></b> يوم</span>
                </div>
                <?php if ((int) ($p['bonus_credits'] ?? 0) > 0 || !empty($p['bonus_note'])): ?>
                    <div class="bl-bonus">🎁 <?= (int) ($p['bonus_credits'] ?? 0) > 0 ? '+' . (int) $p['bonus_credits'] . ' كريدت هدية' : '' ?><?= !empty($p['bonus_note']) ? ((int) ($p['bonus_credits'] ?? 0) > 0 ? ' · ' : '') . e($p['bonus_note']) : '' ?></div>
                <?php endif; ?>
                <?php if (!empty($p['description'])): ?><p class="sub bl-desc"><?= e($p['description']) ?></p><?php endif; ?>
                <?php if ($q || $feats): ?>
                <ul class="bl-feats">
                    <?php foreach ($q as $k => $n): ?><li><?= plan_units()[$k][0] ?? '•' ?> <b><?= (int) $n ?></b> <?= e(plan_units()[$k][1] ?? $k) ?></li><?php endforeach; ?>
                    <?php foreach (array_slice($feats, 0, 10) as $f): ?><li>✓ <?= e($f) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div class="bl-plan-act">
                    <?php if ($isPend): ?>
                        <a href="<?= url('payments.php') ?>" class="btn ghost full">تابع طلبك</a>
                    <?php elseif ($hasMethods): ?>
                        <a href="<?= url('checkout.php?package=' . (int) $p['id']) ?>" class="btn full"><?= $isCur ? 'جدّد الباقة' : 'اشترك دلوقتي' ?></a>
                    <?php elseif ($paymobReady): ?>
                        <a href="<?= url('credits.php#pkg-' . (int) $p['id']) ?>" class="btn full">ادفع أونلاين</a>
                    <?php else: ?>
                        <button class="btn ghost full" disabled>الدفع مش متاح حاليًا</button>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <p class="sub bl-how">① اختار الباقة ← ② حوّل المبلغ (إنستاباي / فودافون كاش) ← ③ ارفع صورة الإيصال ← ④ بنراجع ونفعّل الباقة، وبيوصلك إشعار.</p>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
