<?php
/**
 * API الـ Design Studio (المرحلة ⑤)
 *   GET  ?action=home                         بيانات الشاشة: الهوية · الاستخدام · التكاليف · آخر التصميمات
 *   GET  ?action=design&id=                   تصميم واحد + الـ Brief بتاعه
 *   POST action=brief      {method, text, purpose?, platform?}   Creative Brief بالـ AI (كابشن كامل)
 *   POST action=save_brief {id, brief}                            ربط الـ Brief بالتصميم بعد التوليد
 *   POST action=to_post    {id, caption, hashtags, cta, platform} «اعتماد» ← منشور في المكتبة
 *   GET  ?action=size_hint&text=               المقاس اللي العميل كتبه (لو فيه) — من غير AI
 *   GET  ?action=trends                        الترندات الشغالة + «مناسب لبراندك %»
 *   POST action=trend_ideas {trend_id}         3 أفكار بالـ AI بتفصّل الترند على البراند
 *   GET  ?action=drafts | ?action=draft&id=    المشاريع اللي لسه مكملتش (مسودات)
 *   POST action=draft_save {id?, title, method, step, design_id?, state}   حفظ تلقائي للمشروع
 *   POST action=draft_file (multipart: id, slot, file)                     صورة المشروع بتترفع أول ما تتختار
 *   POST action=draft_delete {id}
 *
 * التوليد نفسه لسه في ajax/studio-design.php (الكريدت · حد المحاولات · العلامة المائية · سجل الـ AI)
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/uploader.php';
if (is_file(__DIR__ . '/../../includes/ui-v2.php')) require_once __DIR__ . '/../../includes/ui-v2.php';
if (is_file(__DIR__ . '/../../includes/brand-brain.php')) require_once __DIR__ . '/../../includes/brand-brain.php';
require_once __DIR__ . '/../../includes/studio-config.php';
require_once __DIR__ . '/../../includes/trends.php';
require_once __DIR__ . '/../../includes/social.php';   // feature_allows · user_connections · default_publish_hour
require_once __DIR__ . '/../../includes/studio-drafts.php';   // المشاريع اللي لسه مكملتش

// منشور اتسلّم لفيسبوك (منشور · متجدول هناك · بيتبعت دلوقتي) — الاستوديو مايعدّلوش ولا يعيد نشره
const STUDIO_POST_LOCKED = ['published', 'scheduled', 'processing'];

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'home';

function studio_modes(): array
{
    return ['before_after' => 'قبل / بعد', 'from_image' => 'من صورة', 'free' => 'فكرة / Prompt',
            'from_content' => 'من محتوى', 'personal' => 'صورة شخصية'];
}

/** اسم طريقة جديدة من الأدمن (حتى لو اتقفلت) — «طريقة مخصصة» لو اتمسحت */
function studio_mode_title(string $key): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $r = null;
        try { $r = db_one('SELECT title FROM studio_methods WHERE mkey = ?', [$key]); } catch (\Throwable $e) {}
        $cache[$key] = $r['title'] ?? 'طريقة مخصصة';
    }
    return $cache[$key];
}

function studio_brief_clean($b): array
{
    $b = is_array($b) ? $b : [];
    $keys = ['idea', 'visual', 'headline', 'sub', 'cta', 'caption', 'hashtags', 'first_comment', 'ad_copy', 'script', 'goal', 'audience', 'method', 'platform'];
    $out = [];
    foreach ($keys as $k) {
        $v = $b[$k] ?? '';
        if (is_array($v)) $v = implode(' ', array_map('strval', $v));
        $out[$k] = mb_substr(trim((string) $v), 0, in_array($k, ['caption', 'ad_copy', 'script'], true) ? 3000 : 600);
    }
    return $out;
}

