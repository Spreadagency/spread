<?php
/**
 * Spread AI — محرك المحتوى v3 · قطع المحرك في مكتبة المحتوى والتقويم
 *
 * المحرك هو الأصل، والمكتبة نسخة منه بتتحدّث لوحدها:
 *   حملة المحرك      ← حملة في «حملاتي» (campaigns) واقفة على مرحلة «الجدولة»
 *   كل قطعة خلصت    ← منشور عادي في contents + فكرته في campaign_ideas (ميعادها في plan_at = تاريخ الخانة)
 *   كل تصميم نهائي  ← صف في content_designs (من غير كريدت ومن غير مايتحسب على الحصة تاني)
 *
 * لما المحرك يعيد كتابة قطعة أو تصميم ← النسخة بتتحدّث، إلا لو العميل عدّل النص في المكتبة (status = edited)
 * أو المنشور اتسلّم للنشر. والنشر من المحرك بياخد نص المكتبة لو العميل عدّله هناك.
 * النشر من مكان واحد بس: لو القطعة بتتنشر من المحرك، المكتبة بترفض تنشرها، والعكس.
 */
const CE_LIB_TYPES = ['education' => 'educational', 'proof' => 'marketing', 'story' => 'storytelling', 'offer' => 'offer', 'behind' => 'engaging'];
const CE_LIB_GOALS = ['new_product' => 'launch', 'offer' => 'offer', 'sales' => 'bookings', 'awareness' => 'awareness', 'seasonal' => 'offer', 'educational' => 'awareness', 'trust' => 'trust', 'engagement' => 'engagement', 'leads' => 'bookings'];
const CE_LIB_LOCKED = ['published', 'scheduled', 'processing', 'pending'];

function ce_lib_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT lib_content_id FROM ce_contents LIMIT 1');
        db_one('SELECT lib_campaign_id FROM ce_campaigns LIMIT 1');
        db_one('SELECT lib_design_id FROM ce_designs LIMIT 1');
        db_one('SELECT id FROM campaign_ideas LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** نص المنشور زي ما بيتكتب في المكتبة: الـ Hook + النص (البوست) · الكابشن (الكاروسيل/الريلز) */
function ce_lib_text(array $body, string $format): string
{
    if ($format === 'post') {
        $hook = trim((string) (((array) ($body['hooks'] ?? []))[0] ?? ''));
        $text = trim((string) ($body['body'] ?? ''));
        return $hook !== '' && !str_contains($text, $hook) ? $hook . "\n\n" . $text : $text;
    }
    $cap = trim((string) ($body['caption'] ?? ''));
    if ($cap === '' && $format === 'carousel') $cap = trim((string) ($body['slides'][0]['text'] ?? ''));
    if ($cap === '' && $format === 'reel') $cap = trim((string) ($body['scenes'][0]['voiceover'] ?? ''));
    return $cap;
}

function ce_lib_hashtags(array $body): ?string
{
    $t = implode(' ', array_map(fn($h) => '#' . ltrim(preg_replace('/\s+/u', '_', trim((string) $h)), '#'), array_filter((array) ($body['hashtags'] ?? []), fn($h) => is_scalar($h) && trim((string) $h) !== '')));
    return $t !== '' ? mb_substr($t, 0, 1000) : null;
}

/** الحملة في «حملاتي» (بتتعمل أول مرة وبعدين بيتحدّث عنوانها بس) */
function ce_lib_campaign(array $c): ?int
{
    $c['lib_campaign_id'] = db_one('SELECT lib_campaign_id FROM ce_campaigns WHERE id = ?', [(int) $c['id']])['lib_campaign_id'] ?? null;   // ممكن تكون اتعملت في نفس المزامنة
    if (!empty($c['lib_campaign_id']) && db_one('SELECT id FROM campaigns WHERE id = ? AND user_id = ?', [(int) $c['lib_campaign_id'], (int) $c['user_id']])) {
        db_run('UPDATE campaigns SET title = ? WHERE id = ?', [mb_substr((string) $c['title'], 0, 150), (int) $c['lib_campaign_id']]);
        return (int) $c['lib_campaign_id'];
    }
    $b = json_decode((string) ($c['brief_json'] ?? ''), true) ?: [];
    $month = !empty($c['start_date']) ? substr((string) $c['start_date'], 0, 7) : date('Y-m');
    $id = (int) db_insert('INSERT INTO campaigns (user_id, title, goal, basis, topic, ideas_count, notes, period_month, stage, max_stage, status, eval_skipped)
                           VALUES (?,?,?,?,?,?,?,?,5,6,"active",1)', [
        (int) $c['user_id'], mb_substr((string) $c['title'], 0, 150), CE_LIB_GOALS[$b['goal'] ?? ''] ?? 'awareness', 'engine',
        mb_substr((string) ($b['offer']['title'] ?? $c['title']), 0, 300), 0, 'من محرك المحتوى ✨ — النص والتصميم بيتحدّثوا لوحدهم من المحرك', $month,
    ]);
    db_run('UPDATE ce_campaigns SET lib_campaign_id = ? WHERE id = ?', [$id, (int) $c['id']]);
    return $id;
}

