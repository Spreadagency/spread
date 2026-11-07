<?php
/**
 * Spread AI — إشعارات الموبايل (Expo Push)
 *   mobile_push_user($uid, $title, $body, $data) — بيبعت لكل أجهزة العميل اللي سجّلت توكن إشعارات
 *   بيتنادى من notify_user() (الدفع والباقات) ومن كرون النشر (نجح/فشل)
 *   مابيوقعش أي حاجة لو الجدول مش موجود أو الخدمة واقعة — الإشعار جوه المنصة بيفضل موجود في كل الأحوال
 *   إيقاف: إعداد mobile_push_enabled = 0
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/db.php';
}

const MOBILE_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

/**
 * @param array $data  بيانات للتطبيق (مثلًا ['screen' => 'content', 'id' => 12])
 * @return int عدد الأجهزة اللي اتبعت لها
 */
function mobile_push_user(int $userId, string $title, string $body = '', array $data = []): int
{
    try {
        if (function_exists('get_setting') && (string) get_setting('mobile_push_enabled', '1') !== '1') return 0;
        $rows = db_all("SELECT id, push_token FROM mobile_tokens
                        WHERE user_id = ? AND kind = 'access' AND revoked_at IS NULL AND expires_at > NOW() AND push_token IS NOT NULL
                        ORDER BY id DESC LIMIT 10", [$userId]);
    } catch (\Throwable $e) {
        return 0; // التطبيق لسه ماتسجّلش عليه حد (الجدول مش موجود)
    }
    if (!$rows || !function_exists('curl_init')) return 0;

    $msgs = [];
    foreach ($rows as $r) {
        $msgs[] = [
            'to' => (string) $r['push_token'],
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr(trim(strip_tags($body)), 0, 240),
            'data' => $data,
            'sound' => 'default',
            'channelId' => 'default',
            'priority' => 'high',
        ];
    }
    $ch = curl_init(MOBILE_PUSH_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($msgs, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !is_string($raw)) {
        error_log('[mobile-push] HTTP ' . $http);
        return 0;
    }
    // الأجهزة اللي شالت التطبيق ← نمسح توكن الإشعارات بتاعها
    $res = json_decode($raw, true);
    foreach (($res['data'] ?? []) as $i => $t) {
        if (($t['status'] ?? '') === 'error' && (($t['details']['error'] ?? '') === 'DeviceNotRegistered') && isset($rows[$i])) {
            try { db_run('UPDATE mobile_tokens SET push_token = NULL WHERE id = ?', [(int) $rows[$i]['id']]); } catch (\Throwable $e) {}
        }
    }
    return count($msgs);
}
