<?php
/**
 * Spread AI — API مركز الهوية (Brand Kit)
 *
 *   GET  action=get                               الـ Kit + الاكتمال والناقص + الخدمات والمواضيع + الشخصيات + الأشكال + الاقتراحات + الـ Presets
 *   POST action=save_section   {section, value}   تحديث قسم (نسخة جديدة)
 *   POST action=regen_section  {section, mode}    «أبسط» · «أفخم» · «اقتراحات تانية» · كلام حر ← اقتراح (مش بيتحفظ لوحده)
 *   POST action=service_save / service_delete / service_focus {id|0}
 *   POST action=topic_add {service_id, titles[]} · topic_delete · topic_toggle · topics_suggest {service_id}
 *   POST action=character_save / character_delete / character_upload (multipart) / character_image_delete / character_primary
 *   POST action=formats_save {items}
 *   GET  action=versions · POST action=version_restore {version}
 *   POST action=suggestion {id, accept}
 *   POST action=sector_set {sector_id}
 *   POST action=draft {answers, use_link}  ·  apply_draft {draft, sections[]}       الـ Onboarding
 *   GET  action=post_chips {content_id} · POST action=post_override {content_id, key, value} · post_feedback {content_id, value}
 *
 * أي توليد AI بيخصم كريدت حسب النظام الحالي (brand_kit_ai_cost · brand_kit_draft_cost).
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/brand-brain.php';
require_once __DIR__ . '/../../includes/brand-kit.php';
require_once __DIR__ . '/../../includes/source-extract.php';
require_once __DIR__ . '/../../includes/uploader.php';

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'get';
bk_ensure_schema();

if (!bk_enabled() && $action !== 'get') api_fail('مركز الهوية متوقف حاليًا', 'disabled', 403);

/** الحالة كاملة للواجهة */
function bkapi_state(int $uid): array
{
    BrandContext::flush($uid);
    $kit = bk_get($uid);
    $comp = bk_completeness($uid, $kit);
    if ($comp['pct'] !== $kit['completeness']) db_run('UPDATE brand_kits SET completeness = ? WHERE id = ?', [$comp['pct'], $kit['id']]);
    $services = bk_services($uid);
    $topics = bk_topics($uid, null, false);
    foreach ($services as &$s) {
        $s['topics'] = array_values(array_filter($topics, fn($t) => (int) $t['service_id'] === (int) $s['id']));
    }
    unset($s);
    $presets = [];
    foreach (db_all('SELECT id, kind, name, preview_path, data FROM style_presets WHERE is_active = 1 AND (sector_template_id IS NULL OR sector_template_id <=> ?) ORDER BY sort_order, id', [$kit['sector_template_id']]) as $p) {
        $p['data'] = bk_arr($p['data']);
        $p['preview'] = $p['preview_path'] ? upload_url($p['preview_path']) : '';
        $presets[$p['kind']][] = $p;
    }
    $chars = bk_characters($uid);
    foreach ($chars as &$c) {
        foreach ($c['images'] as &$im) $im['url'] = upload_url($im['image_path']);
    }
    unset($c, $im);
    $sector = bk_sector($kit['sector_template_id']);
    return [
        'kit' => $kit['data'], 'version' => $kit['version'], 'completeness' => $comp,
        'sector' => $sector ? ['id' => (int) $sector['id'], 'name' => $sector['name'], 'questions' => $sector['brief_questions']] : null,
        'sectors' => array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name']], bk_sectors()),
        'services' => $services,
        'topics' => array_values(array_filter($topics, fn($t) => $t['service_id'] === null)),
        'library_topics' => array_map(fn($t) => ['title' => $t['title'], 'angle' => $t['angle'] ?? ''], bk_library_topics($uid, $kit['sector_template_id'], 8)),
        'characters' => $chars,
        'formats' => bk_formats($uid),
        'suggestions' => bkapi_suggestions($uid),
        'basics' => bkapi_basics($uid),
        'sources' => bkapi_sources($uid),
        'insp' => ($__bp = brand_for_user($uid)) ? brand_insp_list((int) $__bp['id']) : [],
        'insp_max' => brand_insp_max(),
        'sector_auto' => !empty(bk_arr(db_one('SELECT data FROM brand_kits WHERE user_id = ?', [$uid])['data'] ?? '{}')['sector_confirmed']) ? false : (bool) $kit['sector_template_id'],
        'presets' => $presets,
        'seasons' => bk_current_seasons(),
        'logo_url' => !empty($kit['data']['logo']['path']) ? upload_url($kit['data']['logo']['path']) : '',
        'costs' => ['ai' => cost_for_bk('brand_kit_ai_cost', 1), 'draft' => cost_for_bk('brand_kit_draft_cost', 2), 'character' => bkapi_char_cost()],
        'research' => function_exists('ui_research_on') ? ui_research_on() : get_setting('research_enabled', '1') === '1',
        'analyze_cost' => max(0, (int) get_setting('brand_analyze_cost', 1)),
        'labels' => ['formats' => bk_format_labels(), 'hooks' => bk_hook_types()],
        'files' => (int) (db_one('SELECT COUNT(*) n FROM brand_sources s JOIN brand_profiles b ON b.id = s.brand_profile_id WHERE b.user_id = ?', [$uid])['n'] ?? 0),
    ];
}

