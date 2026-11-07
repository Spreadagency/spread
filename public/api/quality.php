<?php
/**
 * Spread AI — نظام الجودة: تقييم التصميم · أسباب التقييم · «بلّغ عن مشكلة» · أحداث من الواجهة
 *
 *   POST action=design_rate     {design_id, value: 1|-1|0, reason?}
 *   POST action=post_reason     {content_id, reason}               (سبب 👍/👎 المنشور — التقييم نفسه في brand-kit post_feedback)
 *   POST action=design_download {design_id}                        (حدث من الواجهة)
 *   POST action=issue_create    multipart: {category, description, page_url?, content_id?, design_id?, ua?, screenshot?}
 *   GET  action=reasons         قايمة الأسباب
 *
 * الملكية بتتشيك في كل طلب (api_own) · CSRF في الهيدر · الصور: jpg/png/webp ≤ 5MB باسم عشوائي في فولدر مقفول
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/EventTracker.php';

$user = api_boot(true);
$uid = (int) $user['id'];
$action = api_action();

/** الأسباب (عربي) — نفس القايمة للمحتوى والتصميم */
function quality_reasons(): array
{
    return ['مش مناسب للبراند', 'الكلام ضعيف', 'التصميم وحش', 'النص في الصورة غلط', 'مكرر', 'أخرى'];
}

/** التصميم ده بتاع العميل؟ */
function quality_design(int $uid, int $designId): array
{
    $d = db_one('SELECT d.id, d.content_id, d.prompt_version_id, d.provider FROM content_designs d JOIN contents c ON c.id = d.content_id WHERE d.id = ? AND c.user_id = ?', [$designId, $uid]);
    if (!$d) api_fail('التصميم ده مش موجود', 'not_found', 404);
    return $d;
}

function quality_reason(): ?string
{
    $r = trim(api_str('reason', 255));
    return $r !== '' ? mb_substr($r, 0, 255) : null;
}

