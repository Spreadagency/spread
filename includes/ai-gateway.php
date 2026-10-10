<?php
/**
 * Spread AI v2 — المرحلة 8-أ: بوابة الذكاء الاصطناعي الموحدة (بالبدائل)
 *
 *   ai_run($task, $req, $ctx)  ← كل المزايا بتعدّي من هنا لما البوابة تتشغل من «الموديلات والـ Router»
 *
 * لكل مهمة سلسلة: أساسي ← بديل ← بديل (مزودين مختلفين). قواعد التحويل:
 *   شبكة / 5xx / رد فاضي      → محاولة إضافية واحدة، بعدها المزود التالي
 *   429                        → لو مدة الانتظار قصيرة نستنى ونعيد، غير كده التالي
 *   مفتاح / رصيد (401/402)      → المزود يتوقف مؤقتًا + تنبيه للأدمن + التالي
 *   موديل مش موجود (404)        → تنبيه إعداد + التالي
 *   JSON غير صالح (لو المهمة بتطلبه) → محاولة إصلاح واحدة، بعدها التالي
 *   رفض سياسة محتوى            → مايحوّلش — رسالة واضحة للعميل
 *   400 صيغة طلب               → خطأ تكامل: تنبيه ومايحوّلش
 * Circuit Breaker لكل مزود × قدرة (نص/صورة/بحث): 3 أعطال مؤقتة في 5 دقايق ← يتوقف 5 دقايق ← محاولة اختبار واحدة.
 * ميزانية لكل مهمة: عدد المحاولات الكلي · الوقت · التكلفة.
 * كل محاولة بتتسجل في ai_attempts بتكلفتها (usage.php).
 */

require_once __DIR__ . '/usage.php';
if (!class_exists('Crypto') && is_file(__DIR__ . '/crypto.php')) {
    require_once __DIR__ . '/crypto.php';
}

/** أنواع المزودين المعروفة + الافتراضي */
function ai_gw_kinds(): array
{
    return [
        'openrouter' => ['OpenRouter', 'https://openrouter.ai/api/v1', ['text' => 'openai/gpt-4o-mini', 'image' => 'google/gemini-2.5-flash-image', 'web' => 'openai/gpt-4o-mini']],
        'openai'     => ['OpenAI', 'https://api.openai.com/v1', ['text' => 'gpt-4o-mini', 'image' => 'gpt-image-1', 'web' => 'gpt-4o-mini-search-preview']],
        'gemini'     => ['Google Gemini', 'https://generativelanguage.googleapis.com/v1beta/openai', ['text' => 'gemini-2.0-flash']],
        'perplexity' => ['Perplexity', 'https://api.perplexity.ai', ['text' => 'sonar', 'web' => 'sonar']],
        'custom'     => ['OpenAI-compatible', '', ['text' => '']],
    ];
}

/** البوابة شغالة؟ */
function ai_gw_enabled(): bool
{
    static $on = null;
    if ($on !== null) return $on;
    if (!function_exists('get_setting') || !usage_ready()) return $on = false;
    try {
        if ((string) get_setting('ai_gateway_enabled', '0') !== '1') return $on = false;
        db_one('SELECT task FROM ai_task_routes LIMIT 1');
        return $on = count(ai_gw_providers()) > 0;
    } catch (\Throwable $e) {
        return $on = false;
    }
}

/** المزودين (النشطين بمفتاح) — منظّمين */
/** Gemini: الرابط المخزّن (…/v1beta) بيستخدمه الـ Smart Router القديم — البوابة بتستخدم نسخة OpenAI-compatible منه */
function ai_gw_norm_base(string $kind, string $base): string
{
    $base = rtrim($base, '/');
    if ($kind === 'gemini' && !preg_match('#/openai$#', $base) && preg_match('#generativelanguage\.googleapis\.com#', $base)) {
        $base .= '/openai';
    }
    return $base;
}

/** تفريغ الكاش بعد تعديل المزودين/المسارات في نفس الطلب */
function ai_gw_cache_bust(): void
{
    $GLOBALS['__ai_gw_ver'] = ($GLOBALS['__ai_gw_ver'] ?? 0) + 1;
}

function ai_gw_providers(bool $all = false): array
{
    static $cache = [];
    $ck = ($all ? 'all' : 'active') . ':' . ($GLOBALS['__ai_gw_ver'] ?? 0);
    if (isset($cache[$ck])) return $cache[$ck];
    $out = [];
    try {
        $rows = db_all('SELECT * FROM ai_providers ' . ($all ? '' : 'WHERE status = "active" ') . 'ORDER BY priority ASC, id ASC');
    } catch (\Throwable $e) {
        return $cache[$ck] = [];
    }
    $kinds = ai_gw_kinds();
    foreach ($rows as $r) {
        $key = '';
        $keySrc = 'none';
        $decryptFail = false;
        if (!empty($r['api_key_encrypted']) && class_exists('Crypto')) {
            try { $key = ai_gw_clean_key((string) Crypto::decrypt((string) $r['api_key_encrypted'])); $keySrc = 'table'; } catch (\Throwable $e) { $key = ''; $decryptFail = true; }
        }
        $kind = (string) ($r['kind'] ?? '') ?: (isset($kinds[$r['provider_name']]) ? $r['provider_name'] : 'custom');
        // مفيش مفتاح في الجدول (أو مابيتفكش): نفس مصادر المسار القديم — config.php ثم إعداد الأدمن القديم
        if ($key === '') {
            [$key, $keySrc] = ai_gw_legacy_key((string) $r['provider_name'], $kind);
        }
        if (!$all && $key === '') continue;
        $models = json_decode((string) ($r['models_json'] ?? ''), true);
        $out[] = [
            'id' => (int) $r['id'], 'name' => (string) $r['provider_name'], 'kind' => $kind,
            'label' => (string) (($r['label'] ?? '') ?: ($kinds[$kind][0] ?? $r['provider_name'])),
            'base' => ai_gw_norm_base($kind, (string) (($r['api_base_url'] ?? '') ?: ($kinds[$kind][1] ?? ''))),
            'key' => $key, 'has_key' => $key !== '' || !empty($r['api_key_encrypted']), 'key_src' => $keySrc, 'key_broken' => $decryptFail && $key === '',
            'status' => (string) $r['status'], 'priority' => (int) $r['priority'],
            'caps' => [
                'text' => (int) ($r['supports_text'] ?? 1) === 1,
                'image' => (int) ($r['supports_image'] ?? 0) === 1,
                'image_edit' => (int) ($r['supports_image_edit'] ?? 0) === 1,
                'web' => (int) ($r['supports_web'] ?? 0) === 1,
                'vision' => (int) ($r['supports_vision'] ?? 0) === 1,
            ],
            'allow_fallback' => (int) ($r['allow_fallback'] ?? 1) === 1,
            'allow_customer_data' => (int) ($r['allow_customer_data'] ?? 1) === 1,
            'timeout_s' => max(10, (int) ($r['timeout_s'] ?? 60)),
            'models' => is_array($models) ? $models : [],
            'row' => $r,
        ];
    }
    return $cache[$ck] = $out;
}

/** تنضيف المفتاح: مسافات/سطور جديدة/«Bearer» اتلصقوا بالغلط */
function ai_gw_clean_key(string $k): string
{
    $k = trim($k);
    $k = preg_replace('/^Bearer\s+/i', '', $k);
    return (string) preg_replace('/\s+/', '', $k);
}

