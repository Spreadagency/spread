<?php
/**
 * Spread AI v2 — الأدمن: تكاليف العمليات + التحكم في القائمة
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';

require_admin();
require_admin_can('manage_packages');

// كل العمليات اللي بتستهلك كريدت
function cost_items(): array
{
    return [
        'content_generation_cost'   => ['✎ توليد منشور', 'كتابة منشور جديد بالكامل', 1],
        'content_regeneration_cost' => ['↻ إعادة توليد منشور', 'إعادة كتابة بملاحظات', 1],
        'content_design_cost'       => ['🎨 توليد تصميم', 'أي صورة/تصميم (من البوست أو الاستوديو)', 2],
        'logo_generate_cost'        => ['✨ توليد لوجو بالـ AI', 'لوجو للبراند', 3],
        'plan_ideas_cost'           => ['💡 أفكار خطة محتوى', 'توليد كل أفكار الخطة مرة واحدة', 2],
        'studio_idea_cost'          => ['✦ فكرة تصميم', 'اقتراح فكرة تصميم في الاستوديو', 1],
        'source_summary_cost'       => ['📄 تلخيص مستند', 'تلخيص مستند هوية', 1],
        'brand_summary_cost'        => ['◈ ملخص الهوية الذكي', 'تحليل شامل للهوية', 2],
        'visual_identity_cost'      => ['🖌 تحليل الهوية البصرية', 'دراسة التصميمات واستخراج الستايل', 2],
        'brand_agent_cost'          => ['🤖 مساعد الهوية', 'لكل رسالة في المحادثة (0 = مجاني)', 0],
        'campaign_eval_cost'        => ['⭐ تقييم منشور في الحملة', 'مراجعة الجودة قبل التصميم — لكل منشور (0 = مجاني)', 0],
        'research_cost_quick'       => ['🔬 بحث عميق — سريع', 'طلب بحث ويب واحد + تحليل (بيرجع لو البحث فشل)', 2],
        'research_cost_medium'      => ['🔬 بحث عميق — متوسط', '3 طلبات بحث ويب + تحليل', 4],
        'research_cost_deep'        => ['🔬 بحث عميق — عميق', 'طلب بحث لكل محور + تحليل · والتحديث بنفس التكلفة', 8],
    ];
}

// عناصر قائمة العميل القابلة للإخفاء
function menu_items(): array
{
    return [
        'create-content'  => '✎ إنشاء منشور',
        'content-plan'    => '🗓 خطة المحتوى',
        'design-studio'   => '✨ استوديو التصميم',
        'research'        => '🔬 البحث العميق',
        'brand-agent'     => '🤖 مساعد الهوية',
        'studio'          => '🎨 معرض الإلهام',
        'content-history' => '▤ المحتوى السابق',
        'brand-profile'   => '◈ هوية البراند',
        'sources'         => '📄 مستندات الهوية',
        'social-accounts' => '🔗 حساباتي المربوطة',
        'referrals'       => '🎁 اربح كريدت',
        'credits'         => '◇ الرصيد',
        'help'            => '❓ دليل المنصة',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();

    if (($_POST['action'] ?? '') === 'save_costs') {
        foreach (array_keys(cost_items()) as $key) {
            $v = max(0, min(999, (int) ($_POST[$key] ?? 0)));
            set_setting($key, (string) $v);
        }
        set_setting('default_credit_validity_days', (string) max(1, min(3650, (int) ($_POST['default_credit_validity_days'] ?? 30))));
        admin_log('update_costs', 'settings', null, 'تكاليف العمليات');
        flash_set('success', 'تم حفظ التكاليف ✓ — سارية فورًا على كل العملاء');
        redirect('admin/pricing.php');
    }

    if (($_POST['action'] ?? '') === 'save_menu') {
        $hidden = [];
        foreach (array_keys(menu_items()) as $key) {
            if (empty($_POST['show_' . $key])) {
                $hidden[] = $key;
            }
        }
        set_setting('menu_hidden', implode(',', $hidden));
        admin_log('update_menu', 'settings', null, 'مخفي: ' . implode(',', $hidden));
        flash_set('success', 'تم حفظ القائمة ✓');
        redirect('admin/pricing.php');
    }
}

$hidden = array_filter(array_map('trim', explode(',', (string) get_setting('menu_hidden', ''))));

$active = 'pricing';
$page_title = 'التكاليف والقائمة';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>تكاليف العمليات والقائمة ⚙️</h1>
            <div class="sub">كام كريدت تتخصم على كل عملية · وإيه اللي يظهر للعميل في القائمة</div>
        </div>

        <?= render_flash() ?>

        <div class="split split-2">
            <div class="card">
                <div class="card-head"><h3>◇ تكلفة كل عملية</h3></div>
                <form method="POST" data-safe-post>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_costs">
                    <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>العملية</th><th style="width:110px">الكريدت</th></tr></thead>
                        <tbody>
                        <?php foreach (cost_items() as $key => [$label, $desc, $default]): ?>
                            <tr>
                                <td>
                                    <b><?= e($label) ?></b>
                                    <div class="sub" style="font-size:11.5px"><?= e($desc) ?></div>
                                </td>
                                <td>
                                    <input type="number" name="<?= e($key) ?>" class="input" min="0" max="999"
                                           value="<?= (int) get_setting($key, $default) ?>" style="width:90px;padding:7px">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="field" style="margin-top:14px">
                        <label>مدة صلاحية الكريدت الافتراضية (يوم)</label>
                        <input type="number" name="default_credit_validity_days" class="input" min="1" max="3650"
                               value="<?= (int) get_setting('default_credit_validity_days', 30) ?>" style="max-width:140px">
                        <div class="field-help">بتتطبق على الشحن اليدوي لو ما حددتش مدة تانية</div>
                    </div>

                    <button class="btn full">💾 حفظ التكاليف</button>
                </form>
            </div>

            <div class="card">
                <div class="card-head"><h3>☰ القائمة الرئيسية للعميل</h3></div>
                <p class="sub" style="margin-bottom:12px">شيل العلامة عن أي عنصر عشان يختفي من قائمة كل العملاء (الصفحة نفسها بتفضل شغالة بالرابط المباشر).</p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_menu">
                    <div style="display:grid;gap:8px">
                        <?php foreach (menu_items() as $key => $label): ?>
                            <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid var(--line);border-radius:10px;cursor:pointer">
                                <input type="checkbox" name="show_<?= e($key) ?>" <?= in_array($key, $hidden, true) ? '' : 'checked' ?>>
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn full" style="margin-top:14px">💾 حفظ القائمة</button>
                </form>
            </div>
        </div>

    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
