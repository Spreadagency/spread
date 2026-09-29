<?php
/**
 * Spread AI v2 — تحليل هوية التصميم البصرية
 * الـ AI بيدرس اللوجو وصور البراند والتصميمات السابقة والمفضلات
 * ويكتب النقط الأساسية في الستايل — وتتستخدم في كل تصميم بعد كده
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/uploader.php';
require_once __DIR__ . '/../../includes/plan-functions.php';
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) {
    require_once __DIR__ . '/../../includes/smart-ai.php';
}

require_login();
require_csrf();
decode_b64_fields();

$user = current_user();
$brand = user_brand();

if (!$brand) {
    json_response(['ok' => false, 'error' => 'كمّل بيانات الهوية الأساسية الأول']);
}

// حفظ يدوي (العميل عدّل النص بنفسه)
if (($_POST['mode'] ?? '') === 'save') {
    $txt = mb_substr(trim($_POST['visual_identity'] ?? ''), 0, 6000);
    db_run('UPDATE brand_profiles SET visual_identity = ?, visual_identity_at = NOW() WHERE id = ?', [$txt ?: null, $brand['id']]);
    json_response(['ok' => true, 'saved' => true]);
}

if (!rate_limit('visual_identity', 'u' . $user['id'], 5, 900)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى — استنى شوية']);
}

// ═══ تجميع الصور اللي هتتحلل ═══
$images = [];   // ['uri' => ..., 'role' => ...]

// 1) اللوجو
if (!empty($brand['logo_path'])) {
    $u = image_data_uri($brand['logo_path']);
    if ($u) {
        $images[] = ['uri' => $u, 'role' => "the brand's LOGO"];
    }
}

// 2) صور البراند
try {
    foreach (array_slice(get_brand_images((int) $brand['id']), 0, 4) as $bi) {
        $u = image_data_uri($bi['image_path']);
        if ($u) {
            $images[] = ['uri' => $u, 'role' => 'a brand photo'];
        }
    }
} catch (\Throwable $e) {
}

// 3) تصميمات سابقة للعميل (أحدث 4)
try {
    $prev = db_all(
        'SELECT image_path FROM (
            SELECT image_path, created_at FROM content_designs WHERE user_id = ?
            UNION ALL
            SELECT image_path, created_at FROM studio_designs WHERE user_id = ?
         ) d ORDER BY created_at DESC LIMIT 4',
        [$user['id'], $user['id']]
    );
    foreach ($prev as $p) {
        $u = image_data_uri($p['image_path']);
        if ($u) {
            $images[] = ['uri' => $u, 'role' => 'a previous design made for this brand'];
        }
    }
} catch (\Throwable $e) {
}

// 4) مفضلات معرض الإلهام (بتوضح ذوقه) — تدعم اللينكات الخارجية
try {
    $favs = db_all(
        'SELECT m.image_path, m.image_url FROM user_media_selections s
         JOIN media_library m ON m.id = s.media_id AND m.is_active = 1
         WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 3',
        [$user['id']]
    );
    foreach ($favs as $f) {
        $u = !empty($f['image_url']) ? remote_image_data_uri($f['image_url']) : image_data_uri($f['image_path']);
        if ($u) {
            $images[] = ['uri' => $u, 'role' => 'a design the client marked as inspiration (their taste)'];
        }
    }
} catch (\Throwable $e) {
}

if (!$images) {
    json_response(['ok' => false, 'error' => 'مفيش صور كفاية للتحليل — ارفع لوجو أو صور للبراند أو اعمل تصميم واحد على الأقل']);
}

$images = array_slice($images, 0, 8);

$cost = (int) get_setting('visual_identity_cost', 2);
if (!credits_consume((int) $user['id'], $cost, 'تحليل هوية التصميم البصرية', 'brand', (int) $brand['id'])) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// ═══ البرومبت ═══
$ctx = '';
if (!empty($brand['business_name'])) $ctx .= 'Business: ' . $brand['business_name'] . "\n";
if (!empty($brand['industry']))      $ctx .= 'Industry: ' . $brand['industry'] . "\n";
if (!empty($brand['colors']))        $ctx .= 'Stated brand colors: ' . $brand['colors'] . "\n";
if (!empty($brand['design_rules']))  $ctx .= 'Existing design rules: ' . $brand['design_rules'] . "\n";

$prompt = "أنت مدير فني خبير. حلل الصور المرفقة (لوجو البراند، صوره، تصميماته السابقة، والتصميمات اللي بيحبها) "
    . "واكتب **دليل الهوية البصرية** للبراند ده بالعربي في نقط واضحة ومختصرة.\n\n"
    . ($ctx !== '' ? "معلومات البراند:\n{$ctx}\n" : '')
    . "الصور المرفقة بالترتيب:\n";
foreach ($images as $i => $img) {
    $prompt .= 'Image ' . ($i + 1) . ' is ' . $img['role'] . "\n";
}
$prompt .= "\nاكتب النقط دي بالظبط (كل واحدة سطر أو سطرين، مباشرة بدون مقدمات):\n"
    . "🎨 الألوان: الألوان الأساسية والثانوية الفعلية اللي شايفها (بأكواد HEX تقريبية لو تقدر)\n"
    . "🔤 الخطوط والكتابة: نوع الخط (عريض/رفيع، حديث/كلاسيكي)، أحجام العناوين مقابل النص\n"
    . "📐 التكوين والتوزيع: مكان اللوجو، توزيع العناصر، المساحات الفاضية\n"
    . "✨ الأسلوب البصري: الشكل العام (بسيط/فاخر/مرح/جريء)، الأشكال المستخدمة (دوائر، زوايا حادة، تدرجات)\n"
    . "🖼 الصور والعناصر: نوع الصور المستخدمة وأسلوب معالجتها\n"
    . "⛔ تجنّب: حاجات ما تظهرش في تصميمات البراند ده\n\n"
    . "خلي كل نقطة عملية وقابلة للتنفيذ — لأن دي هتتبعت للـ AI في كل تصميم جديد.";

// ═══ التوليد (vision) — ai_generate بيعدي على الراوتر تلقائيًا لو مفعّل ═══
$uris = array_column($images, 'uri');
// برومبت الأدمن (لو متظبط من صفحة البرومبت) بيتقدّم على المدمج في الكود
$__adminPrompt = get_active_prompt('visual_identity');
if (trim($__adminPrompt) !== '') {
    $prompt = $__adminPrompt . "\n\n" . $prompt;
}

$ai = ai_generate($prompt, $uris);

if (!$ai['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل تحليل الهوية البصرية', null, 'refund');
    json_response(['ok' => false, 'error' => $ai['error'] ?? 'فشل التحليل']);
}

$text = trim(preg_replace('/\[\/?[A-Z_]+\]/', '', $ai['response'] ?? ''));
$text = mb_substr($text, 0, 6000);

db_run('UPDATE brand_profiles SET visual_identity = ?, visual_identity_at = NOW() WHERE id = ?', [$text, $brand['id']]);
ai_log_usage((int) $user['id'], 'visual_identity', $ai['model'] ?? 'router', $ai['usage'] ?? [], (int) $brand['id']);

json_response([
    'ok' => true,
    'visual_identity' => $text,
    'images_analyzed' => count($images),
]);
