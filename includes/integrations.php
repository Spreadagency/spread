<?php
/**
 * Spread AI — External Integrations
 *
 * - Meta Graph API publishing (Facebook page + Instagram business) — feature 23
 * - Spread CRM webhook on signup — feature 28
 * - Paymob payment gateway (credit packages) — feature 26
 *
 * All settings are managed from admin/integrations.php (stored in settings table).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/credits.php'; // get_setting/set_setting live here

/**
 * Generic JSON POST helper with short timeouts (shared-hosting safe)
 */
function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => !$error && $httpCode >= 200 && $httpCode < 300,
        'code' => $httpCode,
        'error' => $error,
        'data' => json_decode((string) $response, true),
        'raw' => $response
    ];
}

function integrations_log(string $channel, string $message): void
{
    if (!is_dir(LOGS_PATH)) @mkdir(LOGS_PATH, 0755, true);
    @file_put_contents(
        LOGS_PATH . '/integrations.log',
        date('Y-m-d H:i:s') . " [$channel] $message\n",
        FILE_APPEND
    );
}

/* =========================================================
 * META (Facebook + Instagram) — feature 23
 * ========================================================= */

define('META_GRAPH', 'https://graph.facebook.com/v21.0');

/**
 * Publish to the Facebook Page. Text-only or with an image.
 * Returns ['ok', 'post_id', 'error']
 */
function meta_publish_facebook(string $message, ?string $imageUrl = null): array
{
    $pageId = get_setting('meta_page_id', '');
    $token = get_setting('meta_page_token', '');
    if (!$pageId || !$token) {
        return ['ok' => false, 'post_id' => null, 'error' => 'إعدادات فيسبوك غير مكتملة (Page ID / Token)'];
    }

    if ($imageUrl) {
        $r = http_post_json(META_GRAPH . "/{$pageId}/photos", [
            'url' => $imageUrl,
            'caption' => $message,
            'access_token' => $token
        ]);
        $postId = $r['data']['post_id'] ?? $r['data']['id'] ?? null;
    } else {
        $r = http_post_json(META_GRAPH . "/{$pageId}/feed", [
            'message' => $message,
            'access_token' => $token
        ]);
        $postId = $r['data']['id'] ?? null;
    }

    if (!$r['ok'] || !$postId) {
        $err = $r['data']['error']['message'] ?? ($r['error'] ?: 'HTTP ' . $r['code']);
        integrations_log('meta_fb', 'publish failed: ' . $err);
        return ['ok' => false, 'post_id' => null, 'error' => $err];
    }
    return ['ok' => true, 'post_id' => $postId, 'error' => null];
}

/**
 * Publish to Instagram Business (requires an image).
 * Two-step: create media container then publish it.
 * Returns ['ok', 'post_id', 'error']
 */
function meta_publish_instagram(string $caption, string $imageUrl): array
{
    $igUserId = get_setting('meta_ig_user_id', '');
    $token = get_setting('meta_page_token', '');
    if (!$igUserId || !$token) {
        return ['ok' => false, 'post_id' => null, 'error' => 'إعدادات إنستجرام غير مكتملة (IG User ID / Token)'];
    }

    // Step 1: container
    $r1 = http_post_json(META_GRAPH . "/{$igUserId}/media", [
        'image_url' => $imageUrl,
        'caption' => $caption,
        'access_token' => $token
    ]);
    $containerId = $r1['data']['id'] ?? null;
    if (!$r1['ok'] || !$containerId) {
        $err = $r1['data']['error']['message'] ?? ($r1['error'] ?: 'HTTP ' . $r1['code']);
        integrations_log('meta_ig', 'container failed: ' . $err);
        return ['ok' => false, 'post_id' => null, 'error' => $err];
    }

    // Step 2: publish (IG containers usually ready within seconds for images)
    sleep(2);
    $r2 = http_post_json(META_GRAPH . "/{$igUserId}/media_publish", [
        'creation_id' => $containerId,
        'access_token' => $token
    ]);
    $postId = $r2['data']['id'] ?? null;
    if (!$r2['ok'] || !$postId) {
        $err = $r2['data']['error']['message'] ?? ($r2['error'] ?: 'HTTP ' . $r2['code']);
        integrations_log('meta_ig', 'publish failed: ' . $err);
        return ['ok' => false, 'post_id' => null, 'error' => $err];
    }
    return ['ok' => true, 'post_id' => $postId, 'error' => null];
}

