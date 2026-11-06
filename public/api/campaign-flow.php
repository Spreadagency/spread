<?php
/**
 * Spread AI v2 — API الحملة كاملة (المرحلة ⑥-ب)
 *
 *   GET  action=board&id=                      الحملة + كل الأفكار بمراحلها + الأرقام + التكاليف + صفحات النشر
 *   POST action=ideas_generate {id, batch, total}   دفعة أفكار (5) — الواجهة بتكرر لحد العدد المطلوب
 *   POST action=idea_toggle   {id, idea_id, selected}   · idea_select_all {id, selected}
 *   POST action=idea_save     {id, idea_id, title, desc, audience, hook}  ← لو ليها محتوى: has_content (نسأل نحدّثه؟)
 *   POST action=idea_delete   {id, idea_id}
 *   POST action=content_generate {id, idea_id, regen}  منشور + سكريبت + فكرة تصميم (فكرة واحدة في الطلب)
 *   POST action=content_save  {id, idea_id, mode: post|script, hook, body, cta, tags, design}
 *   POST action=evaluate      {id, idea_id}      · improve {id, idea_id}
 *   POST action=approve       {id}  (كل الجاهز)  · skip_eval {id}
 *   POST action=plan_set      {id, idea_id, at|null, platform}
 *   POST action=auto_plan     {id, platform, only_unplanned}
 *
 * التصميم: ajax/generate-design.php (نفس مسار المكتبة، bulk=1) · النشر: ajax/publish-direct.php
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/social.php';
require_once __DIR__ . '/../../includes/campaign-flow.php';
require_once __DIR__ . '/../../includes/campaign-quality.php';   // خطة الحملات §5: كتابة + تقييم في طلب واحد
require_once __DIR__ . '/../../includes/campaign-allowance.php'; // خطة الحملات §2: الحملة وحدة في الباقة
require_once __DIR__ . '/../../includes/campaign-write.php';     // كتابة الفكرة (نفس المسار للصفحة وشغل الخلفية)
require_once __DIR__ . '/../../includes/campaign-jobs.php';      // camp_write · camp_design على الطابور
require_once __DIR__ . '/../../includes/brand-brain.php';
require_once __DIR__ . '/../../includes/brand-kit.php';

// الجلسة بتتقفل للتوليد بالدفعات بس (بيكتب حالة الدفعة فيها) — باقي العمليات بتفك القفل
// علشان طلب AI طويل (كتابة · تقييم · تحسين) مايجمّدش باقي صفحات العميل وهو شغال
$action = api_action() ?: 'board';
$user = api_boot(true, $action === 'ideas_generate');
$uid = (int) $user['id'];

if (get_setting('campaigns_enabled', '1') !== '1') {
    api_fail('الحملات مش متاحة حاليًا', 'disabled', 403);
}

$c = api_own('campaigns', api_int('id'), $uid);

/** فكرة من الحملة دي (وإلا 404) */
function cf_idea(array $c, int $uid): array
{
    $i = db_one('SELECT * FROM campaign_ideas WHERE id = ? AND campaign_id = ? AND user_id = ?', [api_int('idea_id'), $c['id'], $uid]);
    if (!$i) api_fail('الفكرة دي مش موجودة', 'not_found', 404);
    return $i;
}

/** الفكرة بعد التعديل بشكل الواجهة */
function cf_idea_out(array $c, int $uid, int $ideaId): array
{
    $i = db_one('SELECT * FROM campaign_ideas WHERE id = ? AND user_id = ?', [$ideaId, $uid]);
    $content = $i['content_id'] ? db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]) : null;
    $designs = $content ? (campaign_designs([(int) $content['id']])[(int) $content['id']] ?? []) : [];
    return campaign_idea_api($i, $content ?: null, $designs);
}

/** overrides البريف — includes/campaign-write.php */
function cf_brief_overrides(int $uid, array $idea, string $fmt, ?array $old): array
{
    return cw_brief_overrides($uid, $idea, $fmt, $old);
}

/** المحتوى والتقييم والتصميم محتاجين هوية البراند فوق الحد (الأفكار مفتوحة) */
function cf_gate(int $uid): array
{
    $brand = brand_for_user($uid);
    $h = brand_health($brand);
    if (!$h['unlocked']) {
        api_fail('لازم هوية البراند تعدّي ' . $h['gate'] . '% علشان المحتوى يطلع بأسلوبك — هويتك دلوقتي ' . $h['pct'] . '%', 'brand_gate', 422, ['health' => $h]);
    }
    return $brand ?: [];
}

function cf_charge(int $uid, int $cost, string $note, string $ref, ?int $refId): void
{
    if ($cost <= 0) return;
    if (credits_balance($uid) < $cost || !credits_consume($uid, $cost, $note, $ref, $refId)) {
        api_fail(credits_short_msg($cost), 'credits', 402, ['balance' => credits_balance($uid)]);
    }
}

function cf_refund(int $uid, int $cost, string $note, ?int $refId): void
{
    if ($cost > 0) credits_add($uid, $cost, 'استرداد: ' . $note, null, 'refund', $refId);
}

/** «مرفوضة» بدل المسح (لو الترحيل اتشغّل) */
function cf_reject_idea(array $c, array $i): bool
{
    if (!function_exists('ca_ready') || !ca_ready()) return false;
    db_run('UPDATE campaign_ideas SET rejected = 1, selected = 0 WHERE id = ? AND campaign_id = ?', [(int) $i['id'], (int) $c['id']]);
    return true;
}

/** آخر 100 فكرة مرفوضة للعميل (قايمة «ماتكررهاش») */
function cf_rejected_titles(int $uid): array
{
    if (!function_exists('ca_ready') || !ca_ready()) return [];
    return array_column(db_all('SELECT title FROM campaign_ideas WHERE user_id = ? AND rejected = 1 ORDER BY id DESC LIMIT 100', [$uid]), 'title');
}

