<?php
/**
 * Spread AI v2 — البحث العميق (المرحلة ⑦-ب)
 *
 * الفكرة: بحث ويب حقيقي بمصادر حقيقية — مش «الـ AI يفتكر». كل معلومة ليها رقم مصدر [n]
 * واللي من غير مصدر بيتعلّم «استنتاج AI». الأسعار والأرقام اللي من غير مصدر بتتشال.
 *
 * التنفيذ على خطوات (كل خطوة = طلب واحد من الواجهة) علشان مانوقعش في timeout على الاستضافة:
 *   فهم السؤال · تحديد النطاق (فوري) ← بحث ويب لكل محور ← تحليل منظّم ← استخراج الفرص ← كتابة التقرير
 *
 * محرك البحث: الـ Smart Router بس (البوابة الموحدة — مهمة «بحث الويب» ومهمة «تحليل البحث»)
 *   والـ Router هو اللي بيختار المزود: OpenRouter (plugins: web) · OpenAI (search-preview) · Perplexity (sonar)
 *   المسار القديم (مفتاح الإعدادات مباشرة) مابقاش بيتستخدم في البحث.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';

/* ═══════════ الثوابت ═══════════ */

function research_types(): array
{
    return [
        'market'      => ['📊', 'بحث السوق', 'حجم واتجاهات السوق والفرص'],
        'competitors' => ['🥊', 'تحليل المنافسين', 'المنافسين، عروضهم، أسعارهم، ومحتواهم'],
        'audience'    => ['👥', 'بحث الجمهور', 'احتياجات العملاء ومشاكلهم وسلوكهم'],
        'content'     => ['📱', 'بحث المحتوى', 'أنواع المحتوى والموضوعات والزوايا الجذابة'],
        'pricing'     => ['💰', 'بحث الأسعار', 'مقارنة الأسعار والعروض في السوق'],
        'trends'      => ['🔎', 'كلمات واتجاهات', 'الكلمات والموضوعات والاتجاهات المهمة'],
        'marketing'   => ['🎯', 'بحث تسويقي', 'الفرص والاستراتيجيات والزوايا التسويقية'],
        'custom'      => ['✨', 'بحث مخصص', 'حدد أنت ما تريد البحث عنه'],
    ];
}

/** السوق => [كود الدولة, المدن] */
function research_markets(): array
{
    return [
        'مصر'      => ['EG', ['كل المدن', 'القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة']],
        'السعودية' => ['SA', ['كل المدن', 'الرياض', 'جدة', 'الدمام']],
        'الإمارات' => ['AE', ['كل المدن', 'دبي', 'أبوظبي', 'الشارقة']],
        'الكويت'   => ['KW', ['كل المدن', 'مدينة الكويت', 'حولي', 'الفروانية']],
    ];
}

function research_periods(): array { return ['آخر 3 شهور', 'آخر 6 شهور', 'آخر 12 شهر']; }
function research_langs(): array   { return ['العربية', 'الإنجليزية', 'الاتنين']; }

/** العمق => [الاسم, عدد نتايج البحث لكل طلب, سياق OpenAI] */
function research_depths(): array
{
    return [
        'quick'  => ['سريع', 5, 'low'],
        'medium' => ['متوسط', 6, 'medium'],
        'deep'   => ['عميق', 8, 'high'],
    ];
}

function research_cost(string $depth): int
{
    $def = ['quick' => 2, 'medium' => 4, 'deep' => 8][$depth] ?? 4;
    return max(0, (int) get_setting('research_cost_' . $depth, $def));
}

/** أنواع المصادر: key => [emoji, label] */
function research_src_kinds(): array
{
    return [
        'web'     => ['🌐', 'مواقع'],
        'news'    => ['📰', 'أخبار'],
        'data'    => ['📊', 'بيانات سوق'],
        'social'  => ['📱', 'سوشيال ميديا'],
        'comp'    => ['🏢', 'مواقع المنافسين'],
        'reports' => ['📚', 'تقارير'],
        'search'  => ['🔎', 'نتائج البحث'],
    ];
}

/** المحاور: key => [الاسم, نشاط «بيفكر»] */
function research_axes_meta(): array
{
    return [
        'market'      => ['السوق', 'يبحث عن بيانات السوق...'],
        'competitors' => ['المنافسين', 'يقارن المنافسين...'],
        'audience'    => ['الجمهور', 'يحلل احتياجات الجمهور...'],
        'content'     => ['المحتوى', 'يحلل أساليب المحتوى...'],
        'pricing'     => ['الأسعار والعروض', 'يجمع الأسعار والعروض المعلنة...'],
        'trends'      => ['الكلمات والاتجاهات', 'يرصد الكلمات والاتجاهات...'],
    ];
}

/** فئات «احفظ في Brand Brain» */
function research_insight_cats(): array
{
    return ['جمهور السوق', 'احتياجات العملاء', 'المنافسين', 'اتجاهات السوق', 'فرص المحتوى', 'الكلمات والموضوعات', 'الأسعار', 'الملاحظات التسويقية'];
}

/* ═══════════ النطاق والخطة ═══════════ */

/** النطاق الافتراضي من الهوية (مصري = مصر، خليجي = السعودية) */
function research_default_scope(?array $brand): array
{
    $gulf = in_array($brand['dialect'] ?? '', ['gulf', 'khaleeji'], true);
    return ['market' => $gulf ? 'السعودية' : 'مصر', 'city' => 'كل المدن', 'period' => 'آخر 12 شهر', 'lang' => 'العربية',
            'depth' => 'deep', 'opt_comp' => true, 'opt_content' => true, 'opt_price' => true, 'src_off' => []];
}

/** تنضيف النطاق من الواجهة — أي قيمة برّه القوايم بترجع للافتراضي */
function research_scope_clean(array $in, ?array $brand): array
{
    $d = research_default_scope($brand);
    $m = research_markets();
    $s = [];
    $s['market'] = isset($m[$in['market'] ?? '']) ? $in['market'] : $d['market'];
    $s['city'] = in_array($in['city'] ?? '', $m[$s['market']][1], true) ? $in['city'] : 'كل المدن';
    $s['period'] = in_array($in['period'] ?? '', research_periods(), true) ? $in['period'] : $d['period'];
    $s['lang'] = in_array($in['lang'] ?? '', research_langs(), true) ? $in['lang'] : $d['lang'];
    $s['depth'] = isset(research_depths()[$in['depth'] ?? '']) ? $in['depth'] : $d['depth'];
    foreach (['opt_comp', 'opt_content', 'opt_price'] as $k) {
        $s[$k] = array_key_exists($k, $in) ? !empty($in[$k]) && $in[$k] !== '0' : $d[$k];
    }
    $off = is_array($in['src_off'] ?? null) ? $in['src_off'] : [];
    $s['src_off'] = array_values(array_intersect(array_keys(research_src_kinds()), array_map('strval', $off)));
    // مصادر مثبّتة من بحث سابق (بتتراجع تاني في التحديث)
    $pins = is_array($in['pins'] ?? null) ? $in['pins'] : [];
    $s['pins'] = array_values(array_slice(array_filter(array_map('research_norm_url', array_map('strval', $pins))), 0, 8));
    return $s;
}

function research_objectives(string $type, array $s): array
{
    $o = [];
    if (in_array($type, ['market', 'marketing', 'custom', 'trends'], true)) $o[] = 'تحليل السوق';
    if ($s['opt_comp'] || $type === 'competitors') $o[] = 'تحليل المنافسين';
    $o[] = 'فهم الجمهور';
    if ($s['opt_content'] || $type === 'content') $o[] = 'تحليل المحتوى';
    if ($s['opt_price'] || $type === 'pricing') $o[] = 'مقارنة الأسعار والعروض';
    if (in_array($type, ['trends', 'content', 'marketing'], true)) $o[] = 'الكلمات والاتجاهات';
    $o[] = 'استخراج الفرص التسويقية';
    return $o;
}

