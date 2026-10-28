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
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/prompt-library.php';   // البرومبتات من الأدمن (مكتبة v1 بالمتغيرات)
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) require_once __DIR__ . '/../../includes/smart-ai.php';

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
        'costs' => ['ai' => cost_for_bk('brand_kit_ai_cost', 1), 'draft' => cost_for_bk('brand_kit_draft_cost', 2), 'character' => bkapi_char_cost(), 'logo' => bkapi_logo_cost(), 'persona' => max(0, (int) get_setting('brand_kit_persona_cost', '1')),
                    'visual' => max(0, (int) get_setting('brand_visual_cost', '1')), 'insights' => max(0, (int) get_setting('brand_insights_cost', '1'))],
        'personas' => bk_personas($uid, false), 'personas_max' => bk_personas_max(), 'personas_default' => bk_personas_default(),
        'cta_types' => bk_cta_types(),
        'visual' => bkapi_kit_meta($uid, '_visual'), 'insights' => bkapi_kit_meta($uid, '_insights'),
        'logo_candidates' => array_map(fn($p) => ['path' => $p, 'url' => upload_url($p)], bkapi_logo_candidates($uid)),
        'research' => function_exists('ui_research_on') ? ui_research_on() : get_setting('research_enabled', '1') === '1',
        'analyze_cost' => max(0, (int) get_setting('brand_analyze_cost', 1)),
        'labels' => ['formats' => bk_format_labels(), 'hooks' => bk_hook_types()],
        'files' => (int) (db_one('SELECT COUNT(*) n FROM brand_sources s JOIN brand_profiles b ON b.id = s.brand_profile_id WHERE b.user_id = ?', [$uid])['n'] ?? 0),
        // خطوط التصميمات المتاحة (اللي الأدمن بيرفعها بتظهر هنا أوتوماتيك)
        'design_fonts' => (function () { require_once __DIR__ . '/../../includes/ce-overlay.php'; return array_map(fn($f) => ['family' => $f['family'], 'url' => $f['url']], ce_font_families()); })(),
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

const BKAPI_DRAFT_SECTIONS = ['business', 'audience', 'personality', 'voice', 'pillars', 'content', 'visuals', 'colors', 'services', 'topics'];

/** مسودة هوية كاملة بالـ AI من كل اللي نعرفه (بيانات البراند + اقتراحات اللينك + المستندات + الإجابات) — بتخصم وبترجّع لو فشلت */
function bkapi_build_draft(int $uid, array $answersIn = [], bool $soft = false): array
{
    $kit = bk_get($uid);
    $bp = bk_brand_profile($uid) ?: [];
    $sector = bk_sector($kit['sector_template_id']);
    $answers = [];
    foreach ($answersIn as $q => $a) {
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
    if (!$facts && !$answers && $knowledge === '') {
        if ($soft) return ['__error' => 'محتاجين لينك أو ملف أو اسم النشاط على الأقل'];
        api_fail('ابدأ بلينك صفحتك أو جاوب على الأسئلة الأول', 'empty', 422);
    }
    $cost = cost_for_bk('brand_kit_draft_cost', 2);
    if ($soft && $cost > 0 && credits_balance($uid) < $cost) return ['__error' => credits_short_msg($cost)];
    bkapi_charge($uid, $cost, 'مسودة الهوية (Brand Kit)');
    // برومبت «Agent الهوية» الكامل (v1): البيانات والإجابات والمستندات في متغيراته — والكود بيحط المهمة وشكل الـ JSON بس
    $full = function_exists('prompt_full') && prompt_full('agent_brand');
    $admin = $full
        ? BrandContext::agentPrompt('brand', $uid, ['brand_identity' => implode("\n", $facts) ?: 'لسه مفيش', 'brief_answers' => implode("\n", $answers) ?: 'مفيش',
            'documents' => $knowledge !== '' ? $knowledge : 'مفيش'], false)
        : BrandContext::agentPrompt('brand');
    $j = bkapi_ai($uid, ($admin !== '' ? $admin . ($full ? prompt_format_head() : "\n\n") : '')
        . "انت خبير هوية تجارية وتسويق محتوى. ابني مسودة Brand Kit من المعلومات دي.\n"
        . ($sector ? "قالب القطاع: {$sector['name']} — اتجاهات افتراضية: " . implode('، ', array_map(fn($p) => $p['name'] . ' ' . $p['percent'] . '%', $sector['default_pillars'])) . "\n" : '')
        . (!$full && $facts ? "بيانات البراند:\n" . implode("\n", $facts) . "\n" : '')
        . (!$full && $answers ? "إجابات العميل:\n" . implode("\n", $answers) . "\n" : '')
        . (!$full && $knowledge !== '' ? "من مستندات العميل وصفحاته:\n" . $knowledge . "\n" : '')
        . "\nالقواعد: ماتخترعش حقائق (أسعار، عناوين، أرقام). الحاجة اللي مش واضحة حط لها confidence أقل من 0.7.\n"
        . "الألوان: لو في ألوان في بيانات البراند استخدمها، غير كده اقترح 3 ألوان hex مناسبة للمجال والشخصية (متناسقة ومقروءة).\n"
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
        . '"colors":{"primary":"#","secondary":"#","accent":"#"},'
        . '"confidence":{"business":0.9,"audience":0.6,"personality":0.6,"voice":0.6,"pillars":0.7,"content":0.6,"visuals":0.5,"services":0.8}}'
        . "\nقيم goal: bookings|sales|reach|trust|engagement · hooks: question|story|number|problem|myth|offer|tip · kind: photo|3d|flat|mixed · light: bright|warm|dramatic · people: egyptian|arab|characters_only|none", 2600);
    if (isset($j['__error']) || empty($j['business'])) {
        bkapi_refund($uid, $cost, 'مسودة الهوية');
        $err = $j['__error'] ?? 'الرد مكانش مظبوط — الكريدت رجعلك، جرّب تاني';
        if ($soft) return ['__error' => $err];
        api_fail($err, 'ai_failed', 502);
    }
    $draft = [];
    foreach (['business', 'audience', 'personality', 'voice', 'pillars', 'content', 'visuals', 'colors'] as $sec) {
        if (isset($j[$sec])) $draft[$sec] = bk_clean_section($sec, $j[$sec]);
    }
    $draft['services'] = array_values(array_filter(array_map(fn($s) => is_array($s) ? ['name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 150),
        'description' => mb_substr(trim((string) ($s['description'] ?? '')), 0, 500), 'priority' => in_array($s['priority'] ?? '', ['high', 'medium', 'low'], true) ? $s['priority'] : 'medium'] : null,
        array_slice((array) ($j['services'] ?? []), 0, 12)), fn($s) => $s && $s['name'] !== ''));
    $draft['topics'] = array_values(array_slice(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 255), (array) ($j['topics'] ?? []))), 0, 15));
    $conf = [];
    foreach ((array) ($j['confidence'] ?? []) as $k => $v) if (is_numeric($v)) $conf[$k] = max(0, min(1, (float) $v));
    return ['draft' => $draft, 'confidence' => $conf,
            'facts' => array_map(fn($f) => ['id' => (int) $f['id'], 'field' => $f['field'], 'label' => brand_fields()[$f['field']][0] ?? $f['field'],
                'value' => mb_substr((string) $f['value'], 0, 300), 'source' => brand_source_labels()[$f['source']][0] ?? '', 'confidence' => (int) $f['confidence']], $pendingFacts)];
}

/** حفظ أقسام من المسودة (نسخة واحدة) + الخدمات والمواضيع من غير تكرار */
function bkapi_apply_draft(int $uid, array $draft, array $sections, array $unsure, string $note): void
{
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
    bk_write($kit['id'], $uid, $stored, 'ai', $note);
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
}

