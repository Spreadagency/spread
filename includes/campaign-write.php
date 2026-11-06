<?php
/**
 * Spread AI — كتابة فكرة من الحملة (كانت جوه public/api/campaign-flow.php → content_generate — اتنقلت هنا بالحرف)
 * علشان الصفحة وشغل الخلفية (خطة الحملات §4: camp_write) يمشوا على نفس المسار بالظبط:
 * الكتابة + التقييم في طلب واحد (§5) · حدود الحملة في الباقة (§2) · الكريدت والاسترداد.
 *
 *   campaign_write_idea($uid, $campaign, $idea, $o) ← ['ok' => true, 'content_id', 'eval', …] أو ['ok' => false, 'error', 'code', 'http', 'extra']
 *   $o: brand (لازم — بوابة الهوية عند اللي بينادي) · regen · format · slides · no_rate_limit
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/lifecycle.php';
require_once __DIR__ . '/campaign-flow.php';
require_once __DIR__ . '/brand-kit.php';
require_once __DIR__ . '/campaign-quality.php';
require_once __DIR__ . '/campaign-allowance.php';

/** overrides البريف من الفكرة: الخدمة (بالاسم) · الموضوع · الاتجاه · الشكل — إعادة الكتابة بتحافظ على اختيارات المرة اللي فاتت */
function cw_brief_overrides(int $uid, array $idea, string $fmt, ?array $old): array
{
    $ib = bk_arr($idea['brief_json'] ?? '');
    $o = ['format' => bk_platform_to_format($fmt), 'topic' => (string) (($ib['topic'] ?? '') ?: $idea['title'])];
    if (!empty($ib['pillar'])) $o['pillar'] = (string) $ib['pillar'];
    if (!empty($ib['service'])) {
        foreach (bk_services($uid, true) as $s) {
            if (mb_strtolower(trim($s['name'])) === mb_strtolower(trim($ib['service']))) { $o['service_id'] = (int) $s['id']; break; }
        }
    }
    // إعداد الحملة: الشخصيات اللي العميل اختارها (بالتناوب) — أو «من غير شخصيات»
    if (!empty($ib['character_ids'])) {
        $ids = array_values(array_map('intval', (array) $ib['character_ids']));
        $o['character_id'] = $ids[((int) ($idea['sort_order'] ?? 0)) % count($ids)];
    } elseif (!empty($ib['no_characters'])) {
        $o['character_id'] = 0;
    }
    if ($old) {
        $prev = bk_arr($old['brief_json'] ?? '');
        foreach (['service_id', 'method_id', 'pillar'] as $k) if (!isset($o[$k]) && !empty($prev[$k])) $o[$k] = $prev[$k];
        if (array_key_exists('character_id', $prev)) $o['character_id'] = (int) $prev['character_id'];
        $o = array_merge($o, array_diff_key(bk_arr($old['brand_overrides'] ?? ''), ['format' => 1]));
    }
    return $o;
}

/** نوع المنشور في المكتبة من زاوية الفكرة */
function cw_type(string $angle): string
{
    foreach ([['تعليم', 'educational'], ['تفاعل', 'engaging'], ['عرض', 'offer'], ['خصم', 'offer'], ['ترند', 'trend'],
              ['قصة', 'storytelling'], ['كواليس', 'storytelling'], ['وعي', 'awareness'], ['توعي', 'awareness']] as [$k, $v]) {
        if (str_contains($angle, $k)) return $v;
    }
    return 'marketing';
}

/** الشكل وعدد الشرائح المطلوبين (من الطلب أو الفكرة) */
function cw_format_req(array $i, string $f = '', int $slides = 0): array
{
    $f = isset(content_formats()[$f]) ? $f : campaign_idea_format($i);
    $n = $f === 'carousel' ? carousel_clamp($slides ?: (int) ($i['slides_count'] ?? 0)) : 0;
    return [$f, $n];
}

/** الفيديو اتبعت للتنفيذ؟ (السكريبت والبريف بيتقفلوا) */
function cw_video_locked(?array $content): bool
{
    return $content && in_array((string) ($content['video_status'] ?? ''), ['sent', 'in_production', 'ready', 'delivered'], true);
}

/** حقول الشكل في المحتوى من رد الـ AI */
function cw_format_fields(string $format, int $slides, array $p, ?array $old): array
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