/** المحاور اللي هتتبحث (بنفس ترتيب العرض) */
function research_axes(string $type, array $s): array
{
    $a = [];
    if (in_array($type, ['market', 'marketing', 'custom', 'trends'], true) || $s['depth'] === 'deep') $a[] = 'market';
    if ($s['opt_comp'] || in_array($type, ['competitors', 'pricing'], true)) $a[] = 'competitors';
    $a[] = 'audience';
    if ($s['opt_content'] || $type === 'content') $a[] = 'content';
    if ($s['opt_price'] || $type === 'pricing') $a[] = 'pricing';
    if (in_array($type, ['trends', 'content', 'marketing', 'market'], true) || $s['depth'] === 'deep') $a[] = 'trends';
    return array_values(array_unique($a));
}

/**
 * خطوات التنفيذ: فهم · نطاق (فورية) ← طلبات البحث (حسب العمق) ← تحليل ← فرص ← كتابة
 * سريع = طلب واحد لكل المحاور · متوسط = كل محورين في طلب · عميق = طلب لكل محور
 */
function research_build_steps(string $type, array $s): array
{
    $axes = research_axes($type, $s);
    $meta = research_axes_meta();
    $groups = match ($s['depth']) {
        'quick' => [$axes],
        'medium' => array_chunk($axes, 2),
        default => array_map(fn($a) => [$a], $axes),
    };
    $steps = [
        ['key' => 'understand', 'label' => 'فهم سؤال البحث', 'done' => true, 'tries' => 0],
        ['key' => 'scope', 'label' => 'تحديد نطاق البحث', 'done' => true, 'tries' => 0],
    ];
    foreach ($groups as $i => $g) {
        $names = array_map(fn($a) => $meta[$a][0], $g);
        $label = $i === 0 && count($groups) > 1 ? 'جمع المصادر: ' : 'تحليل ';
        if (count($groups) === 1) $label = 'جمع المصادر وتحليلها: ';
        $steps[] = ['key' => 'search', 'axes' => $g, 'label' => $label . implode(' و', $names), 'done' => false, 'tries' => 0];
    }
    $steps[] = ['key' => 'analyze', 'label' => 'تحليل النتائج ومقارنتها', 'done' => false, 'tries' => 0];
    $steps[] = ['key' => 'opps', 'label' => 'استخراج الفرص', 'done' => false, 'tries' => 0];
    $steps[] = ['key' => 'write', 'label' => 'كتابة التقرير', 'done' => false, 'tries' => 0];
    return $steps;
}

function research_default_title(string $type, string $question, array $s): string
{
    $t = research_types()[$type][1] ?? 'بحث';
    if ($type === 'custom') {
        $q = trim(preg_replace('/\s+/u', ' ', $question));
        return mb_strlen($q) > 70 ? mb_substr($q, 0, 68) . '…' : $q;
    }
    return $t . ' — ' . $s['market'] . ($s['city'] !== 'كل المدن' ? ' (' . $s['city'] . ')' : '');
}

/* ═══════════ محرك البحث ═══════════ */

/**
 * المحرك المتاح: البحث العميق شغال على مسار الـ Smart Router بس
 * (البوابة الموحدة — «الموديلات والـ Router» في الإدارة: مهام «بحث الويب» و«تحليل البحث»).
 * المسار القديم (استدعاء مباشر بمفتاح الإعدادات) اتشال — مفيش بحث من بره الـ Router.
 */
function research_engine(): array
{
    if (!function_exists('ai_gw_enabled')) {
        @include_once __DIR__ . '/ai-gateway.php';
    }
    if (!function_exists('ai_gw_enabled') || !ai_gw_enabled()) {
        return ['mode' => 'none', 'ok' => false, 'msg' => 'البحث العميق شغّال على الـ Smart Router بس — الإدارة لازم تشغّله من «الموديلات والـ Router»'];
    }
    $chain = ai_gw_chain('research_search', ['customer_data' => true]);
    if (!$chain) {
        return ['mode' => 'none', 'ok' => false, 'msg' => 'البحث في الويب مش متاح حاليًا في الـ Smart Router — كلّم الإدارة'];
    }
    return ['mode' => 'gateway', 'ok' => true, 'model' => $chain[0]['m'], 'key' => '', 'url' => '', 'msg' => '',
            'chain' => array_map(fn($c) => $c['p']['name'] . ' · ' . $c['m'], $chain)];
}

/** تحليل/فرص البحث — عن طريق الـ Smart Router بس (مهمة «تحليل البحث») */
function research_ai(string $prompt, int $maxTokens, string $task, array $ctx = []): array
{
    if (!function_exists('ai_gw_enabled') || !ai_gw_enabled()) {
        return ['ok' => false, 'response' => null, 'error' => research_engine()['msg'], 'usage' => ['in' => 0, 'out' => 0], 'model' => 'none'];
    }
    if (function_exists('ai_log_set_context')) {
        ai_log_set_context(['kind' => $task, 'user_id' => $ctx['user_id'] ?? null,
            'reference_type' => 'research', 'reference_id' => $ctx['reference_id'] ?? null]);
    }
    return ai_gw_generate_text($prompt, [], $maxTokens, (float) ($ctx['temperature'] ?? 0.3), usage_task_for_kind($task));
}

/**
 * طلب بحث ويب واحد
 * @return array{ok:bool, text:string, cites:array, error:?string, model:string}
 */
function research_web_call(string $prompt, array $scope, array $ctx = []): array
{
    // 8-أ: البوابة الموحدة (بحث ويب بالبدائل: OpenRouter ← Perplexity ← OpenAI …)
    if (function_exists('ai_gw_enabled') && ai_gw_enabled()) {
        [$depthName, $maxResults, $context] = research_depths()[$scope['depth'] ?? 'medium'] ?? research_depths()['medium'];
        $country = research_markets()[$scope['market'] ?? ''][0] ?? 'EG';
        // في وضع البوابة الموديل بيتحدد من «الموديلات والـ Router» (research_model للمسار القديم بس)
        ai_log_set_context(['kind' => 'research_search', 'user_id' => $ctx['user_id'] ?? null,
            'reference_type' => 'research', 'reference_id' => $ctx['reference_id'] ?? null]);
        $r = ai_run('research_search', ['prompt' => $prompt, 'max_results' => $maxResults, 'search_context' => $context,
            'country' => $country, 'max_tokens' => 2200], [
            'user_id' => $ctx['user_id'] ?? null, 'ref_type' => 'research', 'ref_id' => $ctx['reference_id'] ?? null, 'feature' => 'research_search',
        ]);
        ai_gw_debug_log('research_search', ai_log_context(), $prompt, [], $r);
        if (!$r['ok']) {
            return ['ok' => false, 'text' => '', 'cites' => [], 'error' => ($r['error_class'] ?? '') === 'rate_limit'
                ? 'محرك البحث مشغول — جرّب بعد دقيقة' : 'محرك البحث مارجعش نتيجة — جرّب تاني', 'model' => (string) ($r['model'] ?? '')];
        }
        $data = (array) ($r['data'] ?? []);
        return research_web_parse($data, (string) $r['text'], (string) $r['model'], (string) ($r['kind'] ?? $r['provider']));
    }

    // المسار القديم (curl مباشر بمفتاح الإعدادات) اتشال — البحث بيعدّي من الـ Smart Router بس
    return ['ok' => false, 'text' => '', 'cites' => [], 'error' => research_engine()['msg'], 'model' => ''];
}

