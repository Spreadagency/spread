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
require_once __DIR__ . '/../../includes/brand-brain.php';

$user = api_boot(true, true);   // بيكتب في الجلسة
$uid = (int) $user['id'];
$action = api_action() ?: 'board';

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

/** نوع المنشور في المكتبة من زاوية الفكرة */
function cf_type(string $angle): string
{
    foreach ([['تعليم', 'educational'], ['تفاعل', 'engaging'], ['عرض', 'offer'], ['خصم', 'offer'], ['ترند', 'trend'],
              ['قصة', 'storytelling'], ['كواليس', 'storytelling'], ['وعي', 'awareness'], ['توعي', 'awareness']] as [$k, $v]) {
        if (str_contains($angle, $k)) return $v;
    }
    return 'marketing';
}

/** الشكل وعدد الشرائح المطلوبين (من الطلب أو الفكرة) */
function cf_format_req(array $i): array
{
    $f = api_str('format', 12);
    $f = isset(content_formats()[$f]) ? $f : campaign_idea_format($i);
    $n = $f === 'carousel' ? carousel_clamp(api_int('slides') ?: (int) ($i['slides_count'] ?? 0)) : 0;
    return [$f, $n];
}

/** الفيديو اتبعت للتنفيذ؟ (السكريبت والبريف بيتقفلوا) */
function cf_video_locked(?array $content): bool
{
    return $content && in_array((string) ($content['video_status'] ?? ''), ['sent', 'in_production', 'ready', 'delivered'], true);
}

/** حقول الشكل في المحتوى من رد الـ AI */
function cf_format_fields(string $format, int $slides, array $p, ?array $old): array
{
    $f = ['format' => $format, 'slides_count' => $format === 'carousel' ? $slides : null,
          'slides_json' => $format === 'carousel' ? json_encode(campaign_parse_slides($p['slides_raw'], $slides), JSON_UNESCAPED_UNICODE) : null];
    if ($format === 'video') {
        $b = $old && content_format_key($old['format'] ?? '') === 'video' ? video_brief($old) : video_brief([]);
        $b['script'] = ['hook' => $p['hook'], 'body' => $p['script'], 'cta' => $p['cta'], 'shoot' => $p['shoot']];
        $f['video_brief_json'] = json_encode($b, JSON_UNESCAPED_UNICODE);
        $f['video_status'] = 'script';
    } else {
        $f['video_brief_json'] = null;
        $f['video_status'] = null;
    }
    return $f;
}

