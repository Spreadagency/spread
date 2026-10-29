<?php
/**
 * Spread AI — محرك المحتوى v3 · النشر والجدولة من المحرك
 *
 * كل قطعة (بوست · كاروسيل) بتتنشر على صفحة فيسبوك و/أو حساب إنستجرام بيزنس المربوط بيها (social_connections):
 *   دلوقتي · في معاد الخانة في الخطة · أو في معاد يختاره العميل
 * الطابور (ce_publish_queue) — صف لكل قطعة × منصة — والـ worker (cron/ce-worker.php) بينشر اللي معاده جه
 * بنفس دوال النشر بتاعة الموقع (social_fb_send · social_ig_send).
 * بعد النشر: القطعة بتتربط ببوستها في ce_performance والأرقام بتتسحب من Meta Insights لوحدها (ce-meta.php).
 * حصة النشر في الباقة (publishes) بتتحسب مرة واحدة للقطعة — ref_id بالسالب عشان مايتلخبطش مع منشورات الموقع القديمة.
 * الريلز مابتتنشرش من هنا (محتاجة تصوير).
 * مفيش نشر من غير تصميم: البوست محتاج صورته النهائية والكاروسيل كل شرايحه — على فيسبوك وإنستجرام.
 * القطعة اللي نزلت على المنصتين بتتربط بالبوستين، ونتيجتها مجموعهم.
 */
require_once __DIR__ . '/ce-learning.php';   // + ce-meta.php + social.php
require_once __DIR__ . '/uploader.php';

const CE_PUB_PLATFORMS = ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام'];
const CE_PUB_STATUS = ['pending' => 'مستني معاده', 'processing' => 'بيتنشر…', 'published' => 'اتنشر', 'failed' => 'فشل', 'cancelled' => 'اتلغى'];

