<?php
/**
 * /api/v1/auth.php — دخول تطبيق الموبايل (نفس قواعد الموقع بالظبط)
 *
 *   POST action=login               {email, password}                 → {token, user} أو {two_factor:true, challenge}
 *   POST action=verify_code          {code}      + Bearer <challenge>  → {token, user}
 *   POST action=resend_code                      + Bearer <challenge>
 *   POST action=register            {name, email, phone, password, password_confirm, business_name?, ref_code?}
 *   POST action=forgot_password     {email}
 *   POST action=resend_verification {email}
 *   GET  action=me                              + Bearer              → {user, brand, balance}
 *   POST action=logout                          + Bearer
 *   POST action=push_register       {push_token}  + Bearer            (Expo push token — فاضي = إلغاء)
 */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../../../includes/mailer.php';

$action = api_action();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function v1_auth_success(array $user): void
{
    $tok = mobile_issue_token((int) $user['id'], 'access');
    mobile_switch_session($tok, (int) $user['id']);
    api_ok(['token' => $tok, 'expires_in' => MOBILE_TOKEN_DAYS * 86400, 'user' => mobile_user_api($user)]);
}

function v1_email(): string
{
    return mb_substr(trim(strtolower(api_str('email', 150))), 0, 150);
}

switch ($action) {

    case 'login': {
        v1_require_post();
        $email = v1_email();
        $password = (string) api_param('password', '');
        if (!valid_email($email) || $password === '') {
            api_fail('اكتب الإيميل وكلمة المرور', 'validation', 422);
        }
        if (!rate_limit_ok('login', client_ip(), 6, 600)) {
            api_fail('محاولات كتير. استنى 10 دقايق وجرب تاني.', 'rate_limit', 429);
        }
        $r = authenticate_user($email, $password);
        if (!$r['ok']) {
            rate_limit_hit('login', client_ip());
            api_fail((string) $r['error'], !empty($r['use_google']) ? 'use_google' : 'invalid_credentials', 401);
        }
        $user = $r['user'];
        if ($b = mobile_account_block($user)) {
            api_fail($b['error'], $b['code'], $b['http']);
        }
        if (function_exists('account_2fa_on') && account_2fa_on($user)) {
            $ch = mobile_issue_token((int) $user['id'], 'challenge');
            // جلسة كود التحقق (فيها الكود المتشفّر بتاع account_2fa_send)
            $_SESSION = [];
            session_destroy();
            session_id(mobile_sid($ch));
            session_start();
            $GLOBALS['__mobile_ephemeral'] = false;
            $_SESSION['2fa_pending'] = ['uid' => (int) $user['id'], 'next' => 'dashboard.php', 'at' => time()];
            $s = account_2fa_send($user, 'login');
            if (!$s['ok']) {
                mobile_revoke_token($ch);
                api_fail((string) $s['error'], '2fa_send_failed', 503);
            }
            $em = (string) $user['email'];
            $at = strpos($em, '@');
            api_ok(['two_factor' => true, 'challenge' => $ch, 'expires_in' => MOBILE_CHALLENGE_SEC,
                    'email_hint' => $at > 1 ? mb_substr($em, 0, 2) . '•••' . substr($em, $at) : $em]);
        }
        v1_auth_success($user);
    }

    case 'verify_code':
    case 'resend_code': {
        v1_require_post();
        $tok = mobile_bearer();
        $row = $tok ? mobile_token_row($tok, 'challenge') : null;
        if (!$row) {
            api_fail('انتهت مهلة التحقق — سجّل دخولك تاني', 'challenge_expired', 401);
        }
        $user = db_one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
        if (!$user || ($b = mobile_account_block($user))) {
            mobile_revoke_token($tok);
            api_fail($b['error'] ?? 'الحساب غير متاح', $b['code'] ?? 'auth', 403);
        }
        if ($action === 'resend_code') {
            $s = account_2fa_send($user, 'login');
            $s['ok'] ? api_ok(['sent' => true]) : api_fail((string) $s['error'], 'rate_limit', 429);
        }
        if (!rate_limit('login_verify', client_ip(), 10, 600)) {
            api_fail('محاولات كتير — استنى شوية', 'rate_limit', 429);
        }
        $v = account_2fa_verify((int) $user['id'], 'login', api_str('code', 12));
        if (!$v['ok']) {
            api_fail((string) $v['error'], 'invalid_code', 422);
        }
        mobile_revoke_token($tok);
        v1_auth_success($user);
    }

    case 'register': {
        v1_require_post();
        require_once __DIR__ . '/../../../includes/integrations.php';
        $name = api_str('name', 150);
        $email = v1_email();
        $password = (string) api_param('password', '');
        $confirm = (string) api_param('password_confirm', $password);
        $phone = preg_replace('/[^0-9+]/', '', api_str('phone', 30));
        $business = api_str('business_name', 120);
        if ($password !== $confirm) {
            api_fail('كلمتا المرور مش متطابقتين', 'validation', 422);
        }
        if (strlen(preg_replace('/\D/', '', $phone)) < 8) {
            api_fail('اكتب رقم موبايل صحيح', 'validation', 422);
        }
        if (!rate_limit('register', client_ip(), 5, 3600)) {
            api_fail('محاولات تسجيل كتير من نفس الجهاز. جرب تاني بعد ساعة.', 'rate_limit', 429);
        }
        $r = register_user($name, $email, $password);
        if (!$r['ok']) {
            api_fail((string) $r['error'], 'validation', 422);
        }
        $uid = (int) $r['user_id'];
        // نفس خطوات public/register.php
        if ($business !== '') {
            db_run('UPDATE brand_profiles SET business_name = ? WHERE user_id = ?
                    AND (business_name IS NULL OR business_name = "" OR business_name = ?)', [$business, $uid, $name]);
        }
        db_run('UPDATE users SET phone = ? WHERE id = ?', [mb_substr($phone, 0, 30), $uid]);
        if (function_exists('referral_attach_on_register')) {
            try { referral_attach_on_register($uid, api_str('ref_code', 40) ?: null); } catch (\Throwable $e) { error_log('[api/v1] referral: ' . $e->getMessage()); }
        }
        $manual = get_setting('manual_approval', '0') === '1';
        if ($manual) {
            db_run('UPDATE users SET approval_status = "pending" WHERE id = ?', [$uid]);
        }
        $vt = create_verification_token($uid);
        send_verification_email($name, $email, $vt);
        if (function_exists('crm_notify_signup')) {
            try { crm_notify_signup($name, $email); } catch (\Throwable $e) {}
        }
        api_ok(['registered' => true, 'needs_verification' => true, 'pending_approval' => $manual,
                'message' => $manual ? 'تم إنشاء حسابك! فعّل بريدك، وبعدها الإدارة هتراجع حسابك وتوافق عليه قريبًا.'
                                     : 'تم إنشاء حسابك بنجاح! افتح بريدك (والإسبام) وفعّل الحساب، وبعدها سجّل دخولك.'], 201);
    }

    case 'forgot_password': {
        v1_require_post();
        $email = v1_email();
        if (!valid_email($email)) {
            api_fail('البريد الإلكتروني غير صحيح', 'validation', 422);
        }
        if (!rate_limit('forgot', client_ip(), 5, 3600)) {
            api_fail('محاولات كتير. جرب تاني بعد ساعة.', 'rate_limit', 429);
        }
        $user = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($user) {
            $token = random_token(64);
            db_run('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)',
                [$user['id'], $token, date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL)]);
            send_reset_email((string) $user['name'], $email, $token);
        }
        // نفس الرسالة دايمًا (مانكشفش الإيميلات المسجلة)
        api_ok(['message' => 'لو الإيميل موجود في النظام، هتلاقي رابط إعادة التعيين خلال دقايق']);
    }

    case 'resend_verification': {
        v1_require_post();
        $email = v1_email();
        if (!valid_email($email)) {
            api_fail('البريد الإلكتروني غير صحيح', 'validation', 422);
        }
        if (!rate_limit('resend', client_ip(), 5, 3600)) {
            api_fail('محاولات كتير. جرب تاني بعد ساعة.', 'rate_limit', 429);
        }
        $user = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($user && !$user['email_verified_at'] && $user['status'] === 'active') {
            $recent = db_one('SELECT id FROM email_verifications WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1', [$user['id']]);
            if (!$recent) {
                send_verification_email((string) $user['name'], $email, create_verification_token((int) $user['id']));
            }
        }
        api_ok(['message' => 'لو الإيميل موجود ومش مفعل، هيوصلك رابط تفعيل جديد خلال دقايق.']);
    }

    case 'me': {
        $user = mobile_require_user(true);
        $a = mobile_authenticate();
        $brand = user_brand((int) $user['id']);
        api_ok([
            'user'    => mobile_user_api($user),
            'block'   => $a['block'] ? ['code' => $a['block']['code'], 'error' => $a['block']['error']] : null,
            'brand'   => $brand ? ['id' => (int) $brand['id'], 'name' => (string) ($brand['business_name'] ?? ''),
                                   'logo' => mobile_media_url($brand['logo_path'] ?? null)] : null,
            'balance' => credits_balance((int) $user['id']),
        ]);
    }

    case 'logout': {
        v1_require_post();
        $tok = mobile_bearer();
        if ($tok) {
            if (function_exists('account_session_end_current')) account_session_end_current();
            db_run('UPDATE mobile_tokens SET revoked_at = NOW(), push_token = NULL WHERE token_hash = ?', [mobile_token_hash($tok)]);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        api_ok(['logged_out' => true]);
    }

    case 'push_register': {
        v1_require_post();
        mobile_require_user(true);
        $a = mobile_authenticate();
        $pt = api_str('push_token', 255);
        if ($pt !== '' && !preg_match('/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]{10,200}\]$/', $pt)) {
            api_fail('توكن الإشعارات غير صالح', 'validation', 422);
        }
        db_run('UPDATE mobile_tokens SET push_token = ? WHERE id = ?', [$pt !== '' ? $pt : null, (int) $a['row']['id']]);
        api_ok(['registered' => $pt !== '']);
    }

    default:
        api_fail('إجراء غير معروف', 'unknown_action', 404);
}