/**
 * مفتاح المسار القديم لنفس المزود (config.php → إعداد الأدمن القديم)
 * @return array [key, source]
 */
function ai_gw_legacy_key(string $name, string $kind): array
{
    $want = [$name, $kind];
    if (defined('AI_API_KEY') && AI_API_KEY !== '') {
        $prov = defined('AI_PROVIDER') ? (string) AI_PROVIDER : 'openrouter';
        if (in_array($prov, $want, true)) return [ai_gw_clean_key((string) AI_API_KEY), 'config.php'];
    }
    if (function_exists('get_setting') && class_exists('Crypto')) {
        try {
            $enc = (string) get_setting('ai_api_key_enc', '');
            $prov = (string) get_setting('ai_provider', 'openrouter');
            if ($enc !== '' && in_array($prov, $want, true)) {
                $k = ai_gw_clean_key((string) Crypto::decrypt($enc));
                if ($k !== '') return [$k, 'admin_settings'];
            }
        } catch (\Throwable $e) {}
    }
    return ['', 'none'];
}

function ai_gw_provider(string $name, bool $all = false): ?array
{
    foreach (ai_gw_providers($all) as $p) {
        if ($p['name'] === $name) return $p;
    }
    return null;
}

/** الموديل الافتراضي للمزود لقدرة معينة */
function ai_gw_default_model(array $p, string $cap): string
{
    $m = $p['models'][$cap][0] ?? null;
    if (!$m && $cap === 'web' && in_array($p['kind'], ['openrouter'], true)) $m = $p['models']['text'][0] ?? null;
    if (!$m) $m = ai_gw_kinds()[$p['kind']][2][$cap] ?? '';
    if (!$m && $cap !== 'image') $m = $p['models']['text'][0] ?? '';
    return (string) $m;
}

/** المزود بيدعم القدرة؟ */
function ai_gw_supports(array $p, string $cap): bool
{
    if ($cap === 'web') return $p['caps']['web'] && in_array($p['kind'], ['openrouter', 'openai', 'perplexity'], true);
    if ($cap === 'image') return $p['caps']['image'] && in_array($p['kind'], ['openrouter', 'openai', 'custom'], true);
    return $p['caps']['text'];
}

/** إعداد المهمة */
function ai_gw_route_row(string $task): array
{
    static $cache = [];
    $ck = $task . ':' . ($GLOBALS['__ai_gw_ver'] ?? 0);
    if (isset($cache[$ck])) return $cache[$ck];
    $row = null;
    try { $row = db_one('SELECT * FROM ai_task_routes WHERE task = ?', [$task]); } catch (\Throwable $e) {}
    if (!$row && $task !== 'general') {
        $row = ai_gw_route_row('general');
        $row['task'] = $task;
        $row['capability'] = usage_task_capability($task);
    }
    $row = $row ?: ['task' => $task, 'capability' => usage_task_capability($task), 'chain_json' => null, 'max_attempts' => 3,
                    'time_budget_s' => 120, 'cost_budget_usd' => null, 'json_required' => 0, 'allow_fallback' => 1, 'label' => $task];
    return $cache[$ck] = $row;
}

/**
 * سلسلة التنفيذ الفعلية للمهمة: [['p' => provider, 'm' => model], …]
 * $ctx: customer_data (افتراضي true) · has_refs (للصور) · vision (فيه صور داخلة للنص)
 */