/**
 * Publish one content row to its chosen platform(s).
 * Used by cron/publish-scheduled.php and manual publish.
 * Returns ['ok', 'post_id', 'error']
 */
function publish_content(array $content): array
{
    $text = trim(($content['generated_text'] ?? '') . "\n\n" . ($content['hashtags'] ?? ''));
    $platform = $content['publish_platform'] ?: ($content['platform'] ?? 'facebook');

    // Attach latest design image if one exists
    $imageUrl = null;
    $design = db_one(
        'SELECT image_path FROM content_designs WHERE content_id = ? ORDER BY id DESC LIMIT 1',
        [$content['id']]
    );
    if ($design) {
        $imageUrl = rtrim(APP_URL, '/') . '/storage/' . $design['image_path'];
    }

    if ($platform === 'instagram') {
        if (!$imageUrl) {
            return ['ok' => false, 'post_id' => null, 'error' => 'النشر على إنستجرام يتطلب تصميم/صورة'];
        }
        return meta_publish_instagram($text, $imageUrl);
    }

    if ($platform === 'both') {
        $fb = meta_publish_facebook($text, $imageUrl);
        $ig = $imageUrl ? meta_publish_instagram($text, $imageUrl) : ['ok' => false, 'error' => 'لا توجد صورة لإنستجرام'];
        if ($fb['ok'] || ($ig['ok'] ?? false)) {
            $ids = array_filter([$fb['post_id'] ?? null, $ig['post_id'] ?? null]);
            $errs = array_filter([$fb['ok'] ? null : 'FB: ' . $fb['error'], ($ig['ok'] ?? false) ? null : 'IG: ' . ($ig['error'] ?? '')]);
            return ['ok' => true, 'post_id' => implode(',', $ids), 'error' => $errs ? implode(' | ', $errs) : null];
        }
        return ['ok' => false, 'post_id' => null, 'error' => 'FB: ' . $fb['error'] . ' | IG: ' . ($ig['error'] ?? '')];
    }

    // default: facebook
    return meta_publish_facebook($text, $imageUrl);
}

/* =========================================================
 * SPREAD CRM WEBHOOK — feature 28
 * ========================================================= */

/**
 * Fire-and-forget notification to Spread CRM on new signup.
 * Never blocks or fails the signup flow.
 */
function crm_notify_signup(string $name, string $email, string $phone = ''): void
{
    $url = get_setting('crm_webhook_url', '');
    if (!$url) return;

    $secret = get_setting('crm_webhook_secret', '');
    $payload = [
        'source' => 'spread-ai',
        'event' => 'user_registered',
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'timestamp' => date('c')
    ];
    $headers = [];
    if ($secret) {
        $headers[] = 'X-Webhook-Signature: ' . hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE), $secret);
    }

    try {
        $r = http_post_json($url, $payload, $headers, 5);
        if (!$r['ok']) {
            integrations_log('crm', 'webhook failed HTTP ' . $r['code'] . ' ' . ($r['error'] ?? ''));
        }
    } catch (\Throwable $e) {
        integrations_log('crm', 'webhook exception: ' . $e->getMessage());
    }
}

/* =========================================================
 * PAYMOB — feature 26
 * ========================================================= */

define('PAYMOB_API', 'https://accept.paymob.com/api');

/**
 * Full Paymob flow: auth -> order -> payment key.
 * Returns ['ok', 'iframe_url', 'paymob_order_id', 'error']
 */