/** المصادر من رد البحث: annotations (OpenRouter/OpenAI) · citations/search_results (Perplexity) */
function research_web_parse(array $data, string $text, string $model, string $mode): array
{
    $msg = $data['choices'][0]['message'] ?? [];
    $cites = [];
    foreach ((array) ($msg['annotations'] ?? []) as $a) {
        if (!is_array($a)) continue;
        $u = $a['url_citation'] ?? (($a['type'] ?? '') === 'url_citation' ? $a : null);
        if (is_array($u) && !empty($u['url'])) {
            $cites[] = ['url' => (string) $u['url'], 'title' => (string) ($u['title'] ?? ''), 'snippet' => (string) ($u['content'] ?? '')];
        }
    }
    foreach ((array) ($data['search_results'] ?? []) as $sr) {
        if (!empty($sr['url'])) $cites[] = ['url' => (string) $sr['url'], 'title' => (string) ($sr['title'] ?? ''), 'snippet' => (string) ($sr['snippet'] ?? ''), 'date' => (string) ($sr['date'] ?? '')];
    }
    $ordered = [];
    foreach ((array) ($data['citations'] ?? []) as $cu) {
        if (is_string($cu)) { $ordered[] = $cu; $cites[] = ['url' => $cu, 'title' => '', 'snippet' => '']; }
    }
    return ['ok' => true, 'text' => $text, 'cites' => $cites, 'ordered' => $ordered, 'error' => null, 'model' => $model, 'mode' => $mode];
}

/* ═══════════ المصادر ═══════════ */

/** رابط نضيف وآمن (http/https بس) — من غير utm وfragment */
function research_norm_url(string $u): ?string
{
    $u = trim(html_entity_decode($u, ENT_QUOTES, 'UTF-8'), " \t\n\r\0\x0B.,;:!؟،)]}>\"'");
    if ($u === '' || strlen($u) > 600 || !preg_match('#^https?://#i', $u)) return null;
    $p = parse_url($u);
    if (!$p || empty($p['host']) || !preg_match('/^[a-z0-9.-]+$/i', $p['host'])) return null;
    $q = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $qs);
        foreach (array_keys($qs) as $k) {
            if (preg_match('/^(utm_|fbclid|gclid|mc_|ref$|ref_src$)/i', (string) $k)) unset($qs[$k]);
        }
        $q = $qs ? '?' . http_build_query($qs) : '';
    }
    $path = $p['path'] ?? '/';
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $path . $q;
}

function research_host(string $url): string
{
    return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
}

/** مفتاح المقارنة: host من غير www + المسار من غير / في الآخر */
function research_url_key(string $url): string
{
    $p = parse_url($url);
    return research_host($url) . rtrim((string) ($p['path'] ?? ''), '/') . (isset($p['query']) ? '?' . $p['query'] : '');
}

function research_source_kind(string $url): string
{
    $h = research_host($url);
    $is = fn(string $re) => (bool) preg_match($re, $h);
    if ($is('/(^|\.)(facebook|fb|instagram|tiktok|youtube|youtu|x|twitter|linkedin|snapchat|reddit|threads|pinterest)\.(com|be|net)$/')) return 'social';
    if ($is('/(^|\.)(google|bing|duckduckgo|yahoo)\./') || $is('/^maps\./')) return 'search';
    if ($is('/(statista|worldbank|imf\.org|capmas|stats\.|gastat|fcsc|mordorintelligence|grandviewresearch|marketresearch|imarcgroup|kenresearch|6wresearch|euromonitor|ibisworld|data\.)/')) return 'data';
    if ($is('/(\.gov(\.[a-z]{2})?$|who\.int|un\.org|pwc\.|deloitte\.|kpmg\.|mckinsey\.|ey\.com|bcg\.com|report)/')) return 'reports';
    if ($is('/(news|youm7|masrawy|ahram|alarabiya|aljazeera|sabq|okaz|argaam|almasryalyoum|elwatannews|shorouknews|cnn|bbc|reuters|bloomberg|skynewsarabia|gulfnews|khaleejtimes|arabnews|zawya|alkhaleej|albayan|alqabas|alanba|alyaum|almal|enterprise\.press|elaosboa|cairo24|filgoal)/')) return 'news';
    return 'web';
}

/**
 * تسجيل رابط في قايمة المصادر العامة ← رقمه [n]
 * @param array $reg ['list' => [n => source], 'keys' => [key => n]]
 */
function research_register(array &$reg, string $url, string $title = '', string $snippet = '', string $axis = '', string $date = ''): ?int
{
    $u = research_norm_url($url);
    if (!$u) return null;
    $k = research_url_key($u);
    if (isset($reg['keys'][$k])) {
        $n = $reg['keys'][$k];
        $s = &$reg['list'][$n];
        if ($s['title'] === '' && $title !== '') $s['title'] = mb_substr(strip_tags($title), 0, 200);
        if ($s['snippet'] === '' && $snippet !== '') $s['snippet'] = mb_substr(strip_tags($snippet), 0, 400);
        if ($axis !== '' && !in_array($axis, $s['axes'], true)) $s['axes'][] = $axis;
        return $n;
    }
    if (count($reg['list']) >= 60) return null;
    $n = count($reg['list']) + 1;
    $reg['keys'][$k] = $n;
    $reg['list'][$n] = ['n' => $n, 'url' => $u, 'site' => research_host($u), 'title' => mb_substr(strip_tags($title), 0, 200),
                        'snippet' => mb_substr(strip_tags($snippet), 0, 400), 'kind' => research_source_kind($u),
                        'axes' => $axis !== '' ? [$axis] : [], 'took' => '', 'date' => mb_substr($date, 0, 20), 'pinned' => false, 'excluded' => false];
    return $n;
}

/**
 * نص البحث ← نفس النص بأرقام المصادر [n] بدل الروابط
 * (روابط markdown · روابط عادية · [1] بتاعة Perplexity)
 */
function research_cite_text(string $text, array &$reg, array $call, string $axis): string
{
    foreach ($call['cites'] ?? [] as $c) {
        research_register($reg, $c['url'], $c['title'] ?? '', $c['snippet'] ?? '', $axis, $c['date'] ?? '');
    }
    // Perplexity: [1] = citations[0]
    if (!empty($call['ordered'])) {
        $ord = $call['ordered'];
        $text = preg_replace_callback('/\[(\d{1,2})\](?!\()/', function ($m) use (&$reg, $ord, $axis) {
            $u = $ord[(int) $m[1] - 1] ?? null;
            $n = $u ? research_register($reg, $u, '', '', $axis) : null;
            return $n ? '[' . $n . ']' : '';
        }, $text);
    }
    // [label](url)
    $text = preg_replace_callback('/\[([^\]\n]{0,200})\]\((https?:\/\/[^\s)]+)\)/u', function ($m) use (&$reg, $axis) {
        $n = research_register($reg, $m[2], '', '', $axis);
        $label = trim($m[1]);
        $plain = $label === '' || preg_match('/^(\d+|[\w.-]+\.[a-z]{2,}(\/\S*)?|source|المصدر|مصدر|link|رابط)$/iu', $label);
        return ($plain ? '' : $label . ' ') . ($n ? '[' . $n . ']' : '');
    }, $text);
    // روابط عادية
    $text = preg_replace_callback('/<?https?:\/\/[^\s<>"\'\]\)]+>?/u', function ($m) use (&$reg, $axis) {
        $n = research_register($reg, trim($m[0], '<>'), '', '', $axis);
        return $n ? '[' . $n . ']' : '';
    }, $text);
    $text = preg_replace('/\(\s*((\[\d+\]\s*,?\s*)+)\)/u', '$1', $text);          // ([3]) ← [3]
    $text = preg_replace('/\[\[(\d+)\]\]/', '[$1]', $text);
    return trim($text);
}

/* ═══════════ البرومبتات ═══════════ */

function research_brand_line(?array $brand): string
{
    if (!$brand) return '';
    $p = [];
    foreach (['business_name' => 'النشاط', 'industry' => 'المجال', 'services' => 'الخدمات', 'audience' => 'الجمهور', 'address' => 'العنوان'] as $k => $l) {
        if (!empty($brand[$k])) $p[] = $l . ': ' . mb_substr(trim((string) $brand[$k]), 0, 220);
    }
    return $p ? "عن صاحب البحث (للتركيز بس — ماتبحثش عنه هو):\n- " . implode("\n- ", $p) . "\n" : '';
}

