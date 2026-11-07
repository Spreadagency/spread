<?php
/**
 * Spread AI — الأدمن: تقرير تكلفة الحملة (خطة الحملات · المرحلة 0) — قراية بس
 * تكلفة حملة واحدة (المتوسط وأقصى حالة) لكل باقة على موديلات مستوى الجودة بتاعها، والتكلفة الشهرية قدام السعر.
 * بيه بتحدد أرقام الحملات في الباقات قبل ما وحدة «الحملة» تنزل (§11.6 في docs/PLAN_CAMPAIGNS_V2.md).
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/campaign-cost.php';

require_admin();
require_admin_can('view_costs');

$rep = usage_ready() ? cc_report($_GET) : null;
$sh = $rep['shape'] ?? cc_shape();
$tierNames = function_exists('pkg_tiers') ? pkg_tiers() : [];
$srcLbl = ['measured' => ['chip-mint', 'متوسط حقيقي'], 'estimate' => ['chip-line', 'تقدير بالسعر'], 'default' => ['chip-amber', 'سعر افتراضي'], 'unknown' => ['chip-coral', 'مجهولة']];
$mColor = fn(?float $m) => $m === null ? '#5B6B82' : ($m < 0 ? '#C2362E' : ($m < 30 ? '#B7791F' : '#0B8F83'));
$fields = [
    'campaigns' => ['حملات في الشهر', 0, 30, 1], 'posts' => ['منشورات في الحملة', 0, 60, 1], 'carousel_pct' => ['نسبة الكاروسيل %', 0, 100, 5],
    'slides' => ['شرايح الكاروسيل', 1, 10, 1], 'videos' => ['سكريبتات فيديو', 0, 20, 1], 'edits' => ['رصيد التعديلات', 0, 100, 1],
    'improve' => ['نسبة التحسين التلقائي', 0, 1, 0.05], 'plan_regens' => ['إعادات الخطة', 0, 5, 1], 'retry' => ['معامل المحاولات التانية', 1, 3, 0.1],
];

$active = 'campaign_cost';
$page_title = 'تكلفة الحملة';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head">
            <div>
                <h1>🧮 تكلفة الحملة</h1>
                <div class="sub">حملة واحدة بالشكل اللي تحت: المتوسط وأقصى حالة لكل باقة، والتكلفة الشهرية قدام سعر الباقة. التقرير ده بيقرا بس ومابيغيّرش أي حاجة.</div>
            </div>
        </div>

        <?php if (!$rep): ?>
            <div class="alert warning">لازم تشغّل ترحيل «8-أ» (تكاليف الـ AI) الأول من <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.</div>
        <?php else: ?>
        <form method="GET" class="card" style="margin-bottom:16px">
            <div class="a2-h"><h3>شكل الحملة</h3><span class="a2-en">سعر الدولار: <?= a2n($rep['fx'], 2) ?> ج</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
                <?php foreach ($fields as $k => [$lbl, $min, $max, $step]): ?>
                    <label class="field" style="margin:0"><small><?= e($lbl) ?></small>
                        <input type="number" name="<?= e($k) ?>" class="input" min="<?= $min ?>" max="<?= $max ?>" step="<?= $step ?>" value="<?= e((string) $sh[$k]) ?>"></label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:10px"><button class="btn sm">احسب</button> <a href="<?= url('admin/campaign-cost.php') ?>" class="btn ghost sm">الافتراضي</a></div>
        </form>

        <div class="card" style="margin-bottom:16px">
            <div class="a2-h"><h3>لكل باقة</h3><span class="a2-en"><?= (int) $sh['campaigns'] ?> حملة في الشهر</span></div>
            <?php if (!$rep['rows']): ?><div class="a2-empty">مفيش باقات شغالة</div><?php else: ?>
            <div class="a2-tw"><table class="a2-tbl">
                <thead><tr><th>الباقة</th><th>سعر الشهر</th><th>الحملة<br><small>متوسط / أقصى</small></th><th>الشهر<br><small>متوسط / أقصى</small></th><th>الهامش<br><small>متوسط / أقصى</small></th><th>الصور في الحملة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rep['rows'] as $r): ?>
                    <tr>
                        <td><b><?= e($r['name']) ?></b> <span class="a2-muted">· <?= e($tierNames[$r['tier']] ?? $r['tier']) ?><?= ($r['shape_source'] ?? '') === 'package' ? ' · 📣 ' . (int) $r['campaigns'] . ' حملة من حدود الباقة' : '' ?></span></td>
                        <td class="num"><?= $r['price'] !== null ? a2n($r['price'], 2) : '<span class="a2-muted">—</span>' ?></td>
                        <td class="num"><?= a2n($r['campaign_avg_egp'], 2) ?> / <?= a2n($r['campaign_worst_egp'], 2) ?></td>
                        <td class="num"><?= a2n($r['month_avg_egp'], 2) ?> / <?= a2n($r['month_worst_egp'], 2) ?></td>
                        <td class="num" style="font-weight:700"><span style="color:<?= $mColor($r['margin_avg']) ?>"><?= $r['margin_avg'] !== null ? $r['margin_avg'] . '%' : '—' ?></span> /
                            <span style="color:<?= $mColor($r['margin_worst']) ?>"><?= $r['margin_worst'] !== null ? $r['margin_worst'] . '%' : '—' ?></span></td>
                        <td class="num"><?= a2n($r['frames']) ?></td>
                        <td style="font-size:12px">
                            <?php if ($r['unknown']): ?><span class="chip chip-coral">⚠ تكلفة مجهولة: <?= e(implode('، ', $r['unknown'])) ?></span><?php endif; ?>
                            <?php if ($r['defaulted']): ?><span class="chip chip-amber" title="الموديل مالوش سعر في جدول الأسعار — حطه من «موديلات الـ AI»">سعر افتراضي: <?= e(implode('، ', $r['defaulted'])) ?></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>

        <?php foreach ($rep['steps'] as $tier => $steps): ?>
        <div class="card" style="margin-bottom:16px">
            <div class="a2-h"><h3>تكلفة كل خطوة</h3><span class="a2-en"><?= e($tierNames[$tier] ?? $tier) ?></span></div>
            <div class="a2-tw"><table class="a2-tbl">
                <thead><tr><th>الخطوة</th><th>المهمة</th><th>الموديل</th><th>التوكنز<br><small>داخل / خارج</small></th><th>الطلب الواحد</th><th>المصدر</th></tr></thead>
                <tbody>
                <?php foreach ($steps as $k => $s): [$cls, $lbl] = $srcLbl[$s['source']] ?? $srcLbl['unknown']; ?>
                    <tr>
                        <td><?= e($s['label']) ?></td>
                        <td><code><?= e($s['task']) ?></code></td>
                        <td style="font-size:12px"><?= $s['model'] !== null ? e($s['model']) : '<span class="a2-muted">مفيش موديل شغال</span>' ?></td>
                        <td class="num"><?= a2n($s['tin']) ?> / <?= a2n($s['tout']) ?></td>
                        <td class="num"><?= $s['usd'] !== null ? '$' . a2n($s['usd'], 4) . ' <span class="a2-muted">(' . a2n($s['usd'] * $rep['fx'], 2) . ' ج)</span>' : '—' ?></td>
                        <td><span class="chip <?= $cls ?>"><?= e($lbl) ?></span><?php if ($s['runs']): ?> <span class="a2-muted" style="font-size:11px"><?= a2n($s['runs']) ?> عملية</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endforeach; ?>

        <?php $cs = cc_cache_stats(30); ?>
        <div class="card">
            <div class="a2-h"><h3>الكاش (آخر 30 يوم)</h3><span class="a2-en"><?= $cs['on'] ? 'ترتيب الكاش شغال' : 'ترتيب الكاش مقفول (campaign_prompt_cache = 0)' ?></span></div>
            <?php if (!$cs['runs']): ?><div class="a2-empty">لسه مفيش طلبات حملات في السجل</div><?php else: ?>
            <p style="margin:0;line-height:2">
                <?= (int) $cs['runs'] ?> طلب (أفكار · كتابة · تقييم) · <?= a2n($cs['tokens_in'], 0) ?> توكن داخل · منهم <b><?= a2n($cs['tokens_cached'], 0) ?></b> من الكاش
                = <b style="color:<?= $cs['pct'] >= 30 ? 'var(--ok, #12805c)' : 'inherit' ?>"><?= a2n($cs['pct'], 1) ?>%</b>
            </p>
            <p class="a2-muted" style="margin:6px 0 0">الجزء الثابت (ملف البراند + الـ Skill) بقى في أول كل طلب في الحملة وبنفس الحروف — المزوّد بيحسبه بخصم لما يتكرر في دقايق. النسبة دي بتقولك التوفير الحقيقي.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="a2-h"><h3>إزاي بيتحسب</h3></div>
            <p class="a2-muted" style="margin:0;line-height:2">
                <b>المتوسط</b> = دفعات الأفكار (5 لكل دفعة) + المنشورات × (كتابة + نسبة التحسين × كتابة) + كل صورة × (كاتب التصميم + صورة) + الفيديوهات × سكريبت.<br>
                <b>أقصى حالة</b> = نفس الكلام + إعادات الخطة + تحسين لكل منشور + رصيد التعديلات كله على أغلى تعديل (تصميم كاروسيل كامل)، والمجموع × معامل المحاولات التانية.<br>
                <b>المصدر:</b> «متوسط حقيقي» من آخر 30 يوم لو فيه 5 عمليات على الأقل بتكلفة معروفة · «تقدير بالسعر» = أسعار الموديل × التوكنز المتوقعة · «سعر افتراضي» = الموديل مالوش سعر، فحط سعره من «موديلات الـ AI» · «مجهولة» = مفيش موديل شغال للمهمة ومابتدخلش الحساب.<br>
                توفير الكاش <b>مش داخل</b> في الحساب.
            </p>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
