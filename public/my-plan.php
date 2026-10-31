<?php
/**
 * Spread AI — «باقتي»: الاشتراك والترقية · الرصيد والاستهلاك · ادع واكسب في صفحة واحدة
 *
 * فوق: «اشتراك» (مفيش باقة) أو «ترقية» (باقة شغالة) — اسم الباقة وأيامها والباقي من الكريدت والتصميمات والحد الأسبوعي
 * تحت: 3 تبويبات بتعرض نفس الصفحات القديمة جوّاها (packages.php · credits.php · referrals.php في وضع BL_EMBED)
 * الروابط القديمة بتتحوّل هنا (plan_page_unified = 1)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/billing.php';

require_login();
$user = current_user();
$uid = (int) $user['id'];
define('BL_EMBED', true);

$refOn = get_setting('referral_enabled', '1') === '1';
$tabs = ['plans' => ['💎', 'الاشتراك والترقية'], 'balance' => ['◔', 'الرصيد والاستهلاك']] + ($refOn ? ['refer' => ['🎁', 'ادع واكسب']] : []);
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'plans';

/** صفحة قديمة جوه تبويب — في scope لوحدها عشان متغيراتها ماتتلخبطش مع بعض */
function bl_embed(string $file): string
{
    ob_start();
    include $file;
    return (string) ob_get_clean();
}
$panels = [];
$panels['plans'] = bl_embed(__DIR__ . '/packages.php');
$panels['balance'] = bl_embed(__DIR__ . '/credits.php');
if ($refOn) $panels['refer'] = bl_embed(__DIR__ . '/referrals.php');

// الملخص فوق
$pu = function_exists('plan_usage') ? plan_usage($uid, true) : null;
$plan = $pu['plan'] ?? [];
$paid = $pu && empty($plan['synthetic']);
$crShow = !function_exists('credits_show_numbers') || credits_show_numbers();
$units = [];
foreach (($pu['units'] ?? []) as $u) $units[$u['key']] = $u;
$wkC = function_exists('pkg_weekly_credits') ? pkg_weekly_credits($uid) : null;
$wkD = function_exists('pkg_weekly_designs') ? pkg_weekly_designs($uid) : null;
$tier = function_exists('pkg_quality_tier') && $paid ? (pkg_tiers()[pkg_quality_tier($uid)] ?? '') : '';
$pendingN = billing_ready() ? (int) (db_one('SELECT COUNT(*) n FROM payment_requests WHERE user_id = ? AND status IN ("pending","under_review")', [$uid])['n'] ?? 0) : 0;
$hasAddons = function_exists('addon_packs') && $paid && addon_packs();

