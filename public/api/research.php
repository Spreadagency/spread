<?php
/**
 * Spread AI v2 — API البحث العميق (المرحلة ⑦-ب)
 *
 *   GET  action=list                              سجل الأبحاث (المحفوظة بـ saved=1)
 *   GET  action=get&id=                           بحث كامل (الخطوات · المصادر · النتيجة)
 *   POST action=plan   {id?, q, type, scope}      مسودة البحث + الخطة (مجانية)
 *   POST action=start  {id}                       خصم الكريدت + بداية التنفيذ
 *   POST action=step   {id}                       الخطوة الجاية (الواجهة بتكرر لحد ما يخلص)
 *   POST action=refresh {id}                      نفس البحث بمصادر جديدة + «إيه الجديد»
 *   POST action=source {id, n, op: pin|exclude}   تثبيت / استبعاد مصدر
 *   POST action=brain  {id, cats[], opps[]}       إضافة رؤى لـ Brand Brain
 *   POST action=save_toggle {id} · delete {id}
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/research.php';

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'list';

if (get_setting('research_enabled', '1') !== '1') {
    api_fail('البحث العميق مش متاح حاليًا', 'disabled', 403);
}

/** البحث ده بتاع العميل (وإلا 404) */
function rs_own(int $uid): array
{
    $r = research_row(api_int('id'), $uid);
    if (!$r) api_fail('البحث ده مش موجود', 'not_found', 404);
    return $r;
}

/** قفل على البحث — طلبين في نفس الوقت (تابين أو دبل كليك) مايشتغلوش مع بعض */
function rs_lock(int $id): void
{
    $got = (int) (db_one('SELECT GET_LOCK(?, 0) l', ['spread_rs_' . $id])['l'] ?? 0);
    if ($got !== 1) api_fail('البحث شغّال في طلب تاني — استنى لحظة', 'busy', 409);
    register_shutdown_function(fn() => db_one('SELECT RELEASE_LOCK(?) l', ['spread_rs_' . $id]));
}

function rs_out(array $r): array
{
    return ['research' => research_to_api($r), 'balance' => credits_balance((int) $r['user_id'])];
}

/** بداية تشغيل: المحرك · الحد · الكريدت */
function rs_start(array $r, int $uid, bool $refresh): void
{
    $e = research_engine();
    if (!$e['ok']) api_fail($e['msg'], 'engine', 503);
    if (!rate_limit('research_start', 'u' . $uid, 6, 3600)) api_fail('عملت أبحاث كتير في الساعة دي — جرّب بعد شوية', 'rate_limit', 429);
    $running = (int) (db_one('SELECT COUNT(*) n FROM researches WHERE user_id = ? AND status = "running" AND id <> ?', [$uid, $r['id']])['n'] ?? 0);
    if ($running >= 2) api_fail('عندك بحثين شغّالين — استنى لما واحد يخلص', 'limit', 409);

    if ($__g = plan_gate($uid, 'research')) api_fail($__g, 'quota', 402, ['upgrade' => plan_upgrade_link()]);
    $scope = research_dec($r['scope_json']);
    $cost = research_cost($scope['depth'] ?? 'medium');
    $note = ($refresh ? 'تحديث بحث: ' : 'بحث عميق: ') . mb_substr((string) $r['title'], 0, 60);
    if ($cost > 0 && (credits_balance($uid) < $cost || !credits_consume($uid, $cost, $note, 'research', (int) $r['id']))) {
        api_fail(credits_short_msg($cost), 'credits', 402, ['need' => $cost]);
    }
    research_begin($r, $cost, $e['mode'] . ':' . $e['model'], $refresh);
}

