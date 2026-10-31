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
// كود خصم جاي من الموقع (عرض فيه كود) ← بيتنقل لصفحة الدفع وبيتطبّق هناك بعد التحقق من السيرفر
$promoQ = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['code'] ?? '')));
$promoQ = $promoQ !== '' ? '&code=' . urlencode(mb_substr($promoQ, 0, 40)) : '';

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
                // المدد (شهري · 3 شهور · سنة …) — الكارت بيبدأ بأول مدة والعميل بيبدّل
                $vars = function_exists('pkg_prices') ? array_values(array_filter(array_map(fn($x) => pkg_variant($p, (int) $x['id']), pkg_prices((int) $p['id'])))) : [];
                if ($vars) $p = $vars[0];
                $q = function_exists('plan_quotas_decode') ? array_filter(plan_quotas_decode($p['quotas_json'] ?? null)) : [];
            ?>
            <article class="card bl-plan<?= !empty($p['is_featured']) ? ' featured' : '' ?><?= $isCur ? ' current' : '' ?>" id="pkg-<?= (int) $p['id'] ?>">
                <div class="bl-plan-top">
                    <b class="bl-plan-name"><?= e($p['name']) ?></b>
                    <?php if ($isCur): ?><em class="bl-tag ok">باقتك الحالية</em>
                    <?php elseif ($isPend): ?><em class="bl-tag warn">طلبك بيتراجع</em>
                    <?php elseif (!empty($p['badge'])): ?><em class="bl-tag"><?= e($p['badge']) ?></em><?php endif; ?>
                </div>
                <?php if (count($vars) > 1): ?>
                <div class="bl-durs" role="radiogroup" aria-label="المدة">
                    <?php foreach ($vars as $vi => $v): ?>
                        <button type="button" class="bl-dur<?= $vi ? '' : ' on' ?>" role="radio" aria-checked="<?= $vi ? 'false' : 'true' ?>"
                            data-price="<?= e(number_format((float) $v['price_egp'], 0)) ?>" data-compare="<?= $v['compare_egp'] ? e(number_format((float) $v['compare_egp'], 0)) : '' ?>"
                            data-credits="<?= (int) $v['credits'] ?>" data-days="<?= e(pkg_duration_text((int) $v['validity_days'])) ?>" data-designs="<?= (int) (plan_quotas_decode($v['quotas_json'])['designs'] ?? 0) ?>" data-research="<?= (int) (plan_quotas_decode($v['quotas_json'])['research'] ?? 0) ?>"
                            data-href="<?= e(url('checkout.php?package=' . (int) $v['id'] . '&price=' . (int) $v['price_id'] . $promoQ)) ?>" onclick="blDur(this)"><?= e($v['duration_label']) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="bl-price"><s class="sub bl-was" <?= empty($p['compare_egp']) ? 'hidden' : '' ?>><?= !empty($p['compare_egp']) ? number_format((float) $p['compare_egp'], 0) : '' ?></s> <b class="bl-p"><?= number_format((float) $p['price_egp'], 0) ?></b> <span>جنيه</span></div>
                <div class="bl-meta">
                    <?php if ($crShow): ?><span>◇ <b class="bl-c"><?= (int) $p['credits'] ?></b> كريدت</span><?php endif; ?>
                    <span>⏳ <b class="bl-d"><?= e(function_exists('pkg_duration_text') ? pkg_duration_text((int) ($p['validity_days'] ?? 30)) : (int) ($p['validity_days'] ?? 30) . ' يوم') ?></b></span>
                </div>
                <?php if ((int) ($p['bonus_credits'] ?? 0) > 0 || !empty($p['bonus_note'])): ?>
                    <div class="bl-bonus">🎁 <?= (int) ($p['bonus_credits'] ?? 0) > 0 ? '+' . (int) $p['bonus_credits'] . ' كريدت هدية' : '' ?><?= !empty($p['bonus_note']) ? ((int) ($p['bonus_credits'] ?? 0) > 0 ? ' · ' : '') . e($p['bonus_note']) : '' ?></div>
                <?php endif; ?>
                <?php if (!empty($p['description'])): ?><p class="sub bl-desc"><?= e($p['description']) ?></p><?php endif; ?>
                <?php if ($q || $feats): ?>
                <ul class="bl-feats">
                    <?php foreach ($q as $k => $n): ?><li><?= plan_units()[$k][0] ?? '•' ?> <b class="<?= $k === 'designs' ? 'bl-ds' : ($k === 'research' ? 'bl-rs' : '') ?>"><?= (int) $n ?></b> <?= e(plan_units()[$k][1] ?? $k) ?></li><?php endforeach; ?>
                    <?php if (function_exists('pkg_tiers') && isset($p['quality_tier'])): ?><li>⭐ جودة <?= e(pkg_tiers()[$p['quality_tier']] ?? 'عادي') ?></li><?php endif; ?>
                    <?php if (isset($p['allow_research']) && !(int) $p['allow_research']): ?><li class="sub">من غير بحث عميق</li><?php endif; ?>
                    <?php if ((int) ($p['weekly_credits'] ?? 0) > 0): ?><li>⏱ لحد <?= (int) $p['weekly_credits'] ?> كريدت في الأسبوع (اللي مايتصرفش بيفضل)</li><?php endif; ?>
                    <?php foreach (array_slice($feats, 0, 10) as $f): ?><li>✓ <?= e($f) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <div class="bl-plan-act">
                    <?php if ($isPend): ?>
                        <a href="<?= url('payments.php') ?>" class="btn ghost full">تابع طلبك</a>
                    <?php elseif ($hasMethods): ?>
                        <a href="<?= url('checkout.php?package=' . (int) $p['id'] . (!empty($p['price_id']) ? '&price=' . (int) $p['price_id'] : '') . $promoQ) ?>" class="btn full bl-go"><?= $isCur ? 'جدّد الباقة' : 'اشترك دلوقتي' ?></a>
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

        <?php $__addons = function_exists('addon_packs') ? addon_packs() : []; if ($__addons && $hasMethods): $__blocked = addon_blocked($uid); ?>
        <section class="bl-addons" id="addons">
            <h2>➕ زوّد باقتك</h2>
            <p class="sub"><?= $__blocked ? e($__blocked) : 'تصميمات زيادة لحد آخر باقتك · كريدت مساعد صالح شهر استخدام (لو الباقة خلصت بيقف ويكمل مع الجديدة)' ?></p>
            <div class="bl-plans">
                <?php foreach ($__addons as $a): ?>
                <article class="card bl-plan">
                    <b class="bl-plan-name"><?= e(addon_label($a)) ?></b>
                    <div class="bl-price"><b><?= number_format((float) $a['price_egp'], 0) ?></b> <span>جنيه</span></div>
                    <?php if (!empty($a['description'])): ?><p class="sub bl-desc"><?= e($a['description']) ?></p><?php endif; ?>
                    <div class="bl-plan-act"><?php if ($__blocked): ?><button class="btn ghost full" disabled>محتاج باقة شغالة</button>
                        <?php else: ?><a href="<?= url('checkout.php?addon=' . (int) $a['id']) ?>" class="btn full ghost">اشتري</a><?php endif; ?></div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
<script>
// تبديل المدة في كارت الباقة: السعر والكريدت والمدة واللينك
function blDur(b) {
    const card = b.closest('.bl-plan');
    card.querySelectorAll('.bl-dur').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
    const set = (sel, v) => { const el = card.querySelector(sel); if (el) el.textContent = v; };
    set('.bl-p', b.dataset.price); set('.bl-c', b.dataset.credits); set('.bl-d', b.dataset.days); set('.bl-ds', b.dataset.designs); set('.bl-rs', b.dataset.research);
    const was = card.querySelector('.bl-was'); if (was) { was.textContent = b.dataset.compare; was.hidden = !b.dataset.compare; }
    const go = card.querySelector('.bl-go'); if (go) go.href = b.dataset.href;
}
</script>
    </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
