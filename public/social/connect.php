<?php
/**
 * Spread AI v2 — بدء ربط فيسبوك (OAuth redirect)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/social.php';

social_guard('connect');

require_login();
$user = current_user();
feature_require((int) $user['id']);

// تأكد إن جداول الوحدة موجودة قبل أي حاجة
try {
    social_ensure_schema();
} catch (\Throwable $e) {
    social_log_error('connect ensure_schema: ' . $e->getMessage());
}

if (!meta_configured()) {
    social_render_error(
        'ربط فيسبوك غير مضبوط',
        'App ID أو App Secret ناقص (أو مش بيتفك تشفيره). روح الأدمن ← التكاملات ← تطبيق ميتا واحفظ البيانات تاني.',
        false
    );
    exit;
}

if (!rate_limit('social_connect', 'u' . $user['id'], 5, 3600)) {
    flash_set('danger', 'محاولات ربط كتير — استنى شوية وجرب تاني');
    redirect('social-accounts.php');
}

// حد الصفحات
$current = count(user_connections((int) $user['id'], null));
if ($current >= feature_max_pages((int) $user['id'])) {
    flash_set('warning', 'وصلت للحد الأقصى من الصفحات المربوطة (' . feature_max_pages((int) $user['id']) . ') — افصل صفحة الأول أو اطلب زيادة الحد من الإدارة.');
    redirect('social-accounts.php');
}

// state ضد CSRF
$state = bin2hex(random_bytes(32));
$_SESSION['fb_oauth_state'] = $state;
$_SESSION['fb_oauth_time'] = time();

$authUrl = fb_oauth_base() . '/dialog/oauth?' . http_build_query([
    'client_id' => meta_app_id(),
    'redirect_uri' => social_callback_url(),
    'state' => $state,
    'scope' => 'pages_show_list,pages_manage_posts,pages_read_engagement,instagram_basic,instagram_content_publish,business_management',
    'response_type' => 'code',
]);

header('Location: ' . $authUrl);
exit;
