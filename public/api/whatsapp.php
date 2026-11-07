<?php
/**
 * Spread AI — إعدادات الواتساب للعميل
 *
 *   GET  action=home                       الرقم · التحقق · الموافقة · تفضيلات كل حدث
 *   POST action=send_code  {phone}         كود 6 أرقام على الواتساب (صالح 10 دقايق · 3 أكواد في الساعة)
 *   POST action=verify     {code}          3 محاولات للكود
 *   POST action=opt_in     {on}            استقبال رسايل واتساب (عام)
 *   POST action=prefs      {event, whatsapp, inapp}
 *   POST action=remove                      شيل الرقم
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/Notifier.php';

$user = api_boot(true);
$uid = (int) $user['id'];
$action = api_action() ?: 'home';
wa_ensure_schema();

/** الأحداث اللي العميل يقدر يتحكم فيها (OTP مش منها) */
function wa_user_events(): array
{
    $out = [];
    $tpls = [];
    foreach (db_all('SELECT event_key, default_channel, is_critical, is_active FROM message_templates') as $t) $tpls[$t['event_key']] = $t;
    foreach (wa_event_catalog() as $key => $c) {
        if ($key === 'otp') continue;
        $t = $tpls[$key] ?? null;
        if (!$t || (int) $t['is_active'] !== 1) continue;
        $out[$key] = ['label' => $c[0], 'critical' => (int) $t['is_critical'] === 1, 'channel' => (string) $t['default_channel']];
    }
    return $out;
}

function wa_home(int $uid): array
{
    $u = db_one('SELECT phone, whatsapp_phone, whatsapp_verified, whatsapp_opt_in FROM users WHERE id = ?', [$uid]) ?: [];
    $prefs = [];
    foreach (db_all('SELECT event_key, whatsapp, inapp FROM user_notification_prefs WHERE user_id = ?', [$uid]) as $p) $prefs[$p['event_key']] = $p;
    $events = [];
    foreach (wa_user_events() as $key => $e) {
        $p = $prefs[$key] ?? null;
        $events[] = [
            'key' => $key, 'label' => $e['label'], 'critical' => $e['critical'],
            'has_wa' => in_array($e['channel'], ['whatsapp', 'both'], true), 'has_inapp' => in_array($e['channel'], ['inapp', 'both'], true),
            'whatsapp' => $e['critical'] || !$p || (int) $p['whatsapp'] === 1,
            'inapp' => $e['critical'] || !$p || (int) $p['inapp'] === 1,
        ];
    }
    return [
        'enabled' => wa_enabled(),
        'phone' => (string) ($u['whatsapp_phone'] ?? ''),
        'suggest' => wa_normalize_phone((string) ($u['phone'] ?? '')),
        'verified' => !empty($u['whatsapp_verified']),
        // مش متحقق؟ الرسايل بتروح على رقم الحساب (لو الإعداد شغّال)
        'fallback' => empty($u['whatsapp_verified']) ? wa_phone_for($u) : '',
        'opt_in' => (int) ($u['whatsapp_opt_in'] ?? 1) === 1,
        'pending_phone' => (string) (db_one('SELECT phone FROM wa_otps WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1', [$uid])['phone'] ?? ''),
        'events' => $events,
    ];
}

switch ($action) {
    case 'home':
        api_ok(wa_home($uid));

    case 'send_code':
        if (!wa_enabled()) api_fail('رسايل الواتساب متوقفة دلوقتي — جرّب بعدين', 'disabled', 409);
        $phone = wa_normalize_phone(api_str('phone', 30));
        if ($phone === '') api_fail('الرقم مش صحيح — اكتبه كده 01xxxxxxxxx أو بكود الدولة', 'invalid', 422);
        if (!rate_limit('wa_otp', 'u' . $uid, 3, 3600)) api_fail('طلبت أكواد كتير — استنى ساعة وجرّب تاني', 'rate_limit', 429);
        if (!Notifier::sendOtp($uid, $phone)) api_fail('مقدرناش نبعت الكود دلوقتي — استنى شوية وجرّب تاني', 'otp_failed', 429);
        api_ok(['sent' => true, 'phone' => $phone] + wa_home($uid));

    case 'verify':
        $code = preg_replace('/\D/', '', api_str('code', 10));
        $otp = db_one('SELECT * FROM wa_otps WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$uid]);
        if (!$otp || strtotime((string) $otp['expires_at']) < time()) api_fail('الكود انتهى — اطلب كود جديد', 'expired', 422);
        if ((int) $otp['attempts'] >= 3) api_fail('محاولات كتير — اطلب كود جديد', 'attempts', 422);
        if (strlen($code) !== 6 || !hash_equals((string) $otp['code_hash'], hash('sha256', $uid . '|' . $code))) {
            db_run('UPDATE wa_otps SET attempts = attempts + 1 WHERE id = ?', [(int) $otp['id']]);
            $left = 2 - (int) $otp['attempts'];
            api_fail($left > 0 ? "الكود غلط — باقي $left محاولة" : 'الكود غلط — اطلب كود جديد', 'wrong_code', 422);
        }
        db_run('UPDATE wa_otps SET used_at = NOW() WHERE id = ?', [(int) $otp['id']]);
        db_run('UPDATE users SET whatsapp_phone = ?, whatsapp_verified = 1, whatsapp_opt_in = 1 WHERE id = ?', [(string) $otp['phone'], $uid]);
        api_ok(['verified' => true] + wa_home($uid));

    case 'opt_in':
        db_run('UPDATE users SET whatsapp_opt_in = ? WHERE id = ?', [api_param('on') ? 1 : 0, $uid]);
        api_ok(wa_home($uid));

    case 'prefs':
        $event = preg_replace('/[^a-z_]/', '', api_str('event', 50));
        $events = wa_user_events();
        if (!isset($events[$event])) api_fail('الحدث مش موجود', 'invalid', 422);
        if ($events[$event]['critical']) api_fail('الإشعار ده مهم ومش بيتقفل', 'critical', 422);
        db_run('INSERT INTO user_notification_prefs (user_id, event_key, whatsapp, inapp) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE whatsapp = VALUES(whatsapp), inapp = VALUES(inapp)',
            [$uid, $event, api_param('whatsapp') ? 1 : 0, api_param('inapp') ? 1 : 0]);
        api_ok(wa_home($uid));

    case 'remove':
        db_run('UPDATE users SET whatsapp_phone = NULL, whatsapp_verified = 0 WHERE id = ?', [$uid]);
        api_ok(wa_home($uid));

    default:
        api_fail('طلب غير معروف', 'invalid', 400);
}
