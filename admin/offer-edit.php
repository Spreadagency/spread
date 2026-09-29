<?php
/**
 * Spread AI v2 — الأدمن: محرر العرض
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';
require_once __DIR__ . '/../includes/billing.php';   // 10: أكواد الخصم على الباقات (طرق الدفع)

require_admin();
require_admin_can('manage_packages');

$id = (int) ($_GET['id'] ?? 0);
$offer = $id ? db_one('SELECT * FROM offers WHERE id = ?', [$id]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();

    $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['code'] ?? '')));
    if ($code === '') {
        $code = offer_generate_code(6);
    }
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 191);
    if ($title === '') {
        flash_set('danger', 'اكتب عنوان العرض');
        redirect('admin/offer-edit.php' . ($id ? '?id=' . $id : ''));
    }

    // بناء rules_json من الشيك بوكسات
    $intList = static function (string $raw): array {
        return array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', trim($raw)) ?: [])));
    };
    $rules = [];
    foreach (['once_per_user', 'once_per_identity', 'once_per_group', 'new_users_only',
              'never_purchased', 'block_same_ip', 'require_admin_approval'] as $flag) {
        if (!empty($_POST[$flag])) $rules[$flag] = true;
    }
    if (!empty($_POST['new_users_only']) && !empty($_POST['max_account_age_days'])) {
        $rules['max_account_age_days'] = max(1, (int) $_POST['max_account_age_days']);
    }
    foreach (['max_per_referrer', 'max_uses_per_day', 'min_purchase'] as $numKey) {
        if (!empty($_POST[$numKey])) $rules[$numKey] = max(0, (int) $_POST[$numKey]);
    }
    foreach (['allowed_packages', 'whitelist_user_ids', 'blacklist_user_ids'] as $listKey) {
        $v = $intList((string) ($_POST[$listKey] ?? ''));
        if ($v) $rules[$listKey] = $v;
    }
    // 10: كود خصم على الباقات — المكافأة · المستهدفين · الأهلية · مرات لكل عميل · طرق الدفع
    if (($_POST['type'] ?? '') === 'promo') {
        $dt = in_array($_POST['discount_type'] ?? 'none', ['none', 'percent', 'fixed', 'special', 'free'], true) ? $_POST['discount_type'] : 'none';
        $rules['reward'] = [
            'discount_type'  => $dt,
            'discount_value' => $dt === 'percent' ? max(0, min(100, (float) ($_POST['discount_value'] ?? 0))) : max(0, (float) ($_POST['discount_value'] ?? 0)),
            'bonus_credits'  => max(0, (int) ($_POST['bonus_credits'] ?? 0)),
            'extra_days'     => max(0, min(3650, (int) ($_POST['extra_days'] ?? 0))),
        ];
        // «مخصص لـ»: أرقام حسابات · إيميلات · موبايلات — سطر أو فاصلة لكل واحد
        $t = ['user_ids' => [], 'emails' => [], 'phones' => []];
        foreach (preg_split('/[\s,;]+/u', trim((string) ($_POST['target'] ?? ''))) ?: [] as $tok) {
            if ($tok === '') continue;
            if (str_contains($tok, '@')) $t['emails'][] = mb_strtolower($tok);
            elseif (preg_match('/^\d{1,9}$/', $tok)) $t['user_ids'][] = (int) $tok;
            elseif (preg_match('/^\+?\d{8,15}$/', $tok)) $t['phones'][] = $tok;
        }
        $t = array_map(fn($a) => array_values(array_unique($a)), $t);
        if ($t['user_ids'] || $t['emails'] || $t['phones']) $rules['target'] = $t;
        $el = in_array($_POST['eligibility'] ?? 'all', ['all', 'new', 'existing'], true) ? $_POST['eligibility'] : 'all';
        $rules['eligibility'] = $el;
        if ($el === 'new') $rules['new_users_only'] = true;
        if ($el !== 'all') $rules['max_account_age_days'] = max(1, (int) ($_POST['max_account_age_days'] ?? 7));
        if (!empty($_POST['max_per_user'])) $rules['max_per_user'] = max(1, (int) $_POST['max_per_user']);
        $methods = array_values(array_intersect(array_map('strval', (array) ($_POST['allowed_methods'] ?? [])), array_column(billing_methods(false), 'mkey')));
        if ($methods) $rules['allowed_methods'] = $methods;
    }

    $data = [
        'code'                 => $code,
        'title'                => $title,
        'description'          => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 2000) ?: null,
        'type'                 => in_array($_POST['type'] ?? '', ['referral', 'campaign', 'promo'], true) ? $_POST['type'] : 'campaign',
        'offer_group'          => mb_substr(preg_replace('/[^a-z0-9_]/', '', mb_strtolower(trim((string) ($_POST['offer_group'] ?? '')))), 0, 50) ?: null,
        'referrer_credits'     => max(0, (int) ($_POST['referrer_credits'] ?? 0)),
        'referee_credits'      => max(0, (int) ($_POST['referee_credits'] ?? 0)),
        'credit_validity_days' => ($_POST['credit_validity_days'] ?? '') !== '' ? max(1, (int) $_POST['credit_validity_days']) : null,
        'trigger_event'        => in_array($_POST['trigger_event'] ?? '', ['on_register', 'on_activation', 'on_first_payment'], true) ? $_POST['trigger_event'] : 'on_activation',
        'stackable'            => !empty($_POST['stackable']) ? 1 : 0,
        'rules_json'           => json_encode($rules, JSON_UNESCAPED_UNICODE),
        'starts_at'            => !empty($_POST['starts_at']) ? str_replace('T', ' ', $_POST['starts_at']) . ':00' : null,
        'expires_at'           => !empty($_POST['expires_at']) ? str_replace('T', ' ', $_POST['expires_at']) . ':00' : null,
        'max_uses'             => ($_POST['max_uses'] ?? '') !== '' ? max(1, (int) $_POST['max_uses']) : null,
        'is_active'            => !empty($_POST['is_active']) ? 1 : 0,
    ];

    try {
        if ($id) {
            $set = [];
            $vals = [];
            foreach ($data as $k => $v) { $set[] = "`{$k}` = ?"; $vals[] = $v; }
            $vals[] = $id;
            db_run('UPDATE offers SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ?', $vals);
            admin_log('update_offer', 'offer', $id, $code);
            flash_set('success', 'تم حفظ العرض ✓');
        } else {
            $data['created_by'] = current_admin()['id'];
            $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($data)));
            $ph = implode(', ', array_fill(0, count($data), '?'));
            $id = db_insert("INSERT INTO offers ({$cols}) VALUES ({$ph})", array_values($data));
            admin_log('create_offer', 'offer', $id, $code);
            flash_set('success', 'تم إنشاء العرض ✓');
        }
    } catch (\Throwable $e) {
        flash_set('danger', str_contains($e->getMessage(), 'uq_offer_code') ? 'الكود ده مستخدم بالفعل' : 'تعذّر الحفظ');
    }
    redirect('admin/offer-edit.php?id=' . $id);
}

$r = $offer ? offer_rules($offer) : ['once_per_user' => true, 'once_per_identity' => true, 'once_per_group' => true];
$suggested = $offer['code'] ?? offer_generate_code(6);
$link = rtrim(APP_URL, '/') . '/register.php?ref=' . urlencode($suggested);

$active = 'offers';
$page_title = $offer ? 'تعديل عرض' : 'عرض جديد';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1><?= $offer ? '✎ تعديل: ' . e($offer['title']) : '＋ عرض جديد' ?></h1>
            <div class="sub">كل شرط مقفول = غير مفعّل</div>
        </div>

        <?= render_flash() ?>

        <form method="POST" data-safe-post>
            <?= csrf_field() ?>

            <div class="card">
                <div class="card-head"><h3>① بيانات أساسية</h3></div>
                <div class="field-row">
                    <div class="field">
                        <label>الكود <span class="req">*</span></label>
                        <input type="text" name="code" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e($suggested) ?>" style="text-transform:uppercase;font-weight:700">
                        <div class="field-help">سيبه زي ما هو أو غيّره لكود سهل الحفظ</div>
                    </div>
                    <div class="field">
                        <label>النوع</label>
                        <select name="type" class="input">
                            <option value="campaign" <?= ($offer['type'] ?? 'campaign') === 'campaign' ? 'selected' : '' ?>>📣 حملة إدارية</option>
                            <option value="promo"    <?= ($offer['type'] ?? '') === 'promo' ? 'selected' : '' ?>>🎁 عرض / كوبون</option>
                            <option value="referral" <?= ($offer['type'] ?? '') === 'referral' ? 'selected' : '' ?>>🔗 أفلييت</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>مجموعة العرض</label>
                        <input type="text" name="offer_group" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e($offer['offer_group'] ?? '') ?>" placeholder="welcome_bonus">
                        <div class="field-help">العروض في نفس المجموعة، المستخدم ياخد واحد منها بس</div>
                    </div>
                </div>
                <div class="field">
                    <label>العنوان <span class="req">*</span></label>
                    <input type="text" name="title" class="input" required value="<?= e($offer['title'] ?? '') ?>" placeholder="هدية رمضان">
                </div>
                <div class="field">
                    <label>الوصف</label>
                    <textarea name="description" class="textarea" rows="2"><?= e($offer['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h3>② الكريدت</h3></div>
                <div class="field-row">
                    <div class="field">
                        <label>كريدت للمُحيل</label>
                        <input type="number" name="referrer_credits" class="input" min="0" value="<?= (int) ($offer['referrer_credits'] ?? 0) ?>">
                        <div class="field-help">صفر = مفيش مكافأة لصاحب الكود</div>
                    </div>
                    <div class="field">
                        <label>كريدت للمستخدم الجديد</label>
                        <input type="number" name="referee_credits" class="input" min="0" value="<?= (int) ($offer['referee_credits'] ?? get_setting('referral_welcome_bonus', 100)) ?>">
                    </div>
                    <div class="field">
                        <label>صلاحية الكريدت (يوم)</label>
                        <input type="number" name="credit_validity_days" class="input" min="1"
                               value="<?= $offer && $offer['credit_validity_days'] !== null ? (int) $offer['credit_validity_days'] : '' ?>"
                               placeholder="<?= (int) get_setting('offer_default_validity_days', 30) ?> (الافتراضي)">
                    </div>
                    <div class="field">
                        <label>لحظة استحقاق المُحيل</label>
                        <select name="trigger_event" class="input">
                            <option value="on_register"      <?= ($offer['trigger_event'] ?? '') === 'on_register' ? 'selected' : '' ?>>فور التسجيل (أسرع · أخطر)</option>
                            <option value="on_activation"    <?= ($offer['trigger_event'] ?? 'on_activation') === 'on_activation' ? 'selected' : '' ?>>عند تفعيل الحساب ✅</option>
                            <option value="on_first_payment" <?= ($offer['trigger_event'] ?? '') === 'on_first_payment' ? 'selected' : '' ?>>عند أول اشتراك مدفوع</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php $__w = $offer ? promo_reward($offer) : ['discount_type' => 'none', 'discount_value' => 0, 'bonus_credits' => 0, 'extra_days' => 0];
                  $__t = (array) ($r['target'] ?? []);
                  $__tTxt = implode("\n", array_merge((array) ($__t['user_ids'] ?? []), (array) ($__t['emails'] ?? []), (array) ($__t['phones'] ?? [])));
                  $__am = (array) ($r['allowed_methods'] ?? []); ?>
            <div class="card" id="promo-box">
                <div class="card-head"><h3>②-ب كود خصم على الباقات <small class="sub">(للنوع «🎁 عرض / كوبون» بس)</small></h3></div>
                <p class="sub" style="margin-top:0">العميل بيكتب الكود في صفحة الدفع ← السيرفر بيتحقق من الشروط ← يشوف السعر النهائي أو الهدية قبل ما يدفع. الاستخدام بيتسجّل لما تعتمد الدفع.</p>
                <div class="field-row">
                    <div class="field">
                        <label>نوع المكافأة</label>
                        <select name="discount_type" class="input">
                            <?php foreach (['none' => 'من غير خصم (هدية بس)', 'percent' => 'خصم نسبة %', 'fixed' => 'خصم مبلغ ثابت (جنيه)', 'special' => 'سعر خاص (جنيه)', 'free' => 'الباقة مجانًا'] as $k => $l): ?>
                                <option value="<?= $k ?>" <?= $__w['discount_type'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>قيمة الخصم / السعر</label>
                        <input type="number" step="0.01" min="0" name="discount_value" class="input" value="<?= e((string) ($__w['discount_value'] ?: '')) ?>" placeholder="50">
                    </div>
                    <div class="field">
                        <label>كريدت هدية (Bonus)</label>
                        <input type="number" min="0" name="bonus_credits" class="input" value="<?= (int) $__w['bonus_credits'] ?: '' ?>" placeholder="0">
                    </div>
                    <div class="field">
                        <label>أيام زيادة على الباقة</label>
                        <input type="number" min="0" name="extra_days" class="input" value="<?= (int) $__w['extra_days'] ?: '' ?>" placeholder="0">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>مين يقدر يستخدمه؟</label>
                        <select name="eligibility" class="input">
                            <?php foreach (['all' => 'كل المستخدمين', 'new' => 'مستخدمين جدد بس', 'existing' => 'مستخدمين حاليين بس'] as $k => $l): ?>
                                <option value="<?= $k ?>" <?= ($r['eligibility'] ?? (!empty($r['new_users_only']) ? 'new' : 'all')) === $k ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-help">«جديد» = عمر الحساب أقل من الأيام المحددة في الشروط تحت</div>
                    </div>
                    <div class="field">
                        <label>مرات الاستخدام لكل عميل</label>
                        <input type="number" min="1" name="max_per_user" class="input" value="<?= e((string) ($r['max_per_user'] ?? '')) ?>" placeholder="1 (مرة واحدة)">
                    </div>
                    <div class="field">
                        <label>الحد الأدنى لسعر الباقة (جنيه)</label>
                        <input type="number" min="0" name="min_purchase" class="input" value="<?= e((string) ($r['min_purchase'] ?? '')) ?>" placeholder="بلا حد">
                    </div>
                </div>
                <div class="field">
                    <label>مخصص لـ (اختياري) — رقم الحساب أو الإيميل أو الموبايل، واحد في كل سطر</label>
                    <textarea name="target" class="textarea" rows="3" dir="ltr" data-no-encode="1" placeholder="15&#10;ahmed@example.com&#10;01012345678"><?= e($__tTxt) ?></textarea>
                    <div class="field-help">فاضي = لأي حد تنطبق عليه الشروط. غير كده الكود مايشتغلش غير للحسابات دي.</div>
                </div>
                <?php $__ms = billing_methods(false); if ($__ms): ?>
                <div class="field">
                    <label>طرق دفع محددة (اختياري)</label>
                    <div style="display:flex;gap:14px;flex-wrap:wrap">
                        <?php foreach ($__ms as $__m): ?>
                            <label style="display:flex;gap:6px;align-items:center;font-weight:400"><input type="checkbox" name="allowed_methods[]" value="<?= e($__m['mkey']) ?>" <?= in_array($__m['mkey'], $__am, true) ? 'checked' : '' ?> style="width:auto"> <?= e($__m['label']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <p class="sub" style="margin:0">الباقة المحددة: اكتب رقمها في «باقات مسموحة» تحت (مثلًا كود PRO100 لباقة برو بس). التاريخ والحد الإجمالي في «④ الفترة والحدود».</p>
            </div>

            <div class="card">
                <div class="card-head"><h3>③ الشروط</h3></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px">
                    <?php
                    $flags = [
                        'once_per_user'      => ['مرة واحدة لكل مستخدم', 'الحساب الواحد ياخد العرض مرة'],
                        'once_per_identity'  => ['مرة واحدة لكل موبايل/إيميل', 'يمنع حسابات جديدة بنفس البيانات'],
                        'once_per_group'     => ['مرة واحدة لكل مجموعة', 'يمنع الجمع بين عروض متنافسة'],
                        'never_purchased'    => ['للي لم يشترِ من قبل', ''],
                        'block_same_ip'      => ['منع نفس الـ IP', 'يمنع الإحالة الذاتية من نفس الشبكة'],
                        'require_admin_approval' => ['يتطلب اعتماد الأدمن', 'المكافأة تفضل معلقة لحد ما توافق'],
                        'stackable'          => ['يُجمع مع عروض أخرى', 'مقفول = العرض لوحده'],
                    ];
                    foreach ($flags as $k => [$lbl, $hint]):
                        $checked = $k === 'stackable' ? !empty($offer['stackable']) : !empty($r[$k]);
                    ?>
                        <label style="display:flex;gap:9px;align-items:flex-start;padding:11px 13px;border:1px solid var(--line);border-radius:11px;cursor:pointer">
                            <input type="checkbox" name="<?= $k ?>" value="1" <?= $checked ? 'checked' : '' ?> style="width:auto;margin-top:3px">
                            <span><b style="font-size:13.5px"><?= e($lbl) ?></b>
                                <?php if ($hint): ?><br><span class="sub" style="font-size:11.5px"><?= e($hint) ?></span><?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <label style="display:flex;gap:9px;align-items:flex-start;padding:11px 13px;border:1px solid var(--line);border-radius:11px;cursor:pointer">
                        <input type="checkbox" name="new_users_only" value="1" <?= !empty($r['new_users_only']) ? 'checked' : '' ?> style="width:auto;margin-top:3px">
                        <span><b style="font-size:13.5px">للحسابات الجديدة بس</b><br>
                            <span class="sub" style="font-size:11.5px">عمر الحساب أقل من
                                <input type="number" name="max_account_age_days" min="1" style="width:58px;padding:3px 6px;font-size:12px"
                                       value="<?= (int) ($r['max_account_age_days'] ?? 7) ?>"> يوم</span>
                        </span>
                    </label>
                </div>

                <div class="field-row" style="margin-top:14px">
                    <div class="field">
                        <label>أقصى مكافآت لكل مُحيل</label>
                        <input type="number" name="max_per_referrer" class="input" min="0"
                               value="<?= $r['max_per_referrer'] ?? '' ?>" placeholder="<?= (int) get_setting('referral_max_per_user', 20) ?>">
                    </div>
                    <div class="field">
                        <label>سقف يومي للعرض</label>
                        <input type="number" name="max_uses_per_day" class="input" min="0"
                               value="<?= $r['max_uses_per_day'] ?? '' ?>" placeholder="بلا حد">
                    </div>
                    <div class="field">
                        <label>باقات مسموحة (أرقام مفصولة بفاصلة)</label>
                        <div class="field-help"><?= e(implode(' · ', array_map(fn($p) => $p['id'] . ' = ' . $p['name'], db_all('SELECT id, name FROM credit_packages ORDER BY order_num, id')))) ?></div>
                        <input type="text" name="allowed_packages" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e(implode(',', (array) ($r['allowed_packages'] ?? []))) ?>" placeholder="كل الباقات">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>مسموح لمستخدمين (IDs)</label>
                        <input type="text" name="whitelist_user_ids" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e(implode(',', (array) ($r['whitelist_user_ids'] ?? []))) ?>" placeholder="الكل">
                    </div>
                    <div class="field">
                        <label>ممنوع على مستخدمين (IDs)</label>
                        <input type="text" name="blacklist_user_ids" class="input" dir="ltr" data-no-encode="1"
                               value="<?= e(implode(',', (array) ($r['blacklist_user_ids'] ?? []))) ?>" placeholder="مفيش">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h3>④ الفترة والحدود</h3></div>
                <div class="field-row">
                    <div class="field">
                        <label>يبدأ من</label>
                        <input type="datetime-local" name="starts_at" class="input"
                               value="<?= $offer && $offer['starts_at'] ? date('Y-m-d\TH:i', strtotime($offer['starts_at'])) : '' ?>">
                    </div>
                    <div class="field">
                        <label>ينتهي في</label>
                        <input type="datetime-local" name="expires_at" class="input"
                               value="<?= $offer && $offer['expires_at'] ? date('Y-m-d\TH:i', strtotime($offer['expires_at'])) : '' ?>">
                    </div>
                    <div class="field">
                        <label>أقصى عدد استخدامات</label>
                        <input type="number" name="max_uses" class="input" min="1"
                               value="<?= $offer && $offer['max_uses'] !== null ? (int) $offer['max_uses'] : '' ?>" placeholder="بلا حد">
                    </div>
                    <div class="field">
                        <label>الحالة</label>
                        <label style="display:flex;gap:8px;align-items:center;font-weight:400;padding-top:8px">
                            <input type="checkbox" name="is_active" value="1" <?= ($offer['is_active'] ?? 1) ? 'checked' : '' ?> style="width:auto"> العرض شغّال
                        </label>
                    </div>
                </div>
            </div>

            <?php if ($offer): ?>
            <div class="card">
                <div class="card-head"><h3>⑤ المشاركة</h3></div>
                <div class="field">
                    <label>لينك الدعوة</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <input type="text" class="input" id="offer-link" dir="ltr" readonly value="<?= e($link) ?>" style="flex:1;min-width:220px">
                        <button type="button" class="btn ghost" onclick="navigator.clipboard.writeText(document.getElementById('offer-link').value);this.textContent='✓ اتنسخ'">📋 نسخ</button>
                    </div>
                </div>
                <div class="field">
                    <label>نص واتساب جاهز</label>
                    <textarea class="textarea" id="wa-text" rows="3" readonly>جرّب Spread AI — منصة المحتوى والتصميم بالذكاء الاصطناعي 🎨
سجّل من اللينك ده وهتلاقي رصيد هدية في حسابك:
<?= e($link) ?></textarea>
                    <button type="button" class="btn ghost sm" style="margin-top:7px"
                            onclick="navigator.clipboard.writeText(document.getElementById('wa-text').value);this.textContent='✓ اتنسخ'">📋 نسخ النص</button>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <a href="<?= url('admin/offer-test.php?code=' . urlencode($offer['code'])) ?>" class="btn ghost">🔧 اختبر العرض</a>
                    <a href="<?= url('admin/offer-redemptions.php?offer=' . $offer['id']) ?>" class="btn ghost">📊 سجل الاستخدام (<?= (int) $offer['used_count'] ?>)</a>
                </div>
            </div>
            <?php endif; ?>

            <button class="btn"><?= $offer ? '💾 حفظ التعديلات' : '＋ إنشاء العرض' ?></button>
            <a href="<?= url('admin/offers.php') ?>" class="btn ghost">رجوع</a>
        </form>
    </main>
</div>

<script>
// قسم «كود خصم على الباقات» بيظهر للنوع «عرض / كوبون» بس
(function () {
    var sel = document.querySelector('select[name=type]'), box = document.getElementById('promo-box');
    if (!sel || !box) return;
    var upd = function () { box.style.display = sel.value === 'promo' ? '' : 'none'; };
    sel.addEventListener('change', upd); upd();
})();
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
