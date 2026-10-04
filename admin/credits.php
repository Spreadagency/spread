<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();
require_admin_can('add_credits');

$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_costs') {
        set_setting('content_generation_cost', (int) $_POST['gen_cost']);
        set_setting('content_regeneration_cost', (int) $_POST['regen_cost']);
        set_setting('content_design_cost', (int) $_POST['design_cost']);
        set_setting('plan_ideas_cost', (int) $_POST['plan_ideas_cost']);
        set_setting('source_summary_cost', (int) $_POST['source_summary_cost']);
        // المراحل الجديدة: التعديل بالكلام (④-ب) · تحليل البراند من رابط (المرحلة 2) — 0 = مجاني
        if (isset($_POST['ai_edit_cost']))       set_setting('ai_edit_cost', (string) max(0, (int) $_POST['ai_edit_cost']));
        if (isset($_POST['brand_analyze_cost'])) set_setting('brand_analyze_cost', (string) max(0, (int) $_POST['brand_analyze_cost']));
        set_setting('starter_credits', (int) $_POST['starter']);
        admin_log('update_costs', 'settings', null, json_encode($_POST));
        flash_set('success', 'تم تحديث التكاليف ✓');
        redirect('admin/credits.php');
    }
}

$genCost   = (int) get_setting('content_generation_cost', COST_GENERATE);
$regenCost = (int) get_setting('content_regeneration_cost', COST_REGENERATE);
$designCost = (int) get_setting('content_design_cost', COST_DESIGN);
$starter   = (int) get_setting('starter_credits', STARTER_CREDITS);
$planIdeasCost = (int) get_setting('plan_ideas_cost', 2);
$summaryCost   = (int) get_setting('source_summary_cost', 1);
$aiEditCost    = (int) get_setting('ai_edit_cost', 1);
$brandAnalyzeCost = (int) get_setting('brand_analyze_cost', 1);

// Stats
$totalCreditsAdded = (int) (db_one('SELECT COALESCE(SUM(amount),0) AS s FROM credit_transactions WHERE action_type = ?', ['add'])['s'] ?? 0);
$totalCreditsConsumed = (int) (db_one('SELECT COALESCE(SUM(amount),0) AS s FROM credit_transactions WHERE action_type IN (?, ?)', ['consume', 'deduct'])['s'] ?? 0);
$totalBalance = (int) (db_one('SELECT COALESCE(SUM(balance),0) AS s FROM credit_wallets')['s'] ?? 0);

// Recent transactions across all users
$recentTx = db_all(
    'SELECT t.*, u.name AS user_name, u.email AS user_email
     FROM credit_transactions t JOIN users u ON t.user_id = u.id
     ORDER BY t.id DESC LIMIT 30'
);

