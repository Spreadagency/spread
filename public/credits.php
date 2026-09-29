<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/billing.php';   // 10: الاشتراك بطلب دفع + إيصال

require_login();

$user = current_user();
$balance = credits_balance((int) $user['id']);
$transactions = credits_history((int) $user['id'], 100);

$totalAdded = (int) (db_one('SELECT COALESCE(SUM(amount),0) AS s FROM credit_transactions WHERE user_id = ? AND action_type = ?', [$user['id'], 'add'])['s'] ?? 0);
$totalSpent = (int) (db_one('SELECT COALESCE(SUM(amount),0) AS s FROM credit_transactions WHERE user_id = ? AND action_type IN (?, ?)', [$user['id'], 'consume', 'deduct'])['s'] ?? 0);

$packages = db_all('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY order_num ASC');
$paymobReady = get_setting('paymob_api_key', '') && get_setting('paymob_iframe_id', '');

// الدفع اليدوي (انستاباي / فودافون كاش / واتساب)
$manualPay   = get_setting('manual_pay_enabled', '1') === '1';
$payInstapay = trim((string) get_setting('pay_instapay', ''));
$payInstapayLink = trim((string) get_setting('pay_instapay_link', ''));
if ($payInstapayLink !== '' && !preg_match('#^https?://#i', $payInstapayLink)) { $payInstapayLink = 'https://' . ltrim($payInstapayLink, '/'); }
$payVodafone = trim((string) get_setting('pay_vodafone', ''));
$payWhatsapp = preg_replace('/[^0-9]/', '', (string) get_setting('pay_whatsapp', ''));
$showManual  = $manualPay && ($payInstapay || $payVodafone || $payInstapayLink) && $payWhatsapp;
// 10: طرق الدفع من الأدمن ← صفحة الدفع برفع الإيصال (بدل واتساب)
$billingOn = billing_ready() && (bool) billing_methods();
if ($billingOn) $showManual = false;
$waText      = rawurlencode('السلام عليكم، أنا ' . ($user['name'] ?? '') . ' (' . ($user['email'] ?? '') . ') — حوّلت مبلغ شحن الرصيد ومرفق صورة إثبات الدفع. الباقة: ');

// صلاحية الرصيد (محمي لو الأعمدة لسه متضافتش)
try {
    $wallet = db_one('SELECT expires_at, expired_balance, expired_at FROM credit_wallets WHERE user_id = ?', [$user['id']]);
} catch (Throwable $e) {
    $wallet = null;
}

// 8-ب: العرض بالنسبة % (الحصص الشهرية) أو بأرقام الكريدت
$crShow = !function_exists('credits_show_numbers') || credits_show_numbers();
$pu = function_exists('plan_usage') ? plan_usage((int) $user['id']) : null;