/**
 * نسخ قطعة واحدة (خلصت) للمكتبة + تصميماتها
 * @return int|null رقم المنشور في المكتبة
 */
function ce_lib_sync_piece(array $c, array $x): ?int
{
    if (!in_array($x['status'], ['pass', 'manual'], true) || (int) ($x['lib_content_id'] ?? 0) < 0) return null;
    $slot = db_one('SELECT * FROM ce_slots WHERE id = ?', [(int) $x['slot_id']]);
    if (!$slot) return null;
    $uid = (int) $c['user_id'];
    $libCamp = ce_lib_campaign($c);
    $body = json_decode((string) $x['body_json'], true) ?: [];
    $fmt = (string) $x['format'];
    $text = ce_lib_text($body, $fmt);
    $tags = ce_lib_hashtags($body);
    $cta = trim((string) ($body['cta'] ?? '')) ?: null;
    $libFmt = $fmt === 'reel' ? 'video' : $fmt;
    $slides = $fmt === 'carousel' ? array_map(fn($s) => ['title' => mb_substr(trim((string) ($s['title'] ?? '')), 0, 200), 'text' => mb_substr(trim((string) ($s['text'] ?? '')), 0, 600), 'design' => ''], array_values((array) ($body['slides'] ?? []))) : [];
    $video = null;
    if ($fmt === 'reel') {
        $sc = array_values((array) ($body['scenes'] ?? []));
        $video = json_encode(['type' => 'ريلز', 'duration' => '30 ثانية', 'platform' => 'إنستجرام + فيسبوك', 'ratio' => '9:16', 'notes' => 'من محرك المحتوى',
            'script' => ['hook' => (string) ($sc[0]['voiceover'] ?? $sc[0]['on_screen_text'] ?? ''),
                         'body' => implode("\n", array_map(fn($s) => trim(($s['time'] ?? '') . ' — ' . ($s['voiceover'] ?? '') . (!empty($s['on_screen_text']) ? ' [' . $s['on_screen_text'] . ']' : '')), array_slice($sc, 1))),
                         'cta' => (string) ($body['cta'] ?? ''), 'shoot' => implode("\n", array_map(fn($s) => trim(($s['time'] ?? '') . ' — ' . ($s['visual'] ?? '')), $sc))]], JSON_UNESCAPED_UNICODE);
    }
    $direction = trim((string) (($body['design_brief']['focal_subject'] ?? '') . ' — ' . ($body['design_brief']['mood'] ?? '')), ' —') ?: null;

    $cur = !empty($x['lib_content_id']) ? db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [(int) $x['lib_content_id'], $uid]) : null;
    if (!empty($x['lib_content_id']) && !$cur) {
        // العميل مسح المنشور من المكتبة ← مانرجّعوش تاني
        db_run('UPDATE ce_contents SET lib_content_id = -1 WHERE id = ?', [(int) $x['id']]);
        return null;
    }
    if (!$cur) {
        $bp = db_one('SELECT id FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]);
        if (!$bp) return null;
        $cols = ['user_id' => $uid, 'brand_profile_id' => (int) $bp['id'], 'content_type' => CE_LIB_TYPES[$slot['pillar']] ?? 'marketing', 'platform' => 'both',
                 'generated_text' => $text, 'hashtags' => $tags, 'cta' => $cta, 'status' => 'generated', 'credits_used' => 0, 'model' => 'content-engine',
                 'campaign_id' => $libCamp, 'design_direction' => $direction, 'format' => $libFmt, 'slides_count' => $slides ? count($slides) : 0,
                 'slides_json' => $slides ? json_encode($slides, JSON_UNESCAPED_UNICODE) : null, 'video_status' => $fmt === 'reel' ? 'script' : null, 'video_brief_json' => $video];
        $id = (int) db_insert('INSERT INTO contents (`' . implode('`, `', array_keys($cols)) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($cols));
        db_run('UPDATE ce_contents SET lib_content_id = ? WHERE id = ?', [$id, (int) $x['id']]);
    } else {
        $id = (int) $cur['id'];
        // العميل عدّل في المكتبة أو المنشور اتسلّم للنشر ← النص بيفضل زي ما هو
        if ($cur['status'] !== 'edited' && !in_array((string) $cur['publish_status'], CE_LIB_LOCKED, true)) {
            db_run('UPDATE contents SET generated_text = ?, hashtags = ?, cta = ?, design_direction = ?, slides_count = ?, slides_json = ?, video_brief_json = COALESCE(?, video_brief_json), campaign_id = ? WHERE id = ?',
                [$text, $tags, $cta, $direction, $slides ? count($slides) : 0, $slides ? json_encode($slides, JSON_UNESCAPED_UNICODE) : null, $video, $libCamp, $id]);
        }
    }

    // الفكرة في الحملة (للوحة والتقويم) — الميعاد بيتحط أول مرة بس (العميل ممكن يحركه من التقويم)
    $idea = db_one('SELECT * FROM campaign_ideas WHERE campaign_id = ? AND content_id = ?', [$libCamp, $id]);
    $title = mb_substr(trim(strtok(trim($text) ?: (string) $slot['objective'], "\n")) ?: 'قطعة', 0, 250);
    $angle = mb_substr(CE_PILLARS[$slot['pillar']] ?? 'محرك المحتوى', 0, 60);
    $at = $fmt !== 'reel' && !empty($slot['slot_date']) ? (function_exists('default_publish_datetime') ? default_publish_datetime((string) $slot['slot_date']) : $slot['slot_date'] . ' 19:00:00') : null;
    if (!$idea) {
        db_insert('INSERT INTO campaign_ideas (campaign_id, user_id, sort_order, angle, title, description, hook, formats, selected, content_id, eval_score, approved, plan_at, plan_platform, format, slides_count)
                   VALUES (?,?,?,?,?,?,?,?,1,?,?,1,?, "both",?,?)',
            [$libCamp, $uid, (int) $slot['position'] + 1, $angle, $title, mb_substr((string) $slot['objective'], 0, 2000), mb_substr($title, 0, 500), CE_FORMATS[$fmt] ?? $fmt, $id,
             $x['qa_score'] !== null ? (int) round((float) $x['qa_score']) : null, $at, $libFmt, $slides ? count($slides) : 0]);
    } else {
        db_run('UPDATE campaign_ideas SET title = ?, hook = ?, eval_score = ?, sort_order = ? WHERE id = ?',
            [$title, mb_substr($title, 0, 500), $x['qa_score'] !== null ? (int) round((float) $x['qa_score']) : null, (int) $slot['position'] + 1, (int) $idea['id']]);
    }
    db_run('UPDATE campaigns SET ideas_count = (SELECT COUNT(*) FROM campaign_ideas WHERE campaign_id = ?) WHERE id = ?', [$libCamp, $libCamp]);
    ce_lib_sync_designs($uid, (int) $x['slot_id'], $id, $fmt);
    db_run('UPDATE ce_contents SET lib_synced_at = NOW() WHERE id = ?', [(int) $x['id']]);   // في الآخر (بعد التصميمات) عشان التكملة ماتعيدش
    return $id;
}

