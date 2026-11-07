<?php
/**
 * Spread AI — محرك المحتوى v3 · المرحلة C: سجل الموديلات · خريطة المراحل · الإعدادات الجاهزة
 *
 *   aim_fetch($provider)         ← الموديلات من الـ API بتاع المزود نفسه (GET /models) — مفيش كتابة أسماء
 *   aim_save($row)               ← حفظ في السجل + مزامنة الأسعار (ai_prices) وقايمة موديلات المزود (models_json)
 *   aim_stage_save($task, …)     ← خريطة المراحل: موديل أساسي + بديل + حرارة → ai_task_routes.chain_json / temperature
 *   aim_preset_build/apply()     ← اقتصادي · متوازن · أفضل جودة
 *
 * الموديلات اللي في السجل هي اللي بتظهر في كل القوايم. البوابة (ai-gateway.php) بتقرا chain_json زي ما هي.
 */

require_once __DIR__ . '/ai-gateway.php';

const AIM_TYPES = ['text' => 'نص', 'vision' => 'نص + صور', 'image' => 'صور', 'embeddings' => 'Embeddings', 'web' => 'بحث ويب'];
const AIM_TIERS = ['economy' => 'اقتصادي', 'balanced' => 'متوازن', 'quality' => 'جودة'];

/** حرارة افتراضية لكل مرحلة (من البريف) */
const AIM_DEFAULT_TEMP = [
    'ce_ideation' => 0.9, 'ce_writer' => 0.7, 'ce_matrix' => 0.4, 'ce_critic' => 0.1, 'ce_plan_critic' => 0.1,
    'ce_selection' => 0.1, 'ce_final_qa' => 0.1, 'ce_brand_dna' => 0.3, 'ce_design_prompt' => 0.5, 'ce_vision_qa' => 0.1,
];

/** المراحل اللي «الناقد» فيها لازم يبقى من مزود غير الكاتب (لو متاح) */
const AIM_CRITIC_STAGES = ['ce_critic', 'ce_plan_critic', 'ce_selection', 'ce_final_qa'];

function aim_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { db_one('SELECT id FROM ai_model_registry LIMIT 1'); db_one('SELECT temperature FROM ai_task_routes LIMIT 1'); return $ok = true; } catch (\Throwable $e) { return $ok = false; }
}

/* ═══════════ السجل ═══════════ */

function aim_list(array $f = []): array
{
    if (!aim_ready()) return [];
    $w = ['1=1']; $p = [];
    if (!empty($f['enabled'])) $w[] = 'enabled = 1';
    if (!empty($f['provider'])) { $w[] = 'provider_name = ?'; $p[] = $f['provider']; }
    return db_all('SELECT * FROM ai_model_registry WHERE ' . implode(' AND ', $w) . ' ORDER BY enabled DESC, provider_name, FIELD(tier,"economy","balanced","quality"), model', $p);
}

function aim_get(int $id): ?array
{
    return aim_ready() ? (db_one('SELECT * FROM ai_model_registry WHERE id = ?', [$id]) ?: null) : null;
}

/** الموديل ينفع لمرحلة بالقدرة دي؟ */
function aim_fits(array $m, string $cap, bool $needVision = false): bool
{
    if ((int) $m['enabled'] !== 1) return false;
    $t = (string) $m['type'];
    if ($cap === 'image') return $t === 'image';
    if ($cap === 'web') return $t === 'web';
    if ($cap === 'embeddings') return $t === 'embeddings';
    // نص
    if (!in_array($t, ['text', 'vision'], true)) return false;
    return !$needVision || $t === 'vision' || (int) $m['supports_vision'] === 1;
}

/** مستوى الموديل من سعره (أو اسمه لو مفيش سعر) */
function aim_guess_tier(string $model, ?float $priceIn): string
{
    if ($priceIn !== null && $priceIn > 0) return $priceIn < 0.4 ? 'economy' : ($priceIn < 2.5 ? 'balanced' : 'quality');
    $m = strtolower($model);
    if (preg_match('/(mini|nano|flash|haiku|lite|small|8b|7b|3b)/', $m)) return 'economy';
    if (preg_match('/(opus|\bpro\b|-pro|sonnet|gpt-4o$|gpt-4\.1$|gpt-5$|o3|large|ultra)/', $m)) return 'quality';
    return 'balanced';
}

/* ═══════════ الجلب من المزود ═══════════ */

function aim_http_get(string $url, array $headers, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$http, is_string($raw) ? $raw : '', $err];
}