/** العميل الجديد ممكن ميكونش عنده brand_profile لسه */
function bkapi_profile(int $uid): array
{
    $bp = brand_for_user($uid);
    if (!$bp) {
        db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, "")', [$uid]);
        $bp = brand_for_user($uid);
    }
    return $bp;
}

/** اقتراحات اللينك/الملف ← الحقول الفاضية بتتملى على طول (أعلى ثقة لكل حقل) · المليانة بتفضل اقتراح يراجعه العميل */
function bkapi_fill_empty(int $uid): array
{
    $bp = brand_for_user($uid);
    if (!$bp) return [];
    $done = [];
    foreach (db_all('SELECT id, field FROM brand_facts WHERE brand_profile_id = ? AND status = "suggested" ORDER BY confidence DESC, id DESC', [$bp['id']]) as $f) {
        if (isset($done[$f['field']]) || trim((string) ($bp[$f['field']] ?? '')) !== '') continue;
        $r = brand_fact_apply((int) $f['id'], $uid, null, false);
        if ($r['ok']) $done[$f['field']] = brand_fields()[$f['field']][0] ?? $f['field'];
    }
    if ($done) brand_kit_notify($uid, array_keys($done), 'ai', 'اتملت تلقائيًا من اللينك/الملف: ' . implode('، ', $done));
    return array_values($done);
}

/** ألوان من صور العميل (اللوجو + تصميماته السابقة + التصميمات اللي عاجباه) — من غير AI */
function bkapi_colors_from_images(int $uid, array $extra = []): array
{
    $paths = $extra;
    $bp = brand_for_user($uid);
    if ($bp && !empty($bp['logo_path'])) $paths[] = $bp['logo_path'];
    $q = function (string $sql, array $p) use (&$paths) {
        try { foreach (db_all($sql, $p) as $r) $paths[] = $r['image_path']; } catch (\Throwable $e) {}
    };
    $q('SELECT image_path FROM content_designs WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$uid]);
    $q('SELECT image_path FROM studio_designs WHERE user_id = ? AND image_path IS NOT NULL ORDER BY id DESC LIMIT 6', [$uid]);
    if ($bp) $q('SELECT image_path FROM brand_inspirations WHERE brand_profile_id = ? AND image_path IS NOT NULL ORDER BY id DESC LIMIT 6', [$bp['id']]);
    $base = realpath(STORAGE_PATH);
    $votes = [];
    $n = 0;
    foreach (array_unique(array_filter(array_map('strval', $paths))) as $rel) {
        $abs = realpath(STORAGE_PATH . '/' . ltrim($rel, '/'));
        if (!$abs || !$base || strpos($abs, $base) !== 0) continue;
        $cols = brand_logo_colors($abs, 4);
        if (!$cols) continue;
        $n++;
        foreach ($cols as $i => $hex) {
            $rgb = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
            $hit = false;
            foreach ($votes as &$v) {
                if (sqrt(($v[0][0] - $rgb[0]) ** 2 + ($v[0][1] - $rgb[1]) ** 2 + ($v[0][2] - $rgb[2]) ** 2) < 60) { $v[1] += 4 - $i; $hit = true; break; }
            }
            unset($v);
            if (!$hit) $votes[] = [$rgb, 4 - $i];
        }
    }
    if (!$votes) return ['images' => $n, 'palette' => null];
    usort($votes, fn($a, $b) => $b[1] <=> $a[1]);
    $sat = fn(array $c) => (max($c) - min($c)) / 255;
    $hex = fn(array $c) => sprintf('#%02X%02X%02X', $c[0], $c[1], $c[2]);
    $vivid = array_values(array_filter($votes, fn($v) => $sat($v[0]) >= 0.18));
    $dull = array_values(array_filter($votes, fn($v) => $sat($v[0]) < 0.18));
    $pick = array_merge($vivid, $dull);
    return ['images' => $n, 'palette' => [
        'name' => 'من تصميماتك', 'why' => 'الألوان اللي بتتكرر في اللوجو وتصميماتك',
        'primary' => $hex($pick[0][0]), 'secondary' => isset($pick[1]) ? $hex($pick[1][0]) : '',
        'accent' => isset($pick[2]) ? $hex($pick[2][0]) : '', 'neutral' => $dull ? $hex($dull[0][0]) : '',
    ]];
}

/** 3 باليتات مقترحة بالـ AI حسب النشاط والشخصية */
function bkapi_ai_palettes(int $uid, ?array $fromDesigns): array
{
    $d = bk_get($uid)['data'];
    $j = bkapi_ai($uid, "انت مصمم هوية بصرية. اقترح 3 باليتات ألوان مختلفة لبراند «{$d['business']['name']}» في مجال «{$d['business']['sector']}».\n"
        . ($d['personality'] ? 'شخصية البراند: ' . implode('، ', $d['personality']) . "\n" : '')
        . ($d['audience']['description'] !== '' ? 'الجمهور: ' . mb_substr($d['audience']['description'], 0, 300) . "\n" : '')
        . ($fromDesigns ? 'ألوان موجودة في تصميماته: ' . implode(', ', array_filter([$fromDesigns['primary'], $fromDesigns['secondary'], $fromDesigns['accent']])) . " — خلّي باليتة منهم قريبة منها.\n" : '')
        . "كل باليتة: primary و secondary و accent و neutral (hex)، متناسقة ومقروءة على الموبايل، واسم قصير وسبب في جملة بالعامية المصرية.\n"
        . 'رجّع JSON بس: {"palettes":[{"name":"","why":"","primary":"#","secondary":"#","accent":"#","neutral":"#"}]}', 900);
    $ok = fn($c) => is_string($c) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c);
    $out = [];
    foreach (array_slice((array) ($j['palettes'] ?? []), 0, 3) as $p) {
        if (!is_array($p) || !$ok($p['primary'] ?? null)) continue;
        $out[] = ['name' => mb_substr(trim((string) ($p['name'] ?? '')), 0, 40) ?: 'باليتة', 'why' => mb_substr(trim((string) ($p['why'] ?? '')), 0, 160),
                  'primary' => strtoupper($p['primary']), 'secondary' => $ok($p['secondary'] ?? null) ? strtoupper($p['secondary']) : '',
                  'accent' => $ok($p['accent'] ?? null) ? strtoupper($p['accent']) : '', 'neutral' => $ok($p['neutral'] ?? null) ? strtoupper($p['neutral']) : ''];
    }
    return $out;
}