switch ($action) {
    case 'list':
        $saved = api_int('saved') === 1;
        $rows = db_all('SELECT * FROM researches WHERE user_id = ?' . ($saved ? ' AND saved = 1' : '') . ' AND status <> "draft" ORDER BY id DESC LIMIT 60', [$uid]);
        api_ok(['items' => array_map(fn($r) => research_to_api($r, false), $rows)]);

    case 'get':
        api_ok(rs_out(rs_own($uid)));

    case 'plan':
        $q = api_str('q', 1500);
        $type = api_str('type', 20);
        if (!isset(research_types()[$type])) $type = 'custom';
        if (mb_strlen(trim($q)) < 4) api_fail('اكتب سؤال البحث الأول', 'invalid', 422);
        require_once __DIR__ . '/../../includes/brand-brain.php';
        $in = api_param('scope', []);
        $scope = research_scope_clean(is_array($in) ? $in : [], brand_for_user($uid));
        $title = research_default_title($type, $q, $scope);
        $id = api_int('id');
        $r = $id ? research_row($id, $uid) : null;
        if ($r && in_array($r['status'], ['draft', 'failed'], true)) {
            // مسودة أو بحث مااكتملش (الكريدت رجع): نفس السجل بالخطة الجديدة
            db_run('UPDATE researches SET question = ?, rtype = ?, scope_json = ?, title = ?, status = "draft", error = NULL WHERE id = ?',
                [$q, $type, json_encode($scope, JSON_UNESCAPED_UNICODE), $title, $r['id']]);
            $id = (int) $r['id'];
        } else {
            $drafts = (int) (db_one('SELECT COUNT(*) n FROM researches WHERE user_id = ? AND status = "draft"', [$uid])['n'] ?? 0);
            if ($drafts >= 20) db_run('DELETE FROM researches WHERE user_id = ? AND status = "draft" ORDER BY id ASC LIMIT 5', [$uid]);
            $id = db_insert('INSERT INTO researches (user_id, title, question, rtype, scope_json) VALUES (?,?,?,?,?)',
                [$uid, $title, $q, $type, json_encode($scope, JSON_UNESCAPED_UNICODE)]);
        }
        $e = research_engine();
        api_ok(rs_out(research_row($id, $uid)) + ['engine' => ['ok' => $e['ok'], 'msg' => $e['msg']]]);

    case 'start':
        $r = rs_own($uid);
        if (!in_array($r['status'], ['draft', 'failed'], true)) api_fail('البحث ده اتبدأ قبل كده', 'state', 409);
        rs_lock((int) $r['id']);
        rs_start($r, $uid, false);
        api_ok(rs_out(research_row((int) $r['id'], $uid)));

    case 'refresh':
        $r = rs_own($uid);
        if ($r['status'] !== 'done') api_fail('البحث لسه ماخلصش', 'state', 409);
        rs_lock((int) $r['id']);
        rs_start($r, $uid, true);
        api_ok(rs_out(research_row((int) $r['id'], $uid)));

    case 'step':
        $r = rs_own($uid);
        if ($r['status'] !== 'running') api_ok(rs_out($r) + ['done' => true]);
        if (!rate_limit('research_step', 'u' . $uid, 90, 600)) api_fail('وقفة قصيرة — كمّل بعد دقيقة', 'rate_limit', 429);
        rs_lock((int) $r['id']);
        $res = research_run_step($r, $uid);
        $r = research_row((int) $r['id'], $uid);
        api_ok(rs_out($r) + ['done' => $r['status'] !== 'running', 'step_ok' => $res['ok'], 'retry' => $res['retry'], 'note' => $res['error']]);

    case 'source':
        $r = rs_own($uid);
        if ($r['status'] === 'running') api_fail('استنى لما البحث يخلص', 'state', 409);
        $n = api_int('n');
        $op = api_str('op', 10);
        $src = research_dec($r['sources_json']);
        $hit = false;
        foreach ($src as &$s) {
            if ((int) $s['n'] !== $n) continue;
            if ($op === 'pin') $s['pinned'] = empty($s['pinned']);
            elseif ($op === 'exclude') $s['excluded'] = empty($s['excluded']);
            else api_fail('عملية غير معروفة', 'invalid', 422);
            $hit = true;
        }
        unset($s);
        if (!$hit) api_fail('المصدر ده مش موجود', 'not_found', 404);
        db_run('UPDATE researches SET sources_json = ? WHERE id = ?', [json_encode($src, JSON_UNESCAPED_UNICODE), $r['id']]);
        api_ok(rs_out(research_row((int) $r['id'], $uid)));

    case 'brain':
        $r = rs_own($uid);
        if ($r['status'] !== 'done') api_fail('البحث لسه ماخلصش', 'state', 409);
        $cats = array_map('strval', (array) api_param('cats', []));
        $opps = array_map('intval', (array) api_param('opps', []));
        if (!$cats && !$opps) api_fail('اختار حاجة تتضاف', 'invalid', 422);
        $n = research_brain_save($r, $uid, $cats, $opps);
        if ($n === 0) api_fail('لازم يكون عندك هوية براند الأول', 'no_brand', 422);
        api_ok(rs_out(research_row((int) $r['id'], $uid)) + ['added' => $n]);

    case 'save_toggle':
        $r = rs_own($uid);
        db_run('UPDATE researches SET saved = 1 - saved WHERE id = ?', [$r['id']]);
        api_ok(['saved' => !$r['saved']]);

    case 'delete':
        $r = rs_own($uid);
        // بحث شغّال بيتمسح بس لو واقف من أكتر من 30 دقيقة (العميل قفل الصفحة ومارجعش)
        if ($r['status'] === 'running' && strtotime((string) $r['updated_at']) > time() - 1800) api_fail('استنى لما البحث يخلص', 'state', 409);
        db_run('DELETE FROM researches WHERE id = ?', [$r['id']]);
        // الرؤى اللي اتضافت لـ Brand Brain بتفضل (العميل يشيلها من المستندات لو عايز)
        api_ok(['deleted' => true]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