/** صندوق اقتراحات واحد: اقتراحات Brand Brain (لينك · ملف · بحث · لوجو) + «اتعلمنا من تعديلاتك» + تحليل التصميمات */
function bkapi_suggestions(int $uid): array
{
    $out = [];
    $bp = brand_for_user($uid);
    if ($bp) {
        $labels = brand_source_labels();
        foreach (db_all('SELECT id, field, value, source, confidence, created_at FROM brand_facts WHERE brand_profile_id = ? AND status = "suggested" ORDER BY confidence DESC, id DESC LIMIT 30', [$bp['id']]) as $f) {
            $cur = trim((string) ($bp[$f['field']] ?? ''));
            $out[] = ['kind' => 'fact', 'id' => (int) $f['id'], 'source' => $labels[$f['source']][0] ?? 'AI', 'confidence' => (int) $f['confidence'],
                      'message' => (brand_fields()[$f['field']][0] ?? $f['field']) . ($cur !== '' ? ' (بدل الموجود)' : ''), 'value' => mb_substr((string) $f['value'], 0, 400), 'created_at' => $f['created_at']];
        }
    }
    foreach (db_all('SELECT id, field, message, created_at FROM brand_suggestions WHERE user_id = ? AND status = "pending" ORDER BY id DESC', [$uid]) as $g) {
        $src = str_starts_with((string) $g['field'], 'vi_') ? 'من تحليل تصميماتك' : ((string) $g['field'] === 'research_topics' ? 'من البحث العميق' : 'من تعديلاتك');
        $out[] = ['kind' => 'learn', 'id' => (int) $g['id'], 'source' => $src, 'confidence' => 0, 'message' => (string) $g['message'], 'value' => '', 'created_at' => $g['created_at']];
    }
    return $out;
}

/** الأساسيات والتواصل (brand_profiles) */
function bkapi_basics(int $uid): array
{
    $bp = brand_for_user($uid) ?: [];
    $out = [];
    foreach (['business_name', 'industry', 'description', 'phones', 'whatsapp', 'address', 'working_hours', 'website', 'social_facebook', 'social_instagram', 'social_tiktok', 'social_linkedin', 'design_rules'] as $f) {
        $out[$f] = (string) ($bp[$f] ?? '');
    }
    return $out;
}

/** المستندات (مصادر المعرفة) — مع «يدخل في المحتوى» */
function bkapi_sources(int $uid): array
{
    $bp = brand_for_user($uid);
    if (!$bp) return [];
    try {
        return array_map(fn($s) => ['id' => (int) $s['id'], 'title' => (string) ($s['title'] ?: ($s['source_url'] ?: 'مستند')), 'type' => (string) $s['type'],
                                    'use' => (int) $s['use_in_prompts'] === 1, 'status' => (string) $s['extract_status'], 'url' => (string) ($s['source_url'] ?? '')],
            db_all('SELECT id, title, type, source_url, use_in_prompts, extract_status FROM brand_sources WHERE brand_profile_id = ? ORDER BY id DESC LIMIT 50', [$bp['id']]));
    } catch (\Throwable $e) {
        return [];
    }
}

function bkapi_char_cost(): int
{
    return max(0, (int) get_setting('brand_kit_character_cost', (string) cost_for('content_design_cost')));
}

function cost_for_bk(string $key, int $def): int
{
    return max(0, (int) get_setting($key, (string) $def));
}

/** خصم قبل الـ AI · استرجاع لو فشل */
function bkapi_charge(int $uid, int $cost, string $note): void
{
    if ($cost > 0 && (credits_balance($uid) < $cost || !credits_consume($uid, $cost, $note, 'brand_kit', null))) {
        api_fail(credits_short_msg($cost), 'credits', 402);
    }
}

function bkapi_refund(int $uid, int $cost, string $note): void
{
    if ($cost > 0) credits_add($uid, $cost, 'استرداد: ' . $note, null, 'refund', null);
}

function bkapi_ai(int $uid, string $prompt, int $maxTokens = 1400): array
{
    require_once __DIR__ . '/../../includes/campaign-flow.php';
    $r = campaign_ai($prompt, $maxTokens, 'brand', ['user_id' => $uid, 'reference_type' => 'brand_kits', 'temperature' => 0.7]);
    return $r['ok'] ? bk_json_from_ai((string) $r['response']) : ['__error' => $r['error'] ?? 'تعذّر التوليد'];
}

function bkapi_own_service(int $uid, int $id): array
{
    $s = db_one('SELECT * FROM brand_services WHERE id = ? AND user_id = ?', [$id, $uid]);
    if (!$s) api_fail('الخدمة مش موجودة', 'not_found', 404);
    return $s;
}

function bkapi_own_character(int $uid, int $id): array
{
    $c = db_one('SELECT * FROM brand_characters WHERE id = ? AND user_id = ?', [$id, $uid]);
    if (!$c) api_fail('الشخصية مش موجودة', 'not_found', 404);
    return $c;
}

