<?php
/**
 * Spread AI v2 — API مركز الإعدادات (المرحلة ⑥-أ)
 *
 *   GET  action=home            كل بيانات الصفحة: الحساب · الباقة والاستخدام · الإحالة · الحسابات المربوطة · الأمان
 *   POST action=profile_save    {name, phone}
 *   POST action=avatar          {image: data:image/jpeg;base64,…}  (الواجهة بتصغّرها لـ 256px قبل الرفع)
 *   POST action=avatar_remove
 *   POST action=password        {current, new, confirm}
 *   POST action=session_end     {id}          · action=sessions_end_others
 *   POST action=twofa_start     {purpose: enable|disable} ← كود على الإيميل
 *   POST action=twofa_confirm   {code}
 *   POST action=delete_request  {password | email}  (مهلة 14 يوم) · action=delete_cancel
 *   POST action=disconnect      {id}          فصل صفحة (نفس «حساباتي المربوطة»)
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/uploader.php';
require_once __DIR__ . '/../../includes/social.php';
require_once __DIR__ . '/../../includes/account.php';
require_once __DIR__ . '/../../includes/ui-v2.php';   // ui_credits_usage

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'home';

/** مسار صورة الحساب: رفع داخلي (uploads/…) أو رابط جوجل */
function settings_avatar(array $u): ?string
{
    $a = (string) ($u['avatar_url'] ?? '');
    if ($a === '') return null;
    return preg_match('#^https?://#i', $a) ? $a : upload_url($a);
}

