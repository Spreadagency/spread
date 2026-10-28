<?php
/**
 * Spread AI — محرك المحتوى v3 · التصميم (المرحلة G)
 *
 * لكل قطعة اتكتبت (بوست · كاروسيل · غلاف ريلز):
 *   1) برومبت التصميم (ce_design_prompt): من الـ Design Brief + هوية البراند — الموضوع ← المشهد المصري ← التكوين (المساحة الفاضية للنص)
 *      ← الإضاءة والكاميرا ← الألوان ← الستايل ← الشخصيات. والممنوع: أي نص أو حروف أو لوجوهات · تشوهات · نتايج طبية مش حقيقية
 *   2) الصورة (ce_image) — من غير أي كلام جوّاها
 *   3) مراجعة الصورة (ce_vision_qa): الألوان · المساحة الفاضية للنص · العيوب · مطابقة الفكرة ← PASS أو إعادة (لحد مرتين بالملاحظات)
 *      ← لو لسه ماعدّتش: «محتاجة مراجعتك» بأحسن صورة
 *   4) النص العربي فوقها بخط البراند (ce-overlay.php) + الشعار + العلامة المائية لو الباقة فيها
 * شرايح الكاروسيل بعد الأولى: نص على خلفية البراند من غير AI (الأدمن بيحدد كام شريحة بصورة).
 */
require_once __DIR__ . '/ce-write.php';
require_once __DIR__ . '/ce-overlay.php';
require_once __DIR__ . '/uploader.php';

ce_job_register('campaign_design', 'ce_design_job', 'تصميم الحملة', 'ce_design_cancel_cost');

const CE_DESIGN_NEGATIVE = 'any text, letters, numbers, words, captions, logos, watermarks, signatures, distorted hands, extra fingers, distorted teeth, deformed faces, unrealistic medical results, blurry, low quality';
/** المقاس اللي بيتطلب من موديل الصور (بعدها بنقصّ للمقاس النهائي) */
const CE_GEN_SIZE = ['1:1' => '1024x1024', '4:5' => '1024x1536', '9:16' => '1024x1536'];

/* ═══════════════════ الإعدادات ═══════════════════ */

function ce_design_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ce_designs LIMIT 1');
        db_one('SELECT id FROM ce_text_templates LIMIT 1');
        return $ok = ce_write_ready();
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function ce_design_rules(): array
{
    return [
        'max_regen' => max(0, min(4, ce_setting_int('ce_vision_max_regen', 2))),
        'min_idea' => max(0, min(100, ce_setting_float('ce_vision_min_idea', 70))),
        'min_palette' => max(0, min(100, ce_setting_float('ce_vision_min_palette', 60))),
        // 0 = كل شرايح الكاروسيل بصورة AI (الافتراضي) · رقم = أول N شريحة والباقي نص على خلفية البراند
        'carousel_ai' => max(0, min(10, ce_setting_int('ce_carousel_ai_images', 0))),
        // غلاف الريلز اختيار العميل — ده الافتراضي في الـ Wizard بس
        'reel_cover' => ce_setting_int('ce_reel_cover', 0) === 1,
        'min_credits' => max(0, ce_setting_int('ce_min_credits_image', 1)),
    ];
}

/* ═══════════════════ الإطارات ═══════════════════ */

/** أول N كلمة */
function ce_words(string $s, int $n): string
{
    $w = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_slice($w, 0, $n));
}

/**
 * الإطارات اللي محتاجة تصميم لخانة اتكتبت: [['frame', 'kind' (ai|text), 'aspect', 'headline', 'subline'], …]
 * بوست = صورة · كاروسيل = شريحة لكل نص (كلها بصورة AI أو أول N) · ريلز = غلاف (لو العميل اختاره)
 */
function ce_design_frames(string $format, array $body, bool $reelCover = false): array
{
    $r = ce_design_rules();
    $db = (array) ($body['design_brief'] ?? []);
    $aspect = CE_FORMAT_ASPECT[$format] ?? '1:1';
    $head = ce_words((string) ($db['headline'] ?? ''), 7) ?: ce_words((string) ($body['hooks'][0] ?? ''), 7);
    $sub = mb_substr(trim((string) ($db['subline'] ?? '')), 0, 120);
    $cta = mb_substr(trim((string) ($body['cta'] ?? '')), 0, 120);
    if ($format === 'carousel') {
        $out = [];
        $slides = array_values((array) ($body['slides'] ?? []));
        foreach ($slides as $i => $sl) {
            $n = $i + 1;
            $t = trim((string) (is_array($sl) ? ($sl['text'] ?? '') : $sl));
            if ($t === '') continue;
            $out[] = ['frame' => $n, 'kind' => !$r['carousel_ai'] || $n <= $r['carousel_ai'] ? 'ai' : 'text', 'aspect' => $aspect,
                      'headline' => $n === 1 && $head !== '' ? $head : mb_substr($t, 0, 120),
                      'subline' => $n === 1 ? $sub : ($n === count($slides) ? $cta : '')];
        }
        return $out;
    }
    if ($format === 'reel') {
        return $reelCover ? [['frame' => 1, 'kind' => 'ai', 'aspect' => $aspect, 'headline' => $head, 'subline' => $sub]] : [];
    }
    return [['frame' => 1, 'kind' => 'ai', 'aspect' => $aspect, 'headline' => $head, 'subline' => $sub ?: $cta]];
}

