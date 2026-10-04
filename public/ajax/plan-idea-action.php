<?php
/**
 * Spread AI v2 — إجراءات أفكار الخطة (Phase 3)
 * select / reject / restore / set_date / delete / auto_schedule
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/social.php';
require_once __DIR__ . '/../../includes/plan-functions.php';

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 60 كل 5 دقيقة لكل مستخدم
if (!rate_limit('idea_action', 'u' . ($user['id'] ?? 0), 60, 300)) {
    json_response(['ok' => false, 'error' => 'استنى شوية وحاول تاني']);
}
$action = $_POST['action'] ?? '';

// ── جدولة تلقائية لخطة كاملة ──
if ($action === 'auto_schedule') {
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $plan = db_one('SELECT * FROM content_plans WHERE id = ? AND user_id = ?', [$planId, $user['id']]);
    if (!$plan) {
        json_response(['ok' => false, 'error' => 'الخطة غير موجودة']);
    }
    $start = $_POST['start_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        json_response(['ok' => false, 'error' => 'اختر تاريخ بداية صحيح']);
    }
    $every = (int) ($_POST['every_days'] ?? 2);
    $n = plan_auto_schedule($planId, $start, $every);
    db_run('UPDATE content_plans SET start_date = ? WHERE id = ?', [$start, $planId]);
    json_response(['ok' => true, 'scheduled' => $n]);
}

// ── باقي الإجراءات على فكرة واحدة ──
$ideaId = (int) ($_POST['idea_id'] ?? 0);
$idea = db_one(
    'SELECT pi.* FROM plan_ideas pi JOIN content_plans p ON p.id = pi.plan_id
     WHERE pi.id = ? AND p.user_id = ?',
    [$ideaId, $user['id']]
);
if (!$idea) {
    json_response(['ok' => false, 'error' => 'الفكرة غير موجودة']);
}

switch ($action) {
    case 'select':
        if ($idea['status'] === 'produced') {
            json_response(['ok' => false, 'error' => 'الفكرة متنتجة بالفعل']);
        }
        db_run('UPDATE plan_ideas SET status = "selected" WHERE id = ?', [$ideaId]);
        json_response(['ok' => true, 'status' => 'selected']);

    case 'reject':
        if ($idea['status'] === 'produced') {
            json_response(['ok' => false, 'error' => 'الفكرة متنتجة بالفعل']);
        }
        db_run('UPDATE plan_ideas SET status = "rejected" WHERE id = ?', [$ideaId]);
        json_response(['ok' => true, 'status' => 'rejected']);

    case 'restore':
        if ($idea['status'] === 'produced') {
            json_response(['ok' => false, 'error' => 'الفكرة متنتجة بالفعل']);
        }
        db_run('UPDATE plan_ideas SET status = "suggested" WHERE id = ?', [$ideaId]);
        json_response(['ok' => true, 'status' => 'suggested']);

    case 'set_date':
        $date = $_POST['date'] ?? '';
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_response(['ok' => false, 'error' => 'تاريخ غير صالح']);
        }
        db_run('UPDATE plan_ideas SET scheduled_date = ? WHERE id = ?', [$date ?: null, $ideaId]);
        // التاريخ في التقويم هو المرجع — يتزامن مع البوست المنتَج (طالما لسه ماتنشرش)
        if ($date && !empty($idea['content_id'])) {
            db_run(
                'UPDATE contents SET scheduled_at = ? WHERE id = ? AND (publish_status IS NULL OR publish_status IN ("draft","pending"))',
                [default_publish_datetime($date), $idea['content_id']]
            );
        }
        json_response(['ok' => true]);

    case 'delete':
        if ($idea['status'] === 'produced') {
            json_response(['ok' => false, 'error' => 'الفكرة مربوطة بمحتوى منتَج — احذف المحتوى الأول لو عايز']);
        }
        db_run('DELETE FROM plan_ideas WHERE id = ?', [$ideaId]);
        json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'إجراء غير معروف']);
