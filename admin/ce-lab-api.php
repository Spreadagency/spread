<?php
/**
 * Spread AI — الأدمن: API مدير البرومبتات + الـ Playground + اختبار الرجوع (المرحلة H)
 *   GET  ?a=state                                   المراحل · البراندات · الإعدادات
 *   GET  ?a=stage&stage=…                           النسخ · v0 · المتغيرات · الموديلات · الحالات · آخر الاختبارات
 *   GET  ?a=diff&stage=…&x=…&y=…                    الفرق بين نسختين (0 = v0)
 *   GET  ?a=run_get&id=…                            حالة اختبار رجوع
 *   POST a=input {stage, uid, format, prev}         رسالة المستخدم بنفس دوال المحرك
 *   POST a=run {stage, user, images, meta, vars, version|draft, models[≤3]}   تشغيل sandbox جنب بعض
 *   POST a=critic {stage, output, meta, expected?}  تقييم رد
 *   POST a=prompt_save {stage, body, notes}         نسخة جديدة (مسودة)
 *   POST a=prompt_activate {stage, version_id, ack} تفعيل (بعد اختبار الرجوع) أو رجوع لنسخة قديمة/v0
 *   POST a=case_save {stage, name, user, vars, images, meta, min_score, expected} · a=case_seed {stage} · a=case_delete {id} · a=case_toggle {id}
 *   POST a=regression_start {stage, version_id | model} · a=regression_step {id}
 *   POST a=model_apply {stage, model, ack}
 * الـ POST: الحقل csrf + الحقل p (JSON بـ base64 — عشان جدار الحماية مايوقفش نصوص البرومبتات).
 * كل نداءات الـ AI هنا sandbox: مفيش كتابة في بيانات عملاء ولا خصم كريدت.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ce-lab.php';

require_admin();
require_admin_can('ai_settings');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = function (array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $code < 400] + $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
$fail = fn(string $msg, int $code = 400, array $extra = []) => $out(['error' => $msg] + $extra, $code);

if (!ce_prompts_ready()) $fail('شغّل ترحيل 2026_ce_lab الأول', 503);
$admin = current_admin();
$aid = (int) ($admin['id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];
$a = (string) ($method === 'POST' ? ($_POST['a'] ?? '') : ($_GET['a'] ?? ''));
$in = [];
if ($method === 'POST') {
    require_csrf();
    $in = json_decode((string) base64_decode((string) ($_POST['p'] ?? ''), true), true) ?: [];
    session_release();   // النداءات طويلة — مانقفلش باقي صفحات الأدمن
}
$stage = (string) ($in['stage'] ?? ($_GET['stage'] ?? ''));
$needStage = fn() => isset(CE_PROMPT_STAGES[$stage]) ? null : $fail('مرحلة مش معروفة');

switch ($a) {
    case 'state':
        $stages = [];
        foreach (CE_PROMPT_STAGES as $k => $l) {
            $act = ce_prompt_active($k);
            $stages[] = ['key' => $k, 'label' => $l, 'production' => in_array($k, CE_PROMPT_PRODUCTION, true), 'active_version' => $act ? (int) $act['version'] : 0,
                         'cases' => (int) (db_one('SELECT COUNT(*) n FROM ce_golden_cases WHERE stage = ? AND is_active = 1', [$k])['n'] ?? 0)];
        }
        $out(['stages' => $stages, 'brands' => ce_lab_brands(), 'pipeline' => CE_LAB_PIPELINE, 'max_drop' => ce_setting_float('ce_regression_max_drop', 3)]);

    case 'stage':
        $needStage();
        $vers = [];
        foreach (ce_prompt_versions($stage) as $v) {
            $run = db_one('SELECT id, status, delta, avg_new, avg_base FROM ce_regression_runs WHERE stage = ? AND kind = "prompt" AND candidate_version_id = ? ORDER BY id DESC LIMIT 1', [$stage, (int) $v['id']]);
            $vers[] = ['id' => (int) $v['id'], 'version' => (int) $v['version'], 'body' => $v['body'], 'notes' => $v['notes'], 'author' => $v['author'], 'created_at' => $v['created_at'],
                       'is_active' => (int) $v['is_active'] === 1, 'was_active' => !empty($v['activated_at']), 'run' => $run ? ['id' => (int) $run['id'], 'status' => $run['status'], 'delta' => $run['delta'] !== null ? (float) $run['delta'] : null] : null];
        }
        $vars = [];
        $cur = ce_prompt_vars($stage, ['dna' => ce_demo_dna()]);
        foreach (CE_PROMPT_VARS[$stage] ?? [] as $k => $l) $vars[] = ['key' => $k, 'label' => $l, 'sample' => mb_substr((string) ($cur[$k] ?? ''), 0, 200)];
        $cases = array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'min_score' => (float) $c['min_score'], 'is_active' => (int) $c['is_active'] === 1,
                                      'has_expected' => !empty($c['expected_json']), 'images' => !empty($c['images_json']), 'created_at' => $c['created_at']], ce_lab_cases($stage));
        $runs = array_map(fn($r) => ce_lab_run_public($r) + ['items' => null], db_all('SELECT * FROM ce_regression_runs WHERE stage = ? ORDER BY id DESC LIMIT 15', [$stage]));
        $out(['stage' => $stage, 'label' => CE_PROMPT_STAGES[$stage], 'default' => ce_prompt_default($stage), 'versions' => $vers, 'vars' => $vars,
              'models' => ce_lab_models($stage), 'current_model' => ce_lab_current_model($stage), 'cases' => $cases, 'runs' => $runs]);

    case 'diff':
        $needStage();
        $body = function ($id) use ($stage) {
            if ((int) $id === 0) return ce_prompt_default($stage);
            $v = db_one('SELECT body FROM ce_prompt_versions WHERE id = ? AND stage = ?', [(int) $id, $stage]);
            return $v ? (string) $v['body'] : '';
        };
        $out(['diff' => ce_prompt_diff($body($_GET['x'] ?? 0), $body($_GET['y'] ?? 0))]);

    case 'run_get':
        $r = db_one('SELECT * FROM ce_regression_runs WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
        if (!$r) $fail('مش موجود', 404);
        $out(['run' => ce_lab_run_public($r)]);

    case 'input':
        $needStage();
        $uid = (int) ($in['uid'] ?? 0);
        if ($uid && !ce_dna_row($uid)) $fail('البراند ده مالوش Brand DNA');
        $r = ce_lab_input($stage, $uid, (string) ($in['format'] ?? 'post'), (array) ($in['prev'] ?? []));
        $out($r);

    case 'run':
        $needStage();
        $user = (string) ($in['user'] ?? '');
        if (mb_strlen($user) < 10) $fail('الرسالة فاضية');
        $vars = (array) ($in['vars'] ?? []) + ce_prompt_vars($stage);
        $sys = isset($in['draft']) && is_string($in['draft']) && trim($in['draft']) !== '' ? ce_prompt_render($in['draft'], $vars)
            : ce_lab_system($stage, isset($in['version']) && $in['version'] !== null && $in['version'] !== '' ? (int) $in['version'] : null, $vars);
        $images = array_values(array_filter((array) ($in['images'] ?? []), fn($u) => is_string($u) && str_starts_with($u, 'data:image/')));
        $allowed = array_column(ce_lab_models($stage), 'key');
        $models = array_slice(array_values(array_unique(array_filter((array) ($in['models'] ?? []), fn($m) => in_array($m, $allowed, true)))), 0, 3) ?: [null];
        @set_time_limit(300);
        $res = [];
        foreach ($models as $m) {
            $r = ce_lab_call($stage, $sys, $user, $images, $m);
            $sc = ce_lab_score($stage, $r['ok'] ? $r['data'] : null, (array) ($in['meta'] ?? []), null, false);
            $res[] = $r + ['model_key' => $m ?? ce_lab_current_model($stage), 'check' => $sc];
        }
        $out(['results' => $res, 'system' => $sys]);

    case 'critic':
        $needStage();
        @set_time_limit(180);
        $out(['score' => ce_lab_score($stage, is_array($in['output'] ?? null) ? $in['output'] : null, (array) ($in['meta'] ?? []), is_array($in['expected'] ?? null) ? $in['expected'] : null)]);

    case 'prompt_save':
        $needStage();
        $r = ce_prompt_save($stage, (string) ($in['body'] ?? ''), (string) ($in['notes'] ?? ''), $aid);
        if (!$r['ok']) $fail($r['error'], 422);
        admin_log('ce_prompt_save', 'ce_prompt', $r['id'], $stage . ' v' . $r['version']);
        $out($r);

    case 'prompt_activate':
        $needStage();
        $r = ce_prompt_activate($stage, (int) ($in['version_id'] ?? 0), $aid, !empty($in['ack']));
        if (!$r['ok']) $fail($r['error'], 409, ['code' => $r['code'] ?? null]);
        admin_log(!empty($r['rollback']) ? 'ce_prompt_rollback' : 'ce_prompt_activate', 'ce_prompt', (int) ($in['version_id'] ?? 0), $stage);
        $out($r);

    case 'case_save':
        $needStage();
        $r = ce_lab_case_save($stage, $in, $aid);
        if (!$r['ok']) $fail($r['error'], 422);
        admin_log('ce_case_save', 'ce_golden_case', $r['id'], $stage);
        $out($r);

    case 'case_seed':
        $needStage();
        @set_time_limit(120);
        $r = ce_lab_case_seed($stage, $aid);
        if (!$r['ok']) $fail($r['error'], 422);
        $out($r);

    case 'case_delete':
    case 'case_toggle':
        $id = (int) ($in['id'] ?? 0);
        if ($a === 'case_delete') db_run('DELETE FROM ce_golden_cases WHERE id = ?', [$id]);
        else db_run('UPDATE ce_golden_cases SET is_active = 1 - is_active WHERE id = ?', [$id]);
        admin_log($a === 'case_delete' ? 'ce_case_delete' : 'ce_case_toggle', 'ce_golden_case', $id);
        $out([]);

    case 'regression_start':
        $needStage();
        $r = ce_lab_regression_start($stage, !empty($in['version_id']) ? (int) $in['version_id'] : null, !empty($in['model']) ? (string) $in['model'] : null, $aid);
        if (!$r['ok']) $fail($r['error'], 422, ['code' => $r['code'] ?? null]);
        admin_log('ce_regression_start', 'ce_regression_run', $r['id'], $stage);
        $out($r);

    case 'regression_step':
        @set_time_limit(300);
        $r = ce_lab_regression_step((int) ($in['id'] ?? 0));
        if (!$r['ok']) $fail($r['error'], 404);
        $out($r);

    case 'model_apply':
        $needStage();
        $r = ce_lab_model_apply($stage, (string) ($in['model'] ?? ''), $aid, !empty($in['ack']));
        if (!$r['ok']) $fail($r['error'], 409, ['code' => $r['code'] ?? null]);
        admin_log('ce_model_apply', 'ai_task_route', null, $stage . ' → ' . $in['model']);
        $out($r);
}
$fail('عملية مش معروفة', 404);