function ai_gw_chain(string $task, array $ctx = []): array
{
    $route = ai_gw_route_row($task);
    $cap = (string) ($route['capability'] ?? usage_task_capability($task));
    $custData = $ctx['customer_data'] ?? true;
    $chain = [];
    $explicit = json_decode((string) ($route['chain_json'] ?? ''), true);
    if (is_array($explicit) && $explicit) {
        foreach ($explicit as $e) {
            $p = ai_gw_provider((string) ($e['p'] ?? ''));
            if (!$p) continue;
            $chain[] = ['p' => $p, 'm' => trim((string) ($e['m'] ?? '')) ?: ai_gw_default_model($p, $cap)];
        }
    } else {
        foreach (ai_gw_providers() as $p) {
            $chain[] = ['p' => $p, 'm' => ai_gw_default_model($p, $cap)];
        }
    }
    $out = [];
    $seen = [];
    foreach ($chain as $c) {
        $p = $c['p'];
        if ($c['m'] === '' || !ai_gw_supports($p, $cap)) continue;
        if ($custData && !$p['allow_customer_data']) continue;          // سياسة بيانات العملاء
        if (!empty($ctx['vision']) && $cap === 'text' && !$p['caps']['vision']) continue;
        if ($out && !$p['allow_fallback']) continue;                      // مزود «أساسي بس»
        $k = $p['name'] . '|' . $c['m'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $c;
    }
    // الصور المرجعية: المزودين اللي بيدعموا «edits» الأول
    if ($cap === 'image' && !empty($ctx['has_refs'])) {
        $edit = array_values(array_filter($out, fn($c) => $c['p']['caps']['image_edit']));
        if ($edit) $out = $edit;
    }
    if ((int) ($route['allow_fallback'] ?? 1) !== 1) $out = array_slice($out, 0, 1);
    return $out;
}

/* ═══════════ Circuit Breaker (مزود × قدرة) ═══════════ */

function ai_gw_breaker(string $prov, string $cap): array
{
    try {
        return db_one('SELECT * FROM ai_provider_state WHERE provider = ? AND capability = ?', [$prov, $cap])
            ?: ['state' => 'closed', 'fail_count' => 0, 'window_start' => null, 'opened_until' => null, 'updated_at' => null];
    } catch (\Throwable $e) {
        return ['state' => 'closed', 'fail_count' => 0];
    }
}

/** متوقف دلوقتي؟ (من غير ما يغيّر حاجة — لبناء السلسلة) */
function ai_gw_breaker_blocked(string $prov, string $cap): bool
{
    $b = ai_gw_breaker($prov, $cap);
    $st = (string) ($b['state'] ?? 'closed');
    if ($st === 'open') return !empty($b['opened_until']) && strtotime($b['opened_until']) > time();
    if ($st === 'half') return !empty($b['updated_at']) && strtotime($b['updated_at']) >= time() - 60;
    return false;
}

/**
 * مسموح نبعت للمزود ده دلوقتي؟ بيتنادى لحظة الإرسال بس.
 * مفتوح = متوقف · بعد المهلة = طلب اختبار واحد (أول طلب ياخده بـ UPDATE ذرّي، والباقي يتخطّوه)
 */
function ai_gw_breaker_allows(string $prov, string $cap): bool
{
    $b = ai_gw_breaker($prov, $cap);
    $st = (string) ($b['state'] ?? 'closed');
    if ($st === 'closed') return true;
    $now = time();
    try {
        if ($st === 'open') {
            if (!empty($b['opened_until']) && strtotime($b['opened_until']) > $now) return false;
            $q = db()->prepare('UPDATE ai_provider_state SET state = "half", updated_at = NOW() WHERE provider = ? AND capability = ? AND state = "open" AND (opened_until IS NULL OR opened_until <= NOW())');
            $q->execute([$prov, $cap]);
            return $q->rowCount() === 1;
        }
        // half: اختبار شغال — لو علّق أكتر من دقيقة، أول طلب ياخد الاختبار
        $q = db()->prepare('UPDATE ai_provider_state SET updated_at = NOW() WHERE provider = ? AND capability = ? AND state = "half" AND updated_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)');
        $q->execute([$prov, $cap]);
        return $q->rowCount() === 1;
    } catch (\Throwable $e) {
        return true;
    }
}

function ai_gw_breaker_ok(string $prov, string $cap): void
{
    try {
        $b = ai_gw_breaker($prov, $cap);
        db_run(
            'INSERT INTO ai_provider_state (provider, capability, state, fail_count, last_ok_at, updated_at) VALUES (?,?,"closed",0,NOW(),NOW())
             ON DUPLICATE KEY UPDATE state = "closed", fail_count = 0, window_start = NULL, opened_until = NULL, reason = NULL, last_ok_at = NOW(), updated_at = NOW()',
            [$prov, $cap]
        );
        if (in_array($b['state'] ?? 'closed', ['open', 'half'], true)) {
            admin_alert('info', 'provider_up', "المزود {$prov} رجع يشتغل ({$cap})", 'الاختبار نجح والمزود رجع للسلسلة تلقائيًا.', 'admin/ai-router.php', "prov_up:$prov:$cap");
        }
    } catch (\Throwable $e) {}
}

function ai_gw_breaker_fail(string $prov, string $cap, string $class, string $err): void
{
    $transient = ['network', 'timeout', 'server', 'rate_limit', 'empty', 'bad_json'];
    try {
        $b = ai_gw_breaker($prov, $cap);
        $now = time();
        if (in_array($class, ['auth', 'quota'], true)) {
            $until = date('Y-m-d H:i:s', $now + 30 * 60);
            db_run(
                'INSERT INTO ai_provider_state (provider, capability, state, fail_count, window_start, opened_until, reason, last_error, last_fail_at, updated_at)
                 VALUES (?,?,"open",1,NOW(),?,?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE state = "open", opened_until = VALUES(opened_until), reason = VALUES(reason), last_error = VALUES(last_error), last_fail_at = NOW(), updated_at = NOW()',
                [$prov, $cap, $until, $class, mb_substr($err, 0, 300)]
            );
            $what = $class === 'auth' ? 'المفتاح مرفوض' : 'الرصيد خلص / مشكلة فواتير';
            admin_alert('critical', 'provider_' . $class, "المزود {$prov}: {$what}",
                "اتوقف استخدامه 30 دقيقة (مش هيرجع لوحده غير بعد اختبار ناجح). راجع المفتاح أو الرصيد عند المزود.\nالرد: " . mb_substr($err, 0, 300),
                'admin/ai-providers.php', "prov_$class:$prov");
            return;
        }
        if ($class === 'not_found') {
            admin_alert('warn', 'model_not_found', "موديل مش موجود عند {$prov}", "راجع اسم الموديل في «الموديلات والـ Router».\n" . mb_substr($err, 0, 300), 'admin/ai-router.php', "model404:$prov");
            return;
        }
        if (!in_array($class, $transient, true)) return;

        // لسه متوقف (مثلًا كان كل المزودين متوقفين فاتجرّب غصب) → يفضل متوقف، نحدّث آخر خطأ بس
        if (($b['state'] ?? '') === 'open' && !empty($b['opened_until']) && strtotime($b['opened_until']) > $now) {
            db_run('UPDATE ai_provider_state SET last_error = ?, last_fail_at = NOW(), updated_at = NOW() WHERE provider = ? AND capability = ?',
                [mb_substr($err, 0, 300), $prov, $cap]);
            return;
        }
        $threshold = max(1, (int) usage_setting('ai_breaker_threshold', 3));
        $window = max(1, (int) usage_setting('ai_breaker_window_min', 5)) * 60;
        $cool = max(1, (int) usage_setting('ai_breaker_cooldown_min', 5)) * 60;
        $inWindow = !empty($b['window_start']) && strtotime($b['window_start']) > $now - $window;
        $count = $inWindow ? (int) $b['fail_count'] + 1 : 1;
        $open = $count >= $threshold || ($b['state'] ?? '') === 'half';
        db_run(
            'INSERT INTO ai_provider_state (provider, capability, state, fail_count, window_start, opened_until, reason, last_error, last_fail_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE state = VALUES(state), fail_count = VALUES(fail_count), window_start = VALUES(window_start),
                opened_until = VALUES(opened_until), reason = VALUES(reason), last_error = VALUES(last_error), last_fail_at = NOW(), updated_at = NOW()',
            [$prov, $cap, $open ? 'open' : 'closed', $count, $inWindow ? $b['window_start'] : date('Y-m-d H:i:s', $now),
             $open ? date('Y-m-d H:i:s', $now + $cool) : null, $class, mb_substr($err, 0, 300)]
        );
        if ($open && ($b['state'] ?? 'closed') !== 'open') {
            admin_alert('warn', 'provider_down', "المزود {$prov} متوقف مؤقتًا ({$cap})",
                "{$count} أعطال خلال " . ($window / 60) . " دقايق — الطلبات بتتحول للبديل، وهيتجرب تاني بعد " . ($cool / 60) . " دقايق.\nآخر خطأ: " . mb_substr($err, 0, 200),
                'admin/ai-router.php', "prov_down:$prov:$cap");
        }
    } catch (\Throwable $e) {}
}

/* ═══════════ HTTP + التصنيف ═══════════ */

function ai_gw_http(string $url, array $headers, string $body, int $timeout): array
{
    $t0 = microtime(true);
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => defined('AI_CONNECT_TIMEOUT') ? AI_CONNECT_TIMEOUT : 10,
        CURLOPT_TIMEOUT => max(5, $timeout),
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$respHeaders) {
            $p = strpos($h, ':');
            if ($p !== false) $respHeaders[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
            return strlen($h);
        },
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['http' => $http, 'raw' => is_string($raw) ? $raw : '', 'errno' => $errno, 'err' => $err,
            'ms' => (int) round((microtime(true) - $t0) * 1000), 'headers' => $respHeaders];
}

/** نتيجة HTTP → [class, error, retry_after] (class = null لو نجح) */
function ai_gw_classify(array $h): array
{
    if ($h['errno']) {
        return [$h['errno'] === 28 ? 'timeout' : 'network', 'cURL ' . $h['errno'] . ': ' . $h['err'], null];
    }
    if ($h['http'] !== 200) {
        $ra = isset($h['headers']['retry-after']) && is_numeric($h['headers']['retry-after']) ? (int) $h['headers']['retry-after'] : null;
        return [usage_http_class($h['http'], $h['raw']), 'HTTP ' . $h['http'] . ': ' . mb_substr(strip_tags($h['raw']), 0, 240), $ra];
    }
    return [null, null, null];
}

function ai_gw_headers(array $p): array
{
    $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $p['key']];
    if ($p['kind'] === 'openrouter') {
        $h[] = 'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : '');
        $h[] = 'X-Title: Spread AI';
    }
    return $h;
}

/** الموديلات دي بتاخد max_completion_tokens ومابتقبلش temperature */
function ai_gw_reasoning_model(string $model): bool
{
    $m = strtolower(preg_replace('#^.*/#', '', $model));
    return (bool) preg_match('/^(o\d|gpt-5)|search-preview/', $m);
}

/** نص الرد (string أو parts) */
function ai_gw_msg_text($content): string
{
    if (is_string($content)) return $content;
    if (is_array($content)) {
        $t = '';
        foreach ($content as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) $t .= $part['text'];
        }
        return $t;
    }
    return '';
}