switch ($action) {

    case 'home':
        $u = db_one('SELECT * FROM users WHERE id = ?', [$uid]);

        // ── الباقة والاستخدام ──
        $usage = ui_credits_usage($uid);
        $packages = array_map(fn($p) => [
            'id' => (int) $p['id'], 'name' => (string) $p['name'], 'credits' => credits_show_numbers() ? (int) $p['credits'] : null,
            'price' => (float) $p['price_egp'], 'days' => (int) ($p['validity_days'] ?? 30),
            'badge' => (string) ($p['badge'] ?? ''), 'featured' => !empty($p['is_featured']),
            'quotas' => function_exists('plan_quotas_decode') ? array_values(array_filter(array_map(fn($k, $v) => $v > 0 ? ['k' => $k, 't' => plan_units()[$k][1], 'e' => plan_units()[$k][0], 'n' => $v] : null,
                array_keys(plan_quotas_decode($p['quotas_json'] ?? null)), plan_quotas_decode($p['quotas_json'] ?? null)))) : [],
        ], db_all('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY order_num ASC, id ASC LIMIT 6'));
        $planName = $usage['plan'] ?? account_plan_label($uid);

        // ── الإحالة ──
        $ref = null;
        if (get_setting('referral_enabled', '1') === '1') {
            try {
                require_once __DIR__ . '/../../includes/offers.php';
                $offer = ensure_user_referral_offer($uid);
                if ($offer) {
                    $st = db_one('SELECT COUNT(*) total, SUM(status = "rewarded") rewarded FROM referrals WHERE referrer_user_id = ?', [$uid]) ?: [];
                    $earned = (int) (db_one('SELECT COALESCE(SUM(credits_given), 0) c FROM offer_redemptions
                                             WHERE user_id = ? AND role = "referrer" AND status = "granted"', [$uid])['c'] ?? 0);
                    $link = rtrim(APP_URL, '/') . '/register.php?ref=' . urlencode($offer['code']);
                    $ref = [
                        'link' => $link, 'code' => (string) $offer['code'],
                        'joined' => (int) ($st['total'] ?? 0), 'rewarded' => (int) ($st['rewarded'] ?? 0), 'earned' => $earned,
                        'give' => (int) $offer['referee_credits'], 'get' => (int) $offer['referrer_credits'],
                        'share' => "جرّب Spread AI — منصة المحتوى والتصميم بالذكاء الاصطناعي 🎨\nسجّل من اللينك ده وهتلاقي رصيد هدية في حسابك:\n" . $link,
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[settings] referral: ' . $e->getMessage());
            }
        }

        // ── الحسابات المربوطة ──
        $allowed = feature_allows($uid);
        $pages = [];
        foreach (user_connections($uid, null) as $c) {
            $pages[] = [
                'id' => (int) $c['id'], 'name' => (string) $c['page_name'], 'avatar' => (string) ($c['page_avatar_url'] ?? ''),
                'ig' => (string) ($c['ig_username'] ?? ''), 'has_ig' => !empty($c['ig_user_id']),
                'status' => (string) $c['status'],
            ];
        }
        $maxPages = $allowed ? feature_max_pages($uid) : 0;

        // ── الأمان ──
        $pwAt = $u['password_changed_at'] ?? null;
        $del = account_deletion_date($u);

        api_ok([
            'profile' => [
                'name' => (string) $u['name'], 'email' => (string) $u['email'], 'phone' => (string) ($u['phone'] ?? ''),
                'avatar' => settings_avatar($u), 'initials' => function_exists('initials') ? initials((string) $u['name']) : mb_substr((string) $u['name'], 0, 1),
                'since' => function_exists('fmt_date') ? fmt_date($u['created_at']) : substr((string) $u['created_at'], 0, 10),
                'google' => !empty($u['google_id']),
            ],
            'plan' => [
                // 8-ب: في وضع النسبة % أرقام الكريدت مابتطلعش من السيرفر أصلًا
                'name' => $planName, 'pct' => (int) $usage['pct'],
                'balance' => ($usage['show'] ?? true) ? (int) $usage['balance'] : null,
                'used' => ($usage['show'] ?? true) ? (int) $usage['used'] : null, 'total' => ($usage['show'] ?? true) ? (int) $usage['total'] : null,
                'expires' => $usage['expires_at'] ? date('j', strtotime($usage['expires_at'])) . ' ' . settings_month((int) date('n', strtotime($usage['expires_at']))) . ' ' . date('Y', strtotime($usage['expires_at'])) : null,
                'days_left' => $usage['days_left'],
                'breakdown' => ($usage['show'] ?? true) ? account_usage_breakdown($uid) : [],
                'packages' => $packages,
                // 8-ب: العرض بالنسبة % + الحصص
                'show' => (bool) ($usage['show'] ?? true),
                'units' => array_values(array_map(fn($u) => ['k' => $u['key'], 't' => $u['label'], 'e' => $u['emoji'], 'pct' => $u['pct'],
                    'used' => ($usage['show'] ?? true) ? $u['used'] : null, 'limit' => empty($u['open']) ? (int) $u['limit'] : null], $usage['units'] ?? [])),
                'renews' => $usage['ends_label'] ?? null,
                'upgrade' => function_exists('plan_upgrade_link') ? plan_upgrade_link() : null,
            ],
            'referral' => $ref,
            'accounts' => [
                'allowed' => $allowed, 'pages' => $pages, 'max' => $maxPages,
                'can_add' => $allowed && count($pages) < $maxPages,
            ],
            'security' => [
                'has_password' => !empty($u['password']),
                'password_at' => $pwAt ? str_replace('نشط دلوقتي', 'دلوقتي', account_ago($pwAt)) : null,
                'two_fa' => !empty($u['two_fa_enabled']),
                'sessions' => account_sessions($uid),
                'deletion' => $del ? ['date' => fmt_date($del), 'days' => max(0, (int) ceil((strtotime($del) - time()) / 86400))] : null,
            ],
            'ui_v1_allowed' => get_setting('ui_v2_mode', 'optin') !== 'on',
        ]);

    case 'profile_save':
        $name = api_str('name', 120);
        if (mb_strlen($name) < 2) api_fail('اكتب اسمك (حرفين على الأقل)', 'invalid', 422);
        $phone = mb_substr(preg_replace('/[^0-9+]/', '', api_str('phone', 40)), 0, 30);
        if ($phone !== '' && strlen(preg_replace('/\D/', '', $phone)) < 8) api_fail('رقم الموبايل ناقص', 'invalid', 422);
        db_run('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone ?: null, $uid]);
        $_SESSION['user_name'] = $name;
        api_ok(['name' => $name, 'phone' => $phone, 'initials' => function_exists('initials') ? initials($name) : mb_substr($name, 0, 1)]);

    case 'avatar':
        if (!rate_limit('avatar', 'u' . $uid, 10, 3600)) api_fail('غيّرت الصورة كتير — استنى شوية', 'rate_limit', 429);
        $data = (string) api_param('image', '');
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
            api_fail('الصورة مش مدعومة — JPG أو PNG', 'invalid', 422);
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 1024 * 1024) api_fail('الصورة كبيرة — اختار صورة أصغر', 'too_big', 422);
        // لازم تكون صورة حقيقية (مش ملف متنكّر)
        $info = @getimagesizefromstring($bin);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 2048 || $info[1] > 2048) {
            api_fail('الملف ده مش صورة صالحة', 'invalid', 422);
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $dir = UPLOADS_PATH . '/avatars';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $rel = 'uploads/avatars/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (@file_put_contents(STORAGE_PATH . '/' . $rel, $bin) === false) api_fail('تعذّر حفظ الصورة', 'io', 500);
        $old = (string) ($user['avatar_url'] ?? '');
        db_run('UPDATE users SET avatar_url = ? WHERE id = ?', [$rel, $uid]);
        if ($old !== '' && str_starts_with($old, 'uploads/avatars/')) delete_upload($old);
        api_ok(['avatar' => upload_url($rel)]);

    case 'avatar_remove':
        $old = (string) ($user['avatar_url'] ?? '');
        db_run('UPDATE users SET avatar_url = NULL WHERE id = ?', [$uid]);
        if ($old !== '' && str_starts_with($old, 'uploads/avatars/')) delete_upload($old);
        api_ok();

    case 'password':
        if (!rate_limit('pw_change', 'u' . $uid, 8, 900)) api_fail('محاولات كتير — استنى ربع ساعة', 'rate_limit', 429);
        $cur = (string) api_param('current', '');
        $new = (string) api_param('new', '');
        $conf = (string) api_param('confirm', '');
        $u = db_one('SELECT password, auth_provider FROM users WHERE id = ?', [$uid]);
        $first = empty($u['password']);   // حساب جوجل بيضبط أول باسورد من غير القديمة
        if (!$first && !password_verify($cur, (string) $u['password'])) api_fail('كلمة المرور الحالية غلط', 'wrong_password', 422);
        if (strlen($new) < 6) api_fail('كلمة المرور الجديدة لازم تكون 6 حروف على الأقل', 'invalid', 422);
        if ($new !== $conf) api_fail('كلمتين المرور مش متطابقين', 'mismatch', 422);
        if (!$first && password_verify($new, (string) $u['password'])) api_fail('دي نفس كلمة المرور الحالية', 'same', 422);
        try {
            db_run('UPDATE users SET password = ?, auth_provider = ?, password_changed_at = NOW() WHERE id = ?',
                [password_hash($new, PASSWORD_BCRYPT), $first ? 'both' : ($u['auth_provider'] ?? 'password'), $uid]);
        } catch (\Throwable $e) {   // قبل ترحيل ⑥-أ
            db_run('UPDATE users SET password = ?, auth_provider = ? WHERE id = ?',
                [password_hash($new, PASSWORD_BCRYPT), $first ? 'both' : ($u['auth_provider'] ?? 'password'), $uid]);
        }
        // الأجهزة التانية تخرج — لو حد عارف الباسورد القديم
        $ended = account_session_revoke_others($uid);
        api_ok(['msg' => ($first ? 'اتضبطت كلمة المرور ✓' : 'اتغيّرت كلمة المرور ✓') . ($ended ? " وخرّجنا {$ended} جهاز تاني" : '')]);

    case 'session_end':
        if (!account_session_revoke($uid, api_int('id'))) api_fail('الجلسة دي مش موجودة أو اتنهت', 'not_found', 404);
        api_ok(['sessions' => account_sessions($uid)]);

    case 'sessions_end_others':
        $n = account_session_revoke_others($uid);
        api_ok(['ended' => $n, 'sessions' => account_sessions($uid)]);

    case 'twofa_start':
        $purpose = api_str('purpose', 10) === 'disable' ? 'disable' : 'enable';
        if (($purpose === 'enable') === !empty($user['two_fa_enabled'])) {
            api_fail($purpose === 'enable' ? 'التحقق بخطوتين مفعّل أصلًا' : 'التحقق بخطوتين مش مفعّل', 'state', 409);
        }
        $r = account_2fa_send($user, $purpose);
        if (!$r['ok']) api_fail($r['error'], 'send_failed', 422);
        api_ok(['purpose' => $purpose]);

    case 'twofa_confirm':
        $purpose = (string) (($_SESSION['2fa']['purpose'] ?? '') ?: 'enable');
        if (!in_array($purpose, ['enable', 'disable'], true)) api_fail('اطلب كود جديد', 'state', 409);
        $r = account_2fa_verify($uid, $purpose, api_str('code', 12));
        if (!$r['ok']) api_fail($r['error'], 'bad_code', 422);
        db_run('UPDATE users SET two_fa_enabled = ? WHERE id = ?', [$purpose === 'enable' ? 1 : 0, $uid]);
        api_ok(['two_fa' => $purpose === 'enable']);

    case 'delete_request':
        if (!empty($user['deletion_requested_at'])) api_fail('فيه طلب حذف شغّال بالفعل', 'state', 409);
        // تأكيد الهوية: الباسورد — أو الإيميل لحساب جوجل من غير باسورد
        if (!empty($user['password'])) {
            if (!password_verify((string) api_param('password', ''), (string) $user['password'])) api_fail('كلمة المرور غلط', 'wrong_password', 422);
        } elseif (mb_strtolower(api_str('email', 190)) !== mb_strtolower((string) $user['email'])) {
            api_fail('اكتب إيميل حسابك بالظبط للتأكيد', 'mismatch', 422);
        }
        db_run('UPDATE users SET deletion_requested_at = NOW() WHERE id = ?', [$uid]);
        $u = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
        $d = account_deletion_date($u);
        api_ok(['deletion' => ['date' => fmt_date($d), 'days' => ACCOUNT_DELETE_GRACE_DAYS]]);

    case 'delete_cancel':
        db_run('UPDATE users SET deletion_requested_at = NULL WHERE id = ?', [$uid]);
        api_ok();

    case 'disconnect':
        $conn = connection_for_user(api_int('id'), $uid);
        if (!$conn) api_fail('الصفحة دي مش موجودة', 'not_found', 404);
        social_disconnect($conn);
        api_ok(['msg' => 'اتفصلت «' . $conn['page_name'] . '» — والبوستات المحجوزة عليها اتلغت']);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}

function settings_month(int $m): string
{
    return ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'][$m - 1] ?? '';
}