/** التصميمات النهائية ← content_designs (الكاروسيل: كل شريحة برقمها) والغلاف = أول تصميم */
function ce_lib_sync_designs(int $uid, int $slotId, int $contentId, string $format): void
{
    $cur = db_one('SELECT publish_status FROM contents WHERE id = ? AND user_id = ?', [$contentId, $uid]);
    if (!$cur || in_array((string) $cur['publish_status'], CE_LIB_LOCKED, true)) return;
    $cover = null;
    foreach (db_all('SELECT * FROM ce_designs WHERE slot_id = ? AND user_id = ? ORDER BY frame', [$slotId, $uid]) as $d) {
        if (empty($d['final_path']) || !in_array($d['status'], ['pass', 'manual'], true)) continue;
        $slide = $format === 'carousel' ? (int) $d['frame'] : null;
        $ex = !empty($d['lib_design_id']) ? db_one('SELECT id, image_path FROM content_designs WHERE id = ? AND content_id = ?', [(int) $d['lib_design_id'], $contentId]) : null;
        if ($ex) {
            if ($ex['image_path'] !== $d['final_path']) db_run('UPDATE content_designs SET image_path = ? WHERE id = ?', [$d['final_path'], (int) $ex['id']]);
            $did = (int) $ex['id'];
        } else {
            $cols = ['content_id' => $contentId, 'user_id' => $uid, 'image_path' => $d['final_path'], 'prompt' => mb_substr((string) ($d['prompt'] ?? 'محرك المحتوى'), 0, 5000),
                     'model' => 'content-engine', 'credits_used' => 0, 'ratio' => (string) $d['aspect']];
            if (function_exists('formats_ready') && formats_ready()) $cols['slide_no'] = $slide;
            $did = (int) db_insert('INSERT INTO content_designs (`' . implode('`, `', array_keys($cols)) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($cols));
            db_run('UPDATE ce_designs SET lib_design_id = ? WHERE id = ?', [$did, (int) $d['id']]);
        }
        $cover ??= $did;
    }
    if ($cover) db_run('UPDATE contents SET selected_image_id = ? WHERE id = ? AND (selected_image_id IS NULL OR selected_image_id NOT IN (SELECT id FROM content_designs WHERE content_id = ?))', [$cover, $contentId, $contentId]);
}