$active = 'credits';
$page_title = $crShow ? 'الرصيد' : 'الباقة والاستهلاك';
include __DIR__ . '/../templates/header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main">
        <?php include __DIR__ . '/../templates/topbar.php'; ?>

        <div class="page-head">
            <h1><?= $crShow ? 'الرصيد والاستهلاك ◇' : 'الباقة والاستهلاك ◔' ?></h1>
            <div class="sub"><?= $crShow ? 'تابع كل عمليات الكريدت والاستهلاك' : 'استهلاكك من باقة الشهر — بيتجدد مع كل دورة' ?></div>
        </div>

        <?= render_flash() ?>

        <?php if (!$crShow && $pu): ?>
        <div class="card" style="margin-bottom:20px">
            <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <h3>◔ <?= e($pu['plan']['name']) ?></h3>
                <span class="chip chip-primary">بتتجدد يوم <?= e($pu['ends_label']) ?> · باقي <?= (int) $pu['days_left'] ?> يوم</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">
                <span class="text-mute" style="font-size:13px">استهلاك الباقة</span><b style="font-size:26px"><?= (int) $pu['pct'] ?>%</b>
            </div>
            <div style="height:12px;border-radius:99px;background:var(--line);overflow:hidden"><span style="display:block;height:100%;width:<?= max(2, (int) $pu['pct']) ?>%;border-radius:99px;background:<?= $pu['pct'] >= 85 ? '#F2A93B' : 'linear-gradient(90deg,#2EE3CC,#0C87EF)' ?>"></span></div>
            <?php $__units = array_filter($pu['units'], fn($u) => $u['pct'] !== null); ?>
            <?php if ($__units): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 18px;margin-top:16px">
                <?php foreach ($__units as $u): ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:13px"><b><?= $u['emoji'] ?> <?= e($u['label']) ?></b><b><?= (int) $u['pct'] ?>%</b></div>
                        <div style="height:7px;border-radius:99px;background:var(--line);overflow:hidden;margin-top:5px"><span style="display:block;height:100%;width:<?= max(2, (int) $u['pct']) ?>%;border-radius:99px;background:<?= $u['pct'] >= 85 ? '#F2A93B' : 'linear-gradient(90deg,#2EE3CC,#0C87EF)' ?>"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($pu['pct'] >= 85): ?>
                <div class="alert warning" style="margin:14px 0 0">استخدمت <?= (int) $pu['pct'] ?>% من باقة الشهر — رقّي باقتك علشان الشغل مايتوقفش. <a href="<?= e(plan_upgrade_link()) ?>" target="_blank" rel="noopener">كلمنا واتساب ↗</a></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($packages)): ?>
        <div class="card mb-20" style="margin-bottom:20px">
            <div class="card-head"><h3><?= $crShow ? '💳 اشحن رصيدك' : '💳 الباقات' ?></h3></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
                <?php foreach ($packages as $p): ?>
                    <div id="pkg-<?= (int) $p['id'] ?>" class="pkg-card" style="border:1px solid var(--line);border-radius:14px;padding:18px;text-align:center;scroll-margin-top:90px">
                        <div style="font-weight:700;margin-bottom:6px"><?= e($p['name']) ?></div>
                        <?php if ($crShow): ?>
                        <div style="font-size:28px;font-weight:800;color:var(--primary-ink)"><?= (int) $p['credits'] ?> <span style="font-size:13px">كريدت</span></div>
                        <?php else:
                            $__q = function_exists('plan_quotas_decode') ? array_filter(plan_quotas_decode($p['quotas_json'] ?? null)) : []; ?>
                        <div style="font-size:13px;line-height:2;margin:4px 0;color:var(--ink-2)">
                            <?php foreach ($__q as $__k => $__n): ?><div><?= plan_units()[$__k][0] ?> <b><?= (int) $__n ?></b> <?= e(plan_units()[$__k][1]) ?></div><?php endforeach; ?>
                            <?php if (!$__q): ?><div>كل الخدمات</div><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-mute" style="font-size:13px;margin:6px 0 4px"><?= number_format((float) $p['price_egp'], 0) ?> جنيه</div>
                        <?php if (!empty($p['badge'])): ?>
                            <span class="chip chip-primary" style="font-size:10px"><?= e($p['badge']) ?></span>
                        <?php endif; ?>
                        <div class="text-mute" style="font-size:11.5px;margin-bottom:8px">⏳ صلاحية <?= (int) ($p['validity_days'] ?? 30) ?> يوم</div>
                        <?php if (!empty($p['description'])): ?>
                            <div class="text-mute" style="font-size:12px;margin-bottom:8px"><?= e($p['description']) ?></div>
                        <?php endif; ?>
                        <?php
                            $__feats = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) ($p['features'] ?? '')))));
                        ?>
                        <?php if ($__feats): ?>
                            <ul style="text-align:start;margin:0 16px 12px;font-size:12.5px;line-height:1.95">
                                <?php foreach (array_slice($__feats, 0, 8) as $__f): ?>
                                    <li>✓ <?= e($__f) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($billingOn): ?>
                            <a class="btn full sm" href="<?= url('checkout.php?package=' . (int) $p['id']) ?>"><?= $crShow ? 'اشحن / اشترك' : 'اشترك' ?></a>
                            <?php if ($paymobReady): ?><button class="btn ghost full sm" style="margin-top:6px" onclick="buyPackage(<?= $p['id'] ?>, this)">ادفع أونلاين بالكارت</button><?php endif; ?>
                        <?php elseif ($paymobReady): ?>
                            <button class="btn full sm" onclick="buyPackage(<?= $p['id'] ?>, this)">اشحن الآن</button>
                        <?php elseif ($showManual): ?>
                            <a class="btn full sm" target="_blank"
                               href="https://wa.me/<?= e($payWhatsapp) ?>?text=<?= $waText ?><?= rawurlencode($p['name'] . ' (' . ($crShow ? (int) $p['credits'] . ' كريدت — ' : '') . number_format((float) $p['price_egp'], 0) . ' جنيه)') ?>">
                               <?= $crShow ? 'اشحن بالتحويل 📲' : 'اشترك بالتحويل 📲' ?>
                            </a>
                        <?php else: ?>
                            <button class="btn ghost full sm" disabled title="الدفع غير مفعل حاليًا">قريبًا</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($billingOn): ?>
                <div class="field-help mt-10"><a href="<?= url('packages.php') ?>">كل تفاصيل الباقات ←</a> · <a href="<?= url('payments.php') ?>">طلبات الدفع ←</a></div>
            <?php elseif (!$paymobReady && !$showManual): ?>
                <div class="field-help mt-10">الدفع لسه مش مفعل — تواصل مع الإدارة لشحن رصيدك.</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showManual): ?>
        <div class="card" style="margin-bottom:20px">
            <div class="card-head"><h3>📲 الشحن المباشر (تحويل يدوي)</h3></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px">
                <?php if ($payInstapay || $payInstapayLink): ?>
                <div style="border:1px solid var(--line);border-radius:14px;padding:16px;text-align:center">
                    <div style="font-weight:700;margin-bottom:6px">🏦 إنستاباي InstaPay</div>
                    <?php if ($payInstapay): ?>
                        <div id="pay-insta" style="font-size:18px;font-weight:800;direction:ltr"><?= e($payInstapay) ?></div>
                        <button class="btn ghost sm" style="margin-top:8px" onclick="copyPay('pay-insta', this)">📋 نسخ</button>
                    <?php endif; ?>
                    <?php if ($payInstapayLink): ?>
                        <a class="btn full sm" style="margin-top:10px;background:#6c2bd9;border-color:#6c2bd9;color:#fff"
                           href="<?= e($payInstapayLink) ?>" target="_blank" rel="noopener">💳 ادفع بإنستاباي ↗</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($payVodafone): ?>
                <div style="border:1px solid var(--line);border-radius:14px;padding:16px;text-align:center">
                    <div style="font-weight:700;margin-bottom:6px">📱 فودافون كاش</div>
                    <div id="pay-vf" style="font-size:18px;font-weight:800;direction:ltr"><?= e($payVodafone) ?></div>
                    <button class="btn ghost sm" style="margin-top:8px" onclick="copyPay('pay-vf', this)">📋 نسخ</button>
                </div>
                <?php endif; ?>
                <div style="border:1px solid #25d366;border-radius:14px;padding:16px;text-align:center;background:#25d3660d">
                    <div style="font-weight:700;margin-bottom:6px">💬 بعد التحويل</div>
                    <div class="text-mute" style="font-size:13px;margin-bottom:10px">ابعت صورة إثبات الدفع على واتساب وهنشحن رصيدك فورًا</div>
                    <a class="btn full sm" style="background:#25d366;border-color:#25d366;color:#fff" target="_blank"
                       href="https://wa.me/<?= e($payWhatsapp) ?>?text=<?= $waText ?>">
                       واتساب: إرسال إثبات الدفع ↗
                    </a>
                </div>
            </div>
            <div class="field-help mt-10">١) اختار باقة من فوق واعرف سعرها → ٢) حوّل المبلغ على إنستاباي أو فودافون كاش → ٣) ابعت صورة التحويل واتساب → ٤) هيوصلك الرصيد وصلاحيته شهر كامل.</div>
        </div>
        <?php endif; ?>

        <?php if (!empty($wallet['expired_balance']) && (int) $wallet['expired_balance'] > 0): ?>
        <div class="alert warning" style="margin-bottom:20px">
            ⏳ عندك <b><?= $crShow ? (int) $wallet['expired_balance'] . ' كريدت' : 'رصيد متبقي من باقتك' ?></b> منتهي الصلاحية —
            جدّد اشتراكك قبل <b><?= e(date('Y-m-d', strtotime(($wallet['expired_at'] ?? 'now') . ' +3 days'))) ?></b> وهيرجعلك بالكامل مع الشحنة الجديدة.
        </div>
        <?php elseif (!empty($wallet['expires_at'])): ?>
        <div class="field-help cr" style="margin-bottom:20px">صلاحية رصيدك الحالي حتى: <b><?= e(date('Y-m-d', strtotime($wallet['expires_at']))) ?></b> — التجديد خلال 3 أيام من الانتهاء بيرجّع أي رصيد متبقي.</div>
        <?php endif; ?>

        <?php if ($crShow): ?>
        <div class="kpis">
            <div class="kpi">
                <div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)">◇</div>
                <div>
                    <b><?= $balance ?></b>
                    <small>الرصيد الحالي</small>
                </div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:var(--mint-soft);color:#2a7d5f">+</div>
                <div>
                    <b><?= $totalAdded ?></b>
                    <small>إجمالي ما تم إضافته</small>
                </div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:var(--coral-soft);color:#b0454a">−</div>
                <div>
                    <b><?= $totalSpent ?></b>
                    <small>إجمالي المستهلك</small>
                </div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:var(--amber-soft);color:#a06c1e">▤</div>
                <div>
                    <b><?= count($transactions) ?></b>
                    <small>عدد العمليات</small>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h3>سجل العمليات</h3>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="empty">
                    <div class="ico">◇</div>
                    <h3>لا توجد عمليات بعد</h3>
                    <p>هتلاقي هنا كل تفاصيل الإضافة والاستهلاك</p>
                </div>
            <?php else: ?>
                <div class="table-wrap" style="box-shadow:none">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>التاريخ</th>
                                <th>النوع</th>
                                <th>القيمة</th>
                                <th>المرجع</th>
                                <th>الملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td style="font-size:12px;color:var(--ink-2)"><?= e(fmt_date($t['created_at'], true)) ?></td>
                                    <td>
                                        <?php if ($t['action_type'] === 'add'): ?>
                                            <span class="chip chip-mint">+ إضافة</span>
                                        <?php elseif ($t['action_type'] === 'consume'): ?>
                                            <span class="chip chip-amber">استهلاك</span>
                                        <?php else: ?>
                                            <span class="chip chip-coral">− خصم</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <b style="color:<?= $t['action_type'] === 'add' ? 'var(--success)' : 'var(--danger)' ?>">
                                            <?= $t['action_type'] === 'add' ? '+' : '−' ?><?= $t['amount'] ?>
                                        </b>
                                    </td>
                                    <td style="font-size:12px;color:var(--mute)"><?= e($t['reference_type'] ?? '—') ?></td>
                                    <td style="font-size:12.5px"><?= e($t['notes'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; /* crShow: الأرقام والسجل */ ?>
    </main>
</div>

<script>
async function buyPackage(id, btn) {
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = '⟳ جاري التحويل...';
    const result = await ajaxPost('<?= url('ajax/buy-package.php') ?>', {
        csrf: '<?= e(csrf_token()) ?>',
        package_id: id
    });
    if (result.ok && result.redirect) {
        window.location.href = result.redirect;
    } else {
        btn.disabled = false;
        btn.textContent = orig;
        showToast(result.error || 'تعذر بدء عملية الدفع', 'danger');
    }
}

function copyPay(id, btn) {
    const t = document.getElementById(id).textContent.trim();
    navigator.clipboard.writeText(t).then(() => {
        btn.textContent = '✓ اتنسخ';
        setTimeout(() => btn.textContent = '📋 نسخ', 1500);
    });
}
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