/** موديلات مالهاش علاقة بالمنصة (صوت · إشراف · قديمة) */
function aim_skip(string $id): bool
{
    return (bool) preg_match('/(whisper|tts|audio|realtime|transcribe|moderation|davinci|babbage|computer-use|search-api|codex-mini|-instruct$)/i', $id);
}

/**
 * تحويل رد GET /models لقايمة موحّدة (دالة نقية — بتتختبر لوحدها)
 * @return array [ [model, label, type, supports_vision, supports_json, supports_cache, max_context, price_in_1m, price_out_1m, price_cached_1m, price_image, tier, meta], … ]
 */
function aim_normalize(string $kind, array $json): array
{
    $out = [];
    foreach ((array) ($json['data'] ?? $json['models'] ?? []) as $m) {
        $id = (string) ($m['id'] ?? $m['name'] ?? '');
        if ($kind === 'gemini') $id = preg_replace('#^models/#', '', $id);
        if ($id === '' || aim_skip($id)) continue;
        $low = strtolower($id);
        $r = ['model' => $id, 'label' => (string) ($m['name'] ?? $m['display_name'] ?? ''), 'type' => 'text', 'supports_vision' => 0, 'supports_json' => 0,
              'supports_cache' => 0, 'max_context' => null, 'price_in_1m' => null, 'price_out_1m' => null, 'price_cached_1m' => null, 'price_image' => null, 'meta' => null];
        if ($kind === 'openrouter') {
            $in = (array) ($m['architecture']['input_modalities'] ?? []);
            $outM = (array) ($m['architecture']['output_modalities'] ?? []);
            $params = (array) ($m['supported_parameters'] ?? []);
            $pr = (array) ($m['pricing'] ?? []);
            $per = fn($k) => isset($pr[$k]) && is_numeric($pr[$k]) && (float) $pr[$k] >= 0 ? round((float) $pr[$k] * 1e6, 6) : null;
            $r['type'] = in_array('image', $outM, true) ? 'image' : (str_contains($low, 'embed') ? 'embeddings' : 'text');
            $r['supports_vision'] = in_array('image', $in, true) ? 1 : 0;
            if ($r['type'] === 'text' && $r['supports_vision']) $r['type'] = 'vision';
            $r['supports_json'] = (in_array('response_format', $params, true) || in_array('structured_outputs', $params, true)) ? 1 : 0;
            $r['price_in_1m'] = $per('prompt');
            $r['price_out_1m'] = $per('completion');
            $r['price_cached_1m'] = $per('input_cache_read');
            $r['supports_cache'] = ($r['price_cached_1m'] ?? 0) > 0 ? 1 : 0;
            $r['price_image'] = isset($pr['image']) && is_numeric($pr['image']) && (float) $pr['image'] > 0 ? round((float) $pr['image'], 6) : null;
            $r['max_context'] = isset($m['context_length']) ? (int) $m['context_length'] : null;
            $r['meta'] = ['created' => $m['created'] ?? null, 'params' => array_slice($params, 0, 20)];
        } else {
            if (str_contains($low, 'embed')) $r['type'] = 'embeddings';
            elseif (preg_match('/(dall-e|gpt-image|imagen|image-generation|flash-image|-image)/', $low)) $r['type'] = 'image';
            elseif ($kind === 'perplexity' || str_contains($low, 'search')) $r['type'] = 'web';
            if ($r['type'] === 'text') {
                $vision = (bool) preg_match('/^(gpt-4o|gpt-4\.1|gpt-5|o1|o3|o4|chatgpt-4o|gemini|claude-3|claude-(sonnet|opus|haiku))/', $low);
                $r['supports_vision'] = $vision ? 1 : 0;
                if ($vision) $r['type'] = 'vision';
                $r['supports_json'] = preg_match('/^(gpt-|o\d|gemini|chatgpt)/', $low) ? 1 : 0;
                // كاش تلقائي: OpenAI (gpt-4o وأحدث · o-series) · Gemini 2.5
                $r['supports_cache'] = preg_match('/^(gpt-4o|gpt-4\.1|gpt-5|o1|o3|o4|gemini-2\.5)/', $low) ? 1 : 0;
            }
            $r['meta'] = ['owned_by' => $m['owned_by'] ?? null];
        }
        $out[] = $r;
    }
    usort($out, fn($a, $b) => strcmp($a['model'], $b['model']));
    return $out;
}