/** مزامنة حملة كاملة (بعد الكتابة · التصميم · الاعتماد) */
function ce_lib_sync_campaign(int $uid, int $cid): int
{
    if (!ce_lib_ready()) return 0;
    require_once __DIR__ . '/ce-plan.php';   // CE_PILLARS · CE_FORMATS
    require_once __DIR__ . '/content-formats.php';   // formats_ready() ← رقم الشريحة
    $c = db_one('SELECT * FROM ce_campaigns WHERE id = ? AND user_id = ?', [$cid, $uid]);
    if (!$c) return 0;
    $n = 0;
    try {
        foreach (db_all('SELECT * FROM ce_contents WHERE campaign_id = ? AND user_id = ? AND status IN ("pass", "manual")', [$cid, $uid]) as $x) {
            if (ce_lib_sync_piece($c, $x)) $n++;
        }
    } catch (\Throwable $e) {
        error_log('[ce_lib] campaign ' . $cid . ': ' . $e->getMessage());   // المكتبة نسخة — ماتوقفش شغل المحرك
    }
    return $n;
}

/** تكملة اللي فاتها النسخ (القطع اللي خلصت قبل الميزة دي أو وقت خطأ) — بتتنادي من المكتبة وحملاتي */
function ce_lib_backfill(int $uid, int $limit = 10): int
{
    if (!ce_lib_ready()) return 0;
    $n = 0;
    foreach (db_all('SELECT DISTINCT x.campaign_id FROM ce_contents x JOIN ce_campaigns c ON c.id = x.campaign_id
                     WHERE x.user_id = ? AND x.status IN ("pass", "manual") AND (x.lib_content_id IS NULL OR x.lib_synced_at < x.updated_at
                       OR EXISTS (SELECT 1 FROM ce_designs d WHERE d.slot_id = x.slot_id AND d.status IN ("pass", "manual") AND d.final_path IS NOT NULL AND (d.lib_design_id IS NULL OR d.updated_at > x.lib_synced_at)))
                       AND COALESCE(x.lib_content_id, 0) >= 0
                     LIMIT ' . max(1, $limit), [$uid]) as $r) {
        $n += ce_lib_sync_campaign($uid, (int) $r['campaign_id']);
    }
    return $n;
}

/** المنشور ده نسخة من المحرك؟ ← صف القطعة */
function ce_lib_source(int $contentId): ?array
{
    if (!ce_lib_ready() || $contentId <= 0) return null;
    return db_one('SELECT * FROM ce_contents WHERE lib_content_id = ?', [$contentId]);
}

/** المكتبة: المنشور ده بيتنشر من المحرك؟ ← رسالة الرفض (null = مسموح) */
function ce_lib_engine_publishing(int $contentId): ?string
{
    $x = ce_lib_source($contentId);
    if (!$x) return null;
    try {
        if (db_one('SELECT id FROM ce_publish_queue WHERE content_id = ? AND status IN ("pending", "processing", "published")', [(int) $x['id']])) {
            return 'المنشور ده متجدول أو اتنشر من «محرك المحتوى» — غيّر ميعاده أو الغيه من هناك.';
        }
    } catch (\Throwable $e) {}
    return null;
}

/** المحرك: نسخة المكتبة متجدولة أو اتنشرت من هناك؟ ← رسالة الرفض (null = مسموح) */
function ce_lib_library_publishing(array $x): ?string
{
    if ((int) ($x['lib_content_id'] ?? 0) <= 0) return null;
    $c = db_one('SELECT publish_status FROM contents WHERE id = ?', [(int) $x['lib_content_id']]);
    return $c && in_array((string) $c['publish_status'], CE_LIB_LOCKED, true) ? 'متجدولة أو اتنشرت من «مكتبة المحتوى»' : null;
}

/** نص النشر من المحرك: لو العميل عدّل المنشور في المكتبة ← نص المكتبة (+ الـ CTA والهاشتاجات) */
function ce_lib_edited_message(array $x): ?string
{
    if ((int) ($x['lib_content_id'] ?? 0) <= 0) return null;
    $c = db_one('SELECT status, generated_text, hashtags, cta FROM contents WHERE id = ?', [(int) $x['lib_content_id']]);
    if (!$c || $c['status'] !== 'edited') return null;
    $main = trim((string) $c['generated_text']);
    $cta = trim((string) ($c['cta'] ?? ''));
    $tags = trim((string) ($c['hashtags'] ?? ''));
    return mb_substr(trim($main . ($cta !== '' && !str_contains($main, $cta) ? "\n\n" . $cta : '') . ($tags !== '' ? "\n\n" . $tags : '')), 0, 60000);
}