function campaign_write_idea(int $uid, array $c, array $i, array $o = []): array
{
    $fail = fn(string $error, string $code, int $http, array $extra = []) => ['ok' => false, 'error' => $error, 'code' => $code, 'http' => $http, 'extra' => $extra];
    $brand = (array) ($o['brand'] ?? []);
    $regen = !empty($o['regen']);
    if ($i['content_id'] && !$regen) return ['ok' => true, 'already' => true, 'content_id' => (int) $i['content_id']];
    if (empty($o['no_rate_limit']) && !rate_limit('camp_content', 'u' . $uid, 45, 600)) return $fail('وقفة قصيرة — أنتجت محتوى كتير بسرعة، كمّل بعد دقايق', 'rate_limit', 429);

    $old = $i['content_id'] ? db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$i['content_id'], $uid]) : null;
    if ($old && in_array((string) $old['publish_status'], ['published', 'scheduled', 'processing', 'pending'], true)) {
        return $fail('المنشور ده اتسلّم للنشر خلاص — مش هنعيد كتابته', 'locked', 409);
    }
    // قبل أي خصم أو حصة — الفيديو المقفول مايتحسبش عليه
    if ($old && cw_video_locked($old)) return $fail('الفيديو ده اتبعت للتنفيذ — مش هنعيد كتابته', 'locked', 409);
    [$fmt, $slides] = cw_format_req($i, (string) ($o['format'] ?? ''), (int) ($o['slides'] ?? 0));
    // الحملة في الباقة: المنشورات والفيديوهات من حد الحملة · الإعادة من رصيد التعديلات · الكاروسيل لحد carousel_max_slides
    if ($fmt === 'carousel' && ($__mx = ca_max_slides($c)) > 0 && $slides > $__mx) $slides = $__mx;
    $__ca = ca_write_allow($c, (int) $i['id'], $fmt, $old ? (content_format_key((string) ($old['format'] ?? 'post')) === 'video' ? 'video' : 'post') : null);
    if (!$__ca['ok']) return $fail($__ca['error'], $__ca['code'] ?? 'campaign_limit', 402);
    // 8-ب: حصة الشهر — منشور جديد (أو سكريبت فيديو) بيتحسب؛ إعادة الكتابة لأ
    // تغيير الشكل بين فيديو ومنشور بينقل الحصة → بيتحسب على الوحدة الجديدة
    $__unitNew = $fmt === 'video' ? 'videos' : 'posts';
    $__unitOld = $old ? ((string) ($old['format'] ?? 'post') === 'video' ? 'videos' : 'posts') : null;
    if ($__ca['mode'] === 'off' && (!$old || $__unitOld !== $__unitNew) && ($__g = plan_gate($uid, $__unitNew))) return $fail($__g, 'quota', 402, ['upgrade' => plan_upgrade_link()]);
    $cost = $__ca['cost'] >= 0 ? (int) $__ca['cost'] : ($old ? cost_for('content_regeneration_cost') : cost_for_format($fmt));
    if ($cost > 0 && (credits_balance($uid) < $cost || !credits_consume($uid, $cost, ($old ? 'إعادة كتابة' : 'محتوى') . ' فكرة: ' . mb_substr($i['title'], 0, 60), 'campaign_idea', (int) $i['id']))) {
        ca_write_release($c, (int) $i['id'], $fmt, $__ca);
        return $fail(credits_short_msg($cost), 'credits', 402, ['balance' => credits_balance($uid)]);
    }

    $formatChanged = $old && ($old['format'] !== $fmt || ($fmt === 'carousel' && (int) $old['slides_count'] !== $slides));
    $extra = $old && !$formatChanged ? "اكتب نسخة جديدة مختلفة عن اللي فات (Hook مختلف وزاوية طرح مختلفة)." : '';
    $dur = $fmt === 'video' && $old ? video_brief($old)['duration'] : '30 ثانية';
    // Brand Kit: بريف البوست من الفكرة (خدمة · موضوع · اتجاه) + شكلها — والباقي من الهوية
    $bkO = cw_brief_overrides($uid, $i, $fmt, $old);
    $bkBrief = bk_enabled() ? BrandContext::brief($uid, $bkO) : null;
    $__tok = $fmt === 'carousel' ? 2600 : 1800;
    $__aiCtx = ['user_id' => $uid, 'reference_type' => 'campaign_idea', 'reference_id' => (int) $i['id']];
    $__parse = function (string $r) use ($uid, $fmt): ?array {
        $p = campaign_parse_content(BrandContext::stripWhy($r));
        foreach (['hook', 'body', 'cta', 'script', 'tags'] as $__k) $p[$__k] = BrandContext::enforce((string) $p[$__k], $uid);   // الممنوعات دايمًا
        if ($fmt === 'video' && $p['script'] === '') return null;          // فيديو من غير سكريبت = رد ناقص
        return ($p['body'] === '' && $p['hook'] === '') ? null : $p;
    };
    $__eval = null;
    if (campaign_write_mode() === 'single') {
        // خطة الحملات §5: الكتابة + التقييم في طلب واحد + فحص بالكود + تحسين تلقائي مرة تحت الحد (مش بيتخصم)
        $__w = campaign_write_with_eval(
            fn(string $x) => campaign_content_prompt($brand, $c, $i, trim($extra . ($x !== '' ? "\n\n" . $x : '')), $fmt, $slides, $dur, $bkBrief),
            fn(string $pr, bool $imp) => campaign_ai($pr, $__tok, $imp ? 'campaign_improve' : 'campaign_content', $__aiCtx + ($imp ? ['temperature' => 0.7] : [])),
            $__parse,
            ['fmt' => $fmt, 'slides' => $slides, 'brand' => $brand, 'skill' => campaign_skill($brand, $c), 'uid' => $uid, 'except' => $old ? (int) $old['id'] : 0]);
        $ai = $__w['ai'];
        $p = $__w['p'];
        $__eval = $__w['eval'];
    } else {
        $ai = campaign_ai(campaign_content_prompt($brand, $c, $i, $extra, $fmt, $slides, $dur, $bkBrief), $__tok, 'campaign_content', $__aiCtx);
        $p = $ai['ok'] ? $__parse((string) $ai['response']) : null;
    }
    if (!$p) {
        if ($cost > 0) credits_add($uid, $cost, 'استرداد: محتوى فكرة #' . $i['id'], null, 'refund', (int) $i['id']);
        ca_write_release($c, (int) $i['id'], $fmt, $__ca);
        return $fail(!empty($ai['ok']) ? 'الرد مكانش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التوليد'), 'ai_failed', 502);
    }
    $text = campaign_compose_text($p['hook'], $p['body']);
    $ff = cw_format_fields($fmt, $slides, $p, $old);
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
            'user_id' => $uid, 'brand_profile_id' => (int) ($brand['id'] ?? 0), 'content_type' => cw_type((string) $i['angle']),
            'platform' => 'both', 'length' => 'medium', 'tone' => $brand['tone'] ?? null,
            'extra_notes' => 'من حملة «' . mb_substr($c['title'], 0, 100) . '» — فكرة: ' . mb_substr($i['title'], 0, 150),
            'generated_text' => $text, 'hashtags' => $p['tags'] ?: null, 'cta' => $p['cta'] ?: null,
            'design_direction' => $p['design'] ?: null, 'image_prompt' => $p['image_prompt'] ?: null,
            'status' => 'generated', 'credits_used' => $cost, 'campaign_id' => (int) $c['id'],
        ] + $ff + ($fmt === 'video' ? ['video_status_at' => date('Y-m-d H:i:s')] : []));
        save_content_version($cid, $text, $p['tags'] ?: null, $p['cta'] ?: null, 'ai', 'من الحملة');
    }
    if ($bkBrief) BrandContext::attach($cid, $uid, $bkBrief, BrandContext::parseWhy((string) $ai['response']), $bkO);
    // السكريبت على الفكرة (للتصدير والأرقام) — للفيديو بس
    $script = ['hook' => $p['hook'], 'body' => $p['script'], 'cta' => $p['cta'], 'tags' => $p['tags'], 'design' => $p['shoot']];
    // محتوى جديد = تقييم قديم مايبقاش صالح (الطريقة الجديدة: التقييم الجديد جاي مع الكتابة)
    db_run('UPDATE campaign_ideas SET content_id = ?, script_json = ?, format = ?, slides_count = ?, selected = 1, eval_score = ?, eval_json = ?, approved = ? WHERE id = ?',
        [$cid, $fmt === 'video' ? json_encode($script, JSON_UNESCAPED_UNICODE) : null, $fmt, $slides ?: null,
         $__eval && empty($__eval['missing']) ? $__eval['score'] : null, $__eval ? json_encode($__eval, JSON_UNESCAPED_UNICODE) : null,
         $__eval && $__eval['pass'] ? 1 : 0, $i['id']]);
    if ($__eval) content_set_flag($cid, $uid, $__eval['pass'] ? 'needs_design' : 'needs_review', $__eval['pass'] ? null : mb_substr((string) $__eval['issues'], 0, 250));
    if (function_exists('ai_log_usage')) ai_log_usage($uid, 'generate', $ai['model'] ?? 'router', $ai['usage'] ?? [], $cid);
    ai_stamp('contents', $cid, 'campaign_content', $ai);
    return ['ok' => true, 'content_id' => $cid, 'eval' => $__eval, 'cost' => $cost, 'mode' => $__ca['mode']];
}