/** مزودين مالهمش endpoint للموديلات — قايمة ثابتة (بتتعلّم «قايمة») */
const AIM_STATIC_LISTS = [
    'perplexity' => ['sonar', 'sonar-pro', 'sonar-reasoning', 'sonar-reasoning-pro', 'sonar-deep-research'],
];

/**
 * الموديلات المتاحة عند المزود
 * @return array [ok, models[], error, source]
 */
function aim_fetch(string $providerName): array
{
    $p = ai_gw_provider($providerName, true);
    if (!$p) return [false, [], 'المزود مش موجود', 'api'];
    if ($p['key'] === '') return [false, [], 'المزود ده مالوش API Key محفوظ — ضيفه من «مزودين الـ AI» الأول', 'api'];
    $kind = (string) $p['kind'];
    if (isset(AIM_STATIC_LISTS[$kind])) {
        $models = aim_normalize($kind, ['data' => array_map(fn($id) => ['id' => $id], AIM_STATIC_LISTS[$kind])]);
        foreach ($models as &$m) { $m['type'] = 'web'; $m['source'] = 'list'; }
        unset($m);
        return [true, aim_prefill($providerName, $models), null, 'list'];
    }
    if ($p['base'] === '') return [false, [], 'رابط المزود (Base URL) فاضي', 'api'];
    [$http, $raw, $err] = aim_http_get(rtrim($p['base'], '/') . '/models', ai_gw_headers($p));
    if ($http !== 200) {
        $msg = $err !== '' ? 'تعذّر الاتصال: ' . $err : ('المزود رجّع HTTP ' . $http . ($http === 401 ? ' — المفتاح غلط أو منتهي' : ''));
        return [false, [], $msg, 'api'];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) return [false, [], 'رد المزود مش JSON', 'api'];
    $models = aim_normalize($kind, $json);
    if (!$models) return [false, [], 'المزود مارجّعش موديلات مناسبة', 'api'];
    return [true, aim_prefill($providerName, $models), null, 'api'];
}

/** الأسعار من جدول الأسعار الحالي لو المزود مابيرجعهاش + المستوى + هل موجود في السجل */
function aim_prefill(string $provider, array $models): array
{
    $have = [];
    if (aim_ready()) foreach (db_all('SELECT model FROM ai_model_registry WHERE provider_name = ?', [$provider]) as $r) $have[$r['model']] = true;
    foreach ($models as &$m) {
        foreach (['price_in_1m' => 'in_1m', 'price_out_1m' => 'out_1m', 'price_cached_1m' => 'cached_1m'] as $col => $unit) {
            if ($m[$col] === null && ($pp = usage_price($provider, $m['model'], $unit))) $m[$col] = $pp['price_usd'];
        }
        if ($m['price_image'] === null && $m['type'] === 'image' && ($pp = usage_price($provider, $m['model'], 'image'))) $m['price_image'] = $pp['price_usd'];
        $m['tier'] = aim_guess_tier($m['model'], $m['price_in_1m'] !== null ? (float) $m['price_in_1m'] : null);
        $m['in_registry'] = isset($have[$m['model']]);
        $m['source'] = $m['source'] ?? 'api';
    }
    unset($m);
    return $models;
}

/* ═══════════ الحفظ + المزامنة ═══════════ */

function aim_clean(array $in): array
{
    $num = fn($v) => $v === null || $v === '' || !is_numeric($v) ? null : max(0, round((float) $v, 6));
    $type = isset(AIM_TYPES[$in['type'] ?? '']) ? $in['type'] : 'text';
    return [
        'provider_name' => mb_substr(trim((string) ($in['provider_name'] ?? '')), 0, 60),
        'model' => mb_substr(trim((string) ($in['model'] ?? '')), 0, 160),
        'label' => mb_substr(trim((string) ($in['label'] ?? '')), 0, 120) ?: null,
        'type' => $type,
        'supports_vision' => !empty($in['supports_vision']) || $type === 'vision' ? 1 : 0,
        'supports_json' => !empty($in['supports_json']) ? 1 : 0,
        'supports_cache' => !empty($in['supports_cache']) ? 1 : 0,
        'max_context' => isset($in['max_context']) && is_numeric($in['max_context']) && (int) $in['max_context'] > 0 ? (int) $in['max_context'] : null,
        'price_in_1m' => $num($in['price_in_1m'] ?? null),
        'price_out_1m' => $num($in['price_out_1m'] ?? null),
        'price_cached_1m' => $num($in['price_cached_1m'] ?? null),
        'price_image' => $num($in['price_image'] ?? null),
        'tier' => isset(AIM_TIERS[$in['tier'] ?? '']) ? $in['tier'] : 'balanced',
        'enabled' => !isset($in['enabled']) || !empty($in['enabled']) ? 1 : 0,
        'source' => in_array($in['source'] ?? '', ['api', 'manual', 'list'], true) ? $in['source'] : 'api',
        'meta' => isset($in['meta']) && $in['meta'] !== null ? mb_substr(is_string($in['meta']) ? $in['meta'] : (string) json_encode($in['meta'], JSON_UNESCAPED_UNICODE), 0, 4000) : null,
    ];
}