function ce_design_row(int $slotId, int $frame): ?array
{
    return db_one('SELECT * FROM ce_designs WHERE slot_id = ? AND frame = ?', [$slotId, $frame]) ?: null;
}

/* ═══════════════════ البرومبتات ═══════════════════ */

function ce_design_prompt_system(): string
{
    return ce_prompt_sys('ce_design_prompt');
}

function ce_design_prompt_user(array $ctx, array $slot, array $body, array $frame, string $zone, string $hint): string
{
    $dna = $ctx['dna'];
    $db = (array) ($body['design_brief'] ?? []);
    $zoneTxt = ['top' => 'top third', 'bottom' => 'bottom third', 'left' => 'left half', 'right' => 'right half', 'center' => 'middle band'][$zone] ?? 'bottom third';
    $idea = $body['hooks'][0] ?? ($body['slides'][0]['text'] ?? ($body['scenes'][0]['visual'] ?? ''));
    return ce_dna_prefix((int) $ctx['uid']) . "\n\nCE:design_prompt\n<campaign>\n" . ce_plan_brief_text($ctx) . "\n</campaign>\n"
        . '<design_brief>' . json_encode($db, JSON_UNESCAPED_UNICODE) . "</design_brief>\n"
        . '<brand_visual>' . json_encode(['colors_hex' => $dna['visual']['colors_hex'] ?? [], 'photo_style' => $dna['visual']['photo_style'] ?? '', 'avoid' => $dna['visual']['avoid'] ?? []], JSON_UNESCAPED_UNICODE) . "</brand_visual>\n"
        . 'الفكرة: ' . mb_substr((string) $idea, 0, 200) . ' · الشكل: ' . $slot['format'] . ($frame['frame'] > 1 ? ' · شريحة ' . $frame['frame'] : '') . "\n"
        . "Aspect ratio: {$frame['aspect']}. Text-safe zone that MUST stay clean and empty: {$zoneTxt}."
        . ($hint !== '' ? "\nREGENERATE HINT (the previous image failed review — fix this): " . $hint : '');
}

/** البرومبت النهائي: التكوين والممنوعات بتتضاف في الكود دايمًا (حتى لو الموديل نسيها) */
function ce_design_prompt_final(string $prompt, string $negative, string $zone, string $aspect): array
{
    $zoneTxt = ['top' => 'top third', 'bottom' => 'bottom third', 'left' => 'left half', 'right' => 'right half', 'center' => 'middle band'][$zone] ?? 'bottom third';
    $prompt = trim(preg_replace('/\s+/u', ' ', $prompt));
    $prompt .= " Composition: keep the {$zoneTxt} of the frame clean, calm and empty (soft plain background, no objects) for a text overlay. Aspect ratio {$aspect}."
        . ' Absolutely no text, letters, numbers, logos or watermarks anywhere in the image.';
    $neg = implode(', ', array_unique(array_filter(array_map('trim', explode(',', $negative . ',' . CE_DESIGN_NEGATIVE)))));
    return [mb_substr($prompt, 0, 3000), mb_substr($neg, 0, 500)];
}

/** برومبت من غير AI (لو مرحلة البرومبت مش متاحة): من الـ Design Brief مباشرة */
function ce_design_prompt_fallback(array $ctx, array $body): string
{
    $db = (array) ($body['design_brief'] ?? []);
    $parts = array_filter([
        (string) ($db['focal_subject'] ?? ''), (string) ($db['layout'] ?? ''), 'Egyptian everyday setting, ' . ($ctx['dna']['brand']['sector'] ?? ''),
        (string) ($db['lighting'] ?? 'soft natural light'), (string) ($db['camera_angle'] ?? 'eye level'), 'mood: ' . ($db['mood'] ?? 'warm'),
        'brand color accents ' . implode(' ', (array) ($db['colors_hex'] ?? [])), (string) ($ctx['dna']['visual']['photo_style'] ?? 'photorealistic'),
    ]);
    return 'Photorealistic social media photo: ' . implode(', ', $parts) . '.';
}

function ce_vision_system(): string
{
    return ce_prompt_sys('ce_vision_qa');
}

/** رسالة مراجعة الصورة (نفسها في المحرك والـ Playground) — الصورة نفسها بتتبعت جنبها */
function ce_vision_user(array $designBrief, array $colors, string $prompt): string
{
    return "CE:vision_qa\n<design_brief>" . json_encode($designBrief, JSON_UNESCAPED_UNICODE) . "</design_brief>\n"
        . 'Brand colors: ' . implode(' ', $colors) . "\nImage prompt used: " . mb_substr($prompt, 0, 900);
}