/** «كمّل بالـ AI»: الأقسام اللي العميل ماحفظهاش بس — وأي حاجة كتبها العميل ماتتغيّرش */
function bkapi_apply_draft_empty(int $uid, array $draft, array $conf): array
{
    $kit = bk_get($uid);
    $stored = bk_arr(db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']])['data'] ?? '{}');
    $secs = [];
    $labels = [];
    foreach (['business', 'audience', 'personality', 'voice', 'pillars', 'content', 'visuals', 'colors'] as $sec) {
        if (!isset($draft[$sec])) continue;
        if (in_array($sec, ['business', 'audience'], true)) {
            // الموجود يفضل زي ما هو — بنملا الفاضي بس
            $merged = $kit['data'][$sec];
            $ch = false;
            foreach ((array) $draft[$sec] as $k => $v) {
                $empty = !isset($merged[$k]) || $merged[$k] === '' || $merged[$k] === [] || $merged[$k] === null;
                if ($empty && $v !== '' && $v !== [] && $v !== null) { $merged[$k] = $v; $ch = true; }
            }
            if (!$ch) continue;
            $draft[$sec] = $merged;
        } elseif (isset($stored[$sec]) || ($sec === 'colors' && $kit['data']['colors']['primary'] !== '')) {
            continue;
        }
        $secs[] = $sec;
        $labels[] = bk_section_label($sec);
    }
    if ((int) (db_one('SELECT COUNT(*) n FROM brand_services WHERE user_id = ?', [$uid])['n'] ?? 0) === 0 && !empty($draft['services'])) { $secs[] = 'services'; $labels[] = 'الخدمات'; }
    if ((int) (db_one('SELECT COUNT(*) n FROM brand_topics WHERE user_id = ? AND is_active = 1', [$uid])['n'] ?? 0) < 3 && !empty($draft['topics'])) { $secs[] = 'topics'; $labels[] = 'المواضيع'; }
    if (!$secs) return [];
    // اللي اتبنى بالـ AI بيتعلّم بإطار أصفر «راجعه» — إلا اللي الـ AI متأكد منه
    $unsure = array_values(array_filter($secs, fn($s) => in_array($s, BK_SECTIONS, true) && ($conf[$s] ?? 0.5) < 0.8));
    bkapi_apply_draft($uid, $draft, $secs, $unsure, 'الـ AI كمّل الهوية: ' . implode('، ', $labels));
    return $labels;
}

/** رابط/ملف «ابدأ هنا» ← اقتراحات (بيخصم تكلفة التحليل وبيرجّعها لو مطلعش حاجة) */
function bkapi_read_source(int $uid, array $bp, string $url, ?array $file): array
{
    $cost = max(0, (int) get_setting('brand_analyze_cost', 1));
    $charge = function () use ($uid, $cost) {
        if ($cost > 0 && (credits_balance($uid) < $cost || !credits_consume($uid, $cost, 'تحليل براند (ابدأ هنا)', 'brand_analyze', null))) api_fail(credits_short_msg($cost), 'credits', 402);
    };
    $refund = fn() => $cost > 0 ? credits_add($uid, $cost, 'استرداد — التحليل ما طلعش معلومات', null, 'refund', null) : null;
    if ($url !== '') {
        if (get_setting('brand_analyze_enabled', '1') !== '1') api_fail('التحليل متوقف مؤقتًا', 'disabled', 403);
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        $v = safe_http_validate($url);
        if (!$v['ok']) api_fail($v['error'], 'bad_url', 422);
        $charge();
        $r = brand_analyze_url($bp, $uid, $url);
        if (!$r['ok'] || $r['suggested'] === 0) $refund();
        $social = in_array($r['type'], ['facebook', 'instagram', 'tiktok', 'linkedin'], true) && !$r['ai'];
        return ['read' => $r['ok'] ? 'قرينا اللينك' : '', 'warn' => !$r['ok'] ? ($r['error'] ?: 'تعذّر قراءة اللينك')
            : ($social ? 'صفحات السوشيال مقفولة قدام الزوار — سجّلنا اللينك، وكمّلنا الباقي بالـ AI. لنتيجة أدق ضيف موقعك أو ارفع ملف.' : null), 'images' => []];
    }
    if (!$file || empty($file['name'])) return ['read' => '', 'warn' => null, 'images' => []];
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        // صورة = تصميم/لوجو/صورة للبراند ← بتتحفظ في «تصميمات عاجباك» وبناخد منها الألوان
        if (brand_insp_count((int) $bp['id']) >= brand_insp_max()) api_fail('«تصميمات عاجباك» مليانة — امسح صورة الأول', 'limit', 422);
        $r = brand_insp_add_upload($bp, $uid, $file, 'من «ابدأ هنا»');
        if (!$r['ok']) api_fail($r['error'], 'invalid', 422);
        $last = db_one('SELECT image_path FROM brand_inspirations WHERE brand_profile_id = ? ORDER BY id DESC LIMIT 1', [$bp['id']]);
        return ['read' => 'الصورة اتحفظت في «تصميمات عاجباك»', 'warn' => null, 'images' => $last ? [$last['image_path']] : []];
    }
    $types = ['pdf' => 'pdf', 'docx' => 'docx', 'txt' => 'txt'];
    if (!isset($types[$ext])) api_fail('النوع ده مش مدعوم — ارفع PDF أو Word أو TXT أو صورة', 'invalid', 422);
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || (int) $file['size'] > 20 * 1024 * 1024) api_fail('فشل الرفع أو الملف أكبر من 20 ميجا', 'invalid', 422);
    if ((int) (db_one('SELECT COUNT(*) n FROM brand_sources WHERE brand_profile_id = ?', [$bp['id']])['n'] ?? 0) >= 15) api_fail('عندك 15 مصدر — امسح مصدر قديم الأول', 'limit', 422);
    $dir = UPLOADS_PATH . '/sources';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safe = 'src_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $safe)) api_fail('فشل حفظ الملف', 'invalid', 500);
    $title = mb_substr(pathinfo((string) $file['name'], PATHINFO_FILENAME), 0, 180);
    $id = db_insert('INSERT INTO brand_sources (brand_profile_id, user_id, type, title, file_path) VALUES (?,?,?,?,?)', [$bp['id'], $uid, $types[$ext], $title, 'uploads/sources/' . $safe]);
    $ex = source_extract($id);
    if (!$ex['ok']) return ['read' => 'الملف اتحفظ', 'warn' => 'معرفناش نقرا نص من الملف' . ($ext === 'pdf' ? ' (ممكن يكون صورة ممسوحة)' : '') . ' — كمّلنا بالـ AI من اللي عندنا', 'images' => []];
    $charge();
    $txt = (string) (db_one('SELECT raw_text FROM brand_sources WHERE id = ?', [$id])['raw_text'] ?? '');
    $ai = brand_ai_extract($txt, $uid, 'ملف «' . $title . '»');
    $n = 0;
    if ($ai['ok']) foreach ($ai['facts'] as $field => $value) if (brand_fact_suggest($bp, $uid, $field, $value, 'file', $title, 75)) $n++;
    if (!$n) $refund();
    return ['read' => 'قرينا الملف', 'warn' => $ai['ok'] ? null : ($ai['error'] ?? null), 'images' => []];
}

/** اقتراحات اللوجو (لحد ما العميل يختار واحد) */
function bkapi_logo_candidates(int $uid): array
{
    $d = bk_arr(db_one('SELECT data FROM brand_kits WHERE user_id = ?', [$uid])['data'] ?? '{}');
    return array_values(array_filter((array) ($d['_logo_candidates'] ?? []), 'is_string'));
}

function bkapi_logo_cost(): int
{
    return max(0, (int) get_setting('logo_generate_cost', 3));
}