/** إضافة أو تحديث (نفس المزود + الموديل) → id */
function aim_save(array $in, ?int $adminId = null): int
{
    $r = aim_clean($in);
    if ($r['provider_name'] === '' || $r['model'] === '') throw new \InvalidArgumentException('المزود والموديل مطلوبين');
    if (!ai_gw_provider($r['provider_name'], true)) throw new \InvalidArgumentException('المزود مش موجود');
    $cols = array_keys($r);
    db_run('INSERT INTO ai_model_registry (' . implode(',', $cols) . ', created_by) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', ?)
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['provider_name', 'model', 'source', 'meta']))),
        array_merge(array_values($r), [$adminId]));
    $id = (int) (db_one('SELECT id FROM ai_model_registry WHERE provider_name = ? AND model = ?', [$r['provider_name'], $r['model']])['id'] ?? 0);
    aim_sync_prices($r, $adminId);
    aim_sync_provider_models($r['provider_name']);
    return $id;
}

/** الأسعار بتتسجل في ai_prices (السارية من دلوقتي) — علشان حساب التكلفة الحالي يشتغل زي ما هو */
function aim_sync_prices(array $r, ?int $adminId = null): void
{
    $units = ['in_1m' => $r['price_in_1m'], 'out_1m' => $r['price_out_1m'], 'cached_1m' => $r['price_cached_1m'], 'image' => $r['price_image']];
    foreach ($units as $unit => $price) {
        if ($price === null) continue;
        $cur = db_one('SELECT price_usd FROM ai_prices WHERE provider = ? AND model = ? AND unit = ? AND size = "" AND effective_from <= NOW() ORDER BY effective_from DESC, id DESC LIMIT 1',
            [$r['provider_name'], $r['model'], $unit]);
        if ($cur && abs((float) $cur['price_usd'] - (float) $price) < 0.0000005) continue;
        db_run('INSERT INTO ai_prices (provider, model, unit, size, price_usd, effective_from, note, created_by) VALUES (?,?,?,"",?,NOW(),?,?)',
            [$r['provider_name'], $r['model'], $unit, $price, 'سجل الموديلات', $adminId]);
    }
}

/** قايمة موديلات المزود (models_json) = الموديلات المفعّلة في السجل — علشان الشاشات القديمة تشوفها */
function aim_sync_provider_models(string $provider): void
{
    $row = db_one('SELECT models_json FROM ai_providers WHERE provider_name = ?', [$provider]);
    if (!$row) return;
    $cur = json_decode((string) $row['models_json'], true) ?: [];
    foreach (db_all('SELECT model, type FROM ai_model_registry WHERE provider_name = ? AND enabled = 1', [$provider]) as $m) {
        $cap = $m['type'] === 'vision' ? 'text' : $m['type'];
        if ($cap === 'embeddings') continue;
        $cur[$cap] = array_values(array_unique(array_merge($cur[$cap] ?? [], [$m['model']])));
    }
    db_run('UPDATE ai_providers SET models_json = ? WHERE provider_name = ?', [json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $provider]);
    if (function_exists('ai_gw_cache_bust')) ai_gw_cache_bust();
}

/** الموديل مستخدم في أي مرحلة؟ (قبل التعطيل/الحذف) */
function aim_used_in(array $m): array
{
    $tasks = [];
    foreach (db_all('SELECT task, label, chain_json FROM ai_task_routes') as $t) {
        foreach ((json_decode((string) $t['chain_json'], true) ?: []) as $c) {
            if (($c['p'] ?? '') === $m['provider_name'] && ($c['m'] ?? '') === $m['model']) { $tasks[] = $t['label'] ?: $t['task']; break; }
        }
    }
    return $tasks;
}

/* ═══════════ خريطة المراحل ═══════════ */