function research_axis_brief(string $axis): string
{
    return [
        'market' => 'السوق: حجم السوق ومعدل النمو (بس لو فيه رقم منشور بمصدر)، اتجاهات الطلب، افتتاحات أو تغييرات مهمة، التنظيمات، المخاطر.',
        'competitors' => 'المنافسين: أهم 3–6 منافسين فعليين في النطاق ده — الاسم، رابط موقعهم أو صفحتهم، التموضع، الخدمات، الأسعار المعلنة بس، العروض، نشاطهم على السوشيال، نقطة قوة، فجوة واضحة.',
        'audience' => 'الجمهور: مين العملاء، احتياجاتهم، الأسئلة والاعتراضات المتكررة (من نتائج البحث والمنتديات والتعليقات والمراجعات)، محفزات الشراء، الديموغرافيا لو فيه مصدر.',
        'content' => 'المحتوى: أنواع المحتوى الأكثر انتشارًا في المجال ده على السوشيال، الموضوعات والصيغ (ريلز، قبل/بعد، تعليمي...)، الـ Hooks وأنماط الـ CTA المتكررة، الفجوات.',
        'pricing' => 'الأسعار والعروض: الأسعار المعلنة فعلًا لكل منافس بمصدرها، هيكل الباقات والعروض. أي سعر مش معلن في مصدر واضح اكتب «غير متاح» — ممنوع التقدير.',
        'trends' => 'الكلمات والاتجاهات: الكلمات والعبارات اللي الناس بتدور بيها، الموضوعات الصاعدة، الأسئلة الشائعة في محركات البحث.',
    ][$axis] ?? '';
}

function research_search_prompt(array $r, array $axes, ?array $brand, array $scope): string
{
    $kinds = research_src_kinds();
    $on = array_diff(array_keys($kinds), $scope['src_off']);
    $onL = implode('، ', array_map(fn($k) => $kinds[$k][1], $on));
    $offL = implode('، ', array_map(fn($k) => $kinds[$k][1], $scope['src_off']));
    $where = $scope['market'] . ($scope['city'] !== 'كل المدن' ? ' — ' . $scope['city'] : '');
    $lang = ['العربية' => 'مصادر عربية أساسًا', 'الإنجليزية' => 'مصادر إنجليزية أساسًا', 'الاتنين' => 'مصادر عربية وإنجليزية'][$scope['lang']] ?? '';
    $pins = $scope['pins'] ? "\nراجع المصادر دي كمان (العميل ثبّتها):\n- " . implode("\n- ", $scope['pins']) . "\n" : '';
    $brief = implode("\n", array_map(fn($a) => '• ' . research_axis_brief($a), $axes));

    return "[[RESEARCH_AXIS:" . implode(',', $axes) . "]]\n"
        . "أنت باحث سوق محترف. ابحث في الويب دلوقتي وجاوب بمعلومات حقيقية من مصادر.\n\n"
        . "سؤال البحث: «" . mb_substr((string) $r['question'], 0, 800) . "»\n"
        . "النطاق: {$where} · الفترة: {$scope['period']} · {$lang}\n"
        . research_brand_line($brand)
        . "\nالمطلوب في الطلب ده:\n{$brief}\n"
        . "\nأنواع المصادر المفضلة: {$onL}" . ($offL !== '' ? "\nتجنّب: {$offL}" : '') . "\n{$pins}"
        . "\nقواعد صارمة:\n"
        . "- كل معلومة لازم يكون وراها مصدر حقيقي لقيته في البحث ده، واكتب رابطه جنبها مباشرة.\n"
        . "- ممنوع تأليف أرقام أو أسعار أو أسماء أو إحصائيات. اللي مالقيتلوش مصدر اكتب «غير متاح».\n"
        . "- ركّز على الفترة المطلوبة والنطاق المطلوب.\n"
        . "- اكتب بالعربي في نقاط قصيرة (8–15 نقطة لكل محور)، تحت عنوان لكل محور، وكل نقطة = معلومة واحدة + رابط مصدرها.";
}

/** برومبت التحليل المنظّم (JSON) — المصادر بأرقامها */
function research_analyze_prompt(array $r, array $axesTexts, array $sources, ?array $brand, array $scope, string $type): string
{
    $src = [];
    foreach ($sources as $s) {
        $src[] = '[' . $s['n'] . '] ' . $s['site'] . ($s['title'] !== '' ? ' — ' . mb_substr($s['title'], 0, 110) : '');
    }
    $find = [];
    foreach ($axesTexts as $a) {
        if (!empty($a['ok'])) $find[] = '### ' . $a['label'] . "\n" . mb_substr((string) $a['text'], 0, 7000);
    }
    $crit = in_array($type, ['pricing', 'competitors'], true) ? 'أسعار معلنة، عروض متكررة، محتوى تعليمي، قبل / بعد، نشاط السوشيال' : 'أسعار معلنة، محتوى تعليمي، قبل / بعد، عروض متكررة';
    $schema = <<<JSON
{
 "title": "عنوان قصير للبحث",
 "summary": {"market": {"t": "جملة", "c": [1]}, "audience": {"t": "", "c": []}, "competitors": {"t": "", "c": []}, "content": {"t": "", "c": []}, "opps": {"t": "", "c": []}},
 "market": {"overview": "فقرة قصيرة", "points": [{"k": "trend|demand|opportunity|risk", "t": "", "c": []}], "indicators": [{"label": "حجم السوق", "value": "", "c": []}]},
 "audience": {"confirmed": [{"t": "", "c": []}], "inferred": ["استنتاج بدون مصدر"], "profile": {"demographics": "", "needs": "", "pains": "", "triggers": ""}},
 "criteria": ["معيار 1", "معيار 2"],
 "competitors": [{"name": "", "site": "https://", "positioning": "", "services": "", "prices": "", "offers": "", "social": "", "strength": "", "gap": "", "c": [], "matrix": ["قيمة لكل معيار بنفس الترتيب"]}],
 "content": {"formats": [{"t": "", "c": []}], "hooks": [{"t": "", "c": []}], "ideas": ["فكرة محتوى"]},
 "pricing": [{"competitor": "", "price": "", "structure": "", "c": []}],
 "keywords": ["كلمة"],
 "trends": [{"t": "", "c": []}],
 "sources": [{"n": 1, "took": "إيه اللي اتاخد من المصدر ده في 8 كلمات", "date": "السنة أو —"}]
}
JSON;
    return "[[RESEARCH_ANALYZE]]\n"
        . "أنت محلل أبحاث سوق. عندك نتايج بحث ويب حقيقية بأرقام مصادرها. نظّمها في JSON بالشكل المطلوب بالظبط.\n\n"
        . "سؤال البحث: «" . mb_substr((string) $r['question'], 0, 600) . "»\n"
        . 'النطاق: ' . $scope['market'] . ' · ' . $scope['city'] . ' · ' . $scope['period'] . "\n"
        . research_brand_line($brand)
        . "\nالمصادر:\n" . implode("\n", $src)
        . "\n\nنتايج البحث:\n" . implode("\n\n", $find)
        . "\n\nالقواعد:\n"
        . "- \"c\" = أرقام المصادر اللي فوق بس (أرقام صحيحة). المعلومة اللي مالهاش مصدر في النتايج: \"c\": [].\n"
        . "- ممنوع أي رقم أو سعر أو اسم مش موجود في النتايج. السعر المش معلن = \"غير متاح\".\n"
        . "- indicators: بس لو فيه رقم موثّق بمصدر، وإلا سيبها [].\n"
        . "- audience.confirmed = من المصادر (لازم c)، audience.inferred = استنتاجك (من غير أرقام).\n"
        . "- criteria: 3–5 معايير مقارنة زي ({$crit})، وmatrix لكل منافس بنفس الترتيب (نعم/لا/قليل/أحيانًا/غير متاح...).\n"
        . "- الأقسام اللي مالهاش نتايج خليها فاضية. اكتب بالعربي المختصر.\n"
        . "- رد بـ JSON بس من غير أي كلام قبله أو بعده:\n" . $schema;
}

