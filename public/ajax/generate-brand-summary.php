<?php
/**
 * Spread AI v2 — توليد ملخص الهوية الذكي
 * بيحلل: بيانات الهوية + صور البراند (vision) + مستندات الهوية → ملخص شامل
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/uploader.php';
require_once __DIR__ . '/../../includes/source-extract.php';

require_login();
require_csrf();

$user = current_user();
$brand = user_brand();

if (!$brand || empty($brand['business_name'])) {
    json_response(['ok' => false, 'error' => 'كمّل بيانات الهوية الأساسية الأول']);
}

if (!rate_limit('brand_summary', 'u' . $user['id'], 3, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى — استنى شوية']);
}

$cost = (int) get_setting('brand_summary_cost', 2);
if (credits_balance((int) $user['id']) < $cost) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// ── تجميع كل مصادر الهوية ──
$brandLines = [];
foreach (['business_name' => 'اسم النشاط', 'industry' => 'المجال', 'description' => 'الوصف',
          'audience' => 'الجمهور', 'tone' => 'النبرة', 'colors' => 'الألوان',
          'keywords_use' => 'كلمات مفضلة', 'keywords_avoid' => 'كلمات ممنوعة',
          'design_rules' => 'شروط التصميم', 'notes' => 'ملاحظات'] as $k => $label) {
    if (!empty($brand[$k])) {
        $brandLines[] = "- {$label}: {$brand[$k]}";
    }
}

$knowledge = get_brand_knowledge((int) $brand['id']);

// صور البراند + اللوجو كـ vision
$imageUrls = [];
if (!empty($brand['logo_path'])) {
    $imageUrls[] = upload_url($brand['logo_path']);
}
foreach (array_slice(get_brand_images((int) $brand['id']), 0, 3) as $img) {
    $imageUrls[] = upload_url($img['image_path']);
}

$prompt = "أنت خبير هوية بصرية وبراندينج. حلّل البيانات والصور المرفقة (لوجو وصور البراند لو موجودة) واكتب «ملخص هوية» شامل ومضغوط (200-300 كلمة) يغطي:\n"
    . "1. جوهر البراند ورسالته\n"
    . "2. الشخصية والنبرة\n"
    . "3. الهوية البصرية: الألوان الفعلية (من الصور واللوجو)، الطابع، الأسلوب\n"
    . "4. الجمهور وما يهمه\n"
    . "5. نقاط تميز حقيقية من المستندات\n\n"
    . "بيانات الهوية:\n" . implode("\n", $brandLines)
    . ($knowledge !== '' ? "\n\nمن مستندات العميل:\n" . $knowledge : '')
    . "\n\nاكتب الملخص مباشرة بدون عناوين مرقمة ولا مقدمات — فقرات متصلة عملية، سيُستخدم كمرجع دائم لتوليد المحتوى والتصميمات.";

if (!credits_consume((int) $user['id'], $cost, 'توليد ملخص الهوية الذكي', 'brand', (int) $brand['id'])) {
    json_response(['ok' => false, 'error' => credits_short_msg()]);
}

// برومبت الأدمن (لو متظبط من صفحة البرومبت) بيتقدّم على المدمج في الكود
$__adminPrompt = get_active_prompt('brand_summary');
if (trim($__adminPrompt) !== '') {
    $prompt = $__adminPrompt . "\n\n" . $prompt;
}

$ai = ai_generate($prompt, $imageUrls); // بيعدي على الـ Smart Router تلقائيًا لو مفعّل

if (!$ai['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل توليد ملخص الهوية', null, 'refund');
    json_response(['ok' => false, 'error' => $ai['error']]);
}

$summary = trim($ai['response']);
$summary = preg_replace('/\[\/?[A-Z_]+\]/', '', $summary); // تنضيف أي تاجات لو الموديل رجّعها
$summary = mb_substr(trim(preg_replace('/\n{3,}/', chr(10).chr(10), $summary)), 0, 4000);
db_run('UPDATE brand_profiles SET ai_summary = ? WHERE id = ?', [$summary, $brand['id']]);
ai_log_usage((int) $user['id'], 'generate', $ai['model'] ?? 'router', $ai['usage'] ?? [], null);

json_response(['ok' => true, 'summary' => $summary]);