function aim_stages(): array
{
    if (!aim_ready()) return [];
    $rows = db_all('SELECT * FROM ai_task_routes ORDER BY (stage_group = "engine") DESC, sort_order, task');
    $reg = [];
    foreach (aim_list() as $m) $reg[$m['provider_name'] . '|' . $m['model']] = $m;
    foreach ($rows as &$r) {
        $chain = json_decode((string) $r['chain_json'], true) ?: [];
        $r['primary'] = $chain[0] ?? null;
        $r['fallback'] = $chain[1] ?? null;
        $r['primary_reg'] = $r['primary'] ? ($reg[($r['primary']['p'] ?? '') . '|' . ($r['primary']['m'] ?? '')] ?? null) : null;
        $r['fallback_reg'] = $r['fallback'] ? ($reg[($r['fallback']['p'] ?? '') . '|' . ($r['fallback']['m'] ?? '')] ?? null) : null;
        $r['needs_vision'] = $r['task'] === 'ce_vision_qa';
        $r['extra_chain'] = array_slice($chain, 2);
    }
    unset($r);
    return $rows;
}

/**
 * حفظ مرحلة: موديل أساسي + بديل (من السجل) + حرارة (null = حسب الكود)
 * البدائل القديمة بعد التاني (لو كانت متحددة من «الموديلات والـ Router») بتفضل زي ما هي
 */
function aim_stage_save(string $task, ?int $primaryId, ?int $fallbackId, ?float $temp, ?int $adminId = null): void
{
    $route = db_one('SELECT * FROM ai_task_routes WHERE task = ?', [$task]);
    if (!$route) throw new \InvalidArgumentException('المرحلة مش موجودة');
    $cap = (string) $route['capability'];
    $needVision = $task === 'ce_vision_qa';
    $chainOld = json_decode((string) $route['chain_json'], true) ?: [];
    $chain = [];
    foreach ([$primaryId, $fallbackId] as $i => $mid) {
        if (!$mid) continue;
        $m = aim_get($mid);
        if (!$m || !aim_fits($m, $cap, $needVision)) throw new \InvalidArgumentException('الموديل «' . ($m['model'] ?? $mid) . '» مايناسبش المرحلة دي');
        $chain[] = ['p' => $m['provider_name'], 'm' => $m['model']];
    }
    if (count($chain) === 2 && $chain[0] === $chain[1]) array_pop($chain);
    if ($chain) {
        foreach (array_slice($chainOld, 2) as $c) if (!in_array($c, $chain, true)) $chain[] = $c;
    }
    $t = $temp === null ? null : max(0, min(1.5, round($temp, 2)));
    db_run('UPDATE ai_task_routes SET chain_json = ?, temperature = ?, updated_by = ?, updated_at = NOW() WHERE task = ?',
        [$chain ? json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $cap === 'text' ? $t : null, $adminId, $task]);
    if (function_exists('ai_gw_cache_bust')) ai_gw_cache_bust();
}

/* ═══════════ الإعدادات الجاهزة ═══════════ */

/** أحسن موديل مناسب بالمستوى ده (ولو مفيش: أقرب مستوى) — مع تفضيل مزود معيّن أو تجنّبه */
function aim_pick(array $models, string $cap, bool $needVision, string $tier, ?string $avoidProvider = null, ?string $preferProvider = null, ?int $notId = null): ?array
{
    $order = ['economy' => ['economy', 'balanced', 'quality'], 'balanced' => ['balanced', 'quality', 'economy'], 'quality' => ['quality', 'balanced', 'economy']][$tier];
    $cands = array_values(array_filter($models, fn($m) => aim_fits($m, $cap, $needVision) && (int) $m['id'] !== (int) $notId));
    if (!$cands) return null;
    $provOk = array_column(array_filter(ai_gw_providers(), fn($p) => $p['key'] !== ''), 'name');
    usort($cands, function ($a, $b) use ($order, $avoidProvider, $preferProvider, $provOk, $tier) {
        $score = function ($m) use ($order, $avoidProvider, $preferProvider, $provOk, $tier) {
            $s = array_search($m['tier'], $order, true) * 100;
            if (!in_array($m['provider_name'], $provOk, true)) $s += 1000;            // مزود مش شغال
            if ($avoidProvider && $m['provider_name'] === $avoidProvider) $s += 50;  // الناقد من مزود تاني لو أمكن
            if ($preferProvider && $m['provider_name'] !== $preferProvider) $s += 5;
            if ($m['price_in_1m'] === null && $m['price_image'] === null) $s += 30;   // من غير سعر = تكلفته مش معروفة → بعد اللي ليهم سعر
            $price = (float) ($m['price_in_1m'] ?? 0) + (float) ($m['price_out_1m'] ?? 0);
            $s += $tier === 'quality' ? -min($price, 40) / 10 : min($price, 40) / 10;   // الجودة: الأغلى أولًا · غير كده: الأرخص
            return $s;
        };
        return $score($a) <=> $score($b);
    });
    return $cands[0];
}