/** العملاء المثاليين بالـ AI — مربوطين بخدمات العميل · $soft = مايوقفش الطلب لو فشل */
function bkapi_personas_generate(int $uid, int $n, bool $soft = false): array
{
    require_once __DIR__ . '/../../includes/prompt-library.php';
    $n = max(1, min(bk_personas_max(), $n));
    $kit = bk_get($uid);
    $d = $kit['data'];
    $svc = bk_services($uid, true);
    if ($d['business']['name'] === '' && !$svc) {
        if ($soft) return ['__error' => 'محتاجين اسم النشاط أو الخدمات الأول'];
        api_fail('اكتب اسم النشاط وخدماتك الأول', 'invalid', 422);
    }
    $cost = max(0, (int) get_setting('brand_kit_persona_cost', '1'));
    if ($soft && $cost > 0 && credits_balance($uid) < $cost) return ['__error' => credits_short_msg($cost)];
    bkapi_charge($uid, $cost, 'العملاء المثاليين (Buyer Personas)');
    $data = "النشاط: {$d['business']['name']}" . ($d['business']['sector'] !== '' ? " — المجال: {$d['business']['sector']}" : '') . "\n"
        . ($d['business']['location'] !== '' ? "المكان: {$d['business']['location']}\n" : '')
        . ($d['business']['usp'] !== '' ? "اللي بيميزه: {$d['business']['usp']}\n" : '')
        . ($d['audience']['description'] !== '' ? "الجمهور: {$d['audience']['description']}\n" : '')
        . ($d['audience']['pains'] ? 'مشاكل الجمهور: ' . implode('، ', $d['audience']['pains']) . "\n" : '')
        . ($svc ? "الخدمات (اختار منها بالاسم بالظبط):\n- " . implode("\n- ", array_map(fn($s) => $s['name'] . ($s['description'] ? ' — ' . mb_substr((string) $s['description'], 0, 120) : ''), array_slice($svc, 0, 15))) . "\n" : '')
        . ($d['offers'] ? 'عروض: ' . implode('، ', array_column($d['offers'], 'title')) . "\n" : '');
    $pHead = prompt_full('agent_personas')
        ? prompt_fill('agent_personas', ['n' => $n, 'count' => $n, 'brand_identity' => $data], ['uid' => $uid, 'agent' => 'brand'])
          . prompt_format_head() . "مطلوب {$n} أشخاص مختلفين فعلًا. services = أسماء خدمات من القايمة فوق بالظبط."
        : prompt_fill('agent_personas', ['n' => $n, 'data' => $data]);
    $j = bkapi_ai($uid, $pHead
        . "\n\nرجّع JSON بس بالشكل ده (بالظبط {$n} أشخاص):\n"
        . '{"personas":[{"name":"اسم وصفي قصير مثلا «أم مشغولة»","emoji":"👩","age":"30-40","gender":"","job":"","location":"","pains":["",""],"desires":["",""],"objections":["",""],"triggers":["",""],"channels":["facebook","instagram"],"services":["اسم الخدمة"],"voice":"إزاي نكلمه"}]}', 2200);
    $list = array_slice(array_filter((array) ($j['personas'] ?? []), 'is_array'), 0, $n);
    if (!$list) {
        bkapi_refund($uid, $cost, 'العملاء المثاليين');
        $err = $j['__error'] ?? 'الرد مكانش مظبوط — الكريدت رجعلك، جرّب تاني';
        if ($soft) return ['__error' => $err];
        api_fail($err, 'ai_failed', 502);
    }
    $byName = [];
    foreach ($svc as $s) $byName[mb_strtolower(trim($s['name']))] = (int) $s['id'];
    $saved = 0;
    foreach (array_values($list) as $i => $p) {
        $ids = [];
        foreach ((array) ($p['services'] ?? []) as $nm) {
            $k = mb_strtolower(trim((string) $nm));
            if (isset($byName[$k])) { $ids[] = $byName[$k]; continue; }
            foreach ($byName as $sn => $sid) if ($k !== '' && (str_contains($sn, $k) || str_contains($k, $sn))) { $ids[] = $sid; break; }
        }
        // كل شخص مربوط بخدمة على الأقل (لو العميل عنده خدمات)
        if (!$ids && $svc) $ids[] = (int) $svc[$i % count($svc)]['id'];
        $p['service_ids'] = array_values(array_unique($ids));
        bk_persona_save($uid, $p, 0, 'ai');
        $saved++;
    }
    bk_update_completeness($uid);
    return ['saved' => $saved];
}

/** صور العميل للتحليل البصري: اللوجو + صور البراند + تصميمات عاجباه + تصميماته السابقة (data URIs) */
function bkapi_visual_images(int $uid, int $max = 6): array
{
    $bp = brand_for_user($uid);
    $out = [];
    $add = function (?string $rel, string $role) use (&$out, $max) {
        if (!$rel || count($out) >= $max) return;
        $u = image_data_uri($rel);
        if ($u) $out[] = ['uri' => $u, 'role' => $role];
    };
    if ($bp) {
        $add($bp['logo_path'] ?? null, "the brand's LOGO");
        try { foreach (db_all('SELECT image_path FROM brand_images WHERE brand_profile_id = ? AND image_path IS NOT NULL ORDER BY id DESC LIMIT 2', [$bp['id']]) as $r) $add($r['image_path'], 'a brand photo'); } catch (\Throwable $e) {}
        try { foreach (db_all('SELECT image_path FROM brand_inspirations WHERE brand_profile_id = ? AND image_path IS NOT NULL ORDER BY id DESC LIMIT 2', [$bp['id']]) as $r) $add($r['image_path'], 'a design the client LIKES (their taste)'); } catch (\Throwable $e) {}
    }
    try { foreach (db_all('SELECT image_path FROM content_designs WHERE user_id = ? ORDER BY id DESC LIMIT 2', [$uid]) as $r) $add($r['image_path'], 'a previous design made for this brand'); } catch (\Throwable $e) {}
    return $out;
}

