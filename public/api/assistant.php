<?php
/**
 * Spread AI — API المساعدين (المرحلة 7)
 *
 *   GET  action=load {kind}                         المحادثة + هل المساعد متاح + مهمة الوكيل الحالية + الصفحات المربوطة
 *   POST action=send {kind, message, page}          رسالة ← الرد (+ زراير الصفحات · تذكرة · مهمة مستنية موافقة)
 *   POST action=reset {kind}                        محادثة جديدة
 *   POST action=agent_confirm {run_id, publish, connection_id, platforms[]}   الموافقة ← الوكيل يبدأ
 *   POST action=agent_cancel {run_id}               إلغاء المهمة
 *   POST action=agent_status {run_id}               حالة المهمة (وخطوة لو الـ cron مش شغال)
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/assistants.php';

$action = api_action() ?: 'load';
$user = api_boot();
$uid = (int) $user['id'];
if (!as_ready()) api_fail('المساعد لسه مش متركّب — محتاج ترحيل 2026_assistants', 'not_ready', 503);

$kind = in_array(api_str('kind', 10), AS_KINDS, true) ? api_str('kind', 10) : 'support';
$current = function () use ($uid): ?array {
    $r = db_one('SELECT * FROM agent_runs WHERE user_id = ? AND (status IN ("pending","running","paused") OR (status IN ("done","failed") AND updated_at > DATE_SUB(NOW(), INTERVAL 1 DAY))) ORDER BY id DESC LIMIT 1', [$uid]);
    return $r ? as_run_public($r) : null;
};

switch ($action) {
    case 'load':
        $blocked = as_blocked($uid, $kind);
        $t = $blocked ? ['messages' => []] : as_thread($uid, $kind);
        api_ok([
            'kind' => $kind, 'blocked' => $blocked, 'messages' => $t['messages'],
            'agent' => as_blocked($uid, 'agent') === null, 'support' => as_blocked($uid, 'support') === null,
            'run' => $kind === 'agent' && !$blocked ? $current() : null,
            'connections' => $kind === 'agent' && !$blocked ? as_connections($uid) : [],
            'upgrade_url' => url('my-plan.php?tab=plans'),
        ]);

    case 'send':
        $msg = api_str('message', 1500);
        $r = $kind === 'agent' ? as_agent_send($uid, $msg) : as_support_send($uid, $msg, api_str('page', 255));
        if (!$r['ok']) api_fail($r['error'], $r['code'] ?? 'error', ($r['code'] ?? '') === 'credits' ? 402 : (($r['code'] ?? '') === 'blocked' ? 403 : 422));
        api_ok($r + ['run' => $r['run'] ?? null]);

    case 'reset':
        $t = as_reset($uid, $kind);
        api_ok(['messages' => $t['messages']]);

    case 'agent_confirm':
        $in = api_input();
        $r = as_agent_confirm($uid, api_int('run_id'), ['publish' => (string) ($in['publish'] ?? 'none'), 'connection_id' => (int) ($in['connection_id'] ?? 0), 'platforms' => (array) ($in['platforms'] ?? [])]);
        if (!$r['ok']) api_fail($r['error'], $r['code'] ?? 'error', ($r['code'] ?? '') === 'credits' ? 402 : 409);
        api_ok(['run' => $r['run'], 'balance' => credits_balance($uid)]);

    case 'agent_cancel':
        $r = as_agent_cancel($uid, api_int('run_id'));
        if (!$r['ok']) api_fail($r['error'], $r['code'] ?? 'state', 409);
        api_ok(['run' => $r['run'], 'balance' => credits_balance($uid)]);

    case 'agent_status':
        $run = as_run(api_int('run_id'), $uid);
        if (!$run) api_fail('المهمة مش موجودة', 'not_found', 404);
        if ($run['status'] === 'running' && (!function_exists('ce_worker_alive') || !ce_worker_alive())) $run = as_agent_tick($run);
        api_ok(['run' => as_run_public($run)]);
}

api_fail('عملية مش معروفة', 'action', 404);