switch ($action) {
    case 'get':
        api_ok(bkapi_state($uid) + ['enabled' => bk_enabled()]);

    case 'save_section':
        $in = api_input();
        $r = bk_save_section($uid, api_str('section', 40), $in['value'] ?? null);
        if (!$r['ok']) api_fail($r['error'], 'invalid', 422);
        api_ok(['state' => bkapi_state($uid)]);

    /* «تعديل بكلمة»: أبسط · أفخم · اقتراحات تانية · أو كلام حر ← اقتراح يتراجع قبل الحفظ */
    case 'regen_section':
        $section = api_str('section', 40);
        $allowed = ['voice', 'personality', 'audience', 'pillars', 'business', 'design_style', 'visuals'];
        if (!in_array($section, $allowed, true)) api_fail('القسم ده مالوش تعديل بالـ AI', 'invalid', 422);
        $mode = api_str('mode', 300);
        if ($mode === '') api_fail('اختار التعديل', 'invalid', 422);
        if (!rate_limit('bk_regen', 'u' . $uid, 15, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        $kit = bk_get($uid);
        $cur = $kit['data'][$section];
        $ask = ['simpler' => 'خليه أبسط وأقرب للناس', 'fancier' => 'خليه أفخم وأرقى', 'alternatives' => 'اقترح بديل مختلف بنفس الروح'][$mode] ?? ('طلب العميل: ' . $mode);
        $cost = cost_for_bk('brand_kit_ai_cost', 1);
        bkapi_charge($uid, $cost, 'تعديل الهوية: ' . bk_section_label($section));
        $bp = bk_brand_profile($uid) ?: [];
        $j = bkapi_ai($uid, "انت خبير هوية تجارية. ده قسم «" . bk_section_label($section) . "» من هوية براند «" . ($bp['business_name'] ?? '') . "» (" . ($bp['industry'] ?? '') . ").\n"
            . "القيمة الحالية (JSON):\n" . bk_json($cur) . "\n\n" . $ask . ".\n"
            . "رجّع نفس شكل الـ JSON بالظبط بنفس المفاتيح (القيم بالعامية المصرية ما عدا الأسماء والأكواد) — من غير أي كلام تاني: {\"value\": ...}");
        if (isset($j['__error']) || !array_key_exists('value', $j)) {
            bkapi_refund($uid, $cost, 'تعديل الهوية');
            api_fail($j['__error'] ?? 'الرد مكانش مظبوط — الكريدت رجعلك', 'ai_failed', 502);
        }
        api_ok(['proposal' => bk_clean_section($section, $j['value']), 'balance' => credits_balance($uid)]);

    /* ══════════ الخدمات ══════════ */
    case 'service_save':
        $in = api_input();
        $id = api_int('id');
        $name = api_str('name', 150);
        if (mb_strlen($name) < 2) api_fail('اكتب اسم الخدمة', 'invalid', 422);
        $prio = in_array($in['priority'] ?? '', ['high', 'medium', 'low'], true) ? $in['priority'] : 'medium';
        $seasons = array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 40), (array) ($in['seasons'] ?? []))), 0, 8));
        $f = [$name, api_str('description', 1000) ?: null, $prio, bk_json($seasons), isset($in['is_active']) ? (int) !empty($in['is_active']) : 1];
        if ($id) {
            bkapi_own_service($uid, $id);
            db_run('UPDATE brand_services SET name = ?, description = ?, priority = ?, seasons = ?, is_active = ? WHERE id = ?', array_merge($f, [$id]));
        } else {
            if ((int) (db_one('SELECT COUNT(*) n FROM brand_services WHERE user_id = ?', [$uid])['n'] ?? 0) >= 40) api_fail('وصلت لأقصى عدد خدمات', 'limit', 422);
            $id = db_insert('INSERT INTO brand_services (user_id, name, description, priority, seasons, is_active, sort_order) VALUES (?,?,?,?,?,?,?)',
                array_merge([$uid], $f, [(int) (db_one('SELECT COALESCE(MAX(sort_order),0)+1 n FROM brand_services WHERE user_id = ?', [$uid])['n'] ?? 1)]));
        }
        bk_services_to_text($uid);
        bk_update_completeness($uid);
        api_ok(['id' => (int) $id, 'state' => bkapi_state($uid)]);

    case 'service_delete':
        $s = bkapi_own_service($uid, api_int('id'));
        db_run('DELETE FROM brand_topics WHERE service_id = ? AND user_id = ?', [$s['id'], $uid]);
        db_run('DELETE FROM brand_services WHERE id = ?', [$s['id']]);
        bk_services_to_text($uid);
        bk_update_completeness($uid);
        api_ok(['state' => bkapi_state($uid)]);

    /* «ركّز الشهر ده على»: خدمة واحدة أو متوازن (0) */
    case 'service_focus':
        $id = api_int('id');
        db_run('UPDATE brand_services SET is_focus = 0 WHERE user_id = ?', [$uid]);
        if ($id) {
            bkapi_own_service($uid, $id);
            db_run('UPDATE brand_services SET is_focus = 1, is_active = 1 WHERE id = ?', [$id]);
        }
        bk_save_section($uid, 'focus', ['mode' => $id ? 'service' : 'balanced', 'service_id' => $id ?: null]);
        bk_services_to_text($uid);
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ المواضيع ══════════ */
    case 'topic_add':
        $in = api_input();
        $sid = api_int('service_id') ?: null;
        if ($sid) bkapi_own_service($uid, $sid);
        $titles = array_values(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 255), (array) ($in['titles'] ?? [api_str('title', 255)]))));
        if (!$titles) api_fail('اكتب الموضوع', 'invalid', 422);
        $src = in_array($in['source'] ?? '', ['ai', 'library', 'template'], true) ? $in['source'] : 'user';
        $have = array_map('mb_strtolower', array_column(db_all('SELECT title FROM brand_topics WHERE user_id = ?', [$uid]), 'title'));
        $n = 0;
        foreach (array_slice($titles, 0, 20) as $t) {
            if (in_array(mb_strtolower($t), $have, true)) continue;
            db_insert('INSERT INTO brand_topics (user_id, service_id, title, source) VALUES (?,?,?,?)', [$uid, $sid, $t, $src]);
            $have[] = mb_strtolower($t);
            $n++;
        }
        bk_update_completeness($uid);
        api_ok(['added' => $n, 'state' => bkapi_state($uid)]);

    case 'topic_delete':
        db_run('DELETE FROM brand_topics WHERE id = ? AND user_id = ?', [api_int('id'), $uid]);
        bk_update_completeness($uid);
        api_ok(['state' => bkapi_state($uid)]);

    case 'topic_toggle':
        db_run('UPDATE brand_topics SET is_active = 1 - is_active WHERE id = ? AND user_id = ?', [api_int('id'), $uid]);
        api_ok(['state' => bkapi_state($uid)]);

    /* «اقترح بالـ AI»: 5 مواضيع جديدة مش مكررة لخدمة (أو عامة) */
    case 'topics_suggest':
        $sid = api_int('service_id');
        $s = $sid ? bkapi_own_service($uid, $sid) : null;
        if (!rate_limit('bk_topics', 'u' . $uid, 12, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        $kit = bk_get($uid);
        $have = array_column(db_all('SELECT title FROM brand_topics WHERE user_id = ?', [$uid]), 'title');
        $lib = array_column(bk_library_topics($uid, $kit['sector_template_id'], 6), 'title');
        $methods = array_column(bk_learning_for($kit['sector_template_id'], 'method'), 'title');
        $cost = cost_for_bk('brand_kit_ai_cost', 1);
        bkapi_charge($uid, $cost, 'مواضيع مقترحة' . ($s ? ': ' . $s['name'] : ''));
        $d = $kit['data'];
        $j = bkapi_ai($uid, "انت استراتيجي محتوى. اقترح 5 مواضيع بوستات جديدة ومحددة"
            . ($s ? " لخدمة «{$s['name']}»" . ($s['description'] ? " ({$s['description']})" : '') : '')
            . " لنشاط «{$d['business']['name']}» في مجال «{$d['business']['sector']}».\n"
            . ($d['audience']['description'] !== '' ? "الجمهور: {$d['audience']['description']}\n" : '')
            . ($d['audience']['pains'] ? 'مشاكلهم: ' . implode('، ', $d['audience']['pains']) . "\n" : '')
            . 'المواسم الحالية: ' . implode('، ', bk_current_seasons()) . "\n"
            . ($have ? "مواضيع موجودة — ماتكررهاش ولا تقرب منها:\n- " . implode("\n- ", array_slice($have, 0, 60)) . "\n" : '')
            . ($lib ? 'استلهم من مكتبة القطاع: ' . implode('، ', $lib) . "\n" : '')
            . ($methods ? 'زوايا ممكنة: ' . implode('، ', $methods) . "\n" : '')
            . ($d['forbidden_topics'] ? 'مواضيع ممنوعة: ' . implode('، ', $d['forbidden_topics']) . "\n" : '')
            . "كل موضوع عنوان قصير (أقل من 10 كلمات) بالعامية المصرية. رجّع JSON بس: {\"topics\":[\"\",\"\",\"\",\"\",\"\"]}");
        $list = array_values(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 255), (array) ($j['topics'] ?? []))));
        $haveL = array_map('mb_strtolower', $have);
        $list = array_values(array_filter($list, fn($t) => !in_array(mb_strtolower($t), $haveL, true)));
        if (!$list) {
            bkapi_refund($uid, $cost, 'مواضيع مقترحة');
            api_fail($j['__error'] ?? 'مطلعش مواضيع جديدة — الكريدت رجعلك، جرّب تاني', 'ai_failed', 502);
        }
        api_ok(['topics' => array_slice($list, 0, 5), 'balance' => credits_balance($uid)]);

    /* ══════════ الشخصيات الثابتة ══════════ */
    case 'character_save':
        $in = api_input();
        $id = api_int('id');
        $name = api_str('name', 100);
        if (mb_strlen($name) < 2) api_fail('اكتب اسم الشخصية', 'invalid', 422);
        $type = in_array($in['type'] ?? '', ['real_person', 'ai_character', 'mascot'], true) ? $in['type'] : 'ai_character';
        $useIn = implode(',', array_values(array_intersect((array) ($in['use_in'] ?? []), ['image', 'reel', 'voice', 'story'])));
        $consent = !empty($in['consent_confirmed']) ? 1 : 0;
        if ($type === 'real_person' && !$consent && !empty($in['require_consent'])) api_fail('لازم تأكد الموافقة على استخدام صور الشخص ده', 'consent', 422);
        $f = [$name, $type, api_str('role', 150) ?: null, api_str('description', 1500) ?: null, api_str('personality', 800) ?: null, $useIn ?: null,
              api_int('share_percent', 0, 0, 100), api_str('voice_id', 100) ?: null, $consent, isset($in['is_active']) ? (int) !empty($in['is_active']) : 1];
        if ($id) {
            $old = bkapi_own_character($uid, $id);
            db_run('UPDATE brand_characters SET name = ?, type = ?, role = ?, description = ?, personality = ?, use_in = ?, share_percent = ?, voice_id = ?,
                    consent_confirmed = ?, is_active = ?, consent_at = IF(? = 1 AND consent_confirmed = 0, NOW(), consent_at) WHERE id = ?', array_merge($f, [$consent, $id]));
        } else {
            if ((int) (db_one('SELECT COUNT(*) n FROM brand_characters WHERE user_id = ?', [$uid])['n'] ?? 0) >= 10) api_fail('وصلت لأقصى عدد شخصيات', 'limit', 422);
            $id = db_insert('INSERT INTO brand_characters (user_id, name, type, role, description, personality, use_in, share_percent, voice_id, consent_confirmed, is_active, consent_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,' . ($consent ? 'NOW()' : 'NULL') . ')', array_merge([$uid], $f));
        }
        api_ok(['id' => (int) $id, 'state' => bkapi_state($uid)]);

    case 'character_delete':
        $c = bkapi_own_character($uid, api_int('id'));
        require_once __DIR__ . '/../../includes/uploader.php';
        foreach (db_all('SELECT image_path FROM brand_character_images WHERE character_id = ?', [$c['id']]) as $im) delete_upload($im['image_path']);
        db_run('DELETE FROM brand_character_images WHERE character_id = ?', [$c['id']]);
        db_run('DELETE FROM brand_characters WHERE id = ?', [$c['id']]);
        api_ok(['state' => bkapi_state($uid)]);

    /* صور مرجعية (3–8): multipart image */
    case 'character_upload':
        $c = bkapi_own_character($uid, api_int('id'));
        require_once __DIR__ . '/../../includes/uploader.php';
        if ((int) (db_one('SELECT COUNT(*) n FROM brand_character_images WHERE character_id = ?', [$c['id']])['n'] ?? 0) >= 8) api_fail('أقصى حاجة 8 صور', 'limit', 422);
        if (empty($_FILES['image'])) api_fail('اختار صورة', 'invalid', 422);
        $up = upload_image($_FILES['image'], 'characters');
        if (!$up['ok']) api_fail($up['error'] ?: 'الصورة مش مقبولة', 'invalid', 422);
        $first = !db_one('SELECT id FROM brand_character_images WHERE character_id = ? LIMIT 1', [$c['id']]);
        db_insert('INSERT INTO brand_character_images (character_id, image_path, is_primary) VALUES (?,?,?)', [$c['id'], $up['path'], $first ? 1 : 0]);
        api_ok(['state' => bkapi_state($uid)]);

    case 'character_image_delete':
        $im = db_one('SELECT i.* FROM brand_character_images i JOIN brand_characters c ON c.id = i.character_id WHERE i.id = ? AND c.user_id = ?', [api_int('image_id'), $uid]);
        if (!$im) api_fail('الصورة مش موجودة', 'not_found', 404);
        require_once __DIR__ . '/../../includes/uploader.php';
        delete_upload($im['image_path']);
        db_run('DELETE FROM brand_character_images WHERE id = ?', [$im['id']]);
        if ((int) $im['is_primary'] === 1) db_run('UPDATE brand_character_images SET is_primary = 1 WHERE character_id = ? ORDER BY id LIMIT 1', [$im['character_id']]);
        api_ok(['state' => bkapi_state($uid)]);

    case 'character_primary':
        $im = db_one('SELECT i.* FROM brand_character_images i JOIN brand_characters c ON c.id = i.character_id WHERE i.id = ? AND c.user_id = ?', [api_int('image_id'), $uid]);
        if (!$im) api_fail('الصورة مش موجودة', 'not_found', 404);
        db_run('UPDATE brand_character_images SET is_primary = (id = ?) WHERE character_id = ?', [$im['id'], $im['character_id']]);
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ أشكال المحتوى (الترتيب بالسحب) ══════════ */
    case 'formats_save':
        $items = (array) (api_input()['items'] ?? []);
        $rank = 0;
        foreach ($items as $it) {
            $f = (string) ($it['format'] ?? '');
            if (!in_array($f, BK_FORMATS, true)) continue;
            $settings = [];
            if ($f === 'carousel') $settings['slides'] = preg_match('/^\d{1,2}(-\d{1,2})?$/', (string) ($it['settings']['slides'] ?? '')) ? $it['settings']['slides'] : '5-7';
            if ($f === 'reel') $settings['duration'] = preg_match('/^\d{1,3}(-\d{1,3})?$/', (string) ($it['settings']['duration'] ?? '')) ? $it['settings']['duration'] : '15-30';
            db_run('INSERT INTO brand_formats (user_id, format, `rank`, per_week, settings, is_enabled) VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE `rank` = VALUES(`rank`), per_week = VALUES(per_week), settings = VALUES(settings), is_enabled = VALUES(is_enabled)',
                [$uid, $f, ++$rank, max(0, min(21, (int) ($it['per_week'] ?? 0))), bk_json($settings ?: new \stdClass()), !empty($it['is_enabled']) ? 1 : 0]);
        }
        bk_update_completeness($uid);
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ النسخ ══════════ */
    case 'versions':
        $kit = bk_get($uid);
        api_ok(['versions' => db_all('SELECT version, changed_by, note, created_at FROM brand_kit_versions WHERE brand_kit_id = ? ORDER BY version DESC LIMIT 40', [$kit['id']]),
                'current' => $kit['version']]);

    case 'version_restore':
        if (!bk_restore_version($uid, api_int('version'), 'user', 'رجوع لنسخة ' . api_int('version'))) api_fail('النسخة مش موجودة', 'not_found', 404);
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ «اتعلمنا من تعديلاتك» ══════════ */
    case 'suggestion':
        $r = bk_suggestion_decide($uid, api_int('id'), api_int('accept') === 1);
        if (!$r['ok']) api_fail($r['error'], 'not_found', 404);
        api_ok(['state' => bkapi_state($uid)]);

    case 'sector_set':
        $sid = api_int('sector_id') ?: null;
        $sec = $sid ? bk_sector($sid) : null;
        if ($sid && !$sec) api_fail('القطاع مش موجود', 'not_found', 404);
        $kit = bk_get($uid);
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $stored = bk_arr($row['data'] ?? '{}');
        $stored['sector_confirmed'] = 1;
        db_run('UPDATE brand_kits SET sector_template_id = ?, data = ? WHERE user_id = ?', [$sid, bk_json($stored), $uid]);
        // المجال فاضي؟ اسم القطاع يملاه (نفس المعلومة في المكانين)
        $bp = brand_for_user($uid);
        if ($sec && $bp && trim((string) $bp['industry']) === '') brand_field_save($bp, $uid, 'industry', $sec['name']);
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ الـ Onboarding: مسودة Brand Kit بالـ AI ══════════ */
    case 'draft':
        if (!rate_limit('bk_draft', 'u' . $uid, 5, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        $in = api_input();
        $kit = bk_get($uid);
        $bp = bk_brand_profile($uid) ?: [];
        $sector = bk_sector($kit['sector_template_id']);
        $answers = [];
        foreach ((array) ($in['answers'] ?? []) as $q => $a) {
            $a = is_array($a) ? implode('، ', array_map('strval', $a)) : (string) $a;
            if (trim($a) !== '' && trim($a) !== 'ai') $answers[] = '- ' . mb_substr((string) $q, 0, 120) . ': ' . mb_substr($a, 0, 500);
        }
        $facts = [];
        foreach (['business_name' => 'الاسم', 'industry' => 'المجال', 'description' => 'الوصف', 'audience' => 'الجمهور', 'services' => 'الخدمات', 'address' => 'العنوان',
                  'colors' => 'الألوان', 'ai_summary' => 'ملخص', 'design_rules' => 'قواعد التصميم', 'keywords_use' => 'كلمات مفضلة', 'keywords_avoid' => 'كلمات ممنوعة'] as $k => $l) {
            if (!empty($bp[$k])) $facts[] = '- ' . $l . ': ' . mb_substr((string) $bp[$k], 0, 700);
        }
        // اقتراحات اللينك/الملفات اللي لسه العميل ماراجعهاش — بتدخل المسودة كـ «غير مؤكد» وبتظهر في المراجعة
        $pendingFacts = !empty($bp['id']) ? db_all('SELECT id, field, value, source, confidence FROM brand_facts WHERE brand_profile_id = ? AND status = "suggested" ORDER BY confidence DESC, id DESC LIMIT 25', [$bp['id']]) : [];
        foreach ($pendingFacts as $pf) $facts[] = '- (من اللينك — غير مؤكد) ' . (brand_fields()[$pf['field']][0] ?? $pf['field']) . ': ' . mb_substr((string) $pf['value'], 0, 500);
        $knowledge = !empty($bp['id']) ? mb_substr(get_brand_knowledge((int) $bp['id']), 0, 5000) : '';
        if (!$facts && !$answers && $knowledge === '') api_fail('ابدأ بلينك صفحتك أو جاوب على الأسئلة الأول', 'empty', 422);
        $cost = cost_for_bk('brand_kit_draft_cost', 2);
        bkapi_charge($uid, $cost, 'مسودة الهوية (Brand Kit)');
        $admin = BrandContext::agentPrompt('brand');
        $j = bkapi_ai($uid, ($admin !== '' ? $admin . "\n\n" : '')
            . "انت خبير هوية تجارية وتسويق محتوى. ابني مسودة Brand Kit من المعلومات دي.\n"
            . ($sector ? "قالب القطاع: {$sector['name']} — اتجاهات افتراضية: " . implode('، ', array_map(fn($p) => $p['name'] . ' ' . $p['percent'] . '%', $sector['default_pillars'])) . "\n" : '')
            . ($facts ? "بيانات البراند:\n" . implode("\n", $facts) . "\n" : '')
            . ($answers ? "إجابات العميل:\n" . implode("\n", $answers) . "\n" : '')
            . ($knowledge !== '' ? "من مستندات العميل وصفحاته:\n" . $knowledge . "\n" : '')
            . "\nالقواعد: ماتخترعش حقائق (أسعار، عناوين، أرقام). الحاجة اللي مش واضحة حط لها confidence أقل من 0.7.\n"
            . "رجّع JSON بس بالشكل ده:\n"
            . '{"business":{"name":"","sector":"","location":"","usp":"","price_range":""},'
            . '"audience":{"description":"","age":"","gender":"","pains":[],"objections":[]},'
            . '"personality":["","",""],'
            . '"voice":{"formal_friendly":60,"serious_fun":40,"scientific_simple":70,"dialect":"egyptian","emoji":true,"words_use":[],"words_avoid":[],"sample_lines":["",""]},'
            . '"pillars":[{"name":"","percent":30}],'
            . '"content":{"goal":"trust","hooks":["question","story"],"caption_length":"medium","platforms":["facebook","instagram"],"posts_per_month":12},'
            . '"visuals":{"kind":"photo","light":"bright","people":"egyptian","places":[],"brand_color_in_photos":"subtle","avoid":[]},'
            . '"services":[{"name":"","description":"","priority":"high"}],'
            . '"topics":["",""],'
            . '"confidence":{"business":0.9,"audience":0.6,"personality":0.6,"voice":0.6,"pillars":0.7,"content":0.6,"visuals":0.5,"services":0.8}}'
            . "\nقيم goal: bookings|sales|reach|trust|engagement · hooks: question|story|number|problem|myth|offer|tip · kind: photo|3d|flat|mixed · light: bright|warm|dramatic · people: egyptian|arab|characters_only|none", 2600);
        if (isset($j['__error']) || empty($j['business'])) {
            bkapi_refund($uid, $cost, 'مسودة الهوية');
            api_fail($j['__error'] ?? 'الرد مكانش مظبوط — الكريدت رجعلك، جرّب تاني', 'ai_failed', 502);
        }
        $draft = [];
        foreach (['business', 'audience', 'personality', 'voice', 'pillars', 'content', 'visuals'] as $sec) {
            if (isset($j[$sec])) $draft[$sec] = bk_clean_section($sec, $j[$sec]);
        }
        $draft['services'] = array_values(array_filter(array_map(fn($s) => is_array($s) ? ['name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 150),
            'description' => mb_substr(trim((string) ($s['description'] ?? '')), 0, 500), 'priority' => in_array($s['priority'] ?? '', ['high', 'medium', 'low'], true) ? $s['priority'] : 'medium'] : null,
            array_slice((array) ($j['services'] ?? []), 0, 12)), fn($s) => $s && $s['name'] !== ''));
        $draft['topics'] = array_values(array_slice(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 255), (array) ($j['topics'] ?? []))), 0, 15));
        $conf = [];
        foreach ((array) ($j['confidence'] ?? []) as $k => $v) if (is_numeric($v)) $conf[$k] = max(0, min(1, (float) $v));
        api_ok(['draft' => $draft, 'confidence' => $conf, 'balance' => credits_balance($uid),
                'facts' => array_map(fn($f) => ['id' => (int) $f['id'], 'field' => $f['field'], 'label' => brand_fields()[$f['field']][0] ?? $f['field'],
                    'value' => mb_substr((string) $f['value'], 0, 300), 'source' => brand_source_labels()[$f['source']][0] ?? '', 'confidence' => (int) $f['confidence']], $pendingFacts)]);

    /* مراجعة المسودة ← الحفظ (الأقسام اللي العميل أكدها) */
    case 'apply_draft':
        $in = api_input();
        $draft = (array) ($in['draft'] ?? []);
        $sections = array_values(array_intersect((array) ($in['sections'] ?? []), ['business', 'audience', 'personality', 'voice', 'pillars', 'content', 'visuals', 'services', 'topics']));
        $unsure = array_values(array_intersect((array) ($in['unsure'] ?? []), BK_SECTIONS));
        $kit = bk_get($uid);
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $stored = bk_arr($row['data'] ?? '{}');
        foreach ($sections as $sec) {
            if (in_array($sec, BK_SECTIONS, true) && isset($draft[$sec])) {
                $stored[$sec] = bk_clean_section($sec, $draft[$sec]);
                bk_sync_profile($uid, $sec, $stored[$sec]);
            }
        }
        // الحاجات اللي الـ AI مش متأكد منها: بتتعلّم في الواجهة لحد ما العميل يأكدها
        $stored['confidence'] = array_fill_keys($unsure, 0.5) + (array) ($stored['confidence'] ?? []);
        bk_write($kit['id'], $uid, $stored, 'ai', 'مسودة الهوية من الـ AI');
        if (in_array('services', $sections, true)) {
            $have = array_map('mb_strtolower', array_column(db_all('SELECT name FROM brand_services WHERE user_id = ?', [$uid]), 'name'));
            foreach ((array) ($draft['services'] ?? []) as $i => $s) {
                $n = mb_substr(trim((string) ($s['name'] ?? '')), 0, 150);
                if ($n === '' || in_array(mb_strtolower($n), $have, true)) continue;
                db_insert('INSERT INTO brand_services (user_id, name, description, priority, sort_order) VALUES (?,?,?,?,?)',
                    [$uid, $n, mb_substr((string) ($s['description'] ?? ''), 0, 1000) ?: null, in_array($s['priority'] ?? '', ['high', 'medium', 'low'], true) ? $s['priority'] : 'medium', $i + 1]);
                $have[] = mb_strtolower($n);
            }
        }
        if (in_array('services', $sections, true)) bk_services_to_text($uid);
        if (in_array('topics', $sections, true)) {
            $have = array_map('mb_strtolower', array_column(db_all('SELECT title FROM brand_topics WHERE user_id = ?', [$uid]), 'title'));
            foreach ((array) ($draft['topics'] ?? []) as $t) {
                $t = mb_substr(trim((string) $t), 0, 255);
                if ($t === '' || in_array(mb_strtolower($t), $have, true)) continue;
                db_insert('INSERT INTO brand_topics (user_id, title, source) VALUES (?,?,"ai")', [$uid, $t]);
                $have[] = mb_strtolower($t);
            }
        }
        bk_update_completeness($uid);
        api_ok(['state' => bkapi_state($uid)]);

    case 'confirm':
        // العميل أكّد قسم كان الـ AI مش متأكد منه
        $sec = api_str('section', 40);
        $kit = bk_get($uid);
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $stored = bk_arr($row['data'] ?? '{}');
        unset($stored['confidence'][$sec]);
        db_run('UPDATE brand_kits SET data = ? WHERE id = ?', [bk_json($stored), $kit['id']]);
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ صفحة البوست: chips الهوية ══════════ */
    case 'post_chips':
        $c = api_own('contents', api_int('content_id'), $uid);
        $brief = bk_arr($c['brief_json'] ?? '');
        $kit = bk_get($uid);
        $tones = db_all('SELECT name FROM style_presets WHERE kind = "tone" AND is_active = 1 ORDER BY sort_order, id');
        api_ok([
            'brief' => $brief ?: null, 'overrides' => bk_arr($c['brand_overrides'] ?? '') ?: new \stdClass(),
            'reasons' => bk_arr($c['ai_reasoning'] ?? ''), 'snapshot' => bk_arr($c['brand_snapshot'] ?? '') ?: null,
            'feedback' => $c['feedback'] !== null ? (int) $c['feedback'] : null,
            'options' => [
                'services' => array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name']], bk_services($uid, true)),
                'topics' => array_map(fn($t) => ['id' => (int) $t['id'], 'title' => $t['title'], 'service_id' => $t['service_id'] !== null ? (int) $t['service_id'] : null], bk_topics($uid)),
                'characters' => array_map(fn($ch) => ['id' => (int) $ch['id'], 'name' => $ch['name'], 'ready' => $ch['ready']], bk_characters($uid, true)),
                'formats' => bk_format_labels(), 'tones' => array_column($tones, 'name'),
                'lengths' => ['short' => 'قصير', 'medium' => 'متوسط', 'long' => 'طويل'],
                'goals' => ['bookings' => 'حجوزات', 'sales' => 'مبيعات', 'reach' => 'انتشار', 'trust' => 'ثقة', 'engagement' => 'تفاعل'],
            ],
            'current' => ['tone' => '', 'length' => $kit['data']['content']['caption_length'], 'goal' => $kit['data']['content']['goal']],
        ]);

    /* تغيير chip = للبوست ده بس (brand_overrides) — مابيأثرش على الهوية ولا على بوستات تانية */
    case 'post_override':
        $c = api_own('contents', api_int('content_id'), $uid);
        $key = api_str('key', 20);
        $map = ['service' => 'service_id', 'topic' => 'topic_id', 'character' => 'character_id', 'format' => 'format', 'tone' => 'tone', 'length' => 'caption_length', 'goal' => 'goal'];
        if (!isset($map[$key])) api_fail('مش معروف', 'invalid', 422);
        $val = (string) (api_input()['value'] ?? '');
        $o = bk_arr($c['brand_overrides'] ?? '');
        $k = $map[$key];
        if ($val === '') {
            unset($o[$k]);
        } elseif (in_array($k, ['service_id', 'topic_id', 'character_id'], true)) {
            $o[$k] = (int) $val;
            if ($k === 'topic_id') unset($o['topic']);
        } else {
            $o[$k] = mb_substr($val, 0, 60);
        }
        $vio = $k === 'tone' ? BrandContext::violations($val, $uid) : [];
        db_run('UPDATE contents SET brand_overrides = ? WHERE id = ?', [$o ? bk_json($o) : null, $c['id']]);
        bk_feedback($uid, (int) $c['id'], 'chip', $key === 'length' ? 'caption_length' : $key, null, $val);
        api_ok(['overrides' => $o ?: new \stdClass(), 'warning' => $vio ? 'فيه كلمات ممنوعة في هويتك — مش هتظهر في البوست: ' . implode('، ', $vio) : null]);

    case 'post_feedback':
        $c = api_own('contents', api_int('content_id'), $uid);
        $v = api_int('value', 0, -1, 1);
        db_run('UPDATE contents SET feedback = ? WHERE id = ?', [$v === 0 ? null : $v, $c['id']]);
        if ($v !== 0) bk_feedback($uid, (int) $c['id'], $v > 0 ? 'like' : 'dislike', 'post', null, mb_substr((string) $c['generated_text'], 0, 1200));
        api_ok(['feedback' => $v]);

    /* المستند يدخل في المحتوى ولا لأ */
    case 'source_toggle':
        $bp = brand_for_user($uid);
        $s = $bp ? db_one('SELECT id FROM brand_sources WHERE id = ? AND brand_profile_id = ?', [api_int('id'), $bp['id']]) : null;
        if (!$s) api_fail('المستند مش موجود', 'not_found', 404);
        db_run('UPDATE brand_sources SET use_in_prompts = 1 - use_in_prompts WHERE id = ?', [$s['id']]);
        api_ok(['sources' => bkapi_sources($uid)]);

    /* تأكيد القطاع (كان متعرّف تلقائي) + المجال */
    case 'sector_confirm':
        $kit = bk_get($uid);
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $stored = bk_arr($row['data'] ?? '{}');
        $stored['sector_confirmed'] = 1;
        db_run('UPDATE brand_kits SET data = ? WHERE id = ?', [bk_json($stored), $kit['id']]);
        api_ok(['state' => bkapi_state($uid)]);

    /*
     * الشخصية: «ابنيها بالـ AI» — صورة مرجعية بتبقى أساس الشخصية (أو نسخة جديدة بنفس الملامح من الصورة الأساسية)
     * mode=new: من الوصف · mode=variation: نفس الشخصية في وضع/زاوية تانية
     */
    case 'character_generate':
        $c = bkapi_own_character($uid, api_int('id'));
        if ($c['type'] === 'real_person') api_fail('الشخص الحقيقي صوره بتترفع بس — مش بتتولّد', 'invalid', 422);
        if ((int) (db_one('SELECT COUNT(*) n FROM brand_character_images WHERE character_id = ?', [$c['id']])['n'] ?? 0) >= 8) api_fail('أقصى حاجة 8 صور — امسح صورة الأول', 'limit', 422);
        if (!rate_limit('bk_char_gen', 'u' . $uid, 6, 900)) api_fail('ولّدت صور كتير بسرعة — استنى شوية', 'rate_limit', 429);
        $mode = api_str('mode', 12) === 'variation' ? 'variation' : 'new';
        $look = api_str('look', 600);
        $preset = api_int('style_preset_id') ? db_one('SELECT data FROM style_presets WHERE id = ? AND kind = "character_style" AND is_active = 1', [api_int('style_preset_id')]) : null;
        $styleEn = $preset ? (string) (bk_arr($preset['data'])['en'] ?? '') : '';
        $desc = trim((string) $c['description'] . ($look !== '' ? "\n" . $look : ''));
        if ($mode === 'new' && mb_strlen($desc) < 8) api_fail('اوصف شكل الشخصية الأول (الملامح واللبس)', 'invalid', 422);
        $kit = bk_get($uid);
        $colors = array_filter([$kit['data']['colors']['primary'], $kit['data']['colors']['secondary']]);
        $refs = [];
        if ($mode === 'variation') {
            $pri = db_one('SELECT image_path FROM brand_character_images WHERE character_id = ? ORDER BY is_primary DESC, id LIMIT 1', [$c['id']]);
            $uri = $pri ? image_data_uri($pri['image_path']) : null;
            if (!$uri) api_fail('ارفع أو ولّد الصورة الأساسية الأول', 'invalid', 422);
            $refs[] = $uri;
        }
        $prompt = ($mode === 'variation'
                ? "Create a new image of the EXACT SAME character shown in the attached reference image — identical face, features, hairstyle, proportions and outfit. Only change the pose/angle/expression: " . ($look !== '' ? $look : 'a friendly three-quarter view, natural pose') . ".\n"
                : "Create a clean CHARACTER REFERENCE image for a brand's recurring " . ($c['type'] === 'mascot' ? 'MASCOT' : 'CHARACTER') . ".\n"
                  . 'Character: ' . $c['name'] . ($c['role'] ? ' — ' . $c['role'] : '') . "\n"
                  . 'Look (follow precisely): ' . $desc . "\n")
            . ($styleEn !== '' ? 'Style: ' . $styleEn . "\n" : ($c['type'] === 'mascot' ? "Style: friendly cartoon mascot, simple shapes\n" : "Style: realistic, natural\n"))
            . ($colors ? 'Brand colors (use subtly in clothing/accessories): ' . implode(', ', $colors) . "\n" : '')
            . "Full body or waist-up, front-facing, plain light neutral background, even soft lighting, no text, no logo, no watermark. "
            . "This image will be the base reference to keep the character identical across future posts.";
        $cost = bkapi_char_cost();
        bkapi_charge($uid, $cost, 'صورة للشخصية «' . mb_substr($c['name'], 0, 40) . '»');
        require_once __DIR__ . '/../../includes/ai.php';
        if (function_exists('smart_ai_enabled') && smart_ai_enabled() && !$refs) {
            $res = smart_ai_image($prompt, ['user_id' => $uid, 'reference_type' => 'brand_character', 'reference_id' => (int) $c['id']]);
        } else {
            ai_log_set_context(['kind' => 'design', 'user_id' => $uid, 'reference_type' => 'brand_character', 'reference_id' => (int) $c['id']]);
            $res = ai_generate_image($prompt, $refs);
        }
        if (empty($res['ok']) || empty($res['path'])) {
            bkapi_refund($uid, $cost, 'صورة الشخصية');
            api_fail(($res['error'] ?? '') ?: 'تعذّر توليد الصورة — الكريدت رجعلك', 'ai_failed', 502);
        }
        $first = !db_one('SELECT id FROM brand_character_images WHERE character_id = ? LIMIT 1', [$c['id']]);
        db_insert('INSERT INTO brand_character_images (character_id, image_path, is_primary) VALUES (?,?,?)', [$c['id'], $res['path'], $first ? 1 : 0]);
        if ($look !== '' && $mode === 'new' && trim((string) $c['description']) === '') db_run('UPDATE brand_characters SET description = ? WHERE id = ?', [mb_substr($look, 0, 1500), $c['id']]);
        if (function_exists('ai_log_usage')) ai_log_usage($uid, 'design', $res['model'] ?? '', [], (int) $c['id']);
        api_ok(['state' => bkapi_state($uid), 'balance' => credits_balance($uid)]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