function paymob_create_payment(array $user, array $package, int $localOrderId): array
{
    $apiKey = get_setting('paymob_api_key', '');
    $integrationId = get_setting('paymob_integration_id', '');
    $iframeId = get_setting('paymob_iframe_id', '');
    if (!$apiKey || !$integrationId || !$iframeId) {
        return ['ok' => false, 'iframe_url' => null, 'paymob_order_id' => null, 'error' => 'بوابة الدفع غير مفعلة حاليًا'];
    }

    $amountCents = (int) round(((float) $package['price_egp']) * 100);

    // 1) Auth token
    $r1 = http_post_json(PAYMOB_API . '/auth/tokens', ['api_key' => $apiKey]);
    $token = $r1['data']['token'] ?? null;
    if (!$token) {
        integrations_log('paymob', 'auth failed HTTP ' . $r1['code']);
        return ['ok' => false, 'iframe_url' => null, 'paymob_order_id' => null, 'error' => 'تعذر الاتصال ببوابة الدفع'];
    }

    // 2) Order
    $r2 = http_post_json(PAYMOB_API . '/ecommerce/orders', [
        'auth_token' => $token,
        'delivery_needed' => false,
        'amount_cents' => $amountCents,
        'currency' => 'EGP',
        'merchant_order_id' => 'SPAI-' . $localOrderId . '-' . time(),
        'items' => [[
            'name' => $package['name'],
            'amount_cents' => $amountCents,
            'quantity' => 1
        ]]
    ]);
    $paymobOrderId = $r2['data']['id'] ?? null;
    if (!$paymobOrderId) {
        integrations_log('paymob', 'order failed HTTP ' . $r2['code'] . ' ' . substr((string) $r2['raw'], 0, 500));
        return ['ok' => false, 'iframe_url' => null, 'paymob_order_id' => null, 'error' => 'تعذر إنشاء طلب الدفع'];
    }

    // 3) Payment key
    $nameParts = explode(' ', trim($user['name'] ?? 'User'), 2);
    $r3 = http_post_json(PAYMOB_API . '/acceptance/payment_keys', [
        'auth_token' => $token,
        'amount_cents' => $amountCents,
        'expiration' => 3600,
        'order_id' => $paymobOrderId,
        'currency' => 'EGP',
        'integration_id' => (int) $integrationId,
        'billing_data' => [
            'first_name' => $nameParts[0] ?: 'User',
            'last_name' => $nameParts[1] ?? 'SpreadAI',
            'email' => $user['email'] ?? 'na@spreadagency.net',
            'phone_number' => $user['phone'] ?: '+201000000000',
            'country' => 'EG', 'city' => 'NA', 'street' => 'NA',
            'building' => 'NA', 'floor' => 'NA', 'apartment' => 'NA'
        ]
    ]);
    $paymentToken = $r3['data']['token'] ?? null;
    if (!$paymentToken) {
        integrations_log('paymob', 'payment key failed HTTP ' . $r3['code'] . ' ' . substr((string) $r3['raw'], 0, 500));
        return ['ok' => false, 'iframe_url' => null, 'paymob_order_id' => null, 'error' => 'تعذر تجهيز صفحة الدفع'];
    }

    return [
        'ok' => true,
        'iframe_url' => 'https://accept.paymob.com/api/acceptance/iframes/' . $iframeId . '?payment_token=' . $paymentToken,
        'paymob_order_id' => (string) $paymobOrderId,
        'error' => null
    ];
}

/**
 * Verify Paymob transaction-processed callback HMAC (GET params).
 */
function paymob_verify_hmac(array $q): bool
{
    $secret = get_setting('paymob_hmac', '');
    if (!$secret) return false;

    $keys = [
        'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction',
        'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
        'is_standalone_payment', 'is_voided', 'order', 'owner', 'pending',
        'source_data_pan', 'source_data_sub_type', 'source_data_type', 'success'
    ];
    $concat = '';
    foreach ($keys as $k) {
        $concat .= $q[$k] ?? '';
    }
    $calculated = hash_hmac('sha512', $concat, $secret);
    return hash_equals($calculated, $q['hmac'] ?? '');
}
