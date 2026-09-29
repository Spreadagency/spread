<?php
/**
 * Spread AI v2 — النشر المباشر / الجدولة على فيسبوك
 * mode=now      → ينشر حالًا
 * mode=schedule → يبعت البوست لفيسبوك دلوقتي بجدولة داخلية (فيسبوك ينشره في معاده)
 * انستجرام مبيدعمش الجدولة عبر الـ API → بيتحط في طابور الناشر المحلي
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/social.php';

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص
decode_b64_fields();

$user = current_user();

if (!feature_allows((int) $user['id'])) {
    json_response(['ok' => false, 'error' => 'ميزة النشر التلقائي غير مفعّلة لحسابك']);
}

$contentId = (int) ($_POST['content_id'] ?? 0);
$content = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$contentId, $user['id']]);
if (!$content) {
    json_response(['ok' => false, 'error' => 'المنشور غير موجود']);
}

$conn = connection_for_user((int) ($_POST['connection_id'] ?? 0), (int) $user['id']);
if (!$conn || $conn['status'] !== 'active') {
    json_response(['ok' => false, 'error' => 'الصفحة المختارة غير متاحة — أعد ربطها من «حساباتي المربوطة»']);
}

$platform = in_array($_POST['platform'] ?? '', ['facebook', 'instagram', 'both'], true) ? $_POST['platform'] : 'facebook';
$mode = in_array($_POST['mode'] ?? '', ['now', 'schedule', 'queue'], true) ? $_POST['mode'] : 'now';

// ⑦-ج أشكال المحتوى
require_once __DIR__ . '/../../includes/content-formats.php';
$__fmt = content_format_key($content['format'] ?? 'post');
if ($__fmt === 'video') {
    json_response(['ok' => false, 'error' => 'الفيديو بيتنشر بعد ما فريق Spread AI ينفّذه — تابع حالته من «🎬 إنشاء الفيديو»']);
}
if ($__fmt === 'carousel') {
    $__pg = content_design_progress($content);
    if (!$__pg['complete']) {
        json_response(['ok' => false, 'error' => 'كمّل تصميم كل شرائح الكاروسيل الأول (' . $__pg['done'] . ' من ' . $__pg['needed'] . ')']);
    }
}
if ($__fmt === 'story' && $platform !== 'instagram' && $mode === 'schedule') {
    $mode = 'queue';   // ستوري فيسبوك مابتتجدولش على فيسبوك نفسه — الناشر بيبعتها في معادها
}

if (!rate_limit('publish_direct', 'u' . $user['id'], 20, 600)) {
    json_response(['ok' => false, 'error' => 'محاولات كتير — استنى شوية']);
}

// 8-ب: حصة النشر (المنشور الواحد بيتحسب مرة واحدة حتى لو اتعاد نشره/جدولته)
$__pubCounted = plan_counted((int) $user['id'], 'publishes', $contentId);
if (!$__pubCounted && ($__g = plan_gate((int) $user['id'], 'publishes'))) {
    json_response(['ok' => false, 'error' => $__g, 'code' => 'quota']);
}

// وقت الجدولة (بتوقيت مصر — نفس توقيت الموقع)
$scheduleTs = null;
if ($mode === 'schedule' || $mode === 'queue') {
    $raw = trim($_POST['scheduled_at'] ?? (string) ($content['scheduled_at'] ?? ''));
    if ($raw === '') {
        json_response(['ok' => false, 'error' => 'مفيش موعد جدولة محدد للبوست']);
    }
    $ts = strtotime(str_replace('T', ' ', $raw));
    if (!$ts) {
        json_response(['ok' => false, 'error' => 'صيغة الموعد غير صحيحة']);
    }
    if ($ts <= time()) {
        json_response(['ok' => false, 'error' => 'الموعد المحدد عدّى خلاص — اختر وقت في المستقبل']);
    }
    $scheduleTs = $ts;
}

// ═══ وضع «احجز على المنصة» — منبعتش لفيسبوك دلوقتي، الناشر يبعته في الموعد ═══
if ($mode === 'queue') {
    db_run(
        'UPDATE contents SET publish_status = "pending", connection_id = ?, publish_platform = ?, scheduled_at = ?,
         attempts = 0, next_attempt_at = NULL, lock_token = NULL, publish_error = NULL, published_at = NULL, publish_post_id = NULL
         WHERE id = ?',
        [(int) $conn['id'], $platform, date('Y-m-d H:i:s', $scheduleTs), $contentId]
    );
    plan_event((int) $user['id'], 'publishes', $contentId);
    json_response([
        'ok' => true,
        'queued' => true,
        'msg' => 'اتحجز على المنصة ✓ — هيتبعت لفيسبوك وينتشر الساعة ' . date('H:i', $scheduleTs) . ' يوم ' . date('d/m', $scheduleTs),
        'post_url' => '',
    ]);
}

$results = [];
$errors = [];
$postIds = [];
$fbScheduled = false;

// ═══ فيسبوك ═══
if ($platform === 'facebook' || $platform === 'both') {
    $r = fb_publish($content, $conn, $scheduleTs);
    if ($r['ok']) {
        $postIds[] = 'facebook:' . $r['post_id'];
        $fbScheduled = !empty($r['scheduled']);
        $results[] = $fbScheduled
            ? 'فيسبوك: اتجدول على الصفحة نفسها ✓'
            : 'فيسبوك: اتنشر ✓';
    } else {
        $errors[] = 'فيسبوك: ' . $r['error'];
        if (!empty($r['token_dead'])) {
            connection_mark((int) $conn['id'], 'expired', $r['error']);
        }
    }
}

// ═══ انستجرام ═══
$igQueued = false;
if ($platform === 'instagram' || $platform === 'both') {
    if ($mode === 'schedule') {
        // مفيش جدولة داخلية في انستجرام → الناشر المحلي هينشره في معاده
        $igQueued = true;
        $results[] = 'انستجرام: اتحط في الجدولة (هينتشر في معاده)';
    } else {
        $r = ig_publish($content, $conn);
        if ($r['ok']) {
            $postIds[] = 'instagram:' . $r['post_id'];
            $results[] = 'انستجرام: اتنشر ✓';
        } else {
            $errors[] = 'انستجرام: ' . $r['error'];
        }
    }
}

// ═══ تحديث حالة البوست ═══
if ($postIds || $igQueued) {
    $newStatus = 'published';
    if ($fbScheduled || $igQueued) {
        // لسه مش منشور فعليًا
        $newStatus = ($igQueued && !$fbScheduled && $platform !== 'facebook') ? 'pending' : 'scheduled';
    }
    // لو انستجرام محتاج الناشر المحلي، سيبه pending عشان الوركر يشوفه
    if ($igQueued && $platform === 'both') {
        $newStatus = 'pending';
    }

    $fields = [
        'publish_status' => $newStatus,
        'connection_id' => (int) $conn['id'],
        'publish_platform' => $platform,
        'publish_post_id' => $postIds ? implode(' | ', $postIds) : null,
        'publish_error' => $errors ? mb_substr(implode(' | ', $errors), 0, 900) : null,
    ];
    if ($newStatus === 'published') {
        $fields['published_at'] = date('Y-m-d H:i:s');
    }
    if ($scheduleTs) {
        $fields['scheduled_at'] = date('Y-m-d H:i:s', $scheduleTs);
    }

    $set = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        $set[] = "`{$k}` = ?";
        $vals[] = $v;
    }
    $vals[] = $contentId;
    db_run('UPDATE contents SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
}

if (!$postIds && !$igQueued) {
    json_response(['ok' => false, 'error' => implode(' | ', $errors) ?: 'فشل النشر']);
}

$fbId = '';
foreach ($postIds as $pid) {
    if (str_starts_with($pid, 'facebook:')) {
        $fbId = substr($pid, 9);
    }
}

plan_event((int) $user['id'], 'publishes', $contentId);
json_response([
    'ok' => true,
    'msg' => implode(' · ', $results) . ($errors ? ' — لكن: ' . implode(' | ', $errors) : ''),
    'scheduled' => $fbScheduled,
    'post_url' => $fbId ? fb_post_url($fbId) : '',
    'partial_errors' => $errors,
]);