/** بيانات خام صغيرة في الـ Kit (مش نسخة): التحليل البصري وتحليل المحتوى */
function bkapi_kit_meta(int $uid, string $key, $value = null)
{
    $kit = bk_get($uid);
    $stored = bk_arr(db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']])['data'] ?? '{}');
    if (func_num_args() < 3) return $stored[$key] ?? null;
    $stored[$key] = $value;
    db_run('UPDATE brand_kits SET data = ? WHERE id = ?', [bk_json($stored), $kit['id']]);
    return $value;
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
        $r = bkapi_build_draft($uid, (array) ($in['answers'] ?? []));
        api_ok($r + ['balance' => credits_balance($uid)]);

    /* مراجعة المسودة ← الحفظ (الأقسام اللي العميل أكدها) */
    case 'apply_draft':
        $in = api_input();
        $draft = (array) ($in['draft'] ?? []);
        $sections = array_values(array_intersect((array) ($in['sections'] ?? []), BKAPI_DRAFT_SECTIONS));
        $unsure = array_values(array_intersect((array) ($in['unsure'] ?? []), BK_SECTIONS));
        bkapi_apply_draft($uid, $draft, $sections, $unsure, 'مسودة الهوية من الـ AI');
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
        if ($v !== 0 && is_file(__DIR__ . '/../../includes/EventTracker.php')) {
            require_once __DIR__ . '/../../includes/EventTracker.php';
            EventTracker::track('post_rated', 'content', (int) $c['id'], ['rating' => $v], $uid);
        }
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
        $mode = in_array(api_str('mode', 12), ['variation', 'photo'], true) ? api_str('mode', 12) : 'new';
        $look = api_str('look', 600);
        $preset = api_int('style_preset_id') ? db_one('SELECT data FROM style_presets WHERE id = ? AND kind = "character_style" AND is_active = 1', [api_int('style_preset_id')]) : null;
        $styleEn = $preset ? (string) (bk_arr($preset['data'])['en'] ?? '') : '';
        $desc = trim((string) $c['description'] . ($look !== '' ? "\n" . $look : ''));
        $kit = bk_get($uid);
        if ($mode === 'new' && mb_strlen($desc) < 8) {
            // من غير وصف: الـ AI يتخيّل شخصية مناسبة للنشاط وشخصيته
            $bd = $kit['data'];
            $desc = 'Invent a fitting, likeable look for a ' . ($bd['business']['sector'] ?: 'local business') . ' brand'
                . ($bd['personality'] ? ' with a ' . implode(', ', $bd['personality']) . ' personality' : '')
                . ($bd['audience']['description'] !== '' ? '; the audience is: ' . mb_substr($bd['audience']['description'], 0, 200) : '')
                . '. Middle-eastern/Egyptian features if human, modest friendly outfit.';
        }
        $colors = array_filter([$kit['data']['colors']['primary'], $kit['data']['colors']['secondary']]);
        $refs = [];
        $tmpPhoto = null;
        if ($mode === 'photo') {
            if (bkapi_char_cost() > 0 && credits_balance($uid) < bkapi_char_cost()) api_fail(credits_short_msg(bkapi_char_cost()), 'credits', 402);
            // صورة من العميل (شخص · رسمة · منتج) ← الـ AI يحولها لشخصية البراند الثابتة
            if (empty($_FILES['photo']['name'])) api_fail('ارفع الصورة الأول', 'invalid', 422);
            if (api_int('consent') !== 1) api_fail('أكّد إن عندك إذن باستخدام الصورة دي', 'consent', 422);
            $up = upload_image($_FILES['photo'], 'characters');
            if (!$up['ok']) api_fail($up['error'], 'invalid', 422);
            $tmpPhoto = $up['path'];
            $uri = image_data_uri($tmpPhoto);
            if (!$uri) { delete_upload($tmpPhoto); api_fail('معرفناش نقرا الصورة', 'invalid', 422); }
            $refs[] = $uri;
        } elseif ($mode === 'variation') {
            $pri = db_one('SELECT image_path FROM brand_character_images WHERE character_id = ? ORDER BY is_primary DESC, id LIMIT 1', [$c['id']]);
            $uri = $pri ? image_data_uri($pri['image_path']) : null;
            if (!$uri) api_fail('ارفع أو ولّد الصورة الأساسية الأول', 'invalid', 422);
            $refs[] = $uri;
        }
        $prompt = ($mode === 'photo'
                ? "Turn the person/subject in the attached photo into the brand's recurring " . ($c['type'] === 'mascot' ? 'MASCOT' : 'CHARACTER') . ' "' . $c['name'] . '"' . ($c['role'] ? ' — ' . $c['role'] : '') . ".\n"
                  . "Keep the recognizable features (face shape, hair, skin tone, distinctive details) so it is clearly the same subject.\n"
                  . ($desc !== '' ? 'Extra details: ' . $desc . "\n" : '')
                : '')
            . ($mode === 'photo' ? '' : ($mode === 'variation'
                ? "Create a new image of the EXACT SAME character shown in the attached reference image — identical face, features, hairstyle, proportions and outfit. Only change the pose/angle/expression: " . ($look !== '' ? $look : 'a friendly three-quarter view, natural pose') . ".\n"
                : "Create a clean CHARACTER REFERENCE image for a brand's recurring " . ($c['type'] === 'mascot' ? 'MASCOT' : 'CHARACTER') . ".\n"
                  . 'Character: ' . $c['name'] . ($c['role'] ? ' — ' . $c['role'] : '') . "\n"
                  . 'Look (follow precisely): ' . $desc . "\n"))
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
        if ($tmpPhoto) delete_upload($tmpPhoto);   // الصورة الأصلية مرجع بس — مش بتتحفظ
        if (empty($res['ok']) || empty($res['path'])) {
            bkapi_refund($uid, $cost, 'صورة الشخصية');
            api_fail(($res['error'] ?? '') ?: 'تعذّر توليد الصورة — الكريدت رجعلك', 'ai_failed', 502);
        }
        $first = !db_one('SELECT id FROM brand_character_images WHERE character_id = ? LIMIT 1', [$c['id']]);
        db_insert('INSERT INTO brand_character_images (character_id, image_path, is_primary) VALUES (?,?,?)', [$c['id'], $res['path'], $first ? 1 : 0]);
        if ($look !== '' && $mode === 'new' && trim((string) $c['description']) === '') db_run('UPDATE brand_characters SET description = ? WHERE id = ?', [mb_substr($look, 0, 1500), $c['id']]);
        if (function_exists('ai_log_usage')) ai_log_usage($uid, 'design', $res['model'] ?? '', [], (int) $c['id']);
        api_ok(['state' => bkapi_state($uid), 'balance' => credits_balance($uid)]);

    /* ══════════ «ابدأ هنا»: لينك أو ملف ← الـ AI يملا ويكمّل الهوية ══════════ */
    case 'autofill':
        if (!rate_limit('bk_autofill', 'u' . $uid, 4, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        @set_time_limit(200);
        $bp = bkapi_profile($uid);
        $name = api_str('name', 150);
        $named = false;
        if ($name !== '' && trim((string) $bp['business_name']) === '') {
            db_run('UPDATE brand_profiles SET business_name = ?, updated_at = NOW() WHERE id = ?', [$name, $bp['id']]);
            $bp = brand_for_user($uid);
            $named = true;
        }
        $src = bkapi_read_source($uid, $bp, api_str('url', 1000), $_FILES['file'] ?? null);
        $filled = bkapi_fill_empty($uid);
        if ($named) { brand_kit_notify($uid, ['business_name'], 'user', 'اسم النشاط'); array_unshift($filled, 'اسم النشاط'); }
        // الألوان: من اللوجو والتصميمات (مجانًا) لو مفيش ألوان
        if (bk_get($uid)['data']['colors']['primary'] === '') {
            $pc = bkapi_colors_from_images($uid, $src['images']);
            if ($pc['palette']) {
                $p = $pc['palette'];
                bk_save_section($uid, 'colors', array_merge(bk_get($uid)['data']['colors'], array_filter(['primary' => $p['primary'], 'secondary' => $p['secondary'], 'accent' => $p['accent'], 'neutral' => $p['neutral']])), 'ai', 'ألوان من صورك وتصميماتك');
                $filled[] = 'الألوان (من صورك)';
            }
        }
        // كمّل الباقي بالـ AI (الأقسام الفاضية بس)
        $built = [];
        $warn = $src['warn'];
        if (api_str('build', 2) !== '0') {
            BrandContext::flush($uid);
            $d = bkapi_build_draft($uid, [], true);
            if (isset($d['__error'])) $warn = $warn ?: $d['__error'];
            else $built = bkapi_apply_draft_empty($uid, $d['draft'], $d['confidence']);
            // العملاء المثاليين لو مفيش
            if (!isset($d['__error']) && !bk_personas($uid, false)) {
                $pr = bkapi_personas_generate($uid, bk_personas_default(), true);
                if (!empty($pr['saved'])) $built[] = 'العملاء المثاليين (' . $pr['saved'] . ')';
            }
        }
        api_ok(['state' => bkapi_state($uid), 'read' => $src['read'], 'filled' => $filled, 'built' => $built, 'warn' => $warn, 'balance' => credits_balance($uid)]);

    /* ══════════ الألوان: من تصميماتك + اقتراحات AI ══════════ */
    case 'palette_suggest':
        if (!rate_limit('bk_palette', 'u' . $uid, 8, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        $fd = bkapi_colors_from_images($uid);
        $list = [];
        if (api_str('ai', 2) !== '0') {
            $cost = cost_for_bk('brand_kit_ai_cost', 1);
            bkapi_charge($uid, $cost, 'ألوان مقترحة للهوية');
            $list = bkapi_ai_palettes($uid, $fd['palette']);
            if (!$list) bkapi_refund($uid, $cost, 'ألوان مقترحة');
        }
        if ($fd['palette']) array_unshift($list, $fd['palette']);
        if (!$list) api_fail('معرفناش نطلّع ألوان — جرّب تاني', 'ai_failed', 502);
        api_ok(['palettes' => $list, 'images' => $fd['images'], 'balance' => credits_balance($uid)]);

    case 'palette_apply':
        $in = api_input();
        $cur = bk_get($uid)['data']['colors'];
        $new = [];
        foreach (['primary', 'secondary', 'accent', 'neutral'] as $k) {
            $v = (string) ($in[$k] ?? '');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) $new[$k] = strtoupper($v);
        }
        if (empty($new['primary'])) api_fail('اختار باليتة', 'invalid', 422);
        $r = bk_save_section($uid, 'colors', array_merge($cur, $new, ['preset_id' => null]), 'user', 'ألوان جديدة للهوية');
        if (!$r['ok']) api_fail($r['error'], 'invalid', 422);
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ اللوجو: تصميم بالـ AI من الهوية ← العميل يختار ══════════ */
    case 'logo_generate':
        if (count(bkapi_logo_candidates($uid)) >= 6) api_fail('عندك 6 اقتراحات — اختار واحد أو امسحهم الأول', 'limit', 422);
        if (!rate_limit('logo_gen', 'u' . $uid, 5, 900)) api_fail('ولّدت لوجوهات كتير بسرعة — استنى شوية', 'rate_limit', 429);
        $kit = bk_get($uid);
        $d = $kit['data'];
        if ($d['business']['name'] === '') api_fail('اكتب اسم النشاط الأول (أو ابدأ بلينك صفحتك)', 'invalid', 422);
        $styles = ['minimal' => 'minimal flat vector logo, clean geometric icon next to the name', 'emblem' => 'emblem / badge logo, the name inside a simple shape',
                   'wordmark' => 'wordmark logo — the brand name only in distinctive custom lettering', 'arabic' => 'modern Arabic calligraphy logo of the brand name, elegant and readable',
                   'icon' => 'simple memorable symbol/icon logo with the name small underneath', 'playful' => 'friendly playful rounded logo with a cute simple icon'];
        $styleKey = api_str('style', 20);
        $style = $styles[$styleKey] ?? $styles['minimal'];
        $notes = api_str('notes', 400);
        $colors = array_filter([$d['colors']['primary'], $d['colors']['secondary'], $d['colors']['accent']]);
        $prompt = "Design a professional LOGO on a plain solid white background.\n"
            . 'Brand name (write it exactly, correct spelling): ' . $d['business']['name'] . "\n"
            . ($d['business']['sector'] !== '' ? 'Industry: ' . $d['business']['sector'] . "\n" : '')
            . ($d['business']['usp'] !== '' ? 'What makes it special: ' . $d['business']['usp'] . "\n" : '')
            . ($d['personality'] ? 'Brand personality: ' . implode(', ', $d['personality']) . "\n" : '')
            . ($colors ? 'Use these brand colors: ' . implode(', ', $colors) . "\n" : "Pick 2 harmonious colors that suit the industry.\n")
            . 'Style: ' . $style . ".\n"
            . ($notes !== '' ? 'Customer notes: ' . $notes . "\n" : '')
            . "Centered, simple, memorable, scalable. Vector-like clean edges, no photo textures, no drop shadows, no mockups, no extra text besides the brand name, no watermark. Plain white background only.";
        require_once __DIR__ . '/../../includes/prompt-builder.php';
        if (($__lp = type_prompt('design', 'logo')) !== '') $prompt .= "\n" . $__lp;
        $cost = bkapi_logo_cost();
        bkapi_charge($uid, $cost, 'تصميم لوجو من الهوية');
        require_once __DIR__ . '/../../includes/ai.php';
        if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
            $res = smart_ai_image($prompt, ['user_id' => $uid, 'reference_type' => 'logo', 'reference_id' => (int) $kit['id']]);
        } else {
            ai_log_set_context(['kind' => 'logo', 'user_id' => $uid, 'reference_type' => 'brand_kits', 'reference_id' => (int) $kit['id']]);
            $res = ai_generate_image($prompt, []);
        }
        if (empty($res['ok']) || empty($res['path'])) {
            bkapi_refund($uid, $cost, 'تصميم اللوجو');
            api_fail(($res['error'] ?? '') ?: 'تعذّر تصميم اللوجو — الكريدت رجعلك', 'ai_failed', 502);
        }
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $stored = bk_arr($row['data'] ?? '{}');
        $stored['_logo_candidates'] = array_values(array_slice(array_merge((array) ($stored['_logo_candidates'] ?? []), [$res['path']]), -6));
        db_run('UPDATE brand_kits SET data = ? WHERE id = ?', [bk_json($stored), $kit['id']]);   // مش نسخة — لسه اقتراح
        if (function_exists('ai_log_usage')) ai_log_usage($uid, 'logo_generate', $res['model'] ?? '', [], (int) $kit['id']);
        api_ok(['state' => bkapi_state($uid), 'balance' => credits_balance($uid)]);

    case 'logo_pick':
    case 'logo_discard':
        $path = api_str('path', 255);
        $cands = bkapi_logo_candidates($uid);
        if ($action === 'logo_pick' && !in_array($path, $cands, true)) api_fail('الاقتراح ده مش موجود', 'not_found', 404);
        $bp = bkapi_profile($uid);
        $kit = bk_get($uid);
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $stored = bk_arr($row['data'] ?? '{}');
        if ($action === 'logo_pick') {
            db_run('UPDATE brand_profiles SET logo_path = ?, updated_at = NOW() WHERE id = ?', [$path, $bp['id']]);
            $rest = array_diff($cands, [$path]);
            if (!empty($bp['logo_path'])) $rest[] = $bp['logo_path'];   // اللوجو القديم يفضل اقتراح ممكن ترجعله
            $stored['_logo_candidates'] = array_values(array_slice(array_unique($rest), -6));
        } else {
            foreach ($cands as $c) if ($c !== ($bp['logo_path'] ?? '') && ($path === '' || $c === $path)) delete_upload($c);
            $stored['_logo_candidates'] = $path === '' ? [] : array_values(array_diff($cands, [$path]));
        }
        db_run('UPDATE brand_kits SET data = ? WHERE id = ?', [bk_json($stored), $kit['id']]);
        if ($action === 'logo_pick') {
            brand_kit_notify($uid, ['logo_path'], 'user', 'لوجو جديد (تصميم الـ AI)');
            $abs = realpath(STORAGE_PATH . '/' . $path);
            if ($abs && bk_get($uid)['data']['colors']['primary'] === '' && ($cols = brand_logo_colors($abs))) {
                bk_save_section($uid, 'colors', array_merge(bk_get($uid)['data']['colors'], array_filter(['primary' => $cols[0] ?? '', 'secondary' => $cols[1] ?? '', 'accent' => $cols[2] ?? ''])), 'ai', 'ألوان من اللوجو الجديد');
            }
        }
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ العملاء المثاليين (Buyer Personas) ══════════ */
    case 'persona_save':
        $in = api_input();
        $id = (int) ($in['id'] ?? 0);
        if ($id && !bk_persona($uid, $id)) api_fail('الشخص ده مش موجود', 'not_found', 404);
        if (!$id && count(bk_personas($uid, false)) >= bk_personas_max()) api_fail('أقصى حاجة ' . bk_personas_max() . ' أشخاص — امسح واحد الأول', 'limit', 422);
        if (trim((string) ($in['name'] ?? '')) === '') api_fail('اكتب اسم وصفي للشخص', 'invalid', 422);
        $id = bk_persona_save($uid, $in, $id);
        bk_update_completeness($uid);
        api_ok(['id' => $id, 'state' => bkapi_state($uid)]);

    case 'persona_delete':
        db_run('DELETE FROM brand_personas WHERE id = ? AND user_id = ?', [api_int('id'), $uid]);
        bk_update_completeness($uid);
        api_ok(['state' => bkapi_state($uid)]);

    case 'personas_generate':
        if (!rate_limit('bk_personas', 'u' . $uid, 6, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        $room = bk_personas_max() - count(bk_personas($uid, false));
        if ($room <= 0) api_fail('عندك ' . bk_personas_max() . ' أشخاص — امسح واحد الأول', 'limit', 422);
        $r = bkapi_personas_generate($uid, min($room, api_int('n') ?: bk_personas_default()));
        api_ok(['state' => bkapi_state($uid), 'saved' => $r['saved'] ?? 0, 'balance' => credits_balance($uid)]);

    /* ══════════ الـ CTA الأساسي: 3 اقتراحات ══════════ */
    case 'cta_suggest':
        if (!rate_limit('bk_cta', 'u' . $uid, 10, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        $type = api_str('type', 20);
        $types = bk_cta_types();
        $d = bk_get($uid)['data'];
        $cost = cost_for_bk('brand_kit_ai_cost', 1);
        bkapi_charge($uid, $cost, 'اقتراح CTA');
        $j = bkapi_ai($uid, "اقترح 3 جمل Call-to-Action قصيرة وقوية بالعامية المصرية لبراند «{$d['business']['name']}» (" . ($d['business']['sector'] ?: 'نشاط تجاري') . ').'
            . (isset($types[$type]) ? " نوع الـ CTA: {$types[$type]}." : '') . ' كل جملة أقل من 10 كلمات ومن غير أرقام مخترعة. رجّع JSON بس: {"ctas":["","",""]}', 400);
        $list = array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 200), (array) ($j['ctas'] ?? []))), 0, 3));
        if (!$list) { bkapi_refund($uid, $cost, 'اقتراح CTA'); api_fail($j['__error'] ?? 'مطلعش اقتراحات — جرّب تاني', 'ai_failed', 502); }
        api_ok(['ctas' => $list, 'balance' => credits_balance($uid)]);

    /* ══════════ تحليل اللوجو والصور ← الألوان والاستايل والخطوط ══════════ */
    case 'visual_analyze':
        if (!rate_limit('bk_visual', 'u' . $uid, 6, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        require_once __DIR__ . '/../../includes/prompt-library.php';
        $imgs = bkapi_visual_images($uid);
        if (!$imgs) api_fail('ارفع اللوجو أو صور/تصميمات الأول علشان نحللها', 'no_images', 422);
        $fd = bkapi_colors_from_images($uid);
        $cost = max(0, (int) get_setting('brand_visual_cost', '1'));
        bkapi_charge($uid, $cost, 'تحليل اللوجو والصور');
        $d = bk_get($uid)['data'];
        $roles = [];
        foreach ($imgs as $k => $im) $roles[] = 'Image ' . ($k + 1) . ' is ' . $im['role'];
        $colors = $fd['palette'] ? implode(', ', array_filter([$fd['palette']['primary'], $fd['palette']['secondary'], $fd['palette']['accent'], $fd['palette']['neutral']])) : 'مفيش';
        $prompt = (prompt_full('visual_analysis')
            ? prompt_fill('visual_analysis', ['colors' => $colors, 'images_note' => "الصور المرفقة بالترتيب:\n" . implode("\n", $roles) . "\nالألوان اللي طلعت بالبرمجة من الصور (مرجع): {$colors}"],
                ['uid' => $uid, 'agent' => 'design', 'identity' => false]) . prompt_format_head()
            : prompt_fill('visual_analysis', ['colors' => $colors, 'data' => "البراند: {$d['business']['name']} — المجال: {$d['business']['sector']}\nالصور المرفقة بالترتيب:\n" . implode("\n", $roles)]))
            . "\n\nرجّع JSON بس (القيم بالعربي ما عدا الـ hex والخطوط):\n"
            . '{"colors":{"primary":"#","secondary":"#","accent":"#","neutral":"#"},"roles":"دور كل لون في جملة","style":"الاستايل العام في جملتين","fonts":{"ar":"Cairo","en":"Inter"},"notes":"ملاحظات تصميم عملية","avoid":["",""]}';
        $ai = ai_generate($prompt, array_column($imgs, 'uri'));
        $j = !empty($ai['ok']) ? bk_json_from_ai((string) $ai['response']) : null;
        $hex = fn($c) => is_string($c) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? strtoupper($c) : '';
        if (!$j || !$hex($j['colors']['primary'] ?? null)) {
            bkapi_refund($uid, $cost, 'تحليل اللوجو والصور');
            api_fail(!empty($ai['ok']) ? 'التحليل مرجعش مظبوط — الكريدت رجعلك، جرّب تاني' : ($ai['error'] ?? 'تعذّر التحليل'), 'ai_failed', 502);
        }
        $res = [
            'colors' => ['primary' => $hex($j['colors']['primary'] ?? ''), 'secondary' => $hex($j['colors']['secondary'] ?? ''), 'accent' => $hex($j['colors']['accent'] ?? ''), 'neutral' => $hex($j['colors']['neutral'] ?? '')],
            'roles' => mb_substr(trim((string) ($j['roles'] ?? '')), 0, 300), 'style' => mb_substr(trim((string) ($j['style'] ?? '')), 0, 400),
            'fonts' => ['ar' => mb_substr(trim((string) ($j['fonts']['ar'] ?? '')), 0, 40), 'en' => mb_substr(trim((string) ($j['fonts']['en'] ?? '')), 0, 40)],
            'notes' => mb_substr(trim((string) ($j['notes'] ?? '')), 0, 600),
            'avoid' => array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 120), (array) ($j['avoid'] ?? []))), 0, 8)),
            'images' => count($imgs), 'at' => date('c'),
        ];
        bkapi_kit_meta($uid, '_visual', $res);
        if (function_exists('ai_log_usage')) ai_log_usage($uid, 'visual_identity', $ai['model'] ?? '', $ai['usage'] ?? []);
        api_ok(['visual' => $res, 'state' => bkapi_state($uid), 'balance' => credits_balance($uid)]);

    case 'visual_apply':
        $v = bkapi_kit_meta($uid, '_visual');
        if (!$v) api_fail('حلّل الأول', 'invalid', 422);
        $kit = bk_get($uid)['data'];
        $parts = (array) (api_input()['parts'] ?? ['colors', 'style', 'fonts']);
        if (in_array('colors', $parts, true) && $v['colors']['primary'] !== '') {
            bk_save_section($uid, 'colors', array_merge($kit['colors'], array_filter($v['colors']), ['preset_id' => null]), 'ai', 'ألوان من تحليل اللوجو والصور');
        }
        if (in_array('style', $parts, true) && ($v['style'] !== '' || $v['notes'] !== '' || $v['avoid'])) {
            $ds = bk_get($uid)['data']['design_style'];
            $ds['notes'] = mb_substr(trim($v['style'] . ($v['notes'] !== '' ? "\n" . $v['notes'] : '')), 0, 1000);
            $ds['avoid'] = array_values(array_unique(array_merge($ds['avoid'] ?? [], $v['avoid'])));
            bk_save_section($uid, 'design_style', $ds, 'ai', 'استايل من تحليل اللوجو والصور');
        }
        if (in_array('fonts', $parts, true) && ($v['fonts']['ar'] !== '' || $v['fonts']['en'] !== '')) {
            bk_save_section($uid, 'typography', array_merge(bk_get($uid)['data']['typography'], array_filter($v['fonts'])), 'ai', 'خطوط من تحليل اللوجو والصور');
        }
        api_ok(['state' => bkapi_state($uid)]);

    /* ══════════ تحليل المحتوى: أفضل المنشورات · شكلها · تعليمات التصميم · تحليل الصور ══════════ */
    case 'insights_generate':
        if (!rate_limit('bk_insights', 'u' . $uid, 6, 600)) api_fail('استنى شوية وجرّب تاني', 'rate_limit', 429);
        require_once __DIR__ . '/../../includes/prompt-library.php';
        $kit = bk_get($uid);
        $d = $kit['data'];
        if ($d['business']['name'] === '') api_fail('اكتب اسم النشاط الأول', 'invalid', 422);
        $cost = max(0, (int) get_setting('brand_insights_cost', '1'));
        bkapi_charge($uid, $cost, 'تحليل المحتوى');
        $per = array_map(fn($p) => bk_persona_text($p, $uid), array_slice(bk_personas($uid), 0, 4));
        $fb = [];
        try {
            foreach (db_all('SELECT COALESCE(format, "post") f, SUM(feedback = 1) up, SUM(feedback = -1) down FROM contents WHERE user_id = ? AND feedback IS NOT NULL AND created_at >= (NOW() - INTERVAL 90 DAY) GROUP BY f', [$uid]) as $r) {
                $fb[] = content_format_label(content_format_key((string) $r['f'])) . ': 👍' . (int) $r['up'] . ' 👎' . (int) $r['down'];
            }
        } catch (\Throwable $e) {}
        $vis = bkapi_kit_meta($uid, '_visual');
        $data = BrandContext::promptFor($uid, 'ideas') . "\n"
            . ($per ? "\nالعملاء المثاليين:\n" . implode("\n", $per) . "\n" : '')
            . ($fb ? "\nتقييمات العميل لمنشوراته (آخر 3 شهور): " . implode(' · ', $fb) . "\n" : '')
            . ($vis ? "\nتحليل صوره وتصميماته: " . $vis['style'] . ' — ' . $vis['notes'] . "\n" : '');
        if (prompt_full('agent_content_insights')) {
            // برومبت «تحليل المحتوى» الكامل (v1): عينات المحتوى وبيانات الأداء في متغيراته
            $samples = [];
            try {
                foreach (db_all('SELECT id, COALESCE(format, "post") f, generated_text, feedback FROM contents WHERE user_id = ? AND generated_text IS NOT NULL ORDER BY id DESC LIMIT 8', [$uid]) as $k => $r) {
                    $samples[] = 'منشور ' . ($k + 1) . ' [' . content_format_label(content_format_key((string) $r['f'])) . ']' . ((int) $r['feedback'] === 1 ? ' 👍' : ((int) $r['feedback'] === -1 ? ' 👎' : '')) . ":\n"
                        . mb_substr(preg_replace('/\s+/u', ' ', (string) $r['generated_text']), 0, 400);
                }
            } catch (\Throwable $e) {}
            $insHead = prompt_fill('agent_content_insights', ['brand_identity' => $data, 'content_samples' => $samples ? implode("\n\n", $samples) : 'لسه مفيش منشورات',
                    'performance_data' => $fb ? 'تقييمات العميل (آخر 3 شهور): ' . implode(' · ', $fb) : 'مفيش بيانات أداء لسه'], ['uid' => $uid, 'agent' => 'ideas'])
                . prompt_format_head() . 'حوّل التحليل للشكل ده (best_posts = أكتر أنواع بوستات هتنجح مع أمثلة · mix = نسب الأشكال · design_rules = قواعد تصميم عملية).';
        } else {
            $insHead = prompt_fill('agent_content_insights', ['data' => $data]);
        }
        $j = bkapi_ai($uid, $insHead
            . "\n\nرجّع JSON بس:\n"
            . '{"best_posts":[{"type":"","why":"","examples":["",""]}],"mix":{"post":40,"carousel":30,"story":20,"video":10},"caption_length":"short|medium|long","hooks":["question","story"],'
            . '"design_rules":["",""],"images_review":{"good":[""],"improve":[""]},"summary":""}'
            . "\nhooks من: question|story|number|problem|myth|offer|tip", 2200);
        $best = [];
        foreach (array_slice((array) ($j['best_posts'] ?? []), 0, 6) as $b) {
            if (!is_array($b) || trim((string) ($b['type'] ?? '')) === '') continue;
            $best[] = ['type' => mb_substr(trim((string) $b['type']), 0, 80), 'why' => mb_substr(trim((string) ($b['why'] ?? '')), 0, 240),
                       'examples' => array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 160), (array) ($b['examples'] ?? []))), 0, 3))];
        }
        if (!$best) {
            bkapi_refund($uid, $cost, 'تحليل المحتوى');
            api_fail($j['__error'] ?? 'التحليل مرجعش مظبوط — الكريدت رجعلك، جرّب تاني', 'ai_failed', 502);
        }
        $mix = [];
        foreach (['post', 'carousel', 'story', 'video'] as $k) $mix[$k] = max(0, min(100, (int) ($j['mix'][$k] ?? 0)));
        $hooks = array_values(array_intersect((array) ($j['hooks'] ?? []), array_keys(bk_hook_types())));
        $ins = [
            'best_posts' => $best, 'mix' => $mix,
            'caption_length' => in_array($j['caption_length'] ?? '', ['short', 'medium', 'long'], true) ? $j['caption_length'] : 'medium',
            'hooks' => array_slice($hooks, 0, 4),
            'design_rules' => array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 200), (array) ($j['design_rules'] ?? []))), 0, 8)),
            'images_review' => ['good' => array_values(array_slice(array_filter(array_map('strval', (array) ($j['images_review']['good'] ?? []))), 0, 4)),
                                'improve' => array_values(array_slice(array_filter(array_map('strval', (array) ($j['images_review']['improve'] ?? []))), 0, 4))],
            'summary' => mb_substr(trim((string) ($j['summary'] ?? '')), 0, 400), 'at' => date('c'),
        ];
        bkapi_kit_meta($uid, '_insights', $ins);
        api_ok(['insights' => $ins, 'state' => bkapi_state($uid), 'balance' => credits_balance($uid)]);

    case 'insights_apply':
        $ins = bkapi_kit_meta($uid, '_insights');
        if (!$ins) api_fail('حلّل الأول', 'invalid', 422);
        $part = api_str('part', 20);
        $done = '';
        if ($part === 'shape') {
            // شكل المنشورات: الطول والـ Hooks + أشكال المحتوى ونسبها في الأسبوع
            $c = bk_get($uid)['data']['content'];
            $c['caption_length'] = $ins['caption_length'];
            if ($ins['hooks']) $c['hooks'] = $ins['hooks'];
            bk_save_section($uid, 'content', $c, 'ai', 'شكل المنشورات من تحليل المحتوى');
            $perWeek = max(1, (int) round(((int) ($c['posts_per_month'] ?? 12)) / 4));
            $map = ['post' => 'single_image', 'carousel' => 'carousel', 'story' => 'story', 'video' => 'reel'];
            $rank = 0;
            arsort($ins['mix']);
            foreach ($ins['mix'] as $k => $pc) {
                db_run('INSERT INTO brand_formats (user_id, format, `rank`, per_week, settings, is_enabled) VALUES (?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE `rank` = VALUES(`rank`), per_week = VALUES(per_week), is_enabled = VALUES(is_enabled)',
                    [$uid, $map[$k], ++$rank, (int) round($perWeek * $pc / 100), bk_json(new \stdClass()), $pc > 0 ? 1 : 0]);
            }
            $done = 'شكل المنشورات اتحدّث ✓';
        } elseif ($part === 'design') {
            $ds = bk_get($uid)['data']['design_style'];
            $ds['notes'] = mb_substr(trim(($ds['notes'] !== '' ? $ds['notes'] . "\n" : '') . implode("\n", array_map(fn($r) => '- ' . $r, $ins['design_rules']))), 0, 1000);
            bk_save_section($uid, 'design_style', $ds, 'ai', 'تعليمات التصميم من تحليل المحتوى');
            $done = 'تعليمات التصميم اتضافت ✓';
        } elseif ($part === 'topics') {
            $have = array_map('mb_strtolower', array_column(db_all('SELECT title FROM brand_topics WHERE user_id = ?', [$uid]), 'title'));
            $n = 0;
            foreach ($ins['best_posts'] as $b) foreach ($b['examples'] as $t) {
                if ($t === '' || in_array(mb_strtolower($t), $have, true)) continue;
                db_insert('INSERT INTO brand_topics (user_id, title, source) VALUES (?,?,"ai")', [$uid, mb_substr($t, 0, 255)]);
                $have[] = mb_strtolower($t); $n++;
            }
            bk_update_completeness($uid);
            $done = 'اتضاف ' . $n . ' موضوع ✓';
        } else {
            api_fail('اختار تطبّق إيه', 'invalid', 422);
        }
        api_ok(['state' => bkapi_state($uid), 'done' => $done]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
