<?php
/**
 * /api/v1/call.php?ep=<name>[&action=…] — نفس endpoints الموقع بالظبط، بتوكن الجهاز
 *
 * مفيش منطق مكرر: الطلب بيتنفّذ بنفس ملف الموقع (الخصم · الحصص · بوابة الاشتراك · الملكية · حدود الطلبات)
 * الإضافات هنا بس:
 *   • التحقق من التوكن ← رد JSON (بدل التحويل لصفحة الدخول)
 *   • Idempotency-Key للعمليات المكلفة ← إعادة نفس الطلب (النت فصل مثلًا) بترجّع نفس النتيجة من غير خصم تاني
 *
 * api/*  : JSON (أو X-Payload: b64) — نفس الـ actions الموثّقة في كل ملف
 * ajax/* : form-urlencoded أو multipart (رفع صور) — نفس أسماء الحقول
 */
require_once __DIR__ . '/_init.php';

/** القائمة المسموحة: الاسم ← [الملف, عملية مكلفة؟ (Idempotency-Key مطلوب للـ POST)] */
function v1_endpoints(): array
{
    return [
        // JSON API
        'contents'           => ['api/contents.php', false],
        'studio'             => ['api/studio.php', false],
        'settings'           => ['api/settings.php', false],
        'brand'              => ['api/brand.php', false],
        'campaigns'          => ['api/campaigns.php', false],
        'campaign_flow'      => ['api/campaign-flow.php', false],
        'journey'            => ['api/journey.php', false],
        // نماذج الموقع (form / multipart)
        'generate_content'   => ['ajax/generate-content.php', true],
        'regenerate_content' => ['ajax/regenerate-content.php', true],
        'save_content'       => ['ajax/save-content.php', false],
        'generate_design'    => ['ajax/generate-design.php', true],
        'studio_design'      => ['ajax/studio-design.php', true],
        'generate_logo'      => ['ajax/generate-logo.php', true],
        'upload_image'       => ['ajax/upload-image.php', false],
        'delete_image'       => ['ajax/delete-image.php', false],
        'add_note'           => ['ajax/add-note.php', false],
        'publish_direct'     => ['ajax/publish-direct.php', true],
        'schedule_content'   => ['ajax/schedule-content.php', false],
    ];
}

/** actions مكلفة جوه الـ JSON API (Idempotency-Key مطلوب) */
function v1_costly_action(string $ep, string $action): bool
{
    $map = [
        'contents'      => ['ai_edit'],
        'studio'        => ['brief', 'trend_ideas'],
        'brand'         => ['analyze_url', 'logo_colors'],
        'campaign_flow' => ['ideas_generate', 'content_generate', 'evaluate', 'improve'],
    ];
    return in_array($action, $map[$ep] ?? [], true);
}

$ep = preg_replace('/[^a-z_]/', '', strtolower((string) ($_GET['ep'] ?? '')));
$list = v1_endpoints();
if (!isset($list[$ep])) {
    api_fail('الخدمة دي مش متاحة للتطبيق', 'unknown_endpoint', 404);
}
[$file, $costly] = $list[$ep];

$user = mobile_require_user();
$uid = (int) $user['id'];
// حساب جوجل من غير موبايل: الموقع بيحوّله لصفحة «كمّل بياناتك» — هنا بنرجّع كود والتطبيق يكمّلها
if (function_exists('account_needs_phone') && account_needs_phone($user)) {
    api_fail('كمّل رقم موبايلك الأول', 'needs_phone', 403);
}

$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
if ($isPost) {
    $act = $file && str_starts_with($file, 'api/') ? api_action() : '';
    if ($costly || ($act !== '' && v1_costly_action($ep, $act))) {
        if (mobile_idem_key() === null) {
            api_fail('Idempotency-Key مطلوب للعملية دي', 'idempotency_required', 428);
        }
        mobile_idem_begin($uid, $ep . ($act !== '' ? ':' . $act : ''));
    }
}

mobile_satisfy_csrf();

$abs = realpath(__DIR__ . '/../../' . $file);
$root = realpath(__DIR__ . '/../../');
if (!$abs || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || !is_file($abs)) {
    api_fail('الخدمة دي مش متاحة', 'unknown_endpoint', 404);
}

// ملفات ajax: الأخطاء غير المتوقعة ترجع JSON برضه
set_exception_handler(function (\Throwable $e) {
    error_log('[api/v1 call] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_fail('حصلت مشكلة مؤقتة — جرّب تاني بعد لحظة', 'server_error', 500);
});

unset($_GET['ep']);
$_SERVER['SCRIPT_NAME'] = '/' . $file;
$_SERVER['PHP_SELF'] = '/' . $file;
$_SERVER['SCRIPT_FILENAME'] = $abs;
chdir(dirname($abs));
require $abs;