/** أسئلة البريف من الـ Skill (خطوة الإعداد) */
function cf_brief_questions(array $c): array
{
    if (!function_exists('ce_skill_questions')) return [];
    $brand = brand_for_user((int) $c['user_id']) ?: [];
    return ce_skill_questions(campaign_skill($brand, $c));
}

function cf_type(string $angle): string
{
    return cw_type($angle);
}

/** الشكل وعدد الشرائح المطلوبين (من الطلب أو الفكرة) */
function cf_format_req(array $i): array
{
    $f = api_str('format', 12);
    $f = isset(content_formats()[$f]) ? $f : campaign_idea_format($i);
    $n = $f === 'carousel' ? carousel_clamp(api_int('slides') ?: (int) ($i['slides_count'] ?? 0)) : 0;
    return [$f, $n];
}

function cf_video_locked(?array $content): bool
{
    return cw_video_locked($content);
}

function cf_format_fields(string $format, int $slides, array $p, ?array $old): array
{
    return cw_format_fields($format, $slides, $p, $old);
}

switch ($action) {

    /* ══════════ اللوحة ══════════ */
    case 'board':
        api_ok(campaign_board_payload($c, $uid) + ['package' => ca_campaign_view($c), 'allowance' => ca_status($uid), 'jobs' => cj_active($uid, (int) $c['id']),
            'write_mode' => campaign_write_mode(), 'worker' => ce_worker_alive(), 'questions' => cf_brief_questions($c)]);

    /* ══════════ الشغل في الخلفية (خطة الحملات §4) — قفل التاب مابيوقفش الشغل ══════════ */
    case 'bulk_write':
        cf_gate($uid);
        $r = cj_start_write($uid, $c, array_map('intval', (array) (api_input()['idea_ids'] ?? [])));
        if (!$r['ok']) api_fail($r['error'] ?? 'مقدرناش نبدأ', $r['code'] ?? 'error', 409);
        api_ok(['job' => ce_job_public($r['job']), 'count' => $r['count'], 'existing' => !empty($r['existing'])]);

    case 'bulk_design':
        cf_gate($uid);
        $r = cj_start_design($uid, $c, array_map('intval', (array) (api_input()['content_ids'] ?? [])), api_str('ratio', 8));
        if (!$r['ok']) api_fail($r['error'] ?? 'مقدرناش نبدأ', $r['code'] ?? 'error', 409);
        api_ok(['job' => ce_job_public($r['job']), 'count' => $r['count'], 'existing' => !empty($r['existing'])]);

    case 'job_step':
        $j = ce_job_get(api_int('job_id'), $uid);
        if (!$j || !in_array($j['kind'], CJ_KINDS, true) || (int) $j['ref_id'] !== (int) $c['id']) api_fail('مش موجودة', 'not_found', 404);
        if (in_array($j['status'], ['queued', 'running'], true) && ($after = ce_job_step((int) $j['id']))) $j = $after;
        api_ok(['job' => ce_job_public($j), 'worker' => ce_worker_alive(), 'balance' => credits_balance($uid)]);

    case 'job_cancel':
        $j = ce_job_get(api_int('job_id'), $uid);
        if (!$j || !in_array($j['kind'], CJ_KINDS, true) || (int) $j['ref_id'] !== (int) $c['id']) api_fail('مش موجودة', 'not_found', 404);
        if (!ce_job_cancel((int) $j['id'], $uid)) api_fail('مينفعش تتلغي دلوقتي', 'state', 409);
        api_ok(['job' => ce_job_public(ce_job_get((int) $j['id'], $uid))]);

    /* ══════════ فكرة بديلة واحدة (المسودة: أول 5 ببلاش — §2.2) ══════════ */
    case 'idea_alt':
        $brand = brand_for_user($uid) ?: [];
        $i = cf_idea($c, $uid);
        if ($i['content_id']) api_fail('الفكرة دي اتكتبت — امسحها أو أعد كتابتها', 'has_content', 409);
        if (!rate_limit('camp_ideas', 'u' . $uid, 12, 600)) api_fail('ولّدت أفكار كتير بسرعة — استنى شوية', 'rate_limit', 429);
        $c = ca_adopt($c);
        $alt = ca_draft_alt($c);
        $cost = $alt['free'] ? 0 : idea_credits_for(1);
        cf_charge($uid, $cost, 'فكرة بديلة في حملة «' . mb_substr($c['title'], 0, 60) . '»', 'campaign', (int) $c['id']);
        $avoid = array_merge(array_column(db_all('SELECT title FROM campaign_ideas WHERE campaign_id = ? ORDER BY id DESC LIMIT 40', [$c['id']]), 'title'), cf_rejected_titles($uid));
        $ai = campaign_ai(campaign_ideas_prompt($brand, $c, 1, $avoid), campaign_ideas_tokens(1), 'campaign_ideas',
            ['user_id' => $uid, 'reference_id' => (int) $c['id'], 'temperature' => 0.95, 'json_mode' => true]);
        $new = $ai['ok'] ? (campaign_parse_ideas((string) $ai['response'])[0] ?? null) : null;
        if (!$new) {
            cf_refund($uid, $cost, 'فكرة بديلة #' . $c['id'], (int) $c['id']);
            if ($alt['free']) db_run('UPDATE campaigns SET draft_alts = GREATEST(draft_alts - 1, 0) WHERE id = ?', [$c['id']]);
            api_fail('الرد مكانش مظبوط — جرّب تاني', 'ai_failed', 502);
        }
        // الفكرة القديمة بتتعلّم «مرفوضة» (مش بتتمسح) والجديدة مكانها بنفس الشكل والترتيب
        if (!cf_reject_idea($c, $i)) db_run('DELETE FROM campaign_ideas WHERE id = ?', [$i['id']]);
        $nid = (int) db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, sort_order, angle, title, description, audience, hook, formats, selected, brief_json, format, slides_count)
                   VALUES (?,?,?,?,?,?,?,?,?,1,?,?,?)', [$c['id'], $uid, (int) $i['sort_order'], $new['angle'], $new['title'], $new['desc'], $new['audience'], $new['hook'],
                   implode('|', $new['formats']), $i['brief_json'], $i['format'], $i['slides_count']]);
        api_ok(['idea' => cf_idea_out($c, $uid, $nid), 'replaced' => (int) $i['id'], 'balance' => credits_balance($uid), 'free' => $alt['free'],
                'package' => ca_campaign_view(db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]))]);

    /* ══════════ الحملة في الباقة (خطة الحملات §2.2) ══════════ */
    case 'plan_approve':
        $r = ca_approve($uid, (int) $c['id']);
        if (!$r['ok']) api_fail($r['error'], $r['code'] ?? 'approve', 409);
        $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]);
        api_ok(['package' => ca_campaign_view($c), 'allowance' => ca_status($uid), 'already' => !empty($r['already'])]);

    case 'plan_cancel':
        $r = ca_cancel($uid, (int) $c['id']);
        if (!$r['ok']) api_fail($r['error'], 'cancel', 409);
        $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]);
        api_ok(['package' => ca_campaign_view($c), 'allowance' => ca_status($uid), 'released' => $r['released']]);

    case 'plan_targets':
        $c = ca_adopt($c);
        $r = ca_set_targets($c, api_param('posts') !== null ? api_int('posts', 0, 0, 500) : null, api_param('videos') !== null ? api_int('videos', 0, 0, 500) : null);
        if (!$r['ok']) api_fail($r['error'], 'targets', 409);
        api_ok(['package' => ca_campaign_view(db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]))]);

    /* ══════════ ① الأفكار ══════════ */
    case 'ideas_generate':
        $brand = brand_for_user($uid) ?: [];
        if (empty($brand['business_name'])) api_fail('اكتب اسم نشاطك في Brand Brain الأول', 'no_brand', 422);
        $total = api_int('total', 10, 3, CAMP_MAX_IDEAS);
        $batch = api_int('batch', 0, 0, 10);
        // إعداد الحملة: العدد = مجموع المطلوب من كل شكل، وكل فكرة بشكلها
        $setup = campaign_setup($c);
        $seq = $setup['set'] ? campaign_format_sequence($setup['counts']) : [];
        if ($seq) $total = max(1, min(CAMP_MAX_IDEAS, count($seq)));
        $key = 'camp_gen_' . $c['id'];
        $batches = (int) ceil($total / CAMP_IDEAS_BATCH);

        $__draft = null;
        if ($batch === 0) {
            if (!rate_limit('camp_ideas', 'u' . $uid, 6, 600)) api_fail('ولّدت أفكار كتير بسرعة — استنى شوية', 'rate_limit', 429);
            // الحملة في الباقة: بتبقى مسودة · الخطة الأولى + إعادتين ببلاش · أقصى حملتين مسودة
            $c = ca_adopt($c);
            $__draft = ca_draft_plan($c);
            if (!$__draft['ok']) api_fail($__draft['error'], 'draft_limit', 409);
            $cost = $__draft['free'] ? 0 : idea_credits_for($total);
            cf_charge($uid, $cost, 'أفكار حملة «' . mb_substr($c['title'], 0, 60) . '» (' . $total . ')', 'campaign', (int) $c['id']);
            // جيل جديد: بنمسح المقترح بس — المختار وأي فكرة ليها محتوى بيفضلوا
            db_run('DELETE FROM campaign_ideas WHERE campaign_id = ? AND selected = 0 AND content_id IS NULL' . (ca_ready() ? ' AND rejected = 0' : ''), [$c['id']]);   // المرفوضة بتفضل للذاكرة
            $_SESSION[$key] = ['total' => $total, 'next' => 0, 'cost' => $cost, 'at' => time()];
        }
        $g = $_SESSION[$key] ?? null;
        // الدفعات بعد الأولى مجانية بس لنفس الجيل (مدفوع في الدفعة 0) وبالترتيب
        if (!$g || $g['total'] !== $total || $batch !== (int) $g['next'] || time() - (int) $g['at'] > 1800 || $batch >= $batches) {
            api_fail('ابدأ توليد الأفكار من الأول', 'state', 409);
        }

        $n = min(CAMP_IDEAS_BATCH, $total - $batch * CAMP_IDEAS_BATCH);
        @set_time_limit(180); ignore_user_abort(true);   // ممكن طلبين AI ورا بعض — والأفكار تتحفظ حتى لو المتصفح قفل
        // includes/campaign-write.php — نفس المسار للوكيل
        $__b = cw_ideas_batch($uid, $c, $brand, $n, $batch, $seq, $setup, cf_rejected_titles($uid));
        if (!$__b['ok']) {
            // الدفعة الأولى فشلت = مفيش ولا فكرة ← نرجّع الكريدت كله · بعدها العميل يقدر يكمّل الباقي مجانًا
            if ($batch === 0) {
                cf_refund($uid, (int) $g['cost'], 'أفكار حملة #' . $c['id'], (int) $c['id']);
                unset($_SESSION[$key]);
                if (ca_is_package($c)) db_run('UPDATE campaigns SET draft_plans = GREATEST(draft_plans - 1, 0), draft_regens = IF(draft_plans > 1, GREATEST(draft_regens - 1, 0), draft_regens) WHERE id = ?', [$c['id']]);
            }
            api_fail($__b['error'], 'ai_failed', 502, ['refunded' => $batch === 0]);
        }
        // بريف الحملة (الهوية وقت الأفكار) — بيظهر للعميل في الحملة
        if ($batch === 0 && bk_enabled()) {
            db_run('UPDATE campaigns SET brief_json = ? WHERE id = ?', [bk_json(BrandContext::campaignBrief($uid, $c)), $c['id']]);
        }
        $_SESSION[$key]['next'] = $batch + 1;
        if ($batch + 1 >= $batches) {
            unset($_SESSION[$key]);
            require_once __DIR__ . '/../../includes/Notifier.php';
            Notifier::notify($uid, 'ideas_ready', ['count' => $total, 'campaign' => (string) $c['title']], ['url' => 'campaign.php?id=' . (int) $c['id']]);
        }
        db_run('UPDATE campaigns SET ideas_count = ?, revision = revision + 1 WHERE id = ?', [min($total, 255), $c['id']]);
        api_ok(['ideas' => campaign_board($c), 'done' => $batch + 1 >= $batches, 'balance' => credits_balance($uid)]);

    case 'idea_toggle':
        $i = cf_idea($c, $uid);
        db_run('UPDATE campaign_ideas SET selected = ? WHERE id = ?', [api_int('selected') ? 1 : 0, $i['id']]);
        if (api_int('selected')) EventTracker::track('idea_selected', 'campaign_idea', (int) $i['id'], ['campaign_id' => (int) $c['id']], $uid);
        api_ok(['selected' => (bool) api_int('selected')]);

    case 'idea_select_all':
        db_run('UPDATE campaign_ideas SET selected = ? WHERE campaign_id = ? AND user_id = ?', [api_int('selected') ? 1 : 0, $c['id'], $uid]);
        if (api_int('selected')) EventTracker::track('idea_selected', 'campaign', (int) $c['id'], ['all' => true], $uid);
        api_ok();

    case 'idea_save':
        $i = cf_idea($c, $uid);
        $title = api_str('title', 255);
        if (mb_strlen($title) < 2) api_fail('اكتب عنوان للفكرة', 'invalid', 422);
        $changed = $title !== $i['title'] || api_str('desc', 1000) !== (string) $i['description'];
        db_run('UPDATE campaign_ideas SET title = ?, description = ?, audience = ?, hook = ? WHERE id = ?',
            [$title, api_str('desc', 1000) ?: null, api_str('audience', 255) ?: null, api_str('hook', 500) ?: null, $i['id']]);
        api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id']), 'has_content' => $changed && !empty($i['content_id'])]);

    /* الشكل: منشور · كاروسيل (عدد الشرائح) · فيديو · ستوري — قبل ما يتكتب المحتوى */
    case 'idea_format':
        $i = cf_idea($c, $uid);
        [$f, $n] = cf_format_req($i);
        if ($i['content_id']) {
            $co = db_one('SELECT format, slides_count FROM contents WHERE id = ?', [$i['content_id']]);
            if ($co && ($co['format'] !== $f || ($f === 'carousel' && (int) $co['slides_count'] !== $n))) {
                api_fail('الفكرة دي ليها محتوى — تغيير الشكل محتاج إعادة كتابة', 'has_content', 409);
            }
        }
        db_run('UPDATE campaign_ideas SET format = ?, slides_count = ? WHERE id = ?', [$f, $n ?: null, $i['id']]);
        api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id'])]);

    case 'idea_delete':
        $i = cf_idea($c, $uid);
        // خطة الحملات §7 (الذاكرة): الفكرة اللي لسه ماتكتبتش بتتعلّم «مرفوضة» بدل المسح — وبتدخل قايمة «ماتكررهاش»
        if (!$i['content_id'] && cf_reject_idea($c, $i)) api_ok(['rejected' => true]);
        // المنشور نفسه بيفضل في المكتبة — بيتفك من الحملة بس
        if ($i['content_id']) db_run('UPDATE contents SET campaign_id = NULL WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]);
        db_run('DELETE FROM campaign_ideas WHERE id = ?', [$i['id']]);
        api_ok();

    /* ══════════ ② المحتوى ══════════ */
    case 'content_generate':
        $brand = cf_gate($uid);
        $i = cf_idea($c, $uid);
        // includes/campaign-write.php — نفس المسار لشغل الخلفية (camp_write)
        $r = campaign_write_idea($uid, $c, $i, ['brand' => $brand, 'regen' => api_int('regen') === 1, 'format' => api_str('format', 12), 'slides' => api_int('slides')]);
        if (!$r['ok']) api_fail($r['error'], $r['code'], $r['http'], $r['extra']);
        if (!empty($r['already'])) api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id']), 'already' => true]);
        api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id']), 'balance' => credits_balance($uid)]);

    case 'content_save':
        $i = cf_idea($c, $uid);
        if (!$i['content_id']) api_fail('الفكرة دي لسه ملهاش محتوى', 'no_content', 422);
        $mode = in_array(api_str('mode', 10), ['script', 'slides', 'brief'], true) ? api_str('mode', 10) : 'post';
        if ($mode !== 'post') {
            $content = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]);
            if (!$content) api_fail('المنشور مش موجود', 'not_found', 404);
            if ($mode === 'slides') {
                if (content_format_key($content['format']) !== 'carousel') api_fail('ده مش كاروسيل', 'invalid', 422);
                if (in_array((string) $content['publish_status'], ['published', 'scheduled', 'processing'], true)) api_fail('الكاروسيل اتسلّم للنشر', 'locked', 409);
                $sl = content_slides_clean(api_param('slides', []), (int) $content['slides_count']);
                db_run('UPDATE contents SET slides_json = ?, status = "edited" WHERE id = ?', [json_encode($sl, JSON_UNESCAPED_UNICODE), $content['id']]);
                db_run('UPDATE campaign_ideas SET eval_score = NULL, eval_json = NULL, approved = 0 WHERE id = ?', [$i['id']]);
                api_ok(['saved' => true, 'eval_reset' => true]);
            }
            if (content_format_key($content['format']) !== 'video') api_fail('ده مش فيديو', 'invalid', 422);
            if (cf_video_locked($content)) api_fail('الفيديو اتبعت للتنفيذ — أي تعديل ابعته لفريقنا على واتساب', 'locked', 409);
            $b = video_brief($content);
            if ($mode === 'script') {
                $b = video_brief_clean(['script' => ['hook' => api_str('hook', 500), 'body' => api_str('body', 5000), 'cta' => api_str('cta', 300), 'shoot' => api_str('design', 1000)]], $b);
                $s2 = ['hook' => $b['script']['hook'], 'body' => $b['script']['body'], 'cta' => $b['script']['cta'], 'tags' => (string) ($content['hashtags'] ?? ''), 'design' => $b['script']['shoot']];
                db_run('UPDATE campaign_ideas SET script_json = ?, eval_score = NULL, eval_json = NULL, approved = 0 WHERE id = ?', [json_encode($s2, JSON_UNESCAPED_UNICODE), $i['id']]);
            } else {
                $in = api_param('brief', []);
                $b = video_brief_clean(is_array($in) ? array_diff_key($in, ['script' => 1]) : [], $b);
            }
            // تعديل السكريبت بعد الاعتماد = يرجع «السكريبت جاهز» ويتعتمد تاني
            $st = $mode === 'script' && ($content['video_status'] ?? '') === 'approved' ? 'script' : ($content['video_status'] ?: 'script');
            db_run('UPDATE contents SET video_brief_json = ?, video_status = ? WHERE id = ?', [json_encode($b, JSON_UNESCAPED_UNICODE), $st, $content['id']]);
            api_ok(['saved' => true, 'eval_reset' => $mode === 'script', 'video_status' => video_status_meta($st)]);
        }
        $content = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]);
        if (!$content) api_fail('المنشور مش موجود', 'not_found', 404);
        if (in_array((string) $content['publish_status'], ['published', 'scheduled', 'processing'], true)) {
            api_fail('المنشور اتسلّم لفيسبوك — التعديل هنا مش هيوصل هناك', 'locked', 409);
        }
        $text = campaign_compose_text(api_str('hook', 500), api_str('body', 8000));
        if ($text === '') api_fail('النص مايبقاش فاضي', 'empty', 422);
        $tags = api_str('tags', 1000);
        $cta = api_str('cta', 500);
        $design = api_str('design', 1500);
        $textChanged = $text !== (string) $content['generated_text'] || $tags !== (string) ($content['hashtags'] ?? '') || $cta !== (string) ($content['cta'] ?? '');
        db_run('UPDATE contents SET generated_text = ?, hashtags = ?, cta = ?, design_direction = ?, status = "edited" WHERE id = ?',
            [$text, $tags ?: null, $cta ?: null, $design ?: null, $content['id']]);
        if ($textChanged) {
            bk_feedback_text_edit($uid, (int) $content['id'], (string) $content['generated_text'], $text);
            save_content_version((int) $content['id'], $text, $tags ?: null, $cta ?: null, 'edited', 'تعديل من الحملة');
            // التقييم كان على النص القديم
            db_run('UPDATE campaign_ideas SET eval_score = NULL, eval_json = NULL, approved = 0 WHERE id = ?', [$i['id']]);
        }
        api_ok(['saved' => true, 'eval_reset' => $textChanged]);

    /* ══════════ ③ التقييم ══════════ */
    case 'evaluate':
        $brand = cf_gate($uid);
        $i = cf_idea($c, $uid);
        $content = $i['content_id'] ? db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]) : null;
        if (!$content) api_fail('اكتب المحتوى الأول', 'no_content', 422);
        if (!rate_limit('camp_eval', 'u' . $uid, 60, 600)) api_fail('وقفة قصيرة — كمّل التقييم بعد دقايق', 'rate_limit', 429);
        $cost = (int) get_setting('campaign_eval_cost', 0);
        cf_charge($uid, $cost, 'تقييم منشور #' . $content['id'], 'campaign_idea', (int) $i['id']);
        $ai = campaign_ai(campaign_eval_prompt($brand, $c, $i, $content), 700, 'campaign_eval',
            ['user_id' => $uid, 'reference_type' => 'campaign_idea', 'reference_id' => (int) $i['id'], 'temperature' => 0.3, 'json_mode' => true]);
        $e = $ai['ok'] ? campaign_parse_eval((string) $ai['response']) : null;
        if (!$e) {
            cf_refund($uid, $cost, 'تقييم منشور #' . $content['id'], (int) $i['id']);
            api_fail($ai['ok'] ? 'التقييم مرجعش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التقييم'), 'ai_failed', 502);
        }
        $e['at'] = date('c');
        $__old = json_decode((string) ($i['eval_json'] ?? ''), true) ?: [];
        if (!empty($__old['prev_text'])) { $e['prev_text'] = $__old['prev_text']; $e['prev'] = (int) ($__old['prev'] ?? 0); }
        db_run('UPDATE campaign_ideas SET eval_score = ?, eval_json = ?, approved = ? WHERE id = ?',
            [$e['score'], json_encode($e, JSON_UNESCAPED_UNICODE), $e['score'] >= eval_ready_score() ? 1 : 0, $i['id']]);
        content_set_flag((int) $content['id'], $uid, $e['score'] >= eval_ready_score() ? 'needs_design' : 'needs_review',
            $e['score'] >= eval_ready_score() ? null : mb_substr($e['issues'], 0, 250));
        api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id']), 'balance' => credits_balance($uid)]);

    case 'improve':
        $brand = cf_gate($uid);
        $i = cf_idea($c, $uid);
        $content = $i['content_id'] ? db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]) : null;
        $ev = json_decode((string) ($i['eval_json'] ?? ''), true) ?: [];
        if (!$content) api_fail('اكتب المحتوى الأول', 'no_content', 422);
        if (in_array((string) $content['publish_status'], ['published', 'scheduled', 'processing', 'pending'], true)) {
            api_fail('المنشور ده اتسلّم للنشر خلاص', 'locked', 409);
        }
        if (!rate_limit('camp_content', 'u' . $uid, 45, 600)) api_fail('وقفة قصيرة — كمّل بعد دقايق', 'rate_limit', 429);
        $cost = cost_for('content_regeneration_cost');
        if (cf_video_locked($content)) api_fail('الفيديو اتبعت للتنفيذ', 'locked', 409);   // قبل أي خصم
        // الحملة في الباقة: «حسّن» من رصيد التعديلات ← ولو خلص بالكريدت
        $__ce = ca_edit_allow($c, $cost);
        if (!$__ce['ok']) api_fail($__ce['error'], 'closed', 409);
        if ($__ce['cost'] >= 0) $cost = (int) $__ce['cost'];
        if ($cost > 0 && credits_balance($uid) < $cost) ca_edit_release($c, $__ce);
        cf_charge($uid, $cost, 'تحسين منشور #' . $content['id'], 'campaign_idea', (int) $i['id']);
        $issues = (($ev['issues'] ?? '') ?: 'قوّي الـ Hook والـ CTA ووضّح الرسالة');
        // برومبت «تحسين بعد التقييم» الكامل (v1) بياخد المنشور والتقييم في متغيراته — غير كده بيتضاف لبرومبت الكتابة زي الأول
        $impTask = [];
        if (prompt_full('campaign_improve')) {
            $evText = 'الدرجة: ' . (int) ($ev['score'] ?? 0) . '/100' . (!empty($ev['labels']) && !empty($ev['cats'])
                ? "\n" . implode("\n", array_map(fn($l, $v) => "- {$l}: {$v}", (array) $ev['labels'], array_slice((array) $ev['cats'], 0, count((array) $ev['labels'])))) : '')
                . "\nالملاحظات: " . $issues . (!empty($ev['comment']) ? "\nنقطة القوة: " . $ev['comment'] : '');
            $impTask = ['type' => 'campaign_improve', 'vars' => ['issues' => $issues, 'evaluation' => $evText,
                'post_text' => mb_substr((string) $content['generated_text'], 0, 2500), 'post_cta' => (string) ($content['cta'] ?? ''), 'post_hashtags' => (string) ($content['hashtags'] ?? '')]];
            $extra = '';
        } else {
            $extra = prompt_fill('campaign_improve', ['issues' => $issues])
                . "\nالنسخة الحالية:\n" . mb_substr((string) $content['generated_text'], 0, 2500)
                . "\nCTA الحالي: " . (string) ($content['cta'] ?? '');
        }
        if (cf_video_locked($content)) api_fail('الفيديو اتبعت للتنفيذ', 'locked', 409);
        $ifmt = content_format_key($content['format'] ?? 'post');
        $islides = (int) ($content['slides_count'] ?? 0);
        if ($ifmt === 'carousel') {
            $extra .= "\nالشرائح الحالية:\n";
            foreach (content_slides($content) as $sl) $extra .= 'شريحة ' . $sl['n'] . ' | ' . $sl['title'] . ' | ' . $sl['text'] . "\n";
        }
        if ($ifmt === 'video') $extra .= "\nالسكريبت الحالي:\n" . mb_substr(video_script_text(video_brief($content)), 0, 2000);
        $bkBrief = bk_enabled() ? bk_brief_for_content($content, $uid) : null;
        $__iCall = fn(string $pr) => campaign_ai($pr, $ifmt === 'carousel' ? 2600 : 1800, 'campaign_improve',
            ['user_id' => $uid, 'reference_type' => 'campaign_idea', 'reference_id' => (int) $i['id'], 'temperature' => 0.7]);
        $__iPrompt = fn(string $x) => campaign_content_prompt($brand, $c, $i, trim($extra . ($x !== '' ? "\n\n" . $x : '')), $ifmt, $islides, video_brief($content)['duration'], $bkBrief, $impTask);
        $__ieval = null;
        if (campaign_write_mode() === 'single') {
            // زرار «حسّن» من العميل: نفس الطلب الواحد (كتابة + تقييم) من غير تحسين تلقائي فوقه
            $__w = campaign_write_with_eval($__iPrompt, fn(string $pr, bool $imp) => $__iCall($pr),
                function (string $r) use ($uid): array { $p = campaign_parse_content(BrandContext::stripWhy($r)); foreach (['hook', 'body', 'cta', 'script', 'tags'] as $__k) $p[$__k] = BrandContext::enforce((string) $p[$__k], $uid); return $p; },
                ['fmt' => $ifmt, 'slides' => $islides, 'brand' => $brand, 'skill' => campaign_skill($brand, $c), 'uid' => $uid, 'except' => (int) $content['id'], 'rounds' => 0]);
            $ai = $__w['ai'];
            $p = $__w['p'];
            $__ieval = $__w['eval'];
        } else {
            $ai = $__iCall($__iPrompt(''));
            $p = $ai['ok'] ? campaign_parse_content(BrandContext::stripWhy((string) $ai['response'])) : null;
        }
        if ($p) {
            foreach (['hook', 'body', 'cta', 'script', 'tags'] as $__k) $p[$__k] = BrandContext::enforce((string) $p[$__k], $uid);
            if ($bkBrief) BrandContext::attach((int) $content['id'], $uid, $bkBrief, BrandContext::parseWhy((string) $ai['response']));
        }
        if (!$p || ($p['body'] === '' && $p['hook'] === '')) {
            cf_refund($uid, $cost, 'تحسين منشور #' . $content['id'], (int) $i['id']);
            ca_edit_release($c, $__ce);
            api_fail(!empty($ai['ok']) ? 'الرد مكانش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التحسين'), 'ai_failed', 502);
        }
        $text = campaign_compose_text($p['hook'], $p['body']);
        ai_stamp('contents', (int) $content['id'], $impTask ? 'campaign_improve' : 'campaign_content', $ai);
        db_run('UPDATE contents SET generated_text = ?, hashtags = ?, cta = ?, status = "edited" WHERE id = ?',
            [$text, ($p['tags'] ?: $content['hashtags']) ?: null, ($p['cta'] ?: $content['cta']) ?: null, $content['id']]);
        if ($ifmt === 'carousel' && $p['slides_raw'] !== '') {
            db_run('UPDATE contents SET slides_json = ? WHERE id = ?', [json_encode(campaign_parse_slides($p['slides_raw'], $islides), JSON_UNESCAPED_UNICODE), $content['id']]);
        }
        if ($ifmt === 'video' && $p['script'] !== '') {
            $vb = video_brief($content);
            $vb['script'] = ['hook' => $p['hook'], 'body' => $p['script'], 'cta' => $p['cta'] ?: $vb['script']['cta'], 'shoot' => $p['shoot'] ?: $vb['script']['shoot']];
            db_run('UPDATE contents SET video_brief_json = ?, video_status = "script" WHERE id = ?', [json_encode($vb, JSON_UNESCAPED_UNICODE), $content['id']]);
            db_run('UPDATE campaign_ideas SET script_json = ? WHERE id = ?', [json_encode(['hook' => $p['hook'], 'body' => $p['script'], 'cta' => $vb['script']['cta'],
                'tags' => (string) $content['hashtags'], 'design' => $vb['script']['shoot']], JSON_UNESCAPED_UNICODE), $i['id']]);
        }
        save_content_version((int) $content['id'], $text, $p['tags'] ?: null, $p['cta'] ?: null, 'ai_edit', 'تحسين بعد التقييم');
        $__prev = ['prev' => (int) ($ev['score'] ?? 0), 'prev_text' => mb_substr((string) $content['generated_text'], 0, 3000)];
        if ($__ieval) {
            // البنود اللي اتحسبت بعد التعديل (الممنوعات اتشالت بالفعل فوق) — الدرجة الجديدة جاية مع الطلب
            db_run('UPDATE campaign_ideas SET eval_score = ?, eval_json = ?, approved = ? WHERE id = ?',
                [empty($__ieval['missing']) ? $__ieval['score'] : null, json_encode($__ieval + $__prev, JSON_UNESCAPED_UNICODE), $__ieval['pass'] ? 1 : 0, $i['id']]);
            content_set_flag((int) $content['id'], $uid, $__ieval['pass'] ? 'needs_design' : 'needs_review', $__ieval['pass'] ? null : mb_substr((string) $__ieval['issues'], 0, 250));
        } else {
            db_run('UPDATE campaign_ideas SET eval_score = NULL, eval_json = ?, approved = 0 WHERE id = ?', [json_encode($__prev, JSON_UNESCAPED_UNICODE), $i['id']]);
        }
        api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id']), 'balance' => credits_balance($uid)]);

    /* ══════════ تقييم الخطة كاملة (فوق مرحلة التقييم) ══════════ */
    case 'plan_evaluate':
        $brand = cf_gate($uid);
        $rows = db_all('SELECT i.*, c.generated_text, c.cta AS c_cta, c.format AS c_format FROM campaign_ideas i JOIN contents c ON c.id = i.content_id
                        WHERE i.campaign_id = ? AND i.user_id = ? AND i.selected = 1 ORDER BY i.sort_order, i.id', [$c['id'], $uid]);
        if (count($rows) < 2) api_fail('اكتب محتوى بوستين على الأقل الأول', 'no_content', 422);
        if (!rate_limit('camp_plan_eval', 'u' . $uid, 10, 600)) api_fail('وقفة قصيرة — جرّب بعد دقايق', 'rate_limit', 429);
        $cost = max(0, (int) get_setting('campaign_plan_eval_cost', '1'));
        cf_charge($uid, $cost, 'تقييم خطة الحملة «' . mb_substr($c['title'], 0, 60) . '»', 'campaign', (int) $c['id']);
        $setup = campaign_setup($c);
        $lines = [];
        foreach ($rows as $k => $r) {
            $b = bk_arr($r['brief_json'] ?? '');
            $lines[] = 'بوست ' . ($k + 1) . ' [' . content_format_label(content_format_key($r['c_format'] ?: 'post')) . ']'
                . (!empty($b['persona']) ? ' — لـ: ' . $b['persona'] : '') . (!empty($b['topic']) ? ' — موضوع: ' . $b['topic'] : '') . (!empty($b['service']) ? ' — خدمة: ' . $b['service'] : '')
                . "\n  العنوان: {$r['title']}\n  النص: " . mb_substr(preg_replace('/\s+/u', ' ', (string) $r['generated_text']), 0, 320) . "\n  CTA: " . (string) $r['c_cta'];
        }
        $data = campaign_brand_block($brand, 'ideas') . "\n\n"
            . 'الحملة: ' . $c['title'] . ' — الهدف: ' . (campaign_flow_bases()[$c['basis'] ?? ''] ?? 'تسويق عام') . "\n"
            . ($setup['set'] ? campaign_setup_plan($uid, $setup, count($rows), []) . "\n" : '')
            . "\nالبوستات بالترتيب:\n" . implode("\n", $lines);
        $planHead = prompt_full('campaign_plan_eval')
            ? prompt_fill('campaign_plan_eval', ['ready' => eval_ready_score(), 'brand_identity' => campaign_brand_block($brand, 'ideas'),
                'campaign_goal' => campaign_flow_bases()[$c['basis'] ?? ''] ?? 'تسويق عام',
                'plan' => 'الحملة: ' . $c['title'] . "\n" . ($setup['set'] ? campaign_setup_plan($uid, $setup, count($rows), []) . "\n" : '') . "\nالبوستات بالترتيب:\n" . implode("\n", $lines)],
                ['uid' => $uid, 'brand' => $brand, 'agent' => 'ideas'])
              . prompt_format_head() . 'score من 100 للخطة كلها · cats درجات من 100 · من 3 لـ 5 ملاحظات (post = رقم البوست لو الملاحظة عن بوست محدد، fix = التعديل المقترح بالظبط) · summary جملة واحدة بالعامية.'
            : prompt_fill('campaign_plan_eval', ['ready' => eval_ready_score(), 'data' => $data]);
        $ai = campaign_ai($planHead
            . "\n\nأعد JSON فقط:\n"
            . '{"score":82,"cats":{"التوازن":80,"عدم التكرار":85,"تغطية العملاء والخدمات":78,"التسلسل":84,"الـ CTA":80,"مناسبة الهدف":86},"summary":"","notes":[{"post":3,"issue":"","fix":""}]}',
            1400, 'campaign_eval', ['user_id' => $uid, 'reference_type' => 'campaign', 'reference_id' => (int) $c['id'], 'temperature' => 0.3, 'json_mode' => true]);
        $j = $ai['ok'] ? campaign_json((string) $ai['response']) : null;
        if (!$j || !isset($j['score'])) {
            cf_refund($uid, $cost, 'تقييم خطة الحملة', (int) $c['id']);
            api_fail($ai['ok'] ? 'التقييم مرجعش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التقييم'), 'ai_failed', 502);
        }
        $cats = [];
        foreach ((array) ($j['cats'] ?? []) as $k => $v) if (is_numeric($v)) $cats[mb_substr((string) $k, 0, 40)] = max(0, min(100, (int) $v));
        $notes = [];
        foreach (array_slice((array) ($j['notes'] ?? []), 0, 6) as $nt) {
            if (!is_array($nt) || trim((string) ($nt['issue'] ?? '')) === '') continue;
            $pn = (int) ($nt['post'] ?? 0);
            $notes[] = ['post' => $pn >= 1 && $pn <= count($rows) ? $pn : 0, 'idea_id' => $pn >= 1 && $pn <= count($rows) ? (int) $rows[$pn - 1]['id'] : 0,
                        'issue' => mb_substr(trim((string) $nt['issue']), 0, 300), 'fix' => mb_substr(trim((string) ($nt['fix'] ?? '')), 0, 300)];
        }
        $pe = ['score' => max(0, min(100, (int) $j['score'])), 'cats' => $cats, 'summary' => mb_substr(trim((string) ($j['summary'] ?? '')), 0, 300),
               'notes' => $notes, 'posts' => count($rows), 'at' => date('c')];
        $pr = campaign_prefs($c);
        $pr['plan_eval'] = $pe;
        db_run('UPDATE campaigns SET prefs_json = ? WHERE id = ?', [json_encode($pr, JSON_UNESCAPED_UNICODE), $c['id']]);
        api_ok(['plan_eval' => $pe, 'balance' => credits_balance($uid)]);

    case 'approve':
        // الجاهز (80+) + اللي العميل اختار يعتمده بنفسه (ids)
        $ids = array_filter(array_map('intval', (array) (api_input()['idea_ids'] ?? [])));
        db_run('UPDATE campaign_ideas SET approved = 1 WHERE campaign_id = ? AND user_id = ? AND selected = 1 AND content_id IS NOT NULL
                AND ((eval_score >= ? AND (eval_json IS NULL OR eval_json NOT LIKE \'%"mode":"self"%\')) OR approved = 1' . ($ids ? ' OR id IN (' . implode(',', $ids) . ')' : '') . ')', [$c['id'], $uid, eval_ready_score()]);
        // الطريقة الجديدة (mode = self): الجاهز = اللي عدّى حده ومن غير مخالفات (اتعلّم approved وقت الكتابة)
        foreach (db_all('SELECT content_id FROM campaign_ideas WHERE campaign_id = ? AND approved = 1 AND content_id IS NOT NULL', [$c['id']]) as $r) {
            content_set_flag((int) $r['content_id'], $uid, 'needs_design');
        }
        api_ok(['approved' => (int) (db_one('SELECT COUNT(*) n FROM campaign_ideas WHERE campaign_id = ? AND approved = 1', [$c['id']])['n'] ?? 0)]);

    case 'skip_eval':
        db_run('UPDATE campaigns SET eval_skipped = 1 WHERE id = ?', [$c['id']]);
        db_run('UPDATE campaign_ideas SET approved = 1 WHERE campaign_id = ? AND selected = 1 AND content_id IS NOT NULL', [$c['id']]);
        api_ok();

    /* ══════════ ⑤ الجدولة ══════════ */
    case 'plan_set':
        $i = cf_idea($c, $uid);
        $at = api_str('at', 20);
        $platform = in_array(api_str('platform', 20), ['facebook', 'instagram', 'both'], true) ? api_str('platform', 20) : 'facebook';
        if ($at === '') {
            db_run('UPDATE campaign_ideas SET plan_at = NULL WHERE id = ?', [$i['id']]);
            api_ok(['plan' => null]);
        }
        $ts = strtotime(str_replace('T', ' ', $at));
        if (!$ts || $ts < strtotime('today') || $ts > strtotime('+400 days')) api_fail('اختار ميعاد صالح (من النهارده لقدّام)', 'invalid', 422);
        db_run('UPDATE campaign_ideas SET plan_at = ?, plan_platform = ? WHERE id = ?', [date('Y-m-d H:i:00', $ts), $platform, $i['id']]);
        EventTracker::track('post_scheduled', $i['content_id'] ? 'content' : 'campaign_idea', (int) ($i['content_id'] ?: $i['id']), ['at' => date('Y-m-d H:i', $ts), 'platform' => $platform, 'source' => 'campaign'], $uid);
        api_ok(['plan' => ['at' => date('Y-m-d H:i', $ts), 'platform' => $platform]]);

    case 'auto_plan':
        $platform = in_array(api_str('platform', 20), ['facebook', 'instagram', 'both'], true) ? api_str('platform', 20) : 'facebook';
        $prefs = campaign_prefs($c);
        $prefs['platform'] = $platform;
        db_run('UPDATE campaigns SET prefs_json = ? WHERE id = ?', [json_encode($prefs, JSON_UNESCAPED_UNICODE), $c['id']]);
        // اللي اتسلّم للنشر خلاص مابيتحركش (ميعاده الحقيقي على فيسبوك)
        $rows = db_all('SELECT ci.*, c.publish_status, c.format AS cformat FROM campaign_ideas ci LEFT JOIN contents c ON c.id = ci.content_id
                        WHERE ci.campaign_id = ? AND ci.user_id = ? ORDER BY ci.sort_order, ci.id', [$c['id'], $uid]);
        $rows = array_values(array_filter($rows, fn($r) => !in_array((string) $r['publish_status'], ['published', 'scheduled', 'processing', 'pending'], true)));
        $n = campaign_auto_distribute($c, $rows, $platform, api_int('only_unplanned') === 1);
        api_ok(['planned' => $n, 'ideas' => campaign_board($c)]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