/* ═══════════ المحوّلات (Adapters) ═══════════ */

/** نص (OpenAI-compatible chat/completions) */
function ai_gw_text_call(array $p, string $model, array $req, int $timeout): array
{
    $payload = ['model' => $model, 'messages' => $req['messages']];
    $max = (int) ($req['max_tokens'] ?? 1000);
    if ($p['kind'] === 'openai') {
        // OpenAI: max_tokens اتلغى في الموديلات الجديدة — max_completion_tokens شغال مع كلهم
        $payload['max_completion_tokens'] = $max;
        if (!ai_gw_reasoning_model($model)) $payload['temperature'] = (float) ($req['temperature'] ?? 0.8);
    } else {
        $payload['max_tokens'] = $max;
        $payload['temperature'] = (float) ($req['temperature'] ?? 0.8);
    }
    if ($p['kind'] === 'openrouter') $payload['usage'] = ['include' => true];
    $h = ai_gw_http($p['base'] . '/chat/completions', ai_gw_headers($p), json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
    // الموديل رفض باراميتر؟ نعدّل ونجرّب مرة واحدة (موديلات جديدة/مزودين متوافقين بقواعد مختلفة)
    for ($fx = 0; $fx < 2 && $h['http'] === 400 && ($fixed = ai_gw_fix_params($payload, (string) $h['raw'])); $fx++) {
        $payload = $fixed;
        $h2 = ai_gw_http($p['base'] . '/chat/completions', ai_gw_headers($p), json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
        $h2['ms'] += $h['ms'];
        $h = $h2;
    }
    return ai_gw_parse_chat($h);
}

/** رسالة 400 عن باراميتر مش مدعوم → نسخة معدّلة من الطلب (أو null) */
function ai_gw_fix_params(array $payload, string $raw): ?array
{
    $m = strtolower($raw);
    $changed = false;
    if (str_contains($m, "'max_tokens'") && isset($payload['max_tokens']) && (str_contains($m, 'unsupported') || str_contains($m, 'not supported'))) {
        $payload['max_completion_tokens'] = $payload['max_tokens'];
        unset($payload['max_tokens']);
        $changed = true;
    } elseif (str_contains($m, "'max_completion_tokens'") && isset($payload['max_completion_tokens']) && (str_contains($m, 'unsupported') || str_contains($m, 'not supported') || str_contains($m, 'unrecognized'))) {
        $payload['max_tokens'] = $payload['max_completion_tokens'];
        unset($payload['max_completion_tokens']);
        $changed = true;
    }
    if (str_contains($m, 'temperature') && isset($payload['temperature']) && (str_contains($m, 'unsupported') || str_contains($m, 'not supported') || str_contains($m, 'only the default'))) {
        unset($payload['temperature']);
        $changed = true;
    }
    return $changed ? $payload : null;
}

/** موديل OpenAI مخصوص للبحث (بيقبل web_search_options في chat/completions)؟ */
function ai_gw_is_search_model(string $model): bool
{
    return str_contains(strtolower($model), 'search');
}

/**
 * بحث ويب بموديل OpenAI عادي (gpt-4o · gpt-4.1 · gpt-5 · o-series …) عن طريق Responses API وأداة web_search.
 * chat/completions مابيقبلش web_search_options غير مع موديلات الـ search — فده الطريق للموديلات التانية.
 * الرد بيتحوّل لنفس شكل chat/completions علشان باقي الكود (المصادر والتكلفة) يشتغل زي ما هو.
 */
function ai_gw_openai_responses_web(array $p, string $model, array $req, int $timeout): array
{
    $tool = ['type' => 'web_search', 'search_context_size' => (string) ($req['search_context'] ?? 'medium'),
             'user_location' => ['type' => 'approximate', 'country' => (string) ($req['country'] ?? 'EG')]];
    $payload = ['model' => $model, 'input' => (string) $req['prompt'], 'tools' => [$tool],
                'max_output_tokens' => max(512, (int) ($req['max_tokens'] ?? 2200))];
    $h = ai_gw_http($p['base'] . '/responses', ai_gw_headers($p), json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
    // تعديلات تلقائية لو الحساب/الموديل بيقبل صيغة أقدم للأداة
    for ($fx = 0; $fx < 2 && $h['http'] === 400; $fx++) {
        $m = strtolower((string) $h['raw']);
        if (str_contains($m, 'web_search') && $payload['tools'][0]['type'] === 'web_search' && (str_contains($m, 'tool') || str_contains($m, 'invalid value') || str_contains($m, 'unsupported'))) {
            $payload['tools'][0]['type'] = 'web_search_preview';
        } elseif (str_contains($m, 'user_location') || str_contains($m, 'search_context_size')) {
            unset($payload['tools'][0]['user_location'], $payload['tools'][0]['search_context_size']);
        } else {
            break;
        }
        $h2 = ai_gw_http($p['base'] . '/responses', ai_gw_headers($p), json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
        $h2['ms'] += $h['ms'];
        $h = $h2;
    }
    if ($h['http'] !== 200 || $h['errno']) return ['h' => $h, 'converted' => false];
    $d = json_decode((string) $h['raw'], true);
    if (!is_array($d) || !isset($d['output'])) return ['h' => $h, 'converted' => false];
    $text = '';
    $ann = [];
    foreach ((array) $d['output'] as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach ((array) ($item['content'] ?? []) as $c) {
            if (($c['type'] ?? '') !== 'output_text') continue;
            $off = mb_strlen($text);
            $text .= (string) ($c['text'] ?? '');
            foreach ((array) ($c['annotations'] ?? []) as $a) {
                if (($a['type'] ?? '') !== 'url_citation' || empty($a['url'])) continue;
                $ann[] = ['type' => 'url_citation', 'url_citation' => [
                    'url' => (string) $a['url'], 'title' => (string) ($a['title'] ?? ''),
                    'start_index' => $off + (int) ($a['start_index'] ?? 0), 'end_index' => $off + (int) ($a['end_index'] ?? 0),
                ]];
            }
        }
    }
    $chat = ['choices' => [['message' => ['role' => 'assistant', 'content' => $text, 'annotations' => $ann],
                            'finish_reason' => ($d['status'] ?? 'completed') === 'incomplete' ? 'length' : 'stop']],
             'usage' => ['prompt_tokens' => (int) ($d['usage']['input_tokens'] ?? 0), 'completion_tokens' => (int) ($d['usage']['output_tokens'] ?? 0)],
             'model' => (string) ($d['model'] ?? $model), 'via' => 'responses'];
    $h['raw'] = json_encode($chat, JSON_UNESCAPED_UNICODE);
    return ['h' => $h, 'converted' => true];
}

/** بحث ويب */
function ai_gw_web_call(array $p, string $model, array $req, int $timeout): array
{
    // OpenAI بموديل مش search → Responses API (ولو المسار ده مش متاح عند المزود: موديل الـ search الافتراضي)
    if ($p['kind'] === 'openai' && !ai_gw_is_search_model($model)) {
        $rw = ai_gw_openai_responses_web($p, $model, $req, $timeout);
        if ($rw['converted'] || !in_array((int) $rw['h']['http'], [404, 405], true)) {
            $r = ai_gw_parse_chat($rw['h']);
            $r['requests'] = 1;
            return $r;
        }
        $model = ai_gw_kinds()['openai'][2]['web'] ?? 'gpt-4o-mini-search-preview';
    }
    $scope = (array) ($req['scope'] ?? []);
    $payload = ['model' => $model, 'messages' => [['role' => 'user', 'content' => (string) $req['prompt']]]];
    $maxResults = (int) ($req['max_results'] ?? 6);
    $context = (string) ($req['search_context'] ?? 'medium');
    $country = (string) ($req['country'] ?? 'EG');
    if ($p['kind'] === 'openai') {
        $payload['max_completion_tokens'] = (int) ($req['max_tokens'] ?? 2200);
        $payload['web_search_options'] = ['search_context_size' => $context,
            'user_location' => ['type' => 'approximate', 'approximate' => ['country' => $country]]];
    } elseif ($p['kind'] === 'openrouter') {
        $payload['max_tokens'] = (int) ($req['max_tokens'] ?? 2200);
        $payload['temperature'] = 0.2;
        $payload['plugins'] = [['id' => 'web', 'max_results' => $maxResults]];
        $payload['usage'] = ['include' => true];
    } else { // perplexity
        $payload['max_tokens'] = (int) ($req['max_tokens'] ?? 2200);
        $payload['temperature'] = 0.2;
        $payload['web_search_options'] = ['search_context_size' => $context];
    }
    $h = ai_gw_http($p['base'] . '/chat/completions', ai_gw_headers($p), json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
    $r = ai_gw_parse_chat($h);
    $r['requests'] = 1;
    return $r;
}

function ai_gw_parse_chat(array $h): array
{
    [$class, $err, $ra] = ai_gw_classify($h);
    $data = $h['raw'] !== '' ? json_decode($h['raw'], true) : null;
    $base = ['ok' => false, 'text' => '', 'tin' => 0, 'tout' => 0, 'cost' => null, 'http' => $h['http'], 'class' => $class,
             'error' => $err, 'retry_after' => $ra, 'ms' => $h['ms'], 'data' => is_array($data) ? $data : null];
    if ($class) return $base;
    if (!is_array($data)) return array_merge($base, ['class' => 'server', 'error' => 'رد مش JSON من المزود']);
    if (isset($data['error']) && empty($data['choices'])) {
        $m = is_array($data['error']) ? (string) ($data['error']['message'] ?? json_encode($data['error'])) : (string) $data['error'];
        $code = is_array($data['error']) ? (int) ($data['error']['code'] ?? 0) : 0;
        return array_merge($base, ['class' => $code >= 400 ? usage_http_class($code, $m) : 'server', 'error' => mb_substr($m, 0, 240)]);
    }
    $text = ai_gw_msg_text($data['choices'][0]['message']['content'] ?? null);
    $base['tin'] = (int) ($data['usage']['prompt_tokens'] ?? 0);
    $base['tout'] = (int) ($data['usage']['completion_tokens'] ?? 0);
    $base['cost'] = isset($data['usage']['cost']) ? (float) $data['usage']['cost'] : null;
    $finish = (string) ($data['choices'][0]['finish_reason'] ?? '');
    if (trim($text) === '') {
        if ($finish === 'content_filter') return array_merge($base, ['class' => 'policy', 'error' => 'content_filter']);
        return array_merge($base, ['class' => 'empty', 'error' => 'رد فاضي']);
    }
    $base['ok'] = true;
    $base['text'] = $text;
    return $base;
}

/** صورة */
function ai_gw_image_call(array $p, string $model, array $req, int $timeout): array
{
    $prompt = (string) $req['prompt'];
    $refs = array_values(array_filter((array) ($req['refs'] ?? [])));
    $size = in_array($req['size'] ?? '', ['1024x1024', '1024x1536', '1536x1024'], true) ? $req['size'] : '1024x1024';
    $fail = ['ok' => false, 'bin' => null, 'mime' => null, 'tin' => 0, 'tout' => 0, 'cost' => null, 'http' => 0,
             'class' => 'server', 'error' => '', 'retry_after' => null, 'ms' => 0, 'size' => $size];

    if ($p['kind'] === 'openrouter') {
        $content = $prompt;
        if ($refs) {
            $content = [['type' => 'text', 'text' => $prompt]];
            foreach (array_slice($refs, 0, 4) as $u) $content[] = ['type' => 'image_url', 'image_url' => ['url' => $u]];
        }
        $aspect = ['1024x1536' => '2:3', '1536x1024' => '3:2'][$size] ?? '1:1';
        if (in_array($req['ratio'] ?? '', ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'], true)) $aspect = (string) $req['ratio'];
        $payload = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $content]], 'modalities' => ['image', 'text'],
                    'image_config' => ['aspect_ratio' => $aspect], 'usage' => ['include' => true]];
        $h = ai_gw_http($p['base'] . '/chat/completions', ai_gw_headers($p), json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
        [$class, $err, $ra] = ai_gw_classify($h);
        $fail = array_merge($fail, ['http' => $h['http'], 'ms' => $h['ms'], 'retry_after' => $ra]);
        if ($class) return array_merge($fail, ['class' => $class, 'error' => $err]);
        $data = json_decode($h['raw'], true);
        $url = $data['choices'][0]['message']['images'][0]['image_url']['url'] ?? null;
        $cost = isset($data['usage']['cost']) ? (float) $data['usage']['cost'] : null;
        if (!$url || !preg_match('#^data:(image/(?:png|jpeg|webp));base64,(.+)$#s', (string) $url, $m)) {
            $txt = ai_gw_msg_text($data['choices'][0]['message']['content'] ?? '');
            $isPolicy = (($data['choices'][0]['finish_reason'] ?? '') === 'content_filter') || preg_match('/safety|policy|can\'t (help|create)|cannot (help|create)/i', $txt);
            return array_merge($fail, ['class' => $isPolicy ? 'policy' : 'empty', 'error' => 'الموديل مارجّعش صورة', 'cost' => $cost,
                'tin' => (int) ($data['usage']['prompt_tokens'] ?? 0), 'tout' => (int) ($data['usage']['completion_tokens'] ?? 0)]);
        }
        return ['ok' => true, 'bin' => base64_decode($m[2]), 'mime' => $m[1], 'tin' => (int) ($data['usage']['prompt_tokens'] ?? 0),
                'tout' => (int) ($data['usage']['completion_tokens'] ?? 0), 'cost' => $cost, 'http' => 200, 'class' => null, 'error' => null,
                'retry_after' => null, 'ms' => $h['ms'], 'size' => $size];
    }

    // OpenAI Images API (openai + أي مزود متوافق)
    $auth = 'Authorization: Bearer ' . $p['key'];
    if ($refs && $p['caps']['image_edit']) {
        $boundary = '----SpreadAI' . bin2hex(random_bytes(8));
        $body = '';
        foreach (['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => '1'] as $k => $v) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
        }
        $n = 0;
        foreach ($refs as $uri) {
            if ($n >= 4) break;
            $bin = function_exists('ai_datauri_to_binary') ? ai_datauri_to_binary((string) $uri) : null;
            if (!$bin) continue;
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"image[]\"; filename=\"ref{$n}.png\"\r\nContent-Type: {$bin['mime']}\r\n\r\n{$bin['data']}\r\n";
            $n++;
        }
        $body .= "--{$boundary}--\r\n";
        if ($n > 0) {
            $h = ai_gw_http($p['base'] . '/images/edits', ['Content-Type: multipart/form-data; boundary=' . $boundary, $auth], $body, $timeout);
        }
    }
    if (!isset($h)) {
        $h = ai_gw_http($p['base'] . '/images/generations', ['Content-Type: application/json', $auth],
            json_encode(['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1], JSON_UNESCAPED_UNICODE), $timeout);
    }
    [$class, $err, $ra] = ai_gw_classify($h);
    $fail = array_merge($fail, ['http' => $h['http'], 'ms' => $h['ms'], 'retry_after' => $ra]);
    if ($class) return array_merge($fail, ['class' => $class, 'error' => $err]);
    $data = json_decode($h['raw'], true);
    $b64 = $data['data'][0]['b64_json'] ?? null;
    if (!$b64) return array_merge($fail, ['class' => 'empty', 'error' => 'مفيش صورة في الرد']);
    return ['ok' => true, 'bin' => base64_decode($b64), 'mime' => 'image/png',
            'tin' => (int) ($data['usage']['input_tokens'] ?? 0), 'tout' => (int) ($data['usage']['output_tokens'] ?? 0),
            'cost' => null, 'http' => 200, 'class' => null, 'error' => null, 'retry_after' => null, 'ms' => $h['ms'], 'size' => $size];
}

/** فيه JSON صالح في النص؟ (بيقبل ```json … ``` أو كائن جوه كلام) */
function ai_gw_json_ok(string $text): bool
{
    $t = trim($text);
    if (preg_match('/```(?:json)?\s*(.+?)```/s', $t, $m)) $t = trim($m[1]);
    json_decode($t, true);
    if (json_last_error() === JSON_ERROR_NONE && ($t[0] ?? '') !== '' && in_array($t[0], ['{', '['], true)) return true;
    $a = strpos($t, '{');
    $b = strrpos($t, '}');
    if ($a === false || $b === false || $b <= $a) return false;
    json_decode(substr($t, $a, $b - $a + 1), true);
    return json_last_error() === JSON_ERROR_NONE;
}

/* ═══════════ التشغيل ═══════════ */

/** رسالة للعميل حسب النتيجة */
function ai_gw_user_error(string $class, string $cap): string
{
    if ($class === 'policy') {
        return $cap === 'image'
            ? 'الطلب ده اترفض من سياسة المحتوى عند مزود التصميم — عدّل الوصف (من غير أسماء أشخاص/علامات تجارية أو محتوى حساس) وجرّب تاني.'
            : 'الطلب ده اترفض من سياسة المحتوى — عدّل الصياغة وجرّب تاني.';
    }
    if ($class === 'no_provider') return 'محرك الذكاء الاصطناعي مش مضبوط للخدمة دي — كلّم الإدارة.';
    return $cap === 'image' ? 'تعذر توليد التصميم حاليًا. حاول تاني بعد دقيقة.' : 'محرك الذكاء الاصطناعي مشغول حاليًا. حاول تاني بعد دقيقة.';
}

/**
 * تشغيل مهمة عبر السلسلة.
 * $req — text: messages, max_tokens, temperature · image: prompt, refs, size, ratio · web: prompt, max_results, search_context, country, max_tokens
 * $ctx — user_id, ref_type, ref_id, feature, customer_data, only (اختبار: ['p'=>name,'m'=>model])
 * @return array ok, text | bin+mime, data, provider, model, usage[in,out], cost_usd, run_id, error, error_class, failover, attempts
 */
function ai_run(string $task, array $req, array $ctx = []): array
{
    $route = ai_gw_route_row($task);
    $cap = (string) ($route['capability'] ?? 'text');
    $t0 = microtime(true);

    if (!empty($ctx['only'])) {
        $p = ai_gw_provider((string) $ctx['only']['p'], true);
        $chain = $p ? [['p' => $p, 'm' => (string) ($ctx['only']['m'] ?? '') ?: ai_gw_default_model($p, $cap)]] : [];
    } else {
        $chain = ai_gw_chain($task, [
            'customer_data' => $ctx['customer_data'] ?? true, // افتراضيًا بيانات عميل — اختبارات الأدمن بس بتبعت false
            'has_refs' => $cap === 'image' && !empty($req['refs']),
            'vision' => $cap === 'text' && ai_gw_has_images($req['messages'] ?? []),
        ]);
    }

    $runId = usage_run_start([
        'task' => $task, 'feature' => $ctx['feature'] ?? usage_feature(), 'route' => 'gateway',
        'user_id' => $ctx['user_id'] ?? null, 'ref_type' => $ctx['ref_type'] ?? null, 'ref_id' => $ctx['ref_id'] ?? null,
    ]);
    $result = ['ok' => false, 'text' => '', 'bin' => null, 'mime' => null, 'data' => null, 'provider' => null, 'model' => null,
               'usage' => ['in' => 0, 'out' => 0], 'cost_usd' => 0.0, 'run_id' => $runId, 'error' => null, 'error_class' => null,
               'failover' => false, 'attempts' => 0];

    if (!$chain) {
        admin_alert('critical', 'no_provider', "مفيش مزود شغال لمهمة «" . ($route['label'] ?? $task) . "»",
            'كل المزودين متوقفين أو مالهمش مفتاح أو مش بيدعموا القدرة المطلوبة (' . $cap . ').', 'admin/ai-providers.php', "noprov:$task");
        usage_run_finish($runId, ['status' => 'failed', 'error_class' => 'no_provider', 'error' => 'no provider', 'duration_ms' => 0]);
        return array_merge($result, ['error' => ai_gw_user_error('no_provider', $cap), 'error_class' => 'no_provider']);
    }

    // المزودين المتوقفين بيتخطّوا — إلا لو كلهم متوقفين (نجرّب الأول بدل ما نقفل الخدمة)
    $allowed = array_values(array_filter($chain, fn($c) => !empty($ctx['only']) || !ai_gw_breaker_blocked($c['p']['name'], $cap)));
    $forced = false;
    if (!$allowed) { $allowed = [$chain[0]]; $forced = true; }

    $maxAtt = max(1, (int) ($route['max_attempts'] ?? 3));
    $budget = max(15, (int) ($route['time_budget_s'] ?? 120));
    $deadline = $t0 + $budget;
    $costCap = isset($route['cost_budget_usd']) && $route['cost_budget_usd'] !== null && (float) $route['cost_budget_usd'] > 0 ? (float) $route['cost_budget_usd'] : null;
    $spent = 0.0;
    $seq = 0;
    $lastClass = 'server';
    $lastErr = null;
    $stop = false;
    @set_time_limit($budget + 30);

    foreach ($allowed as $idx => $target) {
        if ($stop || $seq >= $maxAtt || microtime(true) > $deadline - 5) break;
        if ($costCap !== null && $spent >= $costCap) { $lastErr = 'تخطّى ميزانية التكلفة'; break; }
        $p = $target['p'];
        $model = $target['m'];
        // اختبار «نص الفتحة» بيتاخد لحظة الإرسال بس (طلب واحد)
        if (empty($ctx['only']) && !$forced && !ai_gw_breaker_allows($p['name'], $cap)) continue;
        $tries = 0;
        $repaired = false;
        while (true) {
            $seq++;
            $tries++;
            $left = (int) floor($deadline - microtime(true));
            $timeout = max(8, min($p['timeout_s'] * ($cap === 'image' ? 2 : 1), $left));
            if ($cap === 'image') {
                $r = ai_gw_image_call($p, $model, $req, $timeout);
            } elseif ($cap === 'web') {
                $r = ai_gw_web_call($p, $model, $req, $timeout);
            } else {
                $r = ai_gw_text_call($p, $model, $req, $timeout);
            }
            // JSON مطلوب → إصلاح واحد على نفس الموديل
            if ($r['ok'] && $cap === 'text' && (int) ($route['json_required'] ?? 0) === 1 && !ai_gw_json_ok($r['text'])) {
                $spent += usage_attempt($runId, ai_gw_att($seq, $p, $model, $cap, $r, 'failed', 'bad_json', 'JSON غير صالح'));
                $r = ['ok' => false, 'class' => 'bad_json', 'error' => 'JSON غير صالح'] + $r;
                if (!$repaired && $seq < $maxAtt && microtime(true) < $deadline - 10 && ($costCap === null || $spent < $costCap)) {
                    $repaired = true;
                    $seq++;
                    $fix = ai_gw_text_call($p, $model, ['messages' => [
                        ['role' => 'system', 'content' => 'You fix malformed JSON. Return ONLY the corrected valid JSON, no commentary, no code fences.'],
                        ['role' => 'user', 'content' => mb_substr((string) $r['text'], 0, 30000)],
                    ], 'max_tokens' => (int) ($req['max_tokens'] ?? 2000), 'temperature' => 0], $timeout);
                    if ($fix['ok'] && !ai_gw_json_ok($fix['text'])) {
                        $fix = ['ok' => false, 'class' => 'bad_json', 'error' => 'الإصلاح مارجّعش JSON'] + $fix;
                    }
                    $r = $fix;
                } else {
                    $lastClass = 'bad_json';
                    $lastErr = 'JSON غير صالح';
                    ai_gw_breaker_fail($p['name'], $cap, 'bad_json', 'JSON غير صالح');
                    break;
                }
            }

            $cls = $r['ok'] ? null : (string) ($r['class'] ?: 'server');
            $spent += usage_attempt($runId, ai_gw_att($seq, $p, $model, $cap, $r, $r['ok'] ? 'ok' : 'failed', $cls, $r['error'] ?? null));

            if ($r['ok']) {
                ai_gw_breaker_ok($p['name'], $cap);
                $dur = (int) round((microtime(true) - $t0) * 1000);
                usage_run_finish($runId, ['status' => 'ok', 'duration_ms' => $dur]);
                $failover = $idx > 0;
                if ($failover) ai_gw_failover_watch($task);
                return array_merge($result, [
                    'ok' => true, 'text' => (string) ($r['text'] ?? ''), 'bin' => $r['bin'] ?? null, 'mime' => $r['mime'] ?? null,
                    'data' => $r['data'] ?? null, 'provider' => $p['name'], 'kind' => $p['kind'], 'model' => $model,
                    'usage' => ['in' => (int) ($r['tin'] ?? 0), 'out' => (int) ($r['tout'] ?? 0)],
                    'cost_usd' => $spent, 'failover' => $failover, 'attempts' => $seq, 'size' => $r['size'] ?? null,
                ]);
            }

            $lastClass = $cls;
            $lastErr = $r['error'] ?? null;
            ai_gw_breaker_fail($p['name'], $cap, $cls, (string) $lastErr);

            if ($cls === 'policy') { $stop = true; break; }
            if ($cls === 'unsupported') {
                // باراميتر/خاصية مش مدعومة عند المزود ده بالذات → المزود التالي (المزودين التانيين ممكن يدعموها)
                admin_alert('warn', 'integration', "{$p['name']} مابيدعمش الطلب ده ({$task})",
                    "المزود رفض باراميتر مش مدعوم مع الموديل {$model} — اتحوّل للبديل. راجع الموديل في «الموديلات والـ Router».\n" . mb_substr((string) $lastErr, 0, 300),
                    'admin/ai-router.php', "unsup:{$p['name']}:$task");
                break;
            }
            if ($cls === 'bad_request') {
                admin_alert('warn', 'integration', "خطأ تكامل مع {$p['name']} ({$task})",
                    "المزود رفض صيغة الطلب (400) — ده غالبًا إعداد غلط (اسم الموديل/الباراميترات) مش عطل مؤقت.\n" . mb_substr((string) $lastErr, 0, 300),
                    'admin/ai-runs.php', "int400:{$p['name']}:$task");
                $stop = true;
                break;
            }
            $timeLeft = $deadline - microtime(true);
            if ($costCap !== null && $spent >= $costCap) { $lastErr = 'تخطّى ميزانية التكلفة'; $stop = true; break; }
            if (in_array($cls, ['network', 'server', 'empty', 'timeout'], true) && $tries < 2 && $seq < $maxAtt && $timeLeft > 15) {
                usleep(700000);
                continue;
            }
            if ($cls === 'rate_limit' && $tries < 2 && $seq < $maxAtt) {
                $wait = (int) ($r['retry_after'] ?? 2);
                if ($wait <= min(8, (int) $timeLeft - 10)) {
                    sleep(max(1, $wait));
                    continue;
                }
            }
            break; // المزود التالي
        }
    }

    $status = $lastClass === 'policy' ? 'refused' : 'failed';
    usage_run_finish($runId, ['status' => $status, 'error_class' => $lastClass, 'error' => $lastErr,
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000)]);
    if ($status === 'failed' && count($allowed) > 1) {
        admin_alert('warn', 'all_failed', "كل البدائل فشلت لمهمة «" . ($route['label'] ?? $task) . "»",
            "آخر خطأ ({$lastClass}): " . mb_substr((string) $lastErr, 0, 250), 'admin/ai-runs.php', "allfail:$task");
    }
    return array_merge($result, ['error' => ai_gw_user_error($lastClass, $cap), 'error_class' => $lastClass,
        'error_detail' => $lastErr, 'cost_usd' => $spent, 'attempts' => $seq]);
}

function ai_gw_att(int $seq, array $p, string $model, string $cap, array $r, string $status, ?string $cls, ?string $err): array
{
    return [
        'seq' => $seq, 'provider' => $p['name'], 'model' => $model, 'capability' => $cap,
        'status' => $status, 'error_class' => $cls, 'error' => $status === 'ok' ? null : $err, 'http_code' => $r['http'] ?? null,
        'tokens_in' => (int) ($r['tin'] ?? 0), 'tokens_out' => (int) ($r['tout'] ?? 0),
        'images' => $cap === 'image' && $status === 'ok' ? 1 : 0, 'size' => $r['size'] ?? null,
        'requests' => $status === 'ok' ? (int) ($r['requests'] ?? 0) : 0, 'provider_cost' => $r['cost'] ?? null, 'duration_ms' => (int) ($r['ms'] ?? 0),
    ];
}

function ai_gw_has_images(array $messages): bool
{
    foreach ($messages as $m) {
        if (is_array($m['content'] ?? null)) {
            foreach ($m['content'] as $part) {
                if (($part['type'] ?? '') === 'image_url') return true;
            }
        }
    }
    return false;
}

/** التحويل للبديل كتير في الساعة الأخيرة؟ → تنبيه */
function ai_gw_failover_watch(string $task): void
{
    try {
        $lim = max(1, (int) usage_setting('ai_failover_alert_per_hour', 5));
        $n = (int) (db_one('SELECT COUNT(*) n FROM ai_runs WHERE failovers > 0 AND status = "ok" AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)')['n'] ?? 0);
        if ($n >= $lim) {
            admin_alert('warn', 'failover_rate', "التحويل للمزود البديل حصل {$n} مرة في آخر ساعة",
                'المزود الأساسي بيقع كتير — العملاء لسه بيتخدموا من البديل، بس راجع حالة المزود الأساسي.', 'admin/ai-router.php', 'failover_rate');
        }
    } catch (\Throwable $e) {}
}

/** سجل الـ debug القديم (البرومبت الكامل) للمسار الجديد */
function ai_gw_debug_log(string $kind, array $ctx, string $prompt, array $images, array $r): void
{
    if (!function_exists('ai_log_request')) return;
    ai_log_request([
        'kind' => $kind, 'user_id' => $ctx['user_id'] ?? null,
        'reference_type' => $ctx['reference_type'] ?? ($ctx['ref_type'] ?? null), 'reference_id' => $ctx['reference_id'] ?? ($ctx['ref_id'] ?? null),
        'options' => array_merge((array) ($ctx['options'] ?? []), ['run_id' => $r['run_id'] ?? null, 'attempts' => $r['attempts'] ?? 0]),
        'route' => 'gateway', 'provider' => $r['provider'] ?? null, 'model' => $r['model'] ?? null,
        'endpoint' => 'Gateway → ' . ($r['provider'] ?? '—') . (!empty($r['failover']) ? ' (بديل)' : ''),
        'prompt' => $prompt, 'images' => $images,
        'status' => !empty($r['ok']) ? 'ok' : 'failed', 'error' => !empty($r['ok']) ? null : ($r['error_detail'] ?? $r['error'] ?? null),
        'response' => !empty($r['ok']) ? ($r['text'] !== '' ? $r['text'] : 'صورة') : null,
        'tokens_in' => (int) ($r['usage']['in'] ?? 0), 'tokens_out' => (int) ($r['usage']['out'] ?? 0),
        'duration_ms' => 0,
    ]);
}

/**
 * نص بنفس شكل ai_generate() القديمة: ['ok','response','error','usage','model']
 */
function ai_gw_generate_text(string $prompt, array $imageUrls = [], int $maxTokens = 1000, float $temperature = 0.8, ?string $taskOverride = null): array
{
    $ctx = function_exists('ai_log_context') ? ai_log_context() : [];
    $kind = (string) ($ctx['kind'] ?? 'text');
    $task = $taskOverride ?: usage_task_for_kind($kind);
    $messages = function_exists('ai_build_messages') ? ai_build_messages($prompt, $imageUrls) : [['role' => 'user', 'content' => $prompt]];
    $r = ai_run($task, ['messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature], [
        'user_id' => $ctx['user_id'] ?? null, 'ref_type' => $ctx['reference_type'] ?? null, 'ref_id' => $ctx['reference_id'] ?? null,
        'feature' => $kind !== 'text' ? $kind : null,
    ]);
    ai_gw_debug_log($kind, $ctx, $prompt, $imageUrls, $r);
    return [
        'ok' => $r['ok'], 'response' => $r['ok'] ? $r['text'] : null, 'error' => $r['ok'] ? null : $r['error'],
        'usage' => $r['usage'], 'model' => $r['model'] ?? 'gateway', 'provider' => $r['provider'] ?? null,
        'error_class' => $r['error_class'] ?? null, 'run_id' => $r['run_id'] ?? null,
    ];
}

/** صورة بنفس شكل ai_generate_image(): ['ok','path','error','model'] */
function ai_gw_generate_image(string $prompt, array $refImages = []): array
{
    $ctx = function_exists('ai_log_context') ? ai_log_context() : [];
    $opts = (array) ($ctx['options'] ?? []);
    $r = ai_run('design', ['prompt' => $prompt, 'refs' => $refImages, 'size' => (string) ($opts['size'] ?? ($ctx['size'] ?? '')),
        'ratio' => (string) ($opts['ratio'] ?? '')], [
        'user_id' => $ctx['user_id'] ?? null, 'ref_type' => $ctx['reference_type'] ?? null, 'ref_id' => $ctx['reference_id'] ?? null,
        'feature' => $ctx['kind'] ?? 'design',
    ]);
    $imgMeta = $ctx['images'] ?? $refImages;
    if (!$r['ok'] || !$r['bin']) {
        ai_gw_debug_log((string) ($ctx['kind'] ?? 'design'), $ctx, $prompt, (array) $imgMeta, $r);
        return ['ok' => false, 'path' => null, 'error' => $r['error'] ?: 'تعذر توليد التصميم حاليًا. حاول تاني بعد دقيقة.', 'model' => $r['model'] ?? 'gateway',
                'error_class' => $r['error_class'] ?? null];
    }
    $dir = UPLOADS_PATH . '/designs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ext = ['image/jpeg' => 'jpg', 'image/webp' => 'webp'][$r['mime']] ?? 'png';
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!@file_put_contents($dir . '/' . $filename, $r['bin'])) {
        return ['ok' => false, 'path' => null, 'error' => 'تعذر حفظ التصميم.', 'model' => $r['model']];
    }
    $r['text'] = 'تم حفظ الصورة: uploads/designs/' . $filename;
    ai_gw_debug_log((string) ($ctx['kind'] ?? 'design'), $ctx, $prompt, (array) $imgMeta, $r);
    return ['ok' => true, 'path' => 'uploads/designs/' . $filename, 'error' => null, 'model' => $r['model'], 'provider' => $r['provider']];
}

/** اختبار اتصال مزود (طلب نصي صغير جدًا) → [ok, msg, ms] */
function ai_gw_test_provider(string $name, ?string $model = null, string $cap = 'text'): array
{
    $p = ai_gw_provider($name, true);
    if (!$p) return [false, 'المزود مش موجود', 0];
    if ($p['key'] === '') return [false, 'مفيش API Key محفوظ', 0];
    $model = $model ?: ai_gw_default_model($p, $cap === 'image' ? 'text' : $cap);
    if ($model === '') return [false, 'مفيش موديل محدد', 0];
    $t0 = microtime(true);
    if ($cap === 'web') {
        $r = ai_gw_web_call($p, $model, ['prompt' => 'Reply with the single word: OK', 'max_tokens' => 20, 'max_results' => 1, 'search_context' => 'low'], 30);
    } else {
        $r = ai_gw_text_call($p, $model, ['messages' => [['role' => 'user', 'content' => 'Reply with the single word: OK']], 'max_tokens' => 16, 'temperature' => 0], 30);
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $msg = $r['ok'] ? ('اشتغل ✓ · ' . $model . ' · ' . $ms . 'ms') : (($r['class'] ?? 'error') . ' — ' . mb_substr((string) $r['error'], 0, 180));
    try {
        db_run('UPDATE ai_providers SET last_test_at = NOW(), last_test_ok = ?, last_test_msg = ?, health_status = ?, last_health_check = NOW() WHERE provider_name = ?',
            [$r['ok'] ? 1 : 0, mb_substr($msg, 0, 255), $r['ok'] ? 'healthy' : 'down', $name]);
    } catch (\Throwable $e) {}
    if ($r['ok']) ai_gw_breaker_ok($name, $cap === 'web' ? 'web' : 'text');
    return [$r['ok'], $msg, $ms];
}