/**
 * دفعة أفكار (≤ 5) للحملة — كانت جوه api/campaign-flow.php → ideas_generate (اتنقلت بالحرف)
 * الصفحة بتناديها دفعة دفعة · الوكيل بيناديها لحد العدد المطلوب. الخصم والمسودة عند اللي بينادي.
 * @return array{ok: bool, n: int, error?: string}
 */
function cw_ideas_batch(int $uid, array $c, array $brand, int $n, int $batch, array $seq, array $setup, array $rejected = []): array
{
    $avoid = array_merge(array_column(db_all('SELECT title FROM campaign_ideas WHERE campaign_id = ? ORDER BY id DESC LIMIT 40', [$c['id']]), 'title'), $rejected);
    $fmtSlice = $seq ? array_slice($seq, $batch * CAMP_IDEAS_BATCH, $n) : [];
    $plan = $seq ? campaign_setup_plan($uid, $setup, $n, $fmtSlice) : '';
    // التوكنز على قد الأفكار (العربي + بيانات الإعداد بتطوّل الرد — والموديلات اللي بتفكّر بتصرف من نفس الحد)
    $tok = campaign_ideas_tokens($n);
    $ai = campaign_ai(campaign_ideas_prompt($brand, $c, $n, $avoid, $plan), $tok, 'campaign_ideas',
        ['user_id' => $uid, 'reference_id' => (int) $c['id'], 'temperature' => 0.95, 'json_mode' => true]);
    $ideas = $ai['ok'] ? campaign_parse_ideas((string) $ai['response']) : [];
    // الرد اتقطع أو جاب أقل من المطلوب ← نكمّل الناقص بطلب واحد (من غير ما نكرر اللي طلع)
    if ($ideas && count($ideas) < $n) {
        $miss = $n - count($ideas);
        $avoid2 = array_merge(array_column($ideas, 'title'), $avoid);
        $plan2 = $seq ? campaign_setup_plan($uid, $setup, $miss, array_slice($fmtSlice, count($ideas))) : '';
        $ai2 = campaign_ai(campaign_ideas_prompt($brand, $c, $miss, $avoid2, $plan2), campaign_ideas_tokens($miss), 'campaign_ideas',
            ['user_id' => $uid, 'reference_id' => (int) $c['id'], 'temperature' => 0.95, 'json_mode' => true]);
        if ($ai2['ok']) $ideas = array_merge($ideas, campaign_parse_ideas((string) $ai2['response']));
    }
    if (!$ideas) return ['ok' => false, 'error' => $ai['ok'] ? 'الرد مكانش مظبوط — جرّب تاني' : ($ai['error'] ?? 'تعذّر التوليد'), 'n' => 0];
    $order = (int) (db_one('SELECT COALESCE(MAX(sort_order), 0) m FROM campaign_ideas WHERE campaign_id = ?', [$c['id']])['m'] ?? 0);
    foreach (array_slice($ideas, 0, $n) as $k => $i) {
        $gk = $batch * CAMP_IDEAS_BATCH + $k;
        $fmt = $fmtSlice[$k] ?? null;
        $brief = $seq ? campaign_setup_assign($uid, $setup, $i, $gk) : $i['brief'];
        db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, sort_order, angle, title, description, audience, hook, formats, selected, brief_json, format, slides_count)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$c['id'], $uid, ++$order, $i['angle'], $i['title'], $i['desc'], $i['audience'], $i['hook'], implode('|', $i['formats']),
             // مع إعداد الحملة كل الأفكار مختارة (العميل حدد العدد) — غير كده أول 4 (العميل يغيّر براحته)
             $seq ? 1 : (($batch === 0 && $k < 4) ? 1 : 0), $brief ? bk_json($brief) : null,
             $fmt, $fmt === 'carousel' ? carousel_limits()[2] : null]);
    }
    return ['ok' => true, 'n' => min($n, count($ideas))];
}