/** الحكم في الكود: الألوان والفكرة فوق الحد + المساحة فاضية + مفيش نص + عيب واحد بالكتير */
function ce_vision_score(array $d): array
{
    $r = ce_design_rules();
    $pal = isset($d['palette_match']) && is_numeric($d['palette_match']) ? max(0, min(100, (float) $d['palette_match'])) : 0.0;
    $idea = isset($d['idea_match']) && is_numeric($d['idea_match']) ? max(0, min(100, (float) $d['idea_match'])) : 0.0;
    $clear = filter_var($d['text_zone_clear'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $hasText = filter_var($d['has_text'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $art = array_values(array_slice(array_map(fn($x) => mb_substr((string) $x, 0, 120), array_filter((array) ($d['artifacts'] ?? []), 'is_string')), 0, 6));
    $issues = [];
    if ($idea < $r['min_idea']) $issues[] = 'الصورة بعيدة عن الفكرة (' . $idea . ')';
    if ($pal < $r['min_palette']) $issues[] = 'ألوان البراند مش ظاهرة (' . $pal . ')';
    if (!$clear) $issues[] = 'مكان النص مش فاضي';
    if ($hasText) $issues[] = 'فيه كلام أو حروف جوه الصورة';
    if (count($art) > 1) $issues[] = 'عيوب: ' . implode('، ', $art);
    $pass = !$issues && strtoupper((string) ($d['verdict'] ?? 'PASS')) !== 'REGENERATE';
    $score = round(max(0, ($idea + $pal) / 2 - 8 * count($art) - ($clear ? 0 : 15) - ($hasText ? 20 : 0)), 1);
    return ['palette' => $pal, 'idea' => $idea, 'zone_clear' => $clear, 'has_text' => $hasText, 'artifacts' => $art, 'issues' => $issues,
            'verdict' => $pass ? 'PASS' : 'REGENERATE', 'score' => $score, 'hint' => mb_substr(trim((string) ($d['regenerate_hint'] ?? '')), 0, 300)];
}

/* ═══════════════════ الملفات ═══════════════════ */

function ce_design_dir(int $uid): string
{
    $rel = 'uploads/ce-designs/' . $uid;
    $abs = STORAGE_PATH . '/' . $rel;
    if (!is_dir($abs)) @mkdir($abs, 0755, true);
    return $rel;
}

/** حفظ الصورة النضيفة بعد قصّها على المقاس النهائي */
function ce_design_store_image(int $uid, string $bin, string $aspect): ?string
{
    $src = @imagecreatefromstring($bin);
    if (!$src) return null;
    [$w, $h] = CE_CANVAS[$aspect] ?? CE_CANVAS['1:1'];
    $im = ce_gd_cover($src, $w, $h);
    imagedestroy($src);
    $rel = ce_design_dir($uid) . '/' . bin2hex(random_bytes(10)) . '.png';
    $ok = imagepng($im, STORAGE_PATH . '/' . $rel, 6);
    imagedestroy($im);
    return $ok ? $rel : null;
}

/** صورة صغيرة (768px) كـ data URI للمراجعة — أرخص في التوكنز */
function ce_design_preview_uri(string $rel): ?string
{
    $src = ce_gd_load(STORAGE_PATH . '/' . $rel);
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $s = min(1, 768 / max($w, $h));
    $dst = imagecreatetruecolor(max(1, (int) ($w * $s)), max(1, (int) ($h * $s)));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    ob_start();
    imagejpeg($dst, null, 82);
    $jpg = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return 'data:image/jpeg;base64,' . base64_encode($jpg);
}

/** صور الشخصيات المرجعية (لو التصميم فيه شخصية من الهوية) */
function ce_design_refs(array $ctx, array $body): array
{
    $names = (array) ($body['design_brief']['characters_refs'] ?? []);
    $out = [];
    foreach ($ctx['dna']['visual']['characters'] ?? [] as $ch) {
        if (!in_array($ch['name'], $names, true) || empty($ch['ref_image_ids'])) continue;
        $in = implode(',', array_map('intval', $ch['ref_image_ids']));
        foreach (db_all("SELECT i.image_path FROM brand_character_images i JOIN brand_characters c ON c.id = i.character_id WHERE i.id IN ($in) AND c.user_id = ?", [(int) $ctx['uid']]) as $img) {
            if (function_exists('image_data_uri') && ($u = image_data_uri((string) $img['image_path']))) $out[] = $u;
            if (count($out) >= 2) break 2;
        }
    }
    return $out;
}

/** خطوط البراند الحالية (من الهوية مباشرة — حتى لو ملف الـ DNA قديم) — لحد 3 */
function ce_brand_fonts(int $uid): array
{
    $kit = function_exists('bk_get') ? (bk_get($uid)['data']['typography']['design_fonts'] ?? []) : [];
    $f = array_values(array_filter(array_map('strval', (array) $kit)));
    return array_slice($f ?: (array) (ce_dna_get($uid)['visual']['fonts'] ?? []), 0, 3);
}

/** الحملة فيها غلاف ريلز؟ (اختيار العميل في الـ Wizard — أو الافتراضي من الأدمن) */
function ce_reel_cover_wanted(array $c): bool
{
    $b = json_decode((string) ($c['brief_json'] ?? ''), true) ?: [];
    return array_key_exists('reel_cover', $b) ? !empty($b['reel_cover']) : ce_design_rules()['reel_cover'];
}

/** الرسم النهائي لإطار (نص + شعار + علامة مائية) — بيتنادى بعد الصورة وبعد أي تعديل نص */
function ce_design_render(array $d, int $uid): array
{
    $dna = ce_dna_get($uid) ?: [];
    $slot = db_one('SELECT format FROM ce_slots WHERE id = ?', [(int) $d['slot_id']]);
    $format = (string) ($slot['format'] ?? 'post');
    $tpl = ce_template_get($d['template_id'] ? (int) $d['template_id'] : null, $format);
    $content = db_one('SELECT body_json FROM ce_contents WHERE slot_id = ?', [(int) $d['slot_id']]);
    $body = json_decode((string) ($content['body_json'] ?? ''), true) ?: [];
    $zone = ce_zone_from_text((string) ($body['design_brief']['text_safe_zone'] ?? ''));
    $bp = db_one('SELECT logo_path FROM brand_profiles WHERE user_id = ?', [$uid]);
    $logo = !empty($bp['logo_path']) && is_file(STORAGE_PATH . '/' . $bp['logo_path']) ? STORAGE_PATH . '/' . $bp['logo_path'] : null;
    $rel = ce_design_dir($uid) . '/f' . (int) $d['id'] . '-' . bin2hex(random_bytes(5)) . '.png';
    $r = ce_overlay_render([
        'base' => $d['kind'] === 'ai' && $d['image_path'] ? STORAGE_PATH . '/' . $d['image_path'] : null,
        'aspect' => $d['aspect'], 'headline' => (string) $d['headline'], 'subline' => (string) $d['subline'], 'zone' => $zone,
        'template' => $tpl['style'], 'colors' => $dna['visual']['colors_hex'] ?? [], 'fonts' => ce_brand_fonts($uid), 'font_h' => $d['font_family'] ?? null,
        'logo' => $logo, 'out' => STORAGE_PATH . '/' . $rel,
    ]);
    if (!$r['ok']) return $r;
    if (function_exists('watermark_for_user') || is_file(__DIR__ . '/watermark.php')) {
        require_once __DIR__ . '/watermark.php';
        if (watermark_for_user($uid)) watermark_apply($rel, ['keep_original' => '0']);
    }
    $old = (string) ($d['final_path'] ?? '');
    db_run('UPDATE ce_designs SET final_path = ?, template_id = ? WHERE id = ?', [$rel, $tpl['id'] ?: null, (int) $d['id']]);
    if ($old !== '' && $old !== $rel && str_starts_with($old, 'uploads/ce-designs/')) @unlink(STORAGE_PATH . '/' . $old);
    return ['ok' => true, 'path' => $rel];
}

/* ═══════════════════ الشغلانة ═══════════════════ */

/**
 * الحالة: queue [[slot, frame], …] · i · phase (prompt · image · vision · render) · attempt · hint · best · cost_usd · images · done
 */
function ce_design_job(array $job, array &$state): array
{
    $uid = (int) $job['user_id'];
    $c = db_one('SELECT * FROM ce_campaigns WHERE id = ? AND user_id = ?', [(int) $job['ref_id'], $uid]);
    if (!$c) return ['fail' => 'الحملة مش موجودة'];
    $state += ['queue' => [], 'i' => 0, 'phase' => 'prompt', 'attempt' => 0, 'hint' => '', 'best' => null, 'cost_usd' => 0.0, 'images' => 0, 'done' => 0];
    $rules = ce_design_rules();
    $n = count($state['queue']);
    $prog = fn(float $sub) => (int) max(1, min(98, round(100 * ($state['i'] + $sub) / max(1, $n))));
    if ($state['i'] >= $n) {
        return ['done' => true, 'result' => ['designs' => $state['done'], 'images' => $state['images']], 'used_credits' => ce_design_cancel_cost($state)];
    }
    [$slotId, $frameNo] = $state['queue'][$state['i']];
    $d = ce_design_row((int) $slotId, (int) $frameNo);
    $next = function () use (&$state) {
        $state['i']++;
        $state['phase'] = 'prompt'; $state['attempt'] = 0; $state['hint'] = ''; $state['best'] = null;
    };
    if (!$d) { $next(); return ['done' => false, 'progress' => $prog(0), 'label' => 'تصميم اتشال — بنكمّل']; }
    $slot = db_one('SELECT * FROM ce_slots WHERE id = ?', [(int) $slotId]);
    $content = db_one('SELECT body_json FROM ce_contents WHERE slot_id = ?', [(int) $slotId]);
    $body = json_decode((string) ($content['body_json'] ?? ''), true) ?: [];
    $label = 'التصميم ' . ($state['i'] + 1) . ' من ' . $n;
    $aiCtx = ['user_id' => $uid, 'ref_type' => 'ce_campaign', 'ref_id' => (int) $c['id'], 'campaign_id' => (int) $c['id'],
              'skill' => ce_skill_for_campaign($c, ce_dna_get($uid) ?? [])];

    // شريحة نص على خلفية البراند: الرسم بس
    if ($d['kind'] === 'text') $state['phase'] = 'render';

    if ($state['phase'] === 'prompt') {
        db_run('UPDATE ce_designs SET status = "generating" WHERE id = ?', [(int) $d['id']]);
        $ctx = ce_plan_ctx($uid, $c);
        $zone = ce_zone_from_text((string) ($body['design_brief']['text_safe_zone'] ?? ''));
        $frame = ['frame' => (int) $d['frame'], 'aspect' => $d['aspect']];
        $r = ce_ai_json('ce_design_prompt', ce_design_prompt_system(), ce_design_prompt_user($ctx, $slot, $body, $frame, $zone, $state['hint']), $aiCtx, 700, 0.5);
        $state['cost_usd'] += $r['cost_usd'];
        $prompt = $r['ok'] ? trim((string) ($r['data']['prompt'] ?? '')) : '';
        if ($prompt === '') $prompt = ce_design_prompt_fallback($ctx, $body) . ($state['hint'] !== '' ? ' Fix: ' . $state['hint'] : '');
        $skillNeg = implode(', ', ce_skill_items($aiCtx['skill'], 'visual_avoid'));   // «ممنوع في الصورة» من الـ Skill
        [$p, $neg] = ce_design_prompt_final($prompt, (string) ($r['data']['negative'] ?? '') . ($skillNeg !== '' ? ', ' . $skillNeg : ''), $zone, $d['aspect']);
        db_run('UPDATE ce_designs SET prompt = ?, negative = ? WHERE id = ?', [$p, $neg, (int) $d['id']]);
        $state['refs'] = count(ce_design_refs($ctx, $body));
        $state['phase'] = 'image';
        return ['done' => false, 'progress' => $prog(0.2), 'label' => $label . ($state['attempt'] ? ': بنعيد الصورة بالملاحظات' : ': جهّزنا وصف الصورة… بنرسمها')];
    }

    if ($state['phase'] === 'image') {
        require_once __DIR__ . '/ai-gateway.php';
        $refs = !empty($state['refs']) ? ce_design_refs(ce_plan_ctx($uid, $c), $body) : [];
        $req = ['prompt' => $d['prompt'] . "\nAvoid: " . $d['negative'], 'size' => CE_GEN_SIZE[$d['aspect']] ?? '1024x1024', 'ratio' => $d['aspect']] + ($refs ? ['refs' => $refs] : []);
        $r = function_exists('ai_gw_enabled') && ai_gw_enabled() ? ai_run('ce_image', $req, $aiCtx + ['feature' => 'content_engine']) : ['ok' => false, 'error' => 'gateway_off', 'cost_usd' => 0];
        $state['cost_usd'] += (float) ($r['cost_usd'] ?? 0);
        $rel = !empty($r['ok']) && !empty($r['bin']) ? ce_design_store_image($uid, (string) $r['bin'], $d['aspect']) : null;
        if (!$rel) {
            if (($r['error_class'] ?? '') !== 'policy' && ($r['error'] ?? '') !== 'gateway_off' && (int) $job['attempts'] + 1 < (int) $job['max_attempts']) {
                throw new RuntimeException('ce_image: ' . ($r['error_detail'] ?? $r['error'] ?? 'no image'));
            }
            if ($state['best']) {
                // فيه صورة قبل كده ← نكمّل بيها
                db_run('UPDATE ce_designs SET image_path = ?, qa_json = ?, qa_score = ?, status = "manual" WHERE id = ?', [$state['best']['path'], json_encode($state['best']['qa'], JSON_UNESCAPED_UNICODE), $state['best']['qa']['score'], (int) $d['id']]);
                $state['phase'] = 'render';
                return ['done' => false, 'progress' => $prog(0.8), 'label' => $label . ': بنكمّل بأحسن صورة'];
            }
            db_run('UPDATE ce_designs SET status = "failed" WHERE id = ?', [(int) $d['id']]);
            $next();
            return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': الصورة ماتولّدتش — تقدر تعيدها بعدين'];
        }
        $state['images']++;
        $old = (string) $d['image_path'];
        db_run('UPDATE ce_designs SET image_path = ?, model = ?, attempts = attempts + 1 WHERE id = ?', [$rel, mb_substr(trim(($r['provider'] ?? '') . ' · ' . ($r['model'] ?? ''), ' ·'), 0, 120), (int) $d['id']]);
        // الصورة القديمة بتتمسح لو مش هي «أحسن صورة» المحفوظة
        if ($old !== '' && $old !== ($state['best']['path'] ?? '') && str_starts_with($old, 'uploads/ce-designs/')) @unlink(STORAGE_PATH . '/' . $old);
        $state['phase'] = 'vision';
        return ['done' => false, 'progress' => $prog(0.55), 'label' => $label . ': الصورة جاهزة… بنراجعها'];
    }

    if ($state['phase'] === 'vision') {
        $uri = ce_design_preview_uri((string) $d['image_path']);
        $user = ce_vision_user((array) ($body['design_brief'] ?? []), (array) (ce_dna_get($uid)['visual']['colors_hex'] ?? []), (string) $d['prompt']);
        $r = $uri ? ce_ai_json('ce_vision_qa', ce_vision_system(), $user, $aiCtx, 600, 0.1, [$uri]) : ['ok' => false, 'error' => 'no_image', 'cost_usd' => 0];
        $state['cost_usd'] += $r['cost_usd'];
        if (!$r['ok']) {
            // المراجعة مش متاحة (مفيش موديل رؤية) ← بنكمّل بالصورة ونعلّمها
            db_run('UPDATE ce_designs SET qa_json = ? WHERE id = ?', [json_encode(['skipped' => true, 'note' => 'الصورة ماتراجعتش (مفيش موديل رؤية متاح)'], JSON_UNESCAPED_UNICODE), (int) $d['id']]);
            $state['phase'] = 'render';
            return ['done' => false, 'progress' => $prog(0.8), 'label' => $label . ': بنحط النص'];
        }
        $qa = ce_vision_score($r['data']) + ['attempt' => (int) $state['attempt'], 'model' => trim(($r['provider'] ?? '') . ' · ' . ($r['model'] ?? ''), ' ·')];
        db_run('UPDATE ce_designs SET qa_json = ?, qa_score = ? WHERE id = ?', [json_encode($qa, JSON_UNESCAPED_UNICODE), $qa['score'], (int) $d['id']]);
        if (!$state['best'] || $qa['score'] > $state['best']['qa']['score']) {
            if ($state['best'] && $state['best']['path'] !== $d['image_path'] && str_starts_with($state['best']['path'], 'uploads/ce-designs/')) @unlink(STORAGE_PATH . '/' . $state['best']['path']);
            $state['best'] = ['path' => $d['image_path'], 'qa' => $qa];
        }
        if ($qa['verdict'] === 'PASS') {
            db_run('UPDATE ce_designs SET status = "generating" WHERE id = ?', [(int) $d['id']]);
            $state['phase'] = 'render';
            return ['done' => false, 'progress' => $prog(0.8), 'label' => $label . ': عدّت المراجعة ✓ بنحط النص'];
        }
        if ($state['attempt'] < $rules['max_regen']) {
            $state['attempt']++;
            $state['hint'] = trim($qa['hint'] . ' ' . implode(' · ', $qa['issues']));
            $state['phase'] = 'prompt';
            return ['done' => false, 'progress' => $prog(0.4), 'label' => $label . ': الصورة ماعدّتش — بنعيدها (' . $state['attempt'] . ')'];
        }
        // خلصت الإعادات ← أحسن صورة + «محتاجة مراجعتك»
        $b = $state['best'];
        db_run('UPDATE ce_designs SET image_path = ?, qa_json = ?, qa_score = ?, status = "manual" WHERE id = ?', [$b['path'], json_encode($b['qa'], JSON_UNESCAPED_UNICODE), $b['qa']['score'], (int) $d['id']]);
        if ($d['image_path'] !== $b['path'] && str_starts_with((string) $d['image_path'], 'uploads/ce-designs/')) @unlink(STORAGE_PATH . '/' . $d['image_path']);
        $state['phase'] = 'render';
        return ['done' => false, 'progress' => $prog(0.8), 'label' => $label . ': بأحسن صورة — محتاجة مراجعتك'];
    }

    // ─── النص والشعار ───
    $d = ce_design_row((int) $slotId, (int) $frameNo);
    $r = ce_design_render($d, $uid);
    if (!$r['ok']) {
        db_run('UPDATE ce_designs SET status = "failed" WHERE id = ?', [(int) $d['id']]);
        $next();
        return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': ' . ($r['error'] ?? 'مقدرناش نحط النص')];
    }
    if ($d['status'] !== 'manual') db_run('UPDATE ce_designs SET status = "pass" WHERE id = ?', [(int) $d['id']]);
    $state['done']++;
    $next();
    return ['done' => false, 'progress' => $prog(1), 'label' => $label . ': جاهز ✓'];
}

/** الإلغاء في النص: الصور اللي اتولّدت بتتحسب والباقي بيرجع */
function ce_design_cancel_cost(array $state): int
{
    return max(ce_design_rules()['min_credits'] * (int) ($state['images'] ?? 0), ce_usd_to_credits((float) ($state['cost_usd'] ?? 0)));
}

/* ═══════════════════ العمليات ═══════════════════ */

/** كريدت التصميم: (برومبت + صورة + مراجعة) لكل صورة AI × (1 + احتمال الإعادة) — مش أقل من الحد الأدنى للصورة */
function ce_design_hold_credits(array $c, int $aiFrames): int
{
    if ($aiFrames <= 0) return 0;
    $e = json_decode((string) ($c['estimate_json'] ?? ''), true) ?: [];
    $per = 0.0;
    foreach ((array) ($e['stages'] ?? []) as $s) {
        if (in_array($s['key'], ['ce_design_prompt', 'ce_vision_qa'], true) && (float) ($s['count'] ?? 0) > 0) $per += (float) $s['usd'] / (float) $s['count'];
    }
    if (!$per) {
        foreach (['ce_design_prompt', 'ce_vision_qa'] as $k) {
            [$i, $o] = ce_token_defaults()[$k] ?? [1500, 400];
            $p = ce_stage_price($k);
            $per += $i / 1e6 * $p['in_1m'] + $o / 1e6 * $p['out_1m'];
        }
    }
    $per += ce_image_price()['usd'];
    $regen = 1 + 0.25 * ce_design_rules()['max_regen'];
    return max($aiFrames * ce_design_rules()['min_credits'], ce_usd_to_credits($per * $aiFrames * $regen));
}

/** الإطارات اللي لسه محتاجة تصميم في الحملة (بتتعمل/تتحدّث في ce_designs) */
function ce_design_prepare(int $uid, array $c, bool $reelCover = false): array
{
    $queue = [];
    $ai = 0;
    foreach (ce_plan_slots_load((int) $c['id']) as $s) {
        $x = db_one('SELECT status, body_json FROM ce_contents WHERE slot_id = ?', [$s['id']]);
        if (!$x || !in_array($x['status'], ['pass', 'manual'], true)) continue;
        $body = json_decode((string) $x['body_json'], true) ?: [];
        $frames = ce_design_frames($s['format'], $body, $reelCover);
        $keep = array_column($frames, 'frame');
        // شرايح اتشالت من الكاروسيل بعد إعادة الكتابة
        if ($keep && $s['format'] === 'carousel') db_run('DELETE FROM ce_designs WHERE slot_id = ? AND frame NOT IN (' . implode(',', array_map('intval', $keep)) . ')', [$s['id']]);
        foreach ($frames as $f) {
            $d = ce_design_row($s['id'], $f['frame']);
            if ($d && $d['final_path'] && in_array($d['status'], ['pass', 'manual'], true)) continue;
            if (!$d) {
                db_insert('INSERT INTO ce_designs (campaign_id, slot_id, user_id, frame, kind, aspect, headline, subline, status) VALUES (?,?,?,?,?,?,?,?, "pending")',
                    [(int) $c['id'], $s['id'], $uid, $f['frame'], $f['kind'], $f['aspect'], mb_substr($f['headline'], 0, 160), mb_substr($f['subline'], 0, 200)]);
            } else {
                db_run('UPDATE ce_designs SET kind = ?, aspect = ?, status = "pending" WHERE id = ?', [$f['kind'], $f['aspect'], (int) $d['id']]);
            }
            $queue[] = [$s['id'], $f['frame']];
            if ($f['kind'] === 'ai') $ai++;
        }
    }
    return [$queue, $ai];
}

/** ابدأ تصميم الحملة (بعد الكتابة) — اللي اتصمم قبل كده بيفضل */
function ce_design_start(int $uid, int $cid, ?bool $reelCover = null): array
{
    if (!ce_design_ready()) return ['ok' => false, 'code' => 'not_ready', 'error' => 'التصميم لسه مش متركّب — محتاج ترحيل 2026_ce_design'];
    $c = ce_campaign_get($cid, $uid);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'الحملة مش موجودة'];
    $active = ce_job_active_for($uid, 'campaign_design', 'ce_campaign', $cid);
    if ($active) return ['ok' => true, 'job' => $active, 'existing' => true];
    if ($c['status'] !== 'done' || ce_job_active_for($uid, 'campaign_write', 'ce_campaign', $cid)) return ['ok' => false, 'code' => 'state', 'error' => 'استنى لما الكتابة تخلص'];
    if ($reelCover !== null) {
        // اختيار العميل لغلاف الريلز بيتحفظ في البريف (للمرة الجاية والتقرير)
        $b = json_decode((string) $c['brief_json'], true) ?: [];
        $b['reel_cover'] = $reelCover ? 1 : 0;
        db_run('UPDATE ce_campaigns SET brief_json = ? WHERE id = ?', [json_encode($b, JSON_UNESCAPED_UNICODE), $cid]);
        $c['brief_json'] = json_encode($b);
    }
    [$queue, $ai] = ce_design_prepare($uid, $c, ce_reel_cover_wanted($c));
    if (!$queue) return ['ok' => false, 'code' => 'nothing', 'error' => 'كل التصميمات جاهزة'];
    return ce_job_create($uid, 'campaign_design', ['queue' => $queue], [
        'ref_type' => 'ce_campaign', 'ref_id' => $cid, 'credits' => ce_design_hold_credits($c, $ai),
        'idem_key' => 'design:' . $cid . ':' . bin2hex(random_bytes(6)), 'note' => 'تصميم الحملة: ' . mb_substr((string) $c['title'], 0, 80), 'max_attempts' => 4,
    ]);
}

/** صورة جديدة لإطار واحد (بنفس النص) — حجز صورة واحدة */
function ce_design_redo(int $uid, int $cid, int $slotId, int $frame): array
{
    if (!ce_design_ready()) return ['ok' => false, 'code' => 'not_ready', 'error' => 'التصميم لسه مش متركّب'];
    $c = ce_campaign_get($cid, $uid);
    if (!$c) return ['ok' => false, 'code' => 'not_found', 'error' => 'الحملة مش موجودة'];
    $d = db_one('SELECT * FROM ce_designs WHERE slot_id = ? AND frame = ? AND campaign_id = ? AND user_id = ?', [$slotId, $frame, $cid, $uid]);
    if (!$d) return ['ok' => false, 'code' => 'not_found', 'error' => 'التصميم مش موجود'];
    if ($d['kind'] !== 'ai') return ['ok' => false, 'code' => 'state', 'error' => 'الشريحة دي نص على خلفية البراند — عدّل نصها أو قالبها'];
    if (ce_job_active_for($uid, 'campaign_design', 'ce_campaign', $cid)) return ['ok' => false, 'code' => 'busy', 'error' => 'فيه تصميم شغال على الحملة دي — استنى لما يخلص'];
    $r = ce_job_create($uid, 'campaign_design', ['queue' => [[$slotId, $frame]]], [
        'ref_type' => 'ce_campaign', 'ref_id' => $cid, 'credits' => ce_design_hold_credits($c, 1),
        'idem_key' => 'dredo:' . $d['id'] . ':' . bin2hex(random_bytes(4)), 'note' => 'صورة جديدة لتصميم', 'max_attempts' => 3,
    ]);
    if ($r['ok']) db_run('UPDATE ce_designs SET status = "pending" WHERE id = ?', [(int) $d['id']]);
    return $r;
}

/** تعديل النص أو القالب ← رسم من جديد على نفس الصورة (من غير AI ولا كريدت) */
function ce_design_text(int $uid, int $cid, int $slotId, int $frame, array $in): array
{
    if (!ce_design_ready()) return ['ok' => false, 'code' => 'not_ready', 'error' => 'التصميم لسه مش متركّب'];
    $d = db_one('SELECT * FROM ce_designs WHERE slot_id = ? AND frame = ? AND campaign_id = ? AND user_id = ?', [$slotId, $frame, $cid, $uid]);
    if (!$d) return ['ok' => false, 'code' => 'not_found', 'error' => 'التصميم مش موجود'];
    if (!in_array($d['status'], ['pass', 'manual'], true)) return ['ok' => false, 'code' => 'state', 'error' => 'استنى لما التصميم يخلص'];
    $head = trim(preg_replace('/\s+/u', ' ', (string) ($in['headline'] ?? $d['headline'])));
    $sub = trim(preg_replace('/\s+/u', ' ', (string) ($in['subline'] ?? $d['subline'])));
    if ($head === '') return ['ok' => false, 'code' => 'input', 'error' => 'اكتب العنوان'];
    if (mb_strlen($head) > 90 || mb_strlen($sub) > 140) return ['ok' => false, 'code' => 'input', 'error' => 'النص طويل — العنوان لحد 90 حرف والسطر التاني لحد 140'];
    $tid = isset($in['template_id']) ? (int) $in['template_id'] : (int) $d['template_id'];
    if ($tid) {
        $slot = db_one('SELECT format FROM ce_slots WHERE id = ?', [$slotId]);
        $ok = array_filter(ce_templates(true, (string) ($slot['format'] ?? 'post')), fn($t) => $t['id'] === $tid);
        if (!$ok) return ['ok' => false, 'code' => 'input', 'error' => 'القالب ده مش متاح'];
    }
    // خط العنوان للتصميم ده: من خطوط البراند بس ('' = حسب الترتيب)
    $font = array_key_exists('font', $in) ? (string) $in['font'] : (string) ($d['font_family'] ?? '');
    if ($font !== '' && !in_array($font, ce_brand_fonts($uid), true)) return ['ok' => false, 'code' => 'input', 'error' => 'الخط ده مش من خطوط البراند'];
    db_run('UPDATE ce_designs SET headline = ?, subline = ?, template_id = ?, font_family = ? WHERE id = ?', [$head, $sub, $tid ?: null, $font !== '' ? $font : null, (int) $d['id']]);
    $r = ce_design_render(ce_design_row($slotId, $frame), $uid);
    return $r['ok'] ? ['ok' => true] : ['ok' => false, 'code' => 'render', 'error' => $r['error'] ?? 'مقدرناش نرسم'];
}

/** «اعتمدها زي ما هي» للتصميم اللي محتاج مراجعة */
function ce_design_accept(int $uid, int $cid, int $slotId, int $frame): bool
{
    $st = db()->prepare('UPDATE ce_designs SET status = "pass" WHERE slot_id = ? AND frame = ? AND campaign_id = ? AND user_id = ? AND status = "manual" AND final_path IS NOT NULL');
    $st->execute([$slotId, $frame, $cid, $uid]);
    return $st->rowCount() === 1;
}

/** للواجهة: التصميمات لكل خانة + القوالب + الشغلانة + الحجز المتوقع */
function ce_design_view(int $uid, array $c): array
{
    if (!ce_design_ready()) return ['ready' => false];
    $cid = (int) $c['id'];
    $rows = [];
    foreach (db_all('SELECT * FROM ce_designs WHERE campaign_id = ? ORDER BY slot_id, frame', [$cid]) as $d) {
        $rows[(int) $d['slot_id']][] = [
            'frame' => (int) $d['frame'], 'kind' => $d['kind'], 'status' => $d['status'], 'aspect' => $d['aspect'],
            'url' => $d['final_path'] ? upload_url($d['final_path']) . '?v=' . substr(md5((string) $d['final_path']), 0, 8) : null,
            'headline' => $d['headline'], 'subline' => $d['subline'], 'template_id' => $d['template_id'] ? (int) $d['template_id'] : null, 'font' => $d['font_family'] ?? null,
            'qa' => json_decode((string) $d['qa_json'], true) ?: null, 'qa_score' => $d['qa_score'] !== null ? (float) $d['qa_score'] : null, 'attempts' => (int) $d['attempts'],
        ];
    }
    $job = db_one('SELECT * FROM ce_jobs WHERE kind = "campaign_design" AND ref_type = "ce_campaign" AND ref_id = ? ORDER BY id DESC LIMIT 1', [$cid]);
    $pending = 0;
    $ai = 0;
    $reels = 0;
    if ($c['status'] === 'done') {
        foreach (ce_plan_slots_load($cid) as $s) {
            $x = db_one('SELECT status, body_json FROM ce_contents WHERE slot_id = ?', [$s['id']]);
            if (!$x || !in_array($x['status'], ['pass', 'manual'], true)) continue;
            foreach (ce_design_frames($s['format'], json_decode((string) $x['body_json'], true) ?: [], true) as $f) {
                $have = null;
                foreach ($rows[$s['id']] ?? [] as $r) if ($r['frame'] === $f['frame']) $have = $r;
                if ($have && $have['url'] && in_array($have['status'], ['pass', 'manual'], true)) continue;
                if ($s['format'] === 'reel') { $reels++; continue; }
                $pending++;
                if ($f['kind'] === 'ai') $ai++;
            }
        }
    }
    $tpls = [];
    foreach (ce_templates(true) as $t) $tpls[] = ['id' => $t['id'], 'name' => $t['name'], 'formats' => $t['formats']];
    return [
        'ready' => true, 'by_slot' => $rows, 'job' => $job ? ce_job_public($job) : null, 'templates' => $tpls, 'rules' => ce_design_rules(),
        'pending' => $pending, 'credits' => $pending ? ce_design_hold_credits($c, $ai) : 0,
        // أغلفة الريلز اختيارية: عددها والكريدت لو العميل اختارها
        'reels' => $reels, 'credits_reels' => $reels ? ce_design_hold_credits($c, $ai + $reels) : 0, 'reel_cover' => ce_reel_cover_wanted($c),
        'brand_fonts' => ce_brand_fonts($uid),
    ];
}