$active = 'my-plan';
$page_title = 'باقتي';
$use_app = true;   // SpreadAPI (صفحة الدفع/الأكواد جوه التبويبات)
include __DIR__ . '/../templates/header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>
        <?= render_flash() ?>

        <section class="card mp-hero<?= $paid ? '' : ' new' ?>" aria-labelledby="mp-h">
            <div class="mp-hero-main">
                <small class="mp-kicker"><?= $paid ? 'ترقية' : 'اشتراك' ?></small>
                <?php if ($paid): ?>
                    <h1 id="mp-h"><?= e((string) $plan['name']) ?><?= $tier !== '' ? ' <span class="mp-tier">⭐ ' . e($tier) . '</span>' : '' ?></h1>
                    <p class="sub">شغالة لحد <b><?= e((string) $pu['ends_label']) ?></b> · باقي <b><?= (int) $pu['days_left'] ?></b> يوم</p>
                <?php else: ?>
                    <h1 id="mp-h">اشترك وابدأ شغلك على Spread AI</h1>
                    <p class="sub">اختار الباقة والمدة اللي تناسبك — الكريدت للكتابة والأفكار والبحث، والتصميمات بعدد في الباقة.</p>
                <?php endif; ?>
                <div class="mp-acts">
                    <?php if ($paid): ?>
                        <button type="button" class="btn" onclick="mpTab('plans', 'pkg-top')"><?= (int) $pu['days_left'] <= 7 ? 'جدّد أو رقّي باقتك' : 'رقّي باقتك' ?></button>
                        <?php if ($hasAddons): ?><button type="button" class="btn ghost" onclick="mpTab('plans', 'addons')">➕ زوّد باقتك</button><?php endif; ?>
                    <?php else: ?>
                        <button type="button" class="btn" onclick="mpTab('plans', 'pkg-top')">اشترك دلوقتي</button>
                    <?php endif; ?>
                    <a class="btn ghost" href="<?= url('payments.php') ?>">طلبات الدفع<?= $pendingN ? ' (' . $pendingN . ')' : '' ?></a>
                </div>
            </div>
            <?php if ($pu): ?>
            <div class="mp-meters">
                <div class="mp-m">
                    <span><?= $crShow ? 'الكريدت' : 'استهلاك الباقة' ?></span>
                    <b><?= $crShow ? (int) $pu['credits']['balance'] : (int) $pu['pct'] . '%' ?></b>
                    <i class="mp-bar"><i style="width:<?= (int) $pu['pct'] ?>%"></i></i>
                </div>
                <?php if (isset($units['designs'])): $d = $units['designs']; ?>
                <div class="mp-m">
                    <span>التصميمات</span>
                    <b><?= $d['open'] ? 'مفتوحة' : (int) $d['left'] . ' <small>من ' . (int) $d['limit'] . '</small>' ?></b>
                    <?php if (!$d['open']): ?><i class="mp-bar"><i style="width:<?= (int) $d['pct'] ?>%"></i></i><?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($wkC || $wkD): ?>
                <div class="mp-m">
                    <span>الحد الأسبوعي (أسبوع <?= (int) ($wkC['week'] ?? $wkD['week']) ?>)</span>
                    <b><?= $wkC ? ($crShow ? (int) $wkC['left'] . ' كريدت' : (int) round(100 * $wkC['left'] / max(1, $wkC['allow'])) . '% متاح') : '' ?><?= $wkC && $wkD ? ' · ' : '' ?><?= $wkD ? (int) $wkD['left'] . ' تصميم' : '' ?></b>
                    <small class="sub">بيزيد يوم <?= e(fmt_date((string) ($wkC['next'] ?? $wkD['next']))) ?> — واللي ماتصرفش بيفضل</small>
                </div>
                <?php endif; ?>
                <?php if (isset($units['research']) && (!function_exists('pkg_research_allowed') || pkg_research_allowed($uid))): $r = $units['research']; ?>
                <div class="mp-m"><span>الأبحاث</span><b><?= $r['open'] ? 'مفتوحة' : (int) $r['left'] . ' <small>من ' . (int) $r['limit'] . '</small>' ?></b></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <div class="mp-tabs" role="tablist" aria-label="باقتي">
            <?php foreach ($tabs as $k => [$ic, $lbl]): ?>
                <button type="button" role="tab" id="mpt-<?= $k ?>" aria-controls="mpp-<?= $k ?>" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>" class="mp-tab<?= $k === $tab ? ' on' : '' ?>" onclick="mpTab('<?= $k ?>')"><?= $ic ?> <?= e($lbl) ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($tabs as $k => $_): ?>
            <div class="mp-panel" role="tabpanel" id="mpp-<?= $k ?>" aria-labelledby="mpt-<?= $k ?>" <?= $k === $tab ? '' : 'hidden' ?>>
                <?php if ($k === 'plans'): ?><span id="pkg-top"></span><?php endif; ?>
                <?= $panels[$k] ?>
            </div>
        <?php endforeach; ?>
    </main>
</div>
<script>
function mpTab(k, anchor) {
    document.querySelectorAll('.mp-tab').forEach(b => { const on = b.id === 'mpt-' + k; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    document.querySelectorAll('.mp-panel').forEach(p => { p.hidden = p.id !== 'mpp-' + k; });
    try { const u = new URL(location.href); u.searchParams.set('tab', k); history.replaceState(null, '', u.toString() + (anchor ? '#' + anchor : '')); } catch (e) {}
    const el = anchor ? document.getElementById(anchor) : null;
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
// رابط فيه #pkg-… أو #addons ← التبويب الصح
(function () {
    const h = location.hash.slice(1);
    if (!h) return;
    const el = document.getElementById(h);
    const p = el && el.closest('.mp-panel');
    if (p) mpTab(p.id.replace('mpp-', ''), h);
})();
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