switch ($action) {

    /* ══════════ اللوحة ══════════ */
    case 'board':
        api_ok(campaign_board_payload($c, $uid));

    /* ══════════ ① الأفكار ══════════ */
    case 'ideas_generate':
        $brand = brand_for_user($uid) ?: [];
        if (empty($brand['business_name'])) api_fail('اكتب اسم نشاطك في Brand Brain الأول', 'no_brand', 422);
        $total = api_int('total', 10, 3, CAMP_MAX_IDEAS);
        $batch = api_int('batch', 0, 0, 10);
        $key = 'camp_gen_' . $c['id'];
        $batches = (int) ceil($total / CAMP_IDEAS_BATCH);

        if ($batch === 0) {
            if (!rate_limit('camp_ideas', 'u' . $uid, 6, 600)) api_fail('ولّدت أفكار كتير بسرعة — استنى شوية', 'rate_limit', 429);
            $cost = idea_credits_for($total);
            cf_charge($uid, $cost, 'أفكار حملة «' . mb_substr($c['title'], 0, 60) . '» (' . $total . ')', 'campaign', (int) $c['id']);
            // جيل جديد: بنمسح المقترح بس — المختار وأي فكرة ليها محتوى بيفضلوا
            db_run('DELETE FROM campaign_ideas WHERE campaign_id = ? AND selected = 0 AND content_id IS NULL', [$c['id']]);
            $_SESSION[$key] = ['total' => $total, 'next' => 0, 'cost' => $cost, 'at' => time()];
        }
        $g = $_SESSION[$key] ?? null;
        // الدفعات بعد الأولى مجانية بس لنفس الجيل (مدفوع في الدفعة 0) وبالترتيب
        if (!$g || $g['total'] !== $total || $batch !== (int) $g['next'] || time() - (int) $g['at'] > 1800 || $batch >= $batches) {
            api_fail('ابدأ توليد الأفكار من الأول', 'state', 409);
        }

        $n = min(CAMP_IDEAS_BATCH, $total - $batch * CAMP_IDEAS_BATCH);
        $avoid = array_column(db_all('SELECT title FROM campaign_ideas WHERE campaign_id = ? ORDER BY id DESC LIMIT 40', [$c['id']]), 'title');
        $ai = campaign_ai(campaign_ideas_prompt($brand, $c, $n, $avoid), 1400, 'campaign_ideas',
            ['user_id' => $uid, 'reference_id' => (int) $c['id'], 'temperature' => 0.95, 'json_mode' => true]);
        $ideas = $ai['ok'] ? campaign_parse_ideas((string) $ai['response']) : [];
        if (!$ideas) {
            // الدفعة الأولى فشلت = مفيش ولا فكرة ← نرجّع الكريدت كله · بعدها العميل يقدر يكمّل الباقي مجانًا
            if ($batch === 0) {
                cf_refund($uid, (int) $g['cost'], 'أفكار حملة #' . $c['id'], (int) $c['id']);
                unset($_SESSION[$key]);
            }
            api_fail($ai['ok'] ? 'الرد مكانش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التوليد'), 'ai_failed', 502, ['refunded' => $batch === 0]);
        }
        $order = (int) (db_one('SELECT COALESCE(MAX(sort_order), 0) m FROM campaign_ideas WHERE campaign_id = ?', [$c['id']])['m'] ?? 0);
        foreach (array_slice($ideas, 0, $n) as $k => $i) {
            db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, sort_order, angle, title, description, audience, hook, formats, selected)
                       VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$c['id'], $uid, ++$order, $i['angle'], $i['title'], $i['desc'], $i['audience'], $i['hook'], implode('|', $i['formats']),
                 // أول 4 بتتختار تلقائيًا (زي التصميم) — العميل يغيّر براحته
                 ($batch === 0 && $k < 4) ? 1 : 0]);
        }
        $_SESSION[$key]['next'] = $batch + 1;
        if ($batch + 1 >= $batches) unset($_SESSION[$key]);
        db_run('UPDATE campaigns SET ideas_count = ?, revision = revision + 1 WHERE id = ?', [min($total, 255), $c['id']]);
        api_ok(['ideas' => campaign_board($c), 'done' => $batch + 1 >= $batches, 'balance' => credits_balance($uid)]);

    case 'idea_toggle':
        $i = cf_idea($c, $uid);
        db_run('UPDATE campaign_ideas SET selected = ? WHERE id = ?', [api_int('selected') ? 1 : 0, $i['id']]);
        api_ok(['selected' => (bool) api_int('selected')]);

    case 'idea_select_all':
        db_run('UPDATE campaign_ideas SET selected = ? WHERE campaign_id = ? AND user_id = ?', [api_int('selected') ? 1 : 0, $c['id'], $uid]);
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
        // المنشور نفسه بيفضل في المكتبة — بيتفك من الحملة بس
        if ($i['content_id']) db_run('UPDATE contents SET campaign_id = NULL WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]);
        db_run('DELETE FROM campaign_ideas WHERE id = ?', [$i['id']]);
        api_ok();

    /* ══════════ ② المحتوى ══════════ */
    case 'content_generate':
        $brand = cf_gate($uid);
        $i = cf_idea($c, $uid);
        $regen = api_int('regen') === 1;
        if ($i['content_id'] && !$regen) api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id']), 'already' => true]);
        if (!rate_limit('camp_content', 'u' . $uid, 45, 600)) api_fail('وقفة قصيرة — أنتجت محتوى كتير بسرعة، كمّل بعد دقايق', 'rate_limit', 429);

        $old = $i['content_id'] ? db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]) : null;
        if ($old && in_array((string) $old['publish_status'], ['published', 'scheduled', 'processing', 'pending'], true)) {
            api_fail('المنشور ده اتسلّم للنشر خلاص — مش هنعيد كتابته', 'locked', 409);
        }
        [$fmt, $slides] = cf_format_req($i);
        // 8-ب: حصة الشهر — منشور جديد (أو سكريبت فيديو) بيتحسب؛ إعادة الكتابة لأ
        // تغيير الشكل بين فيديو ومنشور بينقل الحصة → بيتحسب على الوحدة الجديدة
        $__unitNew = $fmt === 'video' ? 'videos' : 'posts';
        $__unitOld = $old ? ((string) ($old['format'] ?? 'post') === 'video' ? 'videos' : 'posts') : null;
        if ((!$old || $__unitOld !== $__unitNew) && ($__g = plan_gate($uid, $__unitNew))) api_fail($__g, 'quota', 402, ['upgrade' => plan_upgrade_link()]);
        $cost = $old ? cost_for('content_regeneration_cost') : cost_for('content_generation_cost');
        cf_charge($uid, $cost, ($old ? 'إعادة كتابة' : 'محتوى') . ' فكرة: ' . mb_substr($i['title'], 0, 60), 'campaign_idea', (int) $i['id']);

        $formatChanged = $old && ($old['format'] !== $fmt || ($fmt === 'carousel' && (int) $old['slides_count'] !== $slides));
        if ($old && cf_video_locked($old)) api_fail('الفيديو ده اتبعت للتنفيذ — مش هنعيد كتابته', 'locked', 409);
        $extra = $old && !$formatChanged ? "اكتب نسخة جديدة مختلفة عن اللي فات (Hook مختلف وزاوية طرح مختلفة)." : '';
        $dur = $fmt === 'video' && $old ? video_brief($old)['duration'] : '30 ثانية';
        $ai = campaign_ai(campaign_content_prompt($brand, $c, $i, $extra, $fmt, $slides, $dur), $fmt === 'carousel' ? 2600 : 1800, 'campaign_content',
            ['user_id' => $uid, 'reference_type' => 'campaign_idea', 'reference_id' => (int) $i['id']]);
        $p = $ai['ok'] ? campaign_parse_content((string) $ai['response']) : null;
        if ($p && $fmt === 'video' && $p['script'] === '') $p = null;          // فيديو من غير سكريبت = رد ناقص
        if (!$p || ($p['body'] === '' && $p['hook'] === '')) {
            cf_refund($uid, $cost, 'محتوى فكرة #' . $i['id'], (int) $i['id']);
            api_fail($ai['ok'] ? 'الرد مكانش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التوليد'), 'ai_failed', 502);
        }
        $text = campaign_compose_text($p['hook'], $p['body']);
        $ff = cf_format_fields($fmt, $slides, $p, $old);
        if ($old) {
            db_run('UPDATE contents SET generated_text = ?, hashtags = ?, cta = ?, design_direction = ?, image_prompt = ?, status = "generated",
                    workflow_flag = "none", format = ?, slides_count = ?, slides_json = ?, video_brief_json = ?, video_status = ?,
                    video_status_at = IF(? IS NULL, video_status_at, NOW()) WHERE id = ?',
                [$text, $p['tags'] ?: null, $p['cta'] ?: null, $p['design'] ?: null, $p['image_prompt'] ?: null,
                 $ff['format'], $ff['slides_count'], $ff['slides_json'], $ff['video_brief_json'], $ff['video_status'], $ff['video_status'], $old['id']]);
            save_content_version((int) $old['id'], $text, $p['tags'] ?: null, $p['cta'] ?: null, 'ai', 'إعادة كتابة من الحملة');
            $cid = (int) $old['id'];
        } else {
            $cid = db_insert_row('contents', [
                'user_id' => $uid, 'brand_profile_id' => (int) ($brand['id'] ?? 0), 'content_type' => cf_type((string) $i['angle']),
                'platform' => 'both', 'length' => 'medium', 'tone' => $brand['tone'] ?? null,
                'extra_notes' => 'من حملة «' . mb_substr($c['title'], 0, 100) . '» — فكرة: ' . mb_substr($i['title'], 0, 150),
                'generated_text' => $text, 'hashtags' => $p['tags'] ?: null, 'cta' => $p['cta'] ?: null,
                'design_direction' => $p['design'] ?: null, 'image_prompt' => $p['image_prompt'] ?: null,
                'status' => 'generated', 'credits_used' => $cost, 'campaign_id' => (int) $c['id'],
            ] + $ff + ($fmt === 'video' ? ['video_status_at' => date('Y-m-d H:i:s')] : []));
            save_content_version($cid, $text, $p['tags'] ?: null, $p['cta'] ?: null, 'ai', 'من الحملة');
        }
        // السكريبت على الفكرة (للتصدير والأرقام) — للفيديو بس
        $script = ['hook' => $p['hook'], 'body' => $p['script'], 'cta' => $p['cta'], 'tags' => $p['tags'], 'design' => $p['shoot']];
        // محتوى جديد = تقييم قديم مايبقاش صالح
        db_run('UPDATE campaign_ideas SET content_id = ?, script_json = ?, format = ?, slides_count = ?, selected = 1, eval_score = NULL, eval_json = NULL, approved = 0 WHERE id = ?',
            [$cid, $fmt === 'video' ? json_encode($script, JSON_UNESCAPED_UNICODE) : null, $fmt, $slides ?: null, $i['id']]);
        if (function_exists('ai_log_usage')) ai_log_usage($uid, 'generate', $ai['model'] ?? 'router', $ai['usage'] ?? [], $cid);
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
        db_run('UPDATE campaign_ideas SET eval_score = ?, eval_json = ?, approved = ? WHERE id = ?',
            [$e['score'], json_encode($e, JSON_UNESCAPED_UNICODE), $e['score'] >= CAMP_EVAL_READY ? 1 : 0, $i['id']]);
        content_set_flag((int) $content['id'], $uid, $e['score'] >= CAMP_EVAL_READY ? 'needs_design' : 'needs_review',
            $e['score'] >= CAMP_EVAL_READY ? null : mb_substr($e['issues'], 0, 250));
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
        cf_charge($uid, $cost, 'تحسين منشور #' . $content['id'], 'campaign_idea', (int) $i['id']);
        $extra = "ده تحسين لمنشور موجود. النسخة الحالية:\n" . mb_substr((string) $content['generated_text'], 0, 2500)
            . "\nCTA الحالي: " . (string) ($content['cta'] ?? '')
            . "\n\nملاحظات المراجع (لازم تتعالج كلها): " . (($ev['issues'] ?? '') ?: 'قوّي الـ Hook والـ CTA ووضّح الرسالة')
            . "\nحافظ على الفكرة والمعلومات — حسّن الصياغة والـ Hook والـ CTA بس.";
        if (cf_video_locked($content)) api_fail('الفيديو اتبعت للتنفيذ', 'locked', 409);
        $ifmt = content_format_key($content['format'] ?? 'post');
        $islides = (int) ($content['slides_count'] ?? 0);
        if ($ifmt === 'carousel') {
            $extra .= "\nالشرائح الحالية:\n";
            foreach (content_slides($content) as $sl) $extra .= 'شريحة ' . $sl['n'] . ' | ' . $sl['title'] . ' | ' . $sl['text'] . "\n";
        }
        if ($ifmt === 'video') $extra .= "\nالسكريبت الحالي:\n" . mb_substr(video_script_text(video_brief($content)), 0, 2000);
        $ai = campaign_ai(campaign_content_prompt($brand, $c, $i, $extra, $ifmt, $islides, video_brief($content)['duration']), $ifmt === 'carousel' ? 2600 : 1800, 'campaign_improve',
            ['user_id' => $uid, 'reference_type' => 'campaign_idea', 'reference_id' => (int) $i['id'], 'temperature' => 0.7]);
        $p = $ai['ok'] ? campaign_parse_content((string) $ai['response']) : null;
        if (!$p || ($p['body'] === '' && $p['hook'] === '')) {
            cf_refund($uid, $cost, 'تحسين منشور #' . $content['id'], (int) $i['id']);
            api_fail($ai['ok'] ? 'الرد مكانش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التحسين'), 'ai_failed', 502);
        }
        $text = campaign_compose_text($p['hook'], $p['body']);
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
        db_run('UPDATE campaign_ideas SET eval_score = NULL, eval_json = ?, approved = 0 WHERE id = ?',
            [json_encode(['prev' => (int) ($ev['score'] ?? 0)], JSON_UNESCAPED_UNICODE), $i['id']]);
        api_ok(['idea' => cf_idea_out($c, $uid, (int) $i['id']), 'balance' => credits_balance($uid)]);

    case 'approve':
        // الجاهز (80+) + اللي العميل اختار يعتمده بنفسه (ids)
        $ids = array_filter(array_map('intval', (array) (api_input()['idea_ids'] ?? [])));
        db_run('UPDATE campaign_ideas SET approved = 1 WHERE campaign_id = ? AND user_id = ? AND selected = 1 AND content_id IS NOT NULL
                AND (eval_score >= ?' . ($ids ? ' OR id IN (' . implode(',', $ids) . ')' : '') . ')', [$c['id'], $uid, CAMP_EVAL_READY]);
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