/** برومبت الفرص والخلاصة (JSON) */
function research_opps_prompt(array $r, array $result, array $axesTexts, ?array $brand): string
{
    $brief = [
        'summary' => $result['summary'] ?? [],
        'market' => array_slice($result['market']['points'] ?? [], 0, 8),
        'audience' => $result['audience'] ?? [],
        'competitors' => array_map(fn($c) => ['name' => $c['name'], 'positioning' => $c['positioning'], 'gap' => $c['gap'], 'c' => $c['c']], $result['competitors'] ?? []),
        'content' => $result['content'] ?? [],
        'pricing' => $result['pricing'] ?? [],
        'trends' => $result['trends'] ?? [],
        'keywords' => $result['keywords'] ?? [],
    ];
    $cats = implode('، ', research_insight_cats());
    return "[[RESEARCH_OPPS]]\n"
        . "أنت استراتيجي تسويق. ده تحليل بحث سوق حقيقي (الأرقام بين [] = أرقام مصادر):\n"
        . json_encode($brief, JSON_UNESCAPED_UNICODE) . "\n\n"
        . 'سؤال البحث: «' . mb_substr((string) $r['question'], 0, 500) . "»\n"
        . research_brand_line($brand)
        . "\nالمطلوب JSON بالشكل ده بالظبط:\n"
        . '{"opps": [{"type": "فرصة محتوى|فرصة تموضع|فرصة جمهور|فرصة عرض|فرصة سوق", "headline": "", "why": "", "evidence": [أرقام مصادر], "use": "إزاي تستخدمها في المحتوى أو الحملات"}],'
        . ' "insights": {"جمهور السوق": "", "احتياجات العملاء": "", "المنافسين": "", "اتجاهات السوق": "", "فرص المحتوى": "", "الكلمات والموضوعات": "", "الأسعار": "", "الملاحظات التسويقية": ""},'
        . ' "conclusion": "خلاصة في 3 جمل"}' . "\n\n"
        . "القواعد:\n- 3 إلى 6 فرص عملية، evidence من أرقام المصادر الموجودة بس.\n"
        . "- insights ({$cats}): كل واحدة 1–3 جمل مختصرة تتحفظ في هوية البراند وتستخدم في كتابة المحتوى. سيب الفاضي \"\".\n"
        . "- ممنوع أرقام أو أسعار مش موجودة في التحليل. رد بـ JSON بس.";
}

/* ═══════════ تنضيف النتيجة ═══════════ */