function studio_row(array $r): array
{
    $brief = $r['brief'] ? (json_decode($r['brief'], true) ?: null) : null;
    return [
        'id'      => (int) $r['id'],
        'url'     => upload_url($r['image_path']),
        'mode'    => $r['mode'],
        'mode_label' => studio_modes()[$r['mode']] ?? studio_mode_title($r['mode']),
        'ratio'   => (string) ($r['ratio'] ?? '1:1'),
        'ago'     => function_exists('ui_time_ago') ? ui_time_ago($r['created_at']) : $r['created_at'],
        'brief'   => $brief,
        'post_id' => $r['content_id'] ? (int) $r['content_id'] : null,
    ];
}

switch ($action) {

    case 'home':
        $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: [];
        $health = function_exists('brand_health') ? brand_health($brand) : ['pct' => 100, 'gate' => 75, 'unlocked' => true];
        $monthStart = date('Y-m-01 00:00:00');
        $month = (int) (db_one('SELECT (SELECT COUNT(*) FROM studio_designs WHERE user_id = ? AND created_at >= ?)
                                    + (SELECT COUNT(*) FROM content_designs WHERE user_id = ? AND created_at >= ?) n',
            [$uid, $monthStart, $uid, $monthStart])['n'] ?? 0);
        $tones = ['simple' => 'بسيطة', 'formal' => 'رسمية', 'fun' => 'مرحة', 'professional' => 'احترافية'];
        $adds = [];
        if (!empty($brand['colors']))    $adds[] = ['k' => 'الألوان', 'v' => $brand['colors']];
        if (!empty($brand['logo_path'])) $adds[] = ['k' => 'اللوجو', 'v' => 'بيتحط في التصميم تلقائيًا'];
        if (!empty($brand['tone']))      $adds[] = ['k' => 'النبرة', 'v' => $tones[$brand['tone']] ?? $brand['tone']];
        if (!empty($brand['audience']))  $adds[] = ['k' => 'الجمهور', 'v' => mb_substr($brand['audience'], 0, 90)];
        if (!empty($brand['design_rules'])) $adds[] = ['k' => 'قواعد التصميم', 'v' => mb_substr($brand['design_rules'], 0, 90)];
        // كارت واحد لكل تصميم = آخر نسخة منه (التعديلات مابتزحمش القايمة)
        $recent = db_all('SELECT sd.*, f.n AS versions_n FROM studio_designs sd
                          JOIN (SELECT MAX(id) AS mid, COUNT(*) AS n FROM studio_designs WHERE user_id = ? GROUP BY COALESCE(parent_id, id)) f
                            ON f.mid = sd.id
                          ORDER BY sd.id DESC LIMIT 12', [$uid]);

        // «اختار الصورة»: صور البراند ← الصورة الشخصية ← مفضلات معرض الإلهام (نفس مصدر style-ref-picker)
        $refs = [];
        try {
            if ($brand && function_exists('get_brand_images')) {
                foreach (array_slice(get_brand_images((int) $brand['id']), 0, 12) as $bi) {
                    $refs[] = ['ref' => 'brand:' . $bi['id'], 'src' => !empty($bi['image_url']) ? $bi['image_url'] : upload_url($bi['image_path']), 'tag' => 'صورتك'];
                }
            }
            if (!empty($brand['personal_image_path'])) {
                $refs[] = ['ref' => 'personal', 'src' => upload_url($brand['personal_image_path']), 'tag' => 'صورتك الشخصية'];
            }
            // تصميمات بتعجبك (من Brand Brain) — مرجع ستايل جاهز
            if ($brand && function_exists('brand_insp_list')) {
                foreach (array_slice(array_filter(brand_insp_list((int) $brand['id']), fn($x) => $x['ref']), 0, 12) as $ins) {
                    $refs[] = ['ref' => $ins['ref'], 'src' => $ins['img'], 'tag' => 'بتعجبك'];
                }
            }
            foreach (db_all('SELECT m.id, m.image_path, m.image_url FROM user_media_selections s
                             JOIN media_library m ON m.id = s.media_id AND m.is_active = 1
                             WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 12', [$uid]) as $f) {
                $refs[] = ['ref' => 'lib:' . $f['id'], 'src' => function_exists('media_display_url') ? media_display_url($f) : upload_url($f['image_path']), 'tag' => 'مفضلتك'];
            }
        } catch (\Throwable $e) { /* جداول المعرض مش موجودة */ }
        api_ok([
            'brand'   => ['name' => (string) ($brand['business_name'] ?? ''), 'logo' => !empty($brand['logo_path']) ? upload_url($brand['logo_path']) : null,
                          'health' => $health, 'adds' => $adds],
            'month'   => $month,
            'costs'   => ['design' => cost_for('content_design_cost'), 'brief' => max(0, (int) get_setting('studio_idea_cost', 1)),
                          'balance' => credits_balance($uid)],
            'ratios'  => array_map(fn($k, $m) => ['key' => $k, 'label' => $m['label'], 'hint' => $m['hint']],
                                   array_keys(design_ratios()), array_values(design_ratios())),
            'recent'  => array_map(fn($r) => studio_row($r) + ['versions_n' => (int) $r['versions_n']], $recent),
            'refs'    => $refs,
            // النشر من جوه الاستوديو — نفس ajax/publish-direct.php بتاع المحتويات
            'publish' => [
                'allowed' => feature_allows($uid),
                'pages'   => array_values(array_map(fn($c) => [
                    'id' => (int) $c['id'], 'name' => (string) ($c['page_name'] ?: 'صفحة'),
                    'avatar' => (string) ($c['page_avatar_url'] ?? ''), 'ig' => !empty($c['ig_user_id']),
                ], feature_allows($uid) ? user_connections($uid) : [])),
                'default' => date('Y-m-d', strtotime('+1 day')) . 'T' . str_pad((string) default_publish_hour(), 2, '0', STR_PAD_LEFT) . ':00',
            ],
            // الطرق من الأدمن (⑤-ب) — قالب البرومبت وأدوار الصور مابيطلعوش للمتصفح
            'methods' => array_map(fn($m) => array_merge(array_diff_key($m, ['prompt' => 1, 'uploads' => 1]), [
                'uploads' => array_map(fn($u) => ['label' => $u['label'], 'required' => $u['required']], $m['uploads']),
            ]), studio_methods(true)),
            'drafts'  => studio_drafts_list($uid),
        ]);

    /* ── المشاريع اللي لسه مكملتش ── */
    case 'drafts':
        api_ok(['drafts' => studio_drafts_list($uid)]);

    case 'draft':
        $d = studio_draft_get(api_int('id'), $uid);
        if (!$d) api_fail('المشروع ده مش موجود', 'not_found', 404);
        $files = [];
        foreach (studio_draft_files($d) as $slot => $path) $files[$slot] = upload_url($path);
        api_ok(['draft' => studio_draft_row($d) + [
            'state' => json_decode((string) $d['state_json'], true) ?: new \stdClass(),
            'files' => $files ?: new \stdClass(),
            'design_id' => $d['design_id'] ? (int) $d['design_id'] : null,
        ]]);

    case 'draft_save':
        if (!studio_drafts_ensure()) api_fail('الحفظ التلقائي مش متاح دلوقتي', 'unavailable', 503);
        if (!rate_limit('studio_draft', 'u' . $uid, 240, 600)) api_fail('حفظ كتير بسرعة', 'rate_limit', 429);
        $in = api_input();
        $state = json_encode(is_array($in['state'] ?? null) ? $in['state'] : [], JSON_UNESCAPED_UNICODE);
        if (strlen($state) > STUDIO_DRAFT_STATE_MAX) api_fail('المشروع كبير جدًا للحفظ', 'too_big', 413);
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', api_str('title', 300))), 0, 160);
        $method = preg_replace('/[^a-z0-9_\-]/i', '', api_str('method', 40));
        $step = in_array(api_str('step', 16), ['input', 'brief', 'result'], true) ? api_str('step', 16) : 'input';
        $designId = api_int('design_id');
        if ($designId && !db_one('SELECT id FROM studio_designs WHERE id = ? AND user_id = ?', [$designId, $uid])) $designId = 0;
        $id = api_int('id');
        if ($id && studio_draft_get($id, $uid)) {
            db_run('UPDATE studio_drafts SET title = ?, method = ?, step = ?, state_json = ?, design_id = ? WHERE id = ? AND user_id = ?',
                [$title, $method, $step, $state, $designId ?: null, $id, $uid]);
        } else {
            $id = db_insert('INSERT INTO studio_drafts (user_id, title, method, step, state_json, design_id) VALUES (?,?,?,?,?,?)',
                [$uid, $title, $method, $step, $state, $designId ?: null]);
            // الأقدم من الحد بيتمسح
            $old = db_all('SELECT id FROM studio_drafts WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 100 OFFSET ' . STUDIO_DRAFTS_MAX, [$uid]);
            foreach ($old as $o) studio_draft_delete((int) $o['id'], $uid);
        }
        api_ok(['id' => (int) $id]);

    case 'draft_file':
        $d = studio_draft_get(api_int('id'), $uid);
        if (!$d) api_fail('احفظ المشروع الأول', 'not_found', 404);
        $slot = preg_replace('/[^a-z0-9_]/i', '', api_str('slot', 20));
        if ($slot === '' || empty($_FILES['file']['name'])) api_fail('مفيش صورة', 'empty', 422);
        if (!rate_limit('upload_image', 'u' . $uid, 30, 600)) api_fail('رفعت صور كتير بسرعة — استنى شوية', 'rate_limit', 429);
        $up = upload_image($_FILES['file'], 'references');
        if (!$up['ok']) api_fail($up['error'], 'upload', 422);
        $files = studio_draft_files($d);
        $files[$slot] = $up['path'];
        db_run('UPDATE studio_drafts SET files_json = ? WHERE id = ? AND user_id = ?', [json_encode($files), $d['id'], $uid]);
        api_ok(['slot' => $slot, 'url' => upload_url($up['path'])]);

    case 'draft_delete':
        studio_draft_delete(api_int('id'), $uid);
        api_ok(['deleted' => true]);

    case 'size_hint':
        $r = studio_ratio_from_text(api_str('text', 1500));
        api_ok(['size' => $r ? ['key' => $r['key'], 'label' => $r['label'], 'w' => $r['w'], 'h' => $r['h'],
                                'match' => $r['match'], 'in_list' => $r['in_list']] : null]);


    /* ── Trend Studio ── */
    case 'trends':
        $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: [];
        $ind = trend_brand_industry($brand);
        $list = array_map(function ($t) use ($ind) {
            return ['id' => $t['id'], 'code' => $t['code'], 'name' => $t['name'], 'type' => $t['type'], 'ref' => $t['ref'],
                    'description' => $t['description'], 'platforms' => $t['platforms'], 'industries' => $t['industries'],
                    'structure' => $t['structure'], 'allowed' => $t['allowed'], 'locked' => $t['locked'], 'tone' => $t['tone'],
                    'fit' => trend_fit($t, $ind)];
        }, trends_live());
        usort($list, fn($a, $b) => $b['fit'] <=> $a['fit']);
        api_ok(['trends' => $list, 'industry' => $ind, 'brand' => [
            'name' => (string) ($brand['business_name'] ?? ''),
            'summary' => trim(implode(' · ', array_filter([$brand['industry'] ?? '', mb_substr((string) ($brand['audience'] ?? ''), 0, 80)]))),
        ]]);

    case 'trend_ideas':
        require_once __DIR__ . '/../../includes/ai.php';
        $t = trend_get_live(api_int('trend_id'));
        if (!$t) api_fail('الترند ده مش متاح دلوقتي', 'not_found', 404);
        if (!rate_limit('studio_idea', 'u' . $uid, 12, 600)) api_fail('طلبت أفكار كتير بسرعة — استنى شوية', 'rate_limit', 429);
        $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: [];
        $ctx = [];
        foreach (['business_name' => 'البراند', 'industry' => 'المجال', 'audience' => 'الجمهور', 'services' => 'الخدمات'] as $k => $l) {
            if (!empty($brand[$k])) $ctx[] = $l . ': ' . mb_substr((string) $brand[$k], 0, 300);
        }
        $dialects = ['egyptian' => 'العامية المصرية', 'khaleeji' => 'الخليجية', 'levantine' => 'الشامية', 'msa' => 'الفصحى'];
        $dialect = $dialects[$brand['dialect'] ?? ''] ?? 'العامية المصرية';
        $isReel = $t['type'] === 'Reel';
        $prompt = "انت مدير إبداعي. عندك ترند سوشيال ميديا، وعايزين نفصّله على براند العميل — **نستخدم هيكل الترند، مش نسخة منه**.\n\n"
            . trend_blueprint_text($t) . "\n"
            . ($ctx ? "براند العميل:\n- " . implode("\n- ", $ctx) . "\n\n" : '')
            . "اقترح 3 أفكار مختلفة. رجّع JSON بس — من غير أي كلام قبله أو بعده:\n"
            . '{"ideas":[{"title":"","desc":"","headline":"","cta":"","caption":"","hashtags":""' . ($isReel ? ',"script":""' : '') . '}]}' . "\n\n"
            . "القواعد:\n"
            . "- كل فكرة لازم تمشي على «هيكل الترند» بالترتيب، وتحترم «ثابت لا يتغير»، وتغيّر بس في «مسموح تغييره».\n"
            . "- title: اسم الفكرة (4-6 كلمات). desc: الفكرة في جملتين. headline: نص قصير على التصميم. cta: 2-4 كلمات.\n"
            . "- caption: كابشن كامل بـ{$dialect} (40-90 كلمة). hashtags: 5-7 في سطر.\n"
            . ($isReel ? "- script: سكريبت الريل — سطر لكل خطوة من الهيكل بالترتيب، بصيغة «خطوة: اللي بيحصل على الشاشة».\n" : '')
            . "- ممنوع تنسخ الترند الأصلي أو تخترع أسعار/أرقام مش موجودة.";
        $cost = max(0, (int) get_setting('studio_idea_cost', 1));
        if ($cost > 0 && !credits_consume($uid, $cost, 'أفكار ترند — ' . $t['code'], 'trend_ideas', $t['id'])) {
            api_fail(credits_short_msg($cost), 'credits', 402);
        }
        if (function_exists('ai_log_set_context')) {
            ai_log_set_context(['kind' => 'studio', 'user_id' => $uid, 'reference_type' => 'trends', 'reference_id' => $t['id']]);
        }
        $ai = (function_exists('smart_ai_enabled') && smart_ai_enabled())
            ? smart_ai_generate($prompt, [], 'content_generation', ['user_id' => $uid, 'job_type' => 'trend_ideas', 'temperature' => 0.8])
            : ai_generate($prompt);
        $ideas = [];
        if (!empty($ai['ok'])) {
            $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', (string) $ai['response']));
            $a = strpos($raw, '{'); $b = strrpos($raw, '}');
            $j = ($a !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
            foreach ((array) ($j['ideas'] ?? []) as $i) {
                if (!is_array($i) || trim((string) ($i['title'] ?? '')) === '') continue;
                $ideas[] = array_map(fn($v) => mb_substr(trim(is_array($v) ? implode("\n", $v) : (string) $v), 0, 2000),
                    array_intersect_key($i + ['desc' => '', 'headline' => '', 'cta' => '', 'caption' => '', 'hashtags' => '', 'script' => ''],
                                        array_flip(['title', 'desc', 'headline', 'cta', 'caption', 'hashtags', 'script'])));
            }
        }
        if (!$ideas) {
            if ($cost > 0) credits_add($uid, $cost, 'استرداد: أفكار الترند ماطلعتش', null, 'refund', $t['id']);
            api_fail(!empty($ai['ok']) ? 'الـ AI رجّع رد مش مفهوم — الكريدت رجعلك، جرّب تاني' : ($ai['error'] ?? 'تعذّر — الكريدت رجعلك'), 'ai_failed', 502);
        }
        api_ok(['ideas' => array_slice($ideas, 0, 3), 'balance' => credits_balance($uid)]);

    case 'design':
        $r = api_own('studio_designs', api_int('id'), $uid);
        $root = (int) ($r['parent_id'] ?: $r['id']);
        $vers = db_all('SELECT id, image_path, design_idea FROM studio_designs
                        WHERE user_id = ? AND (id = ? OR parent_id = ?) ORDER BY id LIMIT 30', [$uid, $root, $root]);
        $row = studio_row($r);
        $row['versions'] = array_map(fn($v, $i) => [
            'id' => (int) $v['id'], 'n' => $i + 1, 'url' => upload_url($v['image_path']),
            'note' => (int) $v['id'] === $root ? 'الأصل' : mb_substr(preg_replace('/^تعديل: /u', '', (string) $v['design_idea']), 0, 60),
            'current' => (int) $v['id'] === (int) $r['id'],
        ], $vers, array_keys($vers));
        // حالة المنشور المرتبط — علشان الاستوديو مايعيدش نشر حاجة اتنشرت أو اتجدولت على فيسبوك
        $row['post_status'] = !empty($r['content_id'])
            ? (string) (db_one('SELECT publish_status FROM contents WHERE id = ? AND user_id = ?', [$r['content_id'], $uid])['publish_status'] ?? '') : '';
        api_ok(['design' => $row]);

    /* ── Creative Brief بالـ AI ── */
    case 'brief':
        require_once __DIR__ . '/../../includes/ai.php';
        $method = api_str('method', 20);
        $text = api_str('text', 1200);
        $purpose = api_str('purpose', 120);
        $platform = api_str('platform', 20) ?: 'facebook';
        if (mb_strlen($text) < 4 && $purpose === '') {
            api_fail('اكتب فكرتك الأول', 'empty', 422);
        }
        if (!rate_limit('studio_idea', 'u' . $uid, 12, 600)) {
            api_fail('طلبت أفكار كتير بسرعة — استنى شوية', 'rate_limit', 429);
        }
        $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: [];
        $ctx = [];
        foreach (['business_name' => 'البراند', 'industry' => 'المجال', 'audience' => 'الجمهور', 'services' => 'الخدمات',
                  'colors' => 'الألوان', 'design_rules' => 'قواعد التصميم'] as $k => $l) {
            if (!empty($brand[$k])) $ctx[] = $l . ': ' . mb_substr((string) $brand[$k], 0, 300);
        }
        $dialects = ['egyptian' => 'العامية المصرية', 'khaleeji' => 'الخليجية', 'levantine' => 'الشامية', 'msa' => 'الفصحى'];
        $dialect = $dialects[$brand['dialect'] ?? ''] ?? 'العامية المصرية';
        $kind = ['before_after' => 'تصميم قبل/بعد يوضح نتيجة حقيقية', 'from_image' => 'تصميم إعلاني حوالين صورة حقيقية للعميل',
                 'free' => 'تصميم سوشيال ميديا'][$method] ?? 'تصميم سوشيال ميديا';

        $prompt = "انت مدير إبداعي ومسوّق سوشيال ميديا محترف. جهّز Creative Brief لـ {$kind}.\n"
            . ($ctx ? "هوية البراند:\n- " . implode("\n- ", $ctx) . "\n" : '')
            . ($purpose !== '' ? "الغرض: {$purpose}\n" : '')
            . ($text !== '' ? "فكرة العميل (بكلامه): {$text}\n" : '')
            . "المنصة: {$platform}\n\n"
            . "رجّع JSON بس — من غير أي كلام قبله أو بعده — بالمفاتيح دي:\n"
            . '{"goal":"","audience":"","idea":"","visual":"","headline":"","sub":"","cta":"","caption":"","hashtags":"","first_comment":"","ad_copy":""}' . "\n\n"
            . "القواعد:\n"
            . "- idea: فكرة التصميم في جملة أو اتنين. visual: الاتجاه البصري (التكوين · الألوان · الإضاءة) في جملتين.\n"
            . "- headline: عنوان قصير للتصميم (حد أقصى 6 كلمات). sub: سطر تاني قصير. cta: زرار قصير (2-4 كلمات).\n"
            . "- caption: كابشن المنشور كامل بـ{$dialect} (60-120 كلمة، Hook قوي في أول سطر، إيموجي معتدل).\n"
            . "- hashtags: 5-8 هاشتاجات في سطر واحد. first_comment: أول كومنت يزوّد التفاعل. ad_copy: نص إعلان ممول قصير.\n"
            . "- ممنوع تخترع أسعار أو أرقام أو مواعيد مش مذكورة.";

        $cost = max(0, (int) get_setting('studio_idea_cost', 1));
        if ($cost > 0 && !credits_consume($uid, $cost, 'Creative Brief — الاستوديو', 'studio_brief', null)) {
            api_fail(credits_short_msg($cost), 'credits', 402);
        }
        if (function_exists('ai_log_set_context')) {
            ai_log_set_context(['kind' => 'studio', 'user_id' => $uid, 'reference_type' => 'studio_brief', 'options' => ['method' => $method]]);
        }
        $ai = (function_exists('smart_ai_enabled') && smart_ai_enabled())
            ? smart_ai_generate($prompt, [], 'content_generation', ['user_id' => $uid, 'job_type' => 'studio_brief', 'temperature' => 0.7])
            : ai_generate($prompt);
        $j = null;
        if (!empty($ai['ok'])) {
            $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', (string) $ai['response']));
            $a = strpos($raw, '{'); $b = strrpos($raw, '}');
            if ($a !== false && $b > $a) $j = json_decode(substr($raw, $a, $b - $a + 1), true);
        }
        if (!is_array($j) || trim((string) ($j['idea'] ?? '')) === '') {
            if ($cost > 0) credits_add($uid, $cost, 'استرداد: Creative Brief ماطلعش', null, 'refund', null);
            api_fail(!empty($ai['ok']) ? 'الـ AI رجّع رد مش مفهوم — الكريدت رجعلك، جرّب تاني' : ($ai['error'] ?? 'تعذّر تجهيز الـ Brief — الكريدت رجعلك'), 'ai_failed', 502);
        }
        $brief = studio_brief_clean($j + ['method' => $method, 'platform' => $platform]);
        api_ok(['brief' => $brief, 'charged' => $cost, 'balance' => credits_balance($uid)]);

    case 'save_brief':
        $r = api_own('studio_designs', api_int('id'), $uid);
        $in = api_input();
        $brief = studio_brief_clean($in['brief'] ?? []);
        db_run('UPDATE studio_designs SET brief = ? WHERE id = ?', [json_encode($brief, JSON_UNESCAPED_UNICODE), $r['id']]);
        api_ok(['saved' => true]);

    /* ── «اعتماد» ← منشور في المكتبة ── */
    case 'to_post':
        $r = api_own('studio_designs', api_int('id'), $uid);
        // المشروع خلص (اتحفظ في المحتويات / رايح للنشر) — مايفضلش في «لسه مكملتش»
        studio_drafts_complete_design((int) $r['id'], $uid);
        // اتحوّل قبل كده — مانكررش. لو الكابشن اتعدّل ولسه ماتنشرش، نحدّثه (بنسخة جديدة في سجل النسخ)
        $existing = !empty($r['content_id'])
            ? db_one('SELECT id, publish_status, generated_text FROM contents WHERE id = ? AND user_id = ?', [$r['content_id'], $uid]) : null;
        if ($existing) {
            $cap = trim(mb_substr(api_str('caption', 5000), 0, 5000));
            // «scheduled» = اتبعت لفيسبوك بالنص القديم — تعديله هنا مش هيوصل هناك، فمانلمسوش
            $locked = in_array((string) ($existing['publish_status'] ?? ''), STUDIO_POST_LOCKED, true);
            if ($cap !== '' && $cap !== (string) $existing['generated_text'] && !$locked) {
                $tg = trim(mb_substr(api_str('hashtags', 1000), 0, 1000));
                $ct = trim(mb_substr(api_str('cta', 300), 0, 300));
                db_run('UPDATE contents SET generated_text = ?, hashtags = ?, cta = ?, status = "edited" WHERE id = ?', [$cap, $tg ?: null, $ct ?: null, $existing['id']]);
                save_content_version((int) $existing['id'], $cap, $tg ?: null, $ct ?: null, 'edited', 'من Design Studio');
            }
            api_ok(['content_id' => (int) $existing['id'], 'existing' => true, 'post_status' => (string) ($existing['publish_status'] ?? '')]);
        }
        // نسخة معدّلة من تصميم اتحوّل منشور (ولسه ماتنشرش) ← بتبقى غلاف نفس المنشور، مش منشور مكرر
        $root = (int) ($r['parent_id'] ?: $r['id']);
        $family = db_one('SELECT c.id FROM studio_designs sd JOIN contents c ON c.id = sd.content_id AND c.user_id = sd.user_id
                          WHERE sd.user_id = ? AND (sd.id = ? OR sd.parent_id = ?) AND COALESCE(c.publish_status, "") NOT IN ("published", "scheduled", "processing")
                          ORDER BY sd.id DESC LIMIT 1', [$uid, $root, $root]);
        if ($family) {
            $did = db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used, ratio) VALUES (?,?,?,?,?,0,?)',
                [$family['id'], $uid, $r['image_path'], $r['prompt'], $r['model'], $r['ratio'] ?? '1:1']);
            db_run('UPDATE contents SET selected_image_id = ? WHERE id = ?', [$did, $family['id']]);
            db_run('UPDATE studio_designs SET content_id = ? WHERE id = ?', [$family['id'], $r['id']]);
            api_ok(['content_id' => (int) $family['id'], 'existing' => true, 'cover_updated' => true]);
        }
        $caption = trim(mb_substr(api_str('caption', 5000), 0, 5000));
        $tags = trim(mb_substr(api_str('hashtags', 1000), 0, 1000));
        $cta = trim(mb_substr(api_str('cta', 300), 0, 300));
        $plat = api_str('platform', 20);
        $plat = in_array($plat, ['facebook', 'instagram', 'both'], true) ? $plat : 'facebook';
        $bp = db_one('SELECT id FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]);
        if (!$bp) api_fail('ملف البراند مش موجود', 'no_brand', 422);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $cid = db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, hashtags, cta, status, design_direction)
                              VALUES (?,?,?,?,?,?,?, "generated", ?)',
                [$uid, $bp['id'], 'marketing', $plat, $caption, $tags ?: null, $cta ?: null, 'من Design Studio']);
            $did = db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used, ratio) VALUES (?,?,?,?,?,0,?)',
                [$cid, $uid, $r['image_path'], $r['prompt'], $r['model'], $r['ratio'] ?? '1:1']);
            db_run('UPDATE contents SET selected_image_id = ? WHERE id = ?', [$did, $cid]);
            if ($caption !== '') save_content_version($cid, $caption, $tags ?: null, $cta ?: null, 'ai', 'من Design Studio');
            db_run('UPDATE studio_designs SET content_id = ? WHERE id = ?', [$cid, $r['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        api_ok(['content_id' => $cid]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