$active = 'credits';
$page_title = 'إدارة الكريدت';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <div style="flex:1"></div>
            <span class="badge-admin">ADMIN</span>
        </div>

        <?= render_flash() ?>

        <div class="page-head">
            <h1>إدارة الكريدت ◇</h1>
            <div class="sub">تحديد التكاليف ومتابعة الاستهلاك</div>
        </div>

        <div class="kpis">
            <div class="kpi">
                <div class="ico" style="background:var(--mint-soft);color:#2a7d5f">+</div>
                <div><b><?= $totalCreditsAdded ?></b><small>إجمالي ما تم إضافته</small></div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:var(--coral-soft);color:#b0454a">−</div>
                <div><b><?= $totalCreditsConsumed ?></b><small>إجمالي المستهلك</small></div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)">◇</div>
                <div><b><?= $totalBalance ?></b><small>الرصيد القائم في المحافظ</small></div>
            </div>
        </div>

        <div class="split split-flex" style="--c1:1fr;--c2:1.3fr;gap:20px;align-items:flex-start">

            <!-- Costs settings -->
            <div class="card">
                <div class="card-head"><h3>تكاليف العمليات</h3></div>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_costs">

                    <div class="field">
                        <label>كريدت ابتدائي للتسجيل</label>
                        <input type="number" name="starter" class="input" value="<?= $starter ?>" min="0" required>
                        <div class="field-help">المستخدم الجديد يحصل على هذا الرصيد عند التسجيل</div>
                    </div>

                    <div class="field">
                        <label>تكلفة توليد محتوى</label>
                        <input type="number" name="gen_cost" class="input" value="<?= $genCost ?>" min="1" required>
                    </div>

                    <div class="field">
                        <label>تكلفة إعادة التوليد</label>
                        <input type="number" name="regen_cost" class="input" value="<?= $regenCost ?>" min="1" required>
                    </div>

                    <div class="field">
                        <label>تكلفة توليد التصميم</label>
                        <input type="number" name="design_cost" class="input" value="<?= $designCost ?>" min="1" required>
                    </div>

                    <div class="field">
                        <label>تكلفة التعديل بالكلام</label>
                        <input type="number" name="ai_edit_cost" class="input" value="<?= $aiEditCost ?>" min="0">
                        <div class="field-help">«خلّي الـ CTA أقوى» في مكتبة المحتوى — بيرجع لو الـ AI فشل · 0 = مجاني</div>
                    </div>

                    <div class="field">
                        <label>تكلفة تحليل البراند من رابط</label>
                        <input type="number" name="brand_analyze_cost" class="input" value="<?= $brandAnalyzeCost ?>" min="0">
                        <div class="field-help">Brand Brain — بيرجع لو ماطلعش معلومات · 0 = مجاني</div>
                    </div>

                    <div class="field">
                        <label>تكلفة أفكار خطة المحتوى</label>
                        <input type="number" name="plan_ideas_cost" class="input" value="<?= $planIdeasCost ?>" min="0" required>
                    </div>

                    <div class="field">
                        <label>تكلفة تلخيص مستند هوية</label>
                        <input type="number" name="source_summary_cost" class="input" value="<?= $summaryCost ?>" min="0" required>
                    </div>

                    <button class="btn">حفظ التغييرات</button>
                </form>
            </div>

            <!-- Recent transactions -->
            <div class="card">
                <div class="card-head">
                    <h3>أحدث 30 عملية</h3>
                </div>

                <?php if (empty($recentTx)): ?>
                    <div class="empty"><div class="ico">◇</div><h3>لا توجد عمليات</h3></div>
                <?php else: ?>
                    <div class="table-wrap" style="box-shadow:none;max-height:600px;overflow-y:auto">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>المستخدم</th>
                                    <th>النوع</th>
                                    <th>القيمة</th>
                                    <th>المصدر</th>
                                    <th>الرصيد بعد</th>
                                    <th>إيراد</th>
                                    <th>التاريخ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTx as $t): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= url('admin/user-view.php?id=' . $t['user_id']) ?>" style="text-decoration:none;color:inherit">
                                                <b style="font-size:12.5px"><?= e($t['user_name']) ?></b>
                                                <div class="text-mute" style="font-size:11px"><?= e($t['user_email']) ?></div>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($t['action_type'] === 'add'): ?>
                                                <span class="chip chip-mint">+ إضافة</span>
                                            <?php elseif ($t['action_type'] === 'consume'): ?>
                                                <span class="chip chip-amber">استهلاك</span>
                                            <?php else: ?>
                                                <span class="chip chip-coral">− خصم</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><b><?= $t['action_type'] === 'add' ? '+' : '−' ?><?= $t['amount'] ?></b></td>
                                        <td style="font-size:12px"><?= e(['paid' => '💳 مدفوع', 'free' => 'مجاني', 'bonus' => '🎁 هدية', 'promo' => '🎟 عرض', 'compensation' => '🛠 تعويض', 'refund' => '↩ استرداد', 'usage' => 'استخدام', 'adjust' => 'تعديل يدوي', 'carryover' => 'استرجاع رصيد'][$t['source'] ?? ''] ?? '—') ?></td>
                                        <td style="font-size:12px"><?= isset($t['balance_after']) && $t['balance_after'] !== null ? (int) $t['balance_after'] : '—' ?></td>
                                        <td style="font-size:12px;direction:ltr"><?= isset($t['revenue_egp']) && $t['revenue_egp'] !== null && (float) $t['revenue_egp'] != 0 ? number_format((float) $t['revenue_egp'], 2) : '—' ?></td>
                                        <td style="font-size:11.5px;color:var(--mute)"><?= e(time_ago($t['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