function research_json(string $raw): ?array
{
    $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));
    $a = strpos($raw, '{');
    $b = strrpos($raw, '}');
    if ($a === false || $b === false || $b <= $a) return null;
    $j = json_decode(substr($raw, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}

/**
 * النتيجة بعد التحقق: أي رقم مصدر مش موجود بيتشال · السعر من غير مصدر = غير متاح
 * · المؤشرات من غير مصدر بتتشال · الجمهور «المؤكد» من غير مصدر بيروح للاستنتاج
 */
function research_clean_result(array $a, array $b, array $sources): array
{
    $valid = array_flip(array_map(fn($s) => (int) $s['n'], $sources));
    $txt = function ($v, int $max = 400): string {
        if (!is_scalar($v)) return '';
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    };
    $cites = function ($c) use ($valid): array {
        $out = [];
        foreach ((array) $c as $n) {
            $n = (int) (is_string($n) ? trim($n, '[] ') : $n);
            if (isset($valid[$n]) && !in_array($n, $out, true)) $out[] = $n;
        }
        return array_slice($out, 0, 6);
    };
    $item = fn($x, int $max = 300) => is_array($x) ? ['t' => $txt($x['t'] ?? ($x['text'] ?? ''), $max), 'c' => $cites($x['c'] ?? [])] : ['t' => $txt($x, $max), 'c' => []];
    $items = function ($list, int $limit, int $max = 300) use ($item) {
        $out = [];
        foreach ((array) $list as $x) {
            $i = $item($x, $max);
            if ($i['t'] !== '') $out[] = $i;
            if (count($out) >= $limit) break;
        }
        return $out;
    };
    $strs = function ($list, int $limit, int $max = 200) use ($txt) {
        $out = [];
        foreach ((array) $list as $x) {
            $x = $txt(is_array($x) ? ($x['t'] ?? '') : $x, $max);
            if ($x !== '' && !in_array($x, $out, true)) $out[] = $x;
            if (count($out) >= $limit) break;
        }
        return $out;
    };

    $res = ['title' => $txt($a['title'] ?? '', 120)];

    $sum = [];
    foreach (['market', 'audience', 'competitors', 'content', 'opps'] as $k) {
        $sum[$k] = $item($a['summary'][$k] ?? '', 220);
    }
    $res['summary'] = $sum;

    $points = [];
    foreach ((array) ($a['market']['points'] ?? []) as $p) {
        $i = $item($p, 260);
        if ($i['t'] === '') continue;
        $k = is_array($p) ? (string) ($p['k'] ?? '') : '';
        $i['k'] = in_array($k, ['trend', 'demand', 'opportunity', 'risk'], true) ? $k : 'trend';
        $points[] = $i;
        if (count($points) >= 8) break;
    }
    $ind = [];
    foreach ((array) ($a['market']['indicators'] ?? []) as $x) {
        if (!is_array($x)) continue;
        $c = $cites($x['c'] ?? []);
        $v = $txt($x['value'] ?? '', 80);
        if (!$c || $v === '' || preg_match('/غير متاح|غير معروف|n\/a/iu', $v)) continue;   // المؤشر بيظهر بس لو موثّق
        $ind[] = ['label' => $txt($x['label'] ?? '', 40), 'value' => $v, 'c' => $c];
        if (count($ind) >= 4) break;
    }
    $res['market'] = ['overview' => $txt($a['market']['overview'] ?? '', 600), 'points' => $points, 'indicators' => $ind];

    $conf = [];
    $inf = $strs($a['audience']['inferred'] ?? [], 6, 220);
    foreach ((array) ($a['audience']['confirmed'] ?? []) as $x) {
        $i = $item($x, 240);
        if ($i['t'] === '') continue;
        if ($i['c']) $conf[] = $i; elseif (count($inf) < 8) $inf[] = $i['t'];
        if (count($conf) >= 8) break;
    }
    $prof = [];
    foreach (['demographics', 'needs', 'pains', 'triggers'] as $k) $prof[$k] = $txt($a['audience']['profile'][$k] ?? '', 160);
    $res['audience'] = ['confirmed' => $conf, 'inferred' => $inf, 'profile' => $prof];

    $crit = $strs($a['criteria'] ?? [], 5, 40);
    $comps = [];
    foreach ((array) ($a['competitors'] ?? []) as $x) {
        if (!is_array($x) || $txt($x['name'] ?? '', 80) === '') continue;
        $c = $cites($x['c'] ?? []);
        $site = research_norm_url((string) ($x['site'] ?? '')) ?? '';
        $price = $txt($x['prices'] ?? '', 160);
        if (!$c || $price === '') $price = 'غير معلن';
        $mx = [];
        foreach (array_keys($crit) as $ci) $mx[] = $txt($x['matrix'][$ci] ?? '—', 30) ?: '—';
        $comps[] = ['name' => $txt($x['name'], 80), 'site' => $site, 'positioning' => $txt($x['positioning'] ?? '', 160),
                    'services' => $txt($x['services'] ?? '', 200), 'prices' => $price, 'offers' => $txt($x['offers'] ?? '', 160),
                    'social' => $txt($x['social'] ?? '', 160), 'strength' => $txt($x['strength'] ?? '', 160),
                    'gap' => $txt($x['gap'] ?? '', 160), 'c' => $c, 'matrix' => $mx];
        if (count($comps) >= 6) break;
    }
    $res['criteria'] = $comps ? $crit : [];
    $res['competitors'] = $comps;

    $res['content'] = ['formats' => $items($a['content']['formats'] ?? [], 6), 'hooks' => $items($a['content']['hooks'] ?? [], 6),
                       'ideas' => $strs($a['content']['ideas'] ?? [], 6, 200)];

    $pr = [];
    foreach ((array) ($a['pricing'] ?? []) as $x) {
        if (!is_array($x) || $txt($x['competitor'] ?? '', 80) === '') continue;
        $c = $cites($x['c'] ?? []);
        $price = $txt($x['price'] ?? '', 80);
        $has = $c && $price !== '' && !preg_match('/غير (متاح|معلن)|n\/a/iu', $price);
        $pr[] = ['competitor' => $txt($x['competitor'], 80), 'price' => $has ? $price : 'غير متاح', 'structure' => $txt($x['structure'] ?? '', 120),
                 'c' => $c, 'status' => $has ? 'يحتاج تأكيد' : 'غير معلن'];
        if (count($pr) >= 8) break;
    }
    $res['pricing'] = $pr;
    $res['keywords'] = $strs($a['keywords'] ?? [], 14, 40);
    $res['trends'] = $items($a['trends'] ?? [], 8, 240);

    $opps = [];
    foreach ((array) ($b['opps'] ?? []) as $x) {
        if (!is_array($x) || $txt($x['headline'] ?? '', 200) === '') continue;
        $opps[] = ['type' => $txt($x['type'] ?? 'فرصة', 30) ?: 'فرصة', 'headline' => $txt($x['headline'], 200), 'why' => $txt($x['why'] ?? '', 260),
                   'evidence' => $cites($x['evidence'] ?? []), 'use' => $txt($x['use'] ?? '', 240)];
        if (count($opps) >= 6) break;
    }
    $res['opps'] = $opps;
    $ins = [];
    foreach (research_insight_cats() as $cat) {
        $v = $txt($b['insights'][$cat] ?? '', 500);
        if ($v !== '') $ins[$cat] = $v;
    }
    $res['insights'] = $ins;
    $res['conclusion'] = $txt($b['conclusion'] ?? '', 700);

    // اللي اتاخد من كل مصدر
    $meta = [];
    foreach ((array) ($a['sources'] ?? []) as $x) {
        $n = (int) ($x['n'] ?? 0);
        if (isset($valid[$n])) $meta[$n] = ['took' => $txt($x['took'] ?? '', 120), 'date' => $txt($x['date'] ?? '', 20)];
    }
    $res['source_meta'] = $meta;
    return $res;
}

/** عدد «الرؤى» زي التصميم */
function research_insight_count(array $res): int
{
    return count($res['market']['points'] ?? []) + count($res['audience']['confirmed'] ?? []) + count($res['competitors'] ?? [])
        + count($res['trends'] ?? []) + count($res['opps'] ?? []) + count($res['content']['formats'] ?? []);
}

/** الجديد بعد التحديث: معلومات · منافسين · اتجاهات */
function research_diff(array $old, array $new): array
{
    $norm = fn($s) => mb_strtolower(trim(preg_replace('/[\s\p{P}]+/u', ' ', (string) $s)));
    $set = fn(array $xs) => array_flip(array_map($norm, $xs));
    $facts = fn(array $r) => array_merge(array_column($r['market']['points'] ?? [], 't'), array_column($r['audience']['confirmed'] ?? [], 't'),
                                         array_column($r['content']['formats'] ?? [], 't'), array_column($r['pricing'] ?? [], 'price'));
    $count = function (array $a, array $b) use ($set, $norm) {
        $o = $set($a);
        return count(array_filter($b, fn($x) => !isset($o[$norm($x)])));
    };
    return [
        'facts' => $count($facts($old), $facts($new)),
        'competitors' => $count(array_column($old['competitors'] ?? [], 'name'), array_column($new['competitors'] ?? [], 'name')),
        'trends' => $count(array_merge(array_column($old['trends'] ?? [], 't'), $old['keywords'] ?? []),
                           array_merge(array_column($new['trends'] ?? [], 't'), $new['keywords'] ?? [])),
    ];
}

/* ═══════════ تنفيذ الخطوات ═══════════ */

function research_dec($v, $def = [])
{
    $j = is_string($v) && $v !== '' ? json_decode($v, true) : null;
    return is_array($j) ? $j : $def;
}

function research_row(int $id, int $uid): ?array
{
    return db_one('SELECT * FROM researches WHERE id = ? AND user_id = ?', [$id, $uid]) ?: null;
}

/** بداية تشغيل (أول مرة أو تحديث) — الكريدت بيتخصم برّه */
function research_begin(array $r, int $credits, string $engine, bool $refresh): void
{
    $scope = research_dec($r['scope_json']);
    $prev = null;
    if ($refresh) {
        $oldSrc = research_dec($r['sources_json']);
        // المصادر المثبّتة تتراجع تاني في التحديث
        $scope['pins'] = array_values(array_slice(array_map(fn($s) => $s['url'], array_filter($oldSrc, fn($s) => !empty($s['pinned']))), 0, 8));
        // النتيجة القديمة: لحساب الجديد، ولو التحديث فشل بترجع زي ما هي
        $prev = json_encode(['result' => research_dec($r['result_json']), 'sources' => $oldSrc, 'brain' => $r['brain_json']], JSON_UNESCAPED_UNICODE);
    }
    $steps = research_build_steps($r['rtype'], $scope);
    // المصادر المثبّتة بتفضل أول القايمة (بنفس بياناتها) — والبحث الجديد بيضيف عليها
    $start = [];
    if ($refresh) {
        foreach (research_dec($r['sources_json']) as $s) {
            if (empty($s['pinned'])) continue;
            $s['n'] = count($start) + 1; $s['excluded'] = false; $s['axes'] = [];
            $start[] = $s;
        }
    }
    db_run('UPDATE researches SET status = "running", step = 2, steps_json = ?, scope_json = ?, sources_json = ?, axes_json = "[]",
            prev_json = ?, changes_json = NULL, error = NULL, credits = ?, engine = ?, runs = runs + 1 WHERE id = ?',
        [json_encode($steps, JSON_UNESCAPED_UNICODE), json_encode($scope, JSON_UNESCAPED_UNICODE), json_encode($start, JSON_UNESCAPED_UNICODE),
         $prev, $credits, mb_substr($engine, 0, 80), $r['id']]);
}

/** فشل البحث كله ← استرداد الكريدت */
function research_fail(array $r, int $uid, string $msg): void
{
    if ((int) $r['credits'] > 0) {
        require_once __DIR__ . '/credits.php';
        credits_add($uid, (int) $r['credits'], 'استرداد: بحث عميق — ' . mb_substr((string) $r['title'], 0, 60), null, 'refund', (int) $r['id']);
    }
    $prev = research_dec($r['prev_json']);
    if (!empty($prev['result'])) {
        // تحديث لبحث خلصان فشل: النتيجة والمصادر القديمة ترجع زي ما هي
        db_run('UPDATE researches SET status = "done", result_json = ?, sources_json = ?, prev_json = NULL, credits = 0, error = ? WHERE id = ?',
            [json_encode($prev['result'], JSON_UNESCAPED_UNICODE), json_encode($prev['sources'] ?? [], JSON_UNESCAPED_UNICODE),
             'التحديث مااكتملش والكريدت رجع — دي النتيجة السابقة', $r['id']]);
        return;
    }
    db_run('UPDATE researches SET status = "failed", credits = 0, error = ? WHERE id = ?', [mb_substr($msg, 0, 250), $r['id']]);
}

/**
 * تنفيذ الخطوة الجاية (طلب واحد)
 * @return array{ok:bool, error:?string, retry:bool}
 */
function research_run_step(array $r, int $uid): array
{
    require_once __DIR__ . '/brand-brain.php';
    $steps = research_dec($r['steps_json']);
    $scope = research_dec($r['scope_json']);
    $axes = research_dec($r['axes_json']);
    $list = [];
    foreach (research_dec($r['sources_json']) as $s) $list[(int) $s['n']] = $s;
    $reg = ['list' => $list, 'keys' => []];
    foreach ($list as $n => $s) $reg['keys'][research_url_key($s['url'])] = $n;
    $brand = brand_for_user($uid);

    $i = null;
    foreach ($steps as $k => $s) { if (empty($s['done'])) { $i = $k; break; } }
    if ($i === null) return ['ok' => true, 'error' => null, 'retry' => false];
    $st = &$steps[$i];
    $save = function (array $extra = []) use (&$steps, &$reg, &$axes, $r, $i) {
        $sets = ['steps_json = ?', 'step = ?', 'sources_json = ?', 'axes_json = ?'];
        $vals = [json_encode($steps, JSON_UNESCAPED_UNICODE), $i + 1, json_encode(array_values($reg['list']), JSON_UNESCAPED_UNICODE),
                 json_encode($axes, JSON_UNESCAPED_UNICODE)];
        foreach ($extra as $col => $v) { $sets[] = "`{$col}` = ?"; $vals[] = $v; }
        $vals[] = $r['id'];
        db_run('UPDATE researches SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    };
    $ctx = ['user_id' => $uid, 'reference_id' => (int) $r['id'], 'reference_type' => 'research'];

    if ($st['key'] === 'search') {
        $call = research_web_call(research_search_prompt($r, $st['axes'], $brand, $scope), $scope, $ctx);
        $st['tries'] = (int) $st['tries'] + 1;
        if (!$call['ok']) {
            if ($st['tries'] < 2) { $save(); return ['ok' => false, 'error' => $call['error'], 'retry' => true]; }
            $st['done'] = true; $st['failed'] = true;          // محور واحد فشل مرتين — نكمّل بالباقي
            $axes[] = ['axes' => $st['axes'], 'label' => $st['label'], 'ok' => false, 'text' => ''];
            $save();
            return ['ok' => true, 'error' => null, 'retry' => false];
        }
        $text = research_cite_text($call['text'], $reg, $call, implode(',', $st['axes']));
        // المصادر المستبعدة من الخطة (سوشيال · أخبار ...) بتتعلّم مستبعدة
        foreach ($reg['list'] as &$s) {
            if (in_array($s['kind'], $scope['src_off'] ?? [], true)) $s['excluded'] = true;
        }
        unset($s);
        $axes[] = ['axes' => $st['axes'], 'label' => $st['label'], 'ok' => true, 'text' => mb_substr($text, 0, 12000)];
        $st['done'] = true;
        $save();
        return ['ok' => true, 'error' => null, 'retry' => false];
    }

    if ($st['key'] === 'analyze') {
        $good = array_filter($axes, fn($a) => !empty($a['ok']));
        $usable = array_filter($reg['list'], fn($s) => empty($s['excluded']));
        if (!$good || !$usable) {
            research_fail($r, $uid, 'مالقيناش مصادر كفاية للسؤال ده — جرّب توسّع السؤال أو النطاق');
            return ['ok' => false, 'error' => 'مالقيناش مصادر كفاية للسؤال ده — الكريدت رجع لرصيدك', 'retry' => false];
        }
        $prompt = research_analyze_prompt($r, $good, array_values($usable), $brand, $scope, $r['rtype']);
        $ai = research_ai($prompt, 3800, 'research_analyze', $ctx + ['temperature' => 0.2]);
        $j = $ai['ok'] ? research_json((string) $ai['response']) : null;
        $st['tries'] = (int) $st['tries'] + 1;
        if (!$j) {
            if ($st['tries'] < 2) { $save(); return ['ok' => false, 'error' => 'التحليل مااكتملش — بنحاول تاني', 'retry' => true]; }
            research_fail($r, $uid, 'التحليل فشل');
            return ['ok' => false, 'error' => 'التحليل فشل — الكريدت رجع لرصيدك', 'retry' => false];
        }
        $st['done'] = true;
        $save(['result_json' => json_encode(['_a' => $j], JSON_UNESCAPED_UNICODE)]);
        return ['ok' => true, 'error' => null, 'retry' => false];
    }

    if ($st['key'] === 'opps') {
        $tmp = research_dec($r['result_json']);
        $a = $tmp['_a'] ?? [];
        $clean = research_clean_result($a, [], array_values($reg['list']));
        $ai = research_ai(research_opps_prompt($r, $clean, $axes, $brand), 2400, 'research_opps', $ctx + ['temperature' => 0.4]);
        $j = $ai['ok'] ? research_json((string) $ai['response']) : null;
        $st['tries'] = (int) $st['tries'] + 1;
        if (!$j && $st['tries'] < 2) { $save(); return ['ok' => false, 'error' => 'بنحاول تاني', 'retry' => true]; }
        $st['done'] = true;
        $tmp['_b'] = $j ?: [];                       // الفرص مش شرط — التحليل نفسه كفاية
        $save(['result_json' => json_encode($tmp, JSON_UNESCAPED_UNICODE)]);
        return ['ok' => true, 'error' => null, 'retry' => false];
    }

    // write: النتيجة النهائية
    $tmp = research_dec($r['result_json']);
    $res = research_clean_result($tmp['_a'] ?? [], $tmp['_b'] ?? [], array_values($reg['list']));
    foreach ($res['source_meta'] as $n => $m) {
        if (isset($reg['list'][$n])) {
            $reg['list'][$n]['took'] = $m['took'];
            if ($reg['list'][$n]['date'] === '') $reg['list'][$n]['date'] = $m['date'];
        }
    }
    unset($res['source_meta']);
    // مواقع المنافسين: أي مصدر من نفس الدومين نوعه «مواقع المنافسين»
    $compHosts = array_filter(array_map(fn($c) => $c['site'] ? research_host($c['site']) : '', $res['competitors']));
    foreach ($reg['list'] as &$s) {
        if ($s['kind'] === 'web' && in_array($s['site'], $compHosts, true)) $s['kind'] = 'comp';
    }
    unset($s);
    $st['done'] = true;
    $prev = research_dec($r['prev_json']);
    $changes = !empty($prev['result']) ? json_encode(research_diff($prev['result'], $res)) : null;
    $title = trim((string) $r['title']) !== '' ? $r['title'] : ($res['title'] ?: research_default_title($r['rtype'], $r['question'], $scope));
    $save(['result_json' => json_encode($res, JSON_UNESCAPED_UNICODE), 'status' => 'done', 'completed_at' => date('Y-m-d H:i:s'),
           'changes_json' => $changes, 'title' => mb_substr($title, 0, 190), 'prev_json' => null]);
    // تحديث بحث كان متضاف لـ Brand Brain: نفس الفئات بالمعلومات الجديدة (الفرص أرقامها اتغيرت فبتتشال)
    $brain = research_dec($r['brain_json'], ['cats' => [], 'opps' => []]);
    if (!empty($prev['result']) && (!empty($brain['cats']) || !empty($brain['opps']))) {
        db_run('UPDATE researches SET brain_json = ? WHERE id = ?', [json_encode(['cats' => [], 'opps' => []]), $r['id']]);
        $fresh = research_row((int) $r['id'], $uid);
        if ($fresh) research_brain_save($fresh, $uid, $brain['cats'], []);
    } elseif (empty($prev['result']) && get_setting('research_auto_brain', '1') === '1') {
        // البحث بيسجّل معلوماته في الهوية تلقائيًا: كل الرؤى في معرفة الـ AI + اقتراحات للحقول الناقصة
        $fresh = research_row((int) $r['id'], $uid);
        if ($fresh) {
            research_brain_save($fresh, $uid, research_insight_cats(), array_keys($res['opps'] ?? []));
            research_brand_suggest($fresh, $uid, $res);
        }
    }
    return ['ok' => true, 'error' => null, 'retry' => false];
}

/* ═══════════ شكل الواجهة ═══════════ */

function research_to_api(array $r, bool $full = true): array
{
    $scope = research_dec($r['scope_json']);
    $src = research_dec($r['sources_json']);
    $res = $r['status'] === 'done' ? research_dec($r['result_json']) : [];
    $steps = research_dec($r['steps_json']);
    $cur = null;
    foreach ($steps as $k => $s) { if (empty($s['done'])) { $cur = $k; break; } }
    $meta = research_axes_meta();
    $activity = '';
    if ($cur !== null) {
        $s = $steps[$cur];
        $activity = $s['key'] === 'search' ? $meta[$s['axes'][0]][1] : ['analyze' => 'يبحث عن الأنماط المشتركة...', 'opps' => 'يستخرج الفرص...', 'write' => 'يكتب التقرير...'][$s['key']] ?? '';
    }
    $done = $r['completed_at'] ? strtotime((string) $r['completed_at']) : null;
    $type = research_types()[$r['rtype']] ?? research_types()['custom'];
    $active = array_values(array_filter($src, fn($s) => empty($s['excluded'])));
    $out = [
        'id' => (int) $r['id'], 'title' => (string) $r['title'], 'question' => (string) $r['question'],
        'type' => (string) $r['rtype'], 'type_e' => $type[0], 'type_t' => $type[1], 'status' => (string) $r['status'],
        'scope' => $scope, 'depth_t' => research_depths()[$scope['depth'] ?? 'medium'][0] ?? '', 'saved' => (bool) $r['saved'],
        'date' => $done ? research_ar_date($done) : research_ar_date(strtotime((string) $r['created_at'])),
        'age_days' => $done ? (int) floor((time() - $done) / 86400) : null,
        'src_count' => count($active), 'insights' => $res ? research_insight_count($res) : 0,
        'changes' => research_dec($r['changes_json'], null), 'error' => $r['error'], 'runs' => (int) $r['runs'],
        'stale' => $r['status'] === 'running' && strtotime((string) $r['updated_at']) < time() - 1800,
    ];
    if (!$full) return $out;
    $out['objectives'] = research_objectives((string) $r['rtype'], $scope + research_default_scope(null));
    $out['steps'] = array_map(fn($s, $k) => ['t' => $s['label'], 'done' => !empty($s['done']), 'now' => $k === $cur, 'failed' => !empty($s['failed'])], $steps, array_keys($steps));
    $out['activity'] = $activity;
    $kinds = research_src_kinds();
    $out['sources'] = array_map(fn($s) => [
        'n' => (int) $s['n'], 'url' => $s['url'], 'site' => $s['site'], 'title' => $s['title'] ?: $s['site'], 'kind' => $s['kind'],
        'e' => $kinds[$s['kind']][0] ?? '🌐', 'kind_t' => $kinds[$s['kind']][1] ?? '', 'took' => $s['took'] ?? '', 'date' => ($s['date'] ?? '') ?: '—',
        'pinned' => !empty($s['pinned']), 'excluded' => !empty($s['excluded']),
    ], $src);
    $out['result'] = $res ?: null;
    $out['brain'] = research_dec($r['brain_json'], ['cats' => [], 'opps' => []]);
    $out['cost'] = research_cost($scope['depth'] ?? 'medium');
    return $out;
}

function research_ar_date(int $ts): string
{
    $m = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/* ═══════════ Brand Brain ═══════════ */

/**
 * إضافة رؤى البحث لهوية البراند: مستند واحد لكل بحث (brand_sources) بيدخل في كل برومبتات المحتوى
 * @return int عدد الرؤى في المستند
 */
function research_brain_save(array $r, int $uid, array $cats, array $opps): int
{
    require_once __DIR__ . '/brand-brain.php';
    $brand = brand_for_user($uid);
    if (!$brand) return 0;
    $res = research_dec($r['result_json']);
    $b = research_dec($r['brain_json'], ['cats' => [], 'opps' => []]);
    $b['cats'] = array_values(array_unique(array_merge($b['cats'] ?? [], array_values(array_intersect(array_keys($res['insights'] ?? []), $cats)))));
    $validOpps = array_keys($res['opps'] ?? []);
    $b['opps'] = array_values(array_unique(array_merge($b['opps'] ?? [], array_values(array_intersect($validOpps, array_map('intval', $opps))))));
    sort($b['opps']);

    $lines = [];
    foreach ($b['cats'] as $c) $lines[] = '- ' . $c . ': ' . $res['insights'][$c];
    foreach ($b['opps'] as $k) $lines[] = '- ' . $res['opps'][$k]['type'] . ': ' . $res['opps'][$k]['headline'] . ($res['opps'][$k]['use'] ? ' — ' . $res['opps'][$k]['use'] : '');
    $scope = research_dec($r['scope_json']);
    $text = 'رؤى من البحث العميق «' . $r['title'] . '» (' . ($scope['market'] ?? '') . ' · ' . research_ar_date(strtotime((string) ($r['completed_at'] ?: 'now'))) . "):\n" . implode("\n", $lines);

    $key = 'research:' . (int) $r['id'];
    $ex = db_one('SELECT id FROM brand_sources WHERE brand_profile_id = ? AND source_url = ?', [$brand['id'], $key]);
    if ($ex && !$lines) {
        db_run('DELETE FROM brand_sources WHERE id = ?', [$ex['id']]);
    } elseif ($ex) {
        db_run('UPDATE brand_sources SET title = ?, raw_text = ?, summary = ?, extract_status = "summarized", extracted_at = NOW() WHERE id = ?',
            [mb_substr('🔬 ' . $r['title'], 0, 180), $text, mb_substr($text, 0, 4000), $ex['id']]);
    } elseif ($lines) {
        db_insert('INSERT INTO brand_sources (brand_profile_id, user_id, type, title, source_url, raw_text, summary, extract_status, extracted_at, use_in_prompts)
                   VALUES (?,?, "text", ?, ?, ?, ?, "summarized", NOW(), 1)',
            [$brand['id'], $uid, mb_substr('🔬 ' . $r['title'], 0, 180), $key, $text, mb_substr($text, 0, 4000)]);
    }
    db_run('UPDATE researches SET brain_json = ? WHERE id = ?', [json_encode($b, JSON_UNESCAPED_UNICODE), $r['id']]);
    return count($lines);
}


/**
 * البحث «بيسمع» الهوية ويسجّل فيها: اقتراحات لحقول الهوية الناقصة (العميل بيأكدها في Brand Brain)
 * مابيكتبش فوق قيمة موجودة — بيقترح بس، والمصدر «البحث العميق».
 * @return int عدد الاقتراحات
 */
function research_brand_suggest(array $r, int $uid, array $res): int
{
    require_once __DIR__ . '/brand-brain.php';
    $brand = brand_for_user($uid);
    if (!$brand) return 0;
    $ref = 'البحث العميق: ' . mb_substr((string) $r['title'], 0, 120);
    $want = [];
    $prof = $res['audience']['profile'] ?? [];
    $aud = array_filter([$res['insights']['جمهور السوق'] ?? '', $prof['demographics'] ?? '', $prof['needs'] ?? '']);
    if ($aud) $want['audience'] = implode("\n", array_unique($aud));
    if (!empty($res['keywords'])) $want['keywords_use'] = implode('، ', array_slice($res['keywords'], 0, 10));
    $n = 0;
    foreach ($want as $field => $value) {
        if (brand_filled($brand[$field] ?? '')) continue;
        if (brand_fact_suggest($brand, $uid, $field, mb_substr($value, 0, 1500), 'ai', $ref, 75)) $n++;
    }
    return $n;
}