/**
 * خريطة الإعداد الجاهز: {task: {model_id, fallback_id, temperature}}
 * المحفوظ في ai_model_presets.map_json بيغلب — غير كده بيتبني تلقائي من مستوى الموديلات
 */
function aim_preset_build(string $preset): array
{
    $row = db_one('SELECT map_json FROM ai_model_presets WHERE preset = ?', [$preset]);
    $saved = json_decode((string) ($row['map_json'] ?? ''), true);
    $models = aim_list(['enabled' => 1]);
    $map = [];
    $writerProv = null;
    $stages = aim_stages();
    // الكاتب الأول — علشان الناقد يتجنّب مزوده
    usort($stages, fn($a, $b) => ($b['task'] === 'ce_writer') <=> ($a['task'] === 'ce_writer'));
    foreach ($stages as $st) {
        $task = $st['task'];
        if (is_array($saved) && isset($saved[$task])) { $map[$task] = $saved[$task] + ['saved' => true]; continue; }
        $cap = (string) $st['capability'];
        $avoid = in_array($task, AIM_CRITIC_STAGES, true) ? $writerProv : null;
        $p = aim_pick($models, $cap, $st['needs_vision'], $preset, $avoid);
        if (!$p) { $map[$task] = ['model_id' => null, 'fallback_id' => null, 'temperature' => AIM_DEFAULT_TEMP[$task] ?? null]; continue; }
        if ($task === 'ce_writer') $writerProv = $p['provider_name'];
        // البديل: مزود تاني بنفس المستوى لو موجود
        $f = aim_pick($models, $cap, $st['needs_vision'], $preset, $p['provider_name'], null, (int) $p['id']);
        $map[$task] = [
            'model_id' => (int) $p['id'],
            'fallback_id' => $f ? (int) $f['id'] : null,
            'temperature' => $cap === 'text' ? (AIM_DEFAULT_TEMP[$task] ?? ($st['temperature'] !== null ? (float) $st['temperature'] : null)) : null,
        ];
    }
    return $map;
}

/** تطبيق الإعداد على كل المراحل (الأدمن يقدر يعدّل أي مرحلة بعدها) */
function aim_preset_apply(string $preset, ?int $adminId = null): int
{
    $n = 0;
    foreach (aim_preset_build($preset) as $task => $e) {
        if (empty($e['model_id'])) continue;
        try { aim_stage_save($task, (int) $e['model_id'], !empty($e['fallback_id']) ? (int) $e['fallback_id'] : null, $e['temperature'] ?? null, $adminId); $n++; } catch (\Throwable $ex) {}
    }
    db_run('UPDATE ai_model_presets SET applied_at = NOW(), updated_by = ? WHERE preset = ?', [$adminId, $preset]);
    return $n;
}

/** حفظ الخريطة الحالية كإعداد جاهز (أو مسحه علشان يرجع تلقائي) */
function aim_preset_save_current(string $preset, ?int $adminId = null, bool $clear = false): void
{
    $map = null;
    if (!$clear) {
        $reg = [];
        foreach (aim_list() as $m) $reg[$m['provider_name'] . '|' . $m['model']] = (int) $m['id'];
        $map = [];
        foreach (aim_stages() as $st) {
            $pid = $st['primary'] ? ($reg[$st['primary']['p'] . '|' . $st['primary']['m']] ?? null) : null;
            if (!$pid) continue;
            $fid = $st['fallback'] ? ($reg[$st['fallback']['p'] . '|' . $st['fallback']['m']] ?? null) : null;
            $map[$st['task']] = ['model_id' => $pid, 'fallback_id' => $fid, 'temperature' => $st['temperature'] !== null ? (float) $st['temperature'] : null];
        }
    }
    db_run('UPDATE ai_model_presets SET map_json = ?, updated_by = ?, updated_at = NOW() WHERE preset = ?',
        [$map ? json_encode($map) : null, $adminId, $preset]);
}