function ce_publish_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!ce_meta_ready()) return $ok = false;
    try {
        db_one('SELECT id FROM ce_publish_queue LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** العميل مسموح له بالنشر المباشر (نفس صلاحية النشر في الموقع) */
function ce_publish_allowed(int $uid): bool
{
    return function_exists('feature_allows') && feature_allows($uid);
}

/** نص البوست: الـ Hook + النص + الـ CTA + الهاشتاجات (الكاروسيل: الكابشن) */
function ce_publish_message(array $body, string $format): string
{
    $tags = implode(' ', array_map(fn($h) => '#' . ltrim(preg_replace('/\s+/u', '_', trim((string) $h)), '#'), array_filter((array) ($body['hashtags'] ?? []), 'is_scalar')));
    if ($format === 'carousel') {
        $main = trim((string) ($body['caption'] ?? ''));
        if ($main === '') $main = trim((string) ($body['slides'][0]['text'] ?? ''));
    } else {
        $hook = trim((string) (((array) ($body['hooks'] ?? []))[0] ?? ''));
        $text = trim((string) ($body['body'] ?? ''));
        $main = $hook !== '' && !str_contains($text, $hook) ? $hook . "\n\n" . $text : $text;
    }
    $cta = trim((string) ($body['cta'] ?? ''));
    $msg = trim($main . ($cta !== '' && !str_contains($main, $cta) ? "\n\n" . $cta : '') . ($tags !== '' ? "\n\n" . $tags : ''));
    return mb_substr($msg, 0, 60000);
}

/** صور التصميمات بالترتيب (روابط كاملة — فيسبوك بيحمّلها من السيرفر) */
function ce_publish_images(int $slotId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM ce_designs WHERE slot_id = ? ORDER BY frame', [$slotId]) as $d) {
        if (empty($d['final_path']) || !in_array($d['status'], ['pass', 'manual'], true)) return [];   // تصميم ناقص ← مانبعتش نص الكاروسيل
        $out[] = upload_url((string) $d['final_path']);
    }
    return $out;
}

/** القطعة جاهزة تتنشر على المنصة دي؟ ← null = تمام، غير كده سبب بالعربي */
function ce_publish_problem(array $content, array $conn, string $platform): ?string
{
    if (!in_array($content['status'], ['pass', 'manual'], true)) return 'القطعة لسه ماخلصتش';
    if ($content['format'] === 'reel') return 'الريلز محتاجة تصوير — مابتتنشرش من هنا';
    if ($conn['status'] !== 'active') return 'الصفحة مش مربوطة — اربطها تاني';
    if ($platform === 'instagram' && empty($conn['ig_user_id'])) return 'الصفحة دي مش مربوط بيها حساب إنستجرام بيزنس';
    $imgs = ce_publish_images((int) $content['slot_id']);
    if (!$imgs) return $content['format'] === 'carousel' ? 'الكاروسيل محتاج كل الشرايح متصممة' : 'صمّم القطعة الأول — مفيش نشر من غير تصميم';
    if ($content['format'] === 'carousel' && count($imgs) < 2) return 'الكاروسيل محتاج كل الشرايح متصممة';
    return null;
}

/** قطع الحملة الخلصانة اللي لسه تصميمها مش جاهز (للواجهة) — content_id ← true */
function ce_publish_needs_design(int $uid, int $campaignId): array
{
    $ok = [];
    foreach (db_all('SELECT slot_id, COUNT(*) n, SUM(final_path IS NOT NULL AND status IN ("pass", "manual")) done FROM ce_designs WHERE campaign_id = ? AND user_id = ? GROUP BY slot_id', [$campaignId, $uid]) as $d) {
        $ok[(int) $d['slot_id']] = (int) $d['n'] > 0 && (int) $d['done'] === (int) $d['n'] ? (int) $d['n'] : 0;
    }
    $out = [];
    foreach (db_all('SELECT id, slot_id, format FROM ce_contents WHERE campaign_id = ? AND user_id = ? AND status IN ("pass", "manual") AND format <> "reel"', [$campaignId, $uid]) as $c) {
        $n = $ok[(int) $c['slot_id']] ?? 0;
        if ($n < ($c['format'] === 'carousel' ? 2 : 1)) $out[(int) $c['id']] = true;
    }
    return $out;
}

/** معاد الخانة في الخطة بالساعة الافتراضية للنشر */
function ce_publish_slot_time(int $slotId): ?string
{
    $s = db_one('SELECT slot_date FROM ce_slots WHERE id = ?', [$slotId]);
    if (!$s || empty($s['slot_date'])) return null;
    return function_exists('default_publish_datetime') ? default_publish_datetime((string) $s['slot_date']) : $s['slot_date'] . ' 19:00:00';
}

/**
 * حط قطع في الطابور
 * $when: now · slot (معاد الخانة) · at (معاد $at)
 * @return array{ok:bool, queued:int, skipped:array<int,array{content_id:int, reason:string}>, error?:string}
 */
function ce_publish_queue_add(int $uid, int $campaignId, array $contentIds, int $connId, array $platforms, string $when = 'now', string $at = ''): array
{
    if (!ce_publish_ready()) return ['ok' => false, 'error' => 'شغّل ترحيل 2026_ce_publish الأول'];
    if (!ce_publish_allowed($uid)) return ['ok' => false, 'code' => 'feature', 'error' => 'النشر المباشر مش مفتوح في باقتك'];
    $conn = connection_for_user($connId, $uid);
    if (!$conn || $conn['status'] !== 'active') return ['ok' => false, 'code' => 'not_found', 'error' => 'الصفحة مش مربوطة'];
    $platforms = array_values(array_intersect(array_keys(CE_PUB_PLATFORMS), $platforms));
    if (!$platforms) return ['ok' => false, 'code' => 'input', 'error' => 'اختار فيسبوك أو إنستجرام'];
    $fixed = null;
    if ($when === 'at') {
        $ts = strtotime(str_replace('T', ' ', $at));
        if (!$ts || $ts <= time() + 60) return ['ok' => false, 'code' => 'input', 'error' => 'المعاد لازم يبقى في المستقبل'];
        if ($ts > time() + 180 * 86400) return ['ok' => false, 'code' => 'input', 'error' => 'الجدولة لحد 6 شهور بس'];
        $fixed = date('Y-m-d H:i:s', $ts);
    } elseif ($when === 'now') {
        $fixed = date('Y-m-d H:i:s');
    } elseif ($when !== 'slot') {
        return ['ok' => false, 'code' => 'input', 'error' => 'المعاد مش معروف'];
    }
    $queued = 0;
    $skipped = [];
    foreach (array_slice(array_values(array_unique(array_map('intval', $contentIds))), 0, 100) as $cid) {
        $c = db_one('SELECT * FROM ce_contents WHERE id = ? AND user_id = ? AND campaign_id = ?', [$cid, $uid, $campaignId]);
        if (!$c) { $skipped[] = ['content_id' => $cid, 'reason' => 'القطعة مش موجودة']; continue; }
        $time = $fixed ?? ce_publish_slot_time((int) $c['slot_id']);
        if (!$time) { $skipped[] = ['content_id' => $cid, 'reason' => 'الخانة مالهاش تاريخ']; continue; }
        if ($when === 'slot' && strtotime($time) <= time()) { $skipped[] = ['content_id' => $cid, 'reason' => 'معادها عدّى — انشرها «دلوقتي» أو اختار معاد']; continue; }
        foreach ($platforms as $pf) {
            $why = ce_publish_problem($c, $conn, $pf);
            if ($why) { $skipped[] = ['content_id' => $cid, 'platform' => $pf, 'reason' => $why]; continue; }
            $row = db_one('SELECT * FROM ce_publish_queue WHERE content_id = ? AND platform = ?', [$cid, $pf]);
            if ($row && in_array($row['status'], ['published', 'processing'], true)) { $skipped[] = ['content_id' => $cid, 'platform' => $pf, 'reason' => $row['status'] === 'published' ? 'اتنشرت قبل كده' : 'بتتنشر دلوقتي']; continue; }
            // حصة النشر: مرة للقطعة (مهما اتنشرت على كام منصة)
            $counted = function_exists('plan_counted') && plan_counted($uid, 'publishes', -$cid);
            if (!$counted && function_exists('plan_gate') && ($g = plan_gate($uid, 'publishes'))) { $skipped[] = ['content_id' => $cid, 'platform' => $pf, 'reason' => $g]; continue; }
            db_run('INSERT INTO ce_publish_queue (user_id, campaign_id, content_id, connection_id, platform, scheduled_at, status) VALUES (?,?,?,?,?,?,"pending")
                    ON DUPLICATE KEY UPDATE connection_id = VALUES(connection_id), scheduled_at = VALUES(scheduled_at), status = "pending", attempts = 0,
                      next_attempt_at = NULL, lock_token = NULL, error = NULL, provider_post_id = NULL, permalink = NULL, published_at = NULL',
                [$uid, $campaignId, $cid, $connId, $pf, $time]);
            if (!$counted && function_exists('plan_event')) plan_event($uid, 'publishes', -$cid);
            $queued++;
        }
    }
    return ['ok' => true, 'queued' => $queued, 'skipped' => $skipped];
}

/** الحصة ترجع لو القطعة مالهاش أي نشر ماشي أو تم */
function ce_publish_release_if_idle(int $uid, int $contentId): void
{
    if (!function_exists('plan_event_release')) return;
    if (!db_one('SELECT id FROM ce_publish_queue WHERE content_id = ? AND status IN ("pending", "processing", "published")', [$contentId])) plan_event_release($uid, 'publishes', -$contentId);
}

function ce_publish_cancel(int $uid, int $queueId): bool
{
    if (!ce_publish_ready()) return false;
    $r = db_one('SELECT * FROM ce_publish_queue WHERE id = ? AND user_id = ?', [$queueId, $uid]);
    if (!$r || $r['status'] !== 'pending') return false;
    db_run('UPDATE ce_publish_queue SET status = "cancelled", lock_token = NULL WHERE id = ? AND status = "pending"', [$queueId]);
    ce_publish_release_if_idle($uid, (int) $r['content_id']);
    return true;
}

function ce_publish_reschedule(int $uid, int $queueId, string $at): array
{
    $r = ce_publish_ready() ? db_one('SELECT * FROM ce_publish_queue WHERE id = ? AND user_id = ?', [$queueId, $uid]) : null;
    if (!$r || $r['status'] !== 'pending') return ['ok' => false, 'error' => 'مينفعش تغيّر معاد ده'];
    $ts = strtotime(str_replace('T', ' ', $at));
    if (!$ts || $ts <= time() + 60 || $ts > time() + 180 * 86400) return ['ok' => false, 'error' => 'اختار معاد في المستقبل (لحد 6 شهور)'];
    db_run('UPDATE ce_publish_queue SET scheduled_at = ?, next_attempt_at = NULL WHERE id = ? AND status = "pending"', [date('Y-m-d H:i:s', $ts), $queueId]);
    return ['ok' => true];
}

/** شكل الصف للواجهة */
function ce_publish_public(array $r, array $conns = []): array
{
    return ['id' => (int) $r['id'], 'content_id' => (int) $r['content_id'], 'platform' => $r['platform'], 'platform_label' => CE_PUB_PLATFORMS[$r['platform']] ?? $r['platform'],
            'page' => $conns[(int) $r['connection_id']] ?? '', 'scheduled_at' => $r['scheduled_at'], 'status' => $r['status'], 'status_label' => CE_PUB_STATUS[$r['status']] ?? $r['status'],
            'permalink' => $r['permalink'], 'error' => $r['error'], 'published_at' => $r['published_at']];
}

/** طابور حملة: content_id ← صفوف */
function ce_publish_for_campaign(int $uid, int $campaignId): array
{
    if (!ce_publish_ready()) return [];
    $conns = [];
    foreach (db_all('SELECT id, page_name FROM social_connections WHERE user_id = ?', [$uid]) as $c) $conns[(int) $c['id']] = (string) $c['page_name'];
    $out = [];
    foreach (db_all('SELECT * FROM ce_publish_queue WHERE campaign_id = ? AND user_id = ? ORDER BY scheduled_at', [$campaignId, $uid]) as $r) $out[(int) $r['content_id']][] = ce_publish_public($r, $conns);
    return $out;
}

/** بعد النشر: القطعة بتتربط ببوستها على المنصة دي (فيسبوك وإنستجرام بيتجمعوا) — والأرقام بتتسحب في المزامنة الجاية */
function ce_publish_attach(array $row, string $postId, ?string $permalink): void
{
    ce_meta_attach_post((int) $row['user_id'], (int) $row['content_id'], (string) $row['platform'], (int) $row['connection_id'], $postId, $permalink, date('Y-m-d'), 'publish');
}

/** نشر صف واحد (بعد ما اتحجز) */
function ce_publish_one(array $row): array
{
    $uid = (int) $row['user_id'];
    $fail = function (string $err, bool $permanent) use ($row, $uid) {
        if ($permanent || (int) $row['attempts'] >= 3) {
            db_run('UPDATE ce_publish_queue SET status = "failed", lock_token = NULL, error = ? WHERE id = ?', [mb_substr($err, 0, 900), (int) $row['id']]);
            ce_publish_release_if_idle($uid, (int) $row['content_id']);
            if (class_exists('Notifier')) Notifier::notify($uid, 'post_failed', ['page_name' => CE_PUB_PLATFORMS[$row['platform']] ?? '', 'date' => date('j/n H:i', strtotime((string) $row['scheduled_at']))], ['url' => 'engine.php', 'tone' => 'danger']);
            return ['ok' => false, 'status' => 'failed', 'error' => $err];
        }
        $mins = social_backoff_minutes((int) $row['attempts']);
        db_run('UPDATE ce_publish_queue SET status = "pending", lock_token = NULL, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), error = ? WHERE id = ?', [$mins, mb_substr($err, 0, 900), (int) $row['id']]);
        return ['ok' => false, 'status' => 'retry', 'error' => $err];
    };
    if (!ce_publish_allowed($uid)) return $fail('النشر المباشر اتقفل من الإدارة', true);
    $conn = db_one('SELECT * FROM social_connections WHERE id = ? AND user_id = ?', [(int) $row['connection_id'], $uid]);
    $content = db_one('SELECT * FROM ce_contents WHERE id = ? AND user_id = ?', [(int) $row['content_id'], $uid]);
    if (!$conn || !$content) return $fail('الصفحة أو القطعة اتمسحت', true);
    if ($why = ce_publish_problem($content, $conn, $row['platform'])) return $fail($why, true);
    $token = social_token_decrypt((string) $conn['access_token_enc']);
    if (!$token) return $fail('تعذّر فك تشفير توكن الصفحة', true);
    $body = json_decode((string) $content['body_json'], true) ?: [];
    $msg = ce_publish_message($body, (string) $content['format']);
    $imgs = ce_publish_images((int) $content['slot_id']);
    $fake = ['id' => 0, 'format' => $content['format'] === 'carousel' ? 'carousel' : 'post'];   // شكل دوال النشر بتاعة الموقع
    $log = ['post_id' => null, 'connection_id' => (int) $conn['id']];
    $r = $row['platform'] === 'instagram' ? social_ig_send($fake, $conn, $token, $msg, $imgs, $log) : social_fb_send($fake, $conn, $token, $msg, $imgs, null, $log);
    if (!$r['ok'] || empty($r['post_id'])) {
        $c = $r['ok'] ? ['error' => 'المنصة مارجّعتش رقم البوست', 'permanent' => false] : social_classify_error($r);
        if (!empty($c['token_dead'])) connection_mark((int) $conn['id'], 'expired', (string) $c['error']);
        return $fail((string) ($c['error'] ?? $r['error'] ?? 'فشل'), !empty($c['permanent']));
    }
    $postId = (string) $r['post_id'];
    $permalink = $row['platform'] === 'facebook' ? fb_post_url($postId) : null;
    if ($row['platform'] === 'instagram') {
        $p = ce_meta_call($conn, $postId, ['fields' => 'permalink']);
        $permalink = $p['ok'] ? (string) ($p['data']['permalink'] ?? '') : null;
    }
    db_run('UPDATE ce_publish_queue SET status = "published", published_at = NOW(), provider_post_id = ?, permalink = ?, error = NULL, lock_token = NULL WHERE id = ?',
        [$postId, $permalink ? mb_substr($permalink, 0, 500) : null, (int) $row['id']]);
    ce_publish_attach($row, $postId, $permalink);
    if (class_exists('Notifier')) Notifier::notify($uid, 'post_published', ['page_name' => (string) ($conn['page_name'] ?? '')], ['url' => 'engine.php', 'tone' => 'ok']);
    return ['ok' => true, 'status' => 'published', 'post_id' => $postId];
}

/** الـ worker: انشر اللي معاده جه (بحجز ذري — مفيش نسختين بينشروا نفس الصف) */
function ce_publish_tick(int $limit = 5): array
{
    if (!ce_publish_ready()) return ['published' => 0];
    if (is_file(__DIR__ . '/Notifier.php')) require_once __DIR__ . '/Notifier.php';
    // صف علق في «بيتنشر» أكتر من 10 دقايق ← يرجع للطابور
    db_run('UPDATE ce_publish_queue SET status = "pending", lock_token = NULL WHERE status = "processing" AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
    $tok = bin2hex(random_bytes(16));
    db_run('UPDATE ce_publish_queue SET status = "processing", lock_token = ?, attempts = attempts + 1
            WHERE status = "pending" AND scheduled_at <= NOW() AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
            ORDER BY scheduled_at LIMIT ' . max(1, min(20, $limit)), [$tok]);
    $out = ['published' => 0, 'failed' => 0, 'retry' => 0];
    foreach (db_all('SELECT * FROM ce_publish_queue WHERE lock_token = ?', [$tok]) as $row) {
        $r = ce_publish_one($row);
        $out[$r['status'] === 'published' ? 'published' : ($r['status'] === 'failed' ? 'failed' : 'retry')]++;
    }
    return $out;
}

/** للأدمن */
function ce_publish_stats(): array
{
    if (!ce_publish_ready()) return [];
    $s = db_one('SELECT SUM(status = "pending") pending, SUM(status = "published") published, SUM(status = "failed") failed, SUM(status = "published" AND published_at >= ?) today FROM ce_publish_queue', [date('Y-m-d 00:00:00')]) ?: [];
    $s['recent_failures'] = db_all('SELECT q.*, u.name uname FROM ce_publish_queue q LEFT JOIN users u ON u.id = q.user_id WHERE q.status = "failed" ORDER BY q.updated_at DESC LIMIT 10');
    return $s;
}