switch ($action) {
    case 'reasons':
        api_ok(['reasons' => quality_reasons(), 'categories' => issue_categories()]);

    case 'design_rate':
        $d = quality_design($uid, api_int('design_id'));
        $v = api_int('value', 0, -1, 1);
        $reason = quality_reason();
        if ($v === 0) {
            db_run('DELETE FROM design_ratings WHERE design_id = ? AND user_id = ?', [$d['id'], $uid]);
            api_ok(['rating' => 0]);
        }
        db_run('INSERT INTO design_ratings (design_id, content_id, user_id, rating, reason) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE rating = VALUES(rating), reason = VALUES(reason)', [$d['id'], $d['content_id'], $uid, $v, $reason]);
        EventTracker::track('design_rated', 'design', (int) $d['id'], ['rating' => $v, 'reason' => $reason, 'content_id' => (int) $d['content_id'],
            'prompt_version_id' => $d['prompt_version_id'], 'provider' => $d['provider']], $uid);
        api_ok(['rating' => $v, 'reason' => $reason]);

    case 'post_reason':
        $c = api_own('contents', api_int('content_id'), $uid);
        $reason = quality_reason();
        if ($reason === null) api_fail('اختار السبب', 'invalid', 422);
        if (function_exists('bk_feedback')) {
            try { bk_feedback($uid, (int) $c['id'], ((int) $c['feedback']) >= 0 ? 'like' : 'dislike', 'post_reason', null, $reason); } catch (\Throwable $e) {}
        } else {
            try {
                db_insert('INSERT INTO brand_feedback (user_id, content_id, field, action, after_value) VALUES (?,?,?,?,?)',
                    [$uid, $c['id'], 'post_reason', ((int) $c['feedback']) >= 0 ? 'like' : 'dislike', $reason]);
            } catch (\Throwable $e) {}
        }
        EventTracker::track('post_rated', 'content', (int) $c['id'], ['rating' => $c['feedback'] !== null ? (int) $c['feedback'] : null, 'reason' => $reason], $uid);
        api_ok(['reason' => $reason]);

    case 'design_download':
        $d = quality_design($uid, api_int('design_id'));
        EventTracker::track('design_downloaded', 'design', (int) $d['id'], ['content_id' => (int) $d['content_id'],
            'prompt_version_id' => $d['prompt_version_id'], 'provider' => $d['provider']], $uid);
        api_ok();

    case 'issue_create':
        if (get_setting('issues_enabled', '1') !== '1') api_fail('البلاغات مقفولة حاليًا', 'disabled', 403);
        $max = max(1, (int) get_setting('issue_rate_per_hour', '5'));
        if (!rate_limit('issue_create', 'u' . $uid, $max, 3600)) api_fail('بعت بلاغات كتير — جرّب بعد شوية', 'rate_limit', 429);
        $cat = api_str('category', 30);
        if (!isset(issue_categories()[$cat])) api_fail('اختار نوع المشكلة', 'invalid', 422);
        $desc = trim(api_str('description', 3000));
        if (mb_strlen($desc) < 5) api_fail('اكتب المشكلة في جملة على الأقل', 'invalid', 422);

        // المنشور / التصميم (لو البلاغ عليهم) — لازم يكونوا بتوع العميل
        $content = ($cid = api_int('content_id')) ? api_own('contents', $cid, $uid) : null;
        $design = ($did = api_int('design_id')) ? quality_design($uid, $did) : null;
        if ($design && !$content) $content = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$design['content_id'], $uid]);

        // صورة الشاشة (اختياري): نفس فحص الصور (النوع الحقيقي + الحجم) في فولدر مقفول قدام الزوار
        $shot = null;
        if (!empty($_FILES['screenshot']) && ($_FILES['screenshot']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            require_once __DIR__ . '/../../includes/uploader.php';
            $dir = UPLOADS_PATH . '/issues';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "# صور البلاغات: للأدمن بس (بتتعرض من admin)\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            }
            $up = upload_image($_FILES['screenshot'], 'issues');
            if (!$up['ok']) api_fail($up['error'] ?: 'الصورة مش مقبولة', 'invalid', 422);
            $shot = $up['path'];
        }

        $page = mb_substr(trim(api_str('page_url', 500)), 0, 500);
        $ctx = [
            'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? api_str('ua', 300)), 0, 300),
            'viewport' => mb_substr(api_str('viewport', 30), 0, 30),
            'events' => EventTracker::recent($uid, 10),
        ];
        if ($content) {
            $ctx['content'] = ['id' => (int) $content['id'], 'format' => $content['format'] ?? null, 'model' => $content['model'] ?? null,
                'prompt_version_id' => isset($content['prompt_version_id']) ? (int) $content['prompt_version_id'] : null, 'provider' => $content['provider'] ?? null,
                'campaign_id' => $content['campaign_id'] ?? null];
        }
        if ($design) $ctx['design'] = ['id' => (int) $design['id'], 'prompt_version_id' => $design['prompt_version_id'], 'provider' => $design['provider']];

        $id = (int) db_insert('INSERT INTO user_issues (user_id, content_id, design_id, page_url, category_user, description, screenshot_path, context) VALUES (?,?,?,?,?,?,?,?)', [
            $uid, $content ? (int) $content['id'] : null, $design ? (int) $design['id'] : null, $page ?: null, $cat, $desc, $shot,
            json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        EventTracker::track('issue_reported', 'issue', $id, ['category' => $cat], $uid);

        // تأكيد في صندوق الرسايل (والتحديثات بتوصل في نفس المكان)
        try {
            require_once __DIR__ . '/../../includes/Notifier.php';
            Notifier::direct($uid, 'وصلنا بلاغك #' . $id . ' ✓',
                "شكرًا إنك بلّغتنا عن: «" . issue_categories()[$cat] . "».\nفريقنا هيراجع المشكلة، وأي تحديث هيوصلك هنا.", 'inapp', null, null, 0);
        } catch (\Throwable $e) {
            error_log('[issues] confirm #' . $id . ': ' . $e->getMessage());
        }
        api_ok(['id' => $id, 'message' => 'وصلنا بلاغك #' . $id . ' — هنراجعه ونرد عليك في صندوق الرسايل']);
}

api_fail('طلب غير معروف', 'unknown_action', 404);

/** أنواع المشكلة (اللي العميل بيختارها) */
function issue_categories(): array
{
    return ['tech' => 'مشكلة تقنية', 'content' => 'المحتوى', 'design' => 'التصميم', 'ux' => 'صعوبة في الاستخدام', 'suggestion' => 'اقتراح'];
}
