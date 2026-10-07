<?php
require_once __DIR__ . '/ai-log.php';
require_once __DIR__ . '/ai-gateway.php'; // 8-أ: البوابة الموحدة بالبدائل (بتشتغل لما تتفعّل من الأدمن)
/**
 * Spread AI — AI Calling Module (v2)
 *
 * - ai_generate():        text generation with vision images, usage tracking, model fallback
 * - ai_generate_stream(): SSE streaming generation (callback per chunk)
 * - ai_generate_image():  image/design generation (OpenAI images API or OpenRouter modalities)
 * - Falls back to mock content/image when no API key is configured.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Log AI errors to storage/logs/ai.log (never shown to users)
 */
function ai_log_error(string $message): void
{
    if (!is_dir(LOGS_PATH)) {
        @mkdir(LOGS_PATH, 0755, true);
    }
    @file_put_contents(
        LOGS_PATH . '/ai.log',
        date('Y-m-d H:i:s') . ' — ' . $message . "\n",
        FILE_APPEND
    );
}

/**
 * Build the messages array (with optional vision image URLs)
 */
function ai_build_messages(string $prompt, array $imageUrls = []): array
{
    $system = ['role' => 'system', 'content' => 'You are a professional Arabic content creator for social media.'];

    if (empty($imageUrls)) {
        return [$system, ['role' => 'user', 'content' => $prompt]];
    }

    // Vision: user message becomes multi-part (text + images)
    $parts = [['type' => 'text', 'text' => $prompt]];
    foreach (array_slice($imageUrls, 0, 4) as $url) { // cap at 4 images
        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
    }
    return [$system, ['role' => 'user', 'content' => $parts]];
}

/**
 * Low-level single API call.
 * Returns ['ok', 'response', 'error', 'usage' => ['in','out'], 'raw']
 */
function ai_call(string $model, array $messages, float $temperature = 0.8, int $maxTokens = 1000): array
{
    $url = AI_PROVIDER === 'openai' ? AI_API_URL_OPENAI : AI_API_URL_OPENROUTER;

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens
    ];

    $__cred = ai_effective_credentials();
    if (!empty($__cred['url'])) {
        $url = $__cred['url'];
    }
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . ($__cred['key'] ?: (defined('AI_API_KEY') ? AI_API_KEY : ''))
    ];
    if (($__cred['provider'] ?: (defined('AI_PROVIDER') ? AI_PROVIDER : '')) === 'openrouter') {
        $headers[] = 'HTTP-Referer: ' . APP_URL;
        $headers[] = 'X-Title: Spread AI';
        $payload['usage'] = ['include' => true]; // 8-أ: التكلفة الفعلية من OpenRouter
    }

    @set_time_limit(AI_TIMEOUT + 15);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(ai_openai_payload($payload, $url), JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => AI_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => AI_TIMEOUT
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        ai_log_error("[$model] cURL: $error");
        return ['ok' => false, 'response' => null, 'error' => 'curl', 'usage' => ['in' => 0, 'out' => 0]];
    }
    if ($httpCode !== 200) {
        ai_log_error("[$model] HTTP $httpCode: " . substr((string) $response, 0, 2000));
        return ['ok' => false, 'response' => null, 'error' => 'http_' . $httpCode, 'usage' => ['in' => 0, 'out' => 0]];
    }

    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? null;
    if (!$text) {
        ai_log_error("[$model] empty response: " . substr((string) $response, 0, 1000));
        return ['ok' => false, 'response' => null, 'error' => 'empty', 'usage' => ['in' => 0, 'out' => 0]];
    }

    return [
        'ok' => true,
        'response' => $text,
        'error' => null,
        'usage' => [
            'in'  => (int) ($data['usage']['prompt_tokens'] ?? 0),
            'out' => (int) ($data['usage']['completion_tokens'] ?? 0),
            'cost' => isset($data['usage']['cost']) ? (float) $data['usage']['cost'] : null,
        ],
        'raw' => $data,
    ];
}


/* ═══════════ مصدر مفتاح الـ API الفعّال ═══════════
 * الأولوية: config.php ← إعداد الأدمن ← أول موفر نشط في إدارة الـ AI
 * كده لو الأدمن ضبط موفر من اللوحة، الكود يستخدمه حتى لو الـ Smart Router مقفول
 */
function ai_effective_credentials(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }

    // 1) config.php
    if (defined('AI_API_KEY') && AI_API_KEY !== '') {
        $provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'openrouter';
        return $c = [
            'key' => AI_API_KEY,
            'provider' => $provider,
            'url' => $provider === 'openai' ? AI_API_URL_OPENAI : AI_API_URL_OPENROUTER,
            'source' => 'config.php',
        ];
    }

    // 2) إعداد مباشر من لوحة الأدمن
    if (function_exists('get_setting')) {
        try {
            $enc = (string) get_setting('ai_api_key_enc', '');
            if ($enc !== '' && class_exists('Crypto')) {
                $k = Crypto::decrypt($enc);
                if ($k) {
                    $provider = (string) get_setting('ai_provider', 'openrouter');
                    return $c = [
                        'key' => $k,
                        'provider' => $provider,
                        'url' => $provider === 'openai' ? AI_API_URL_OPENAI : AI_API_URL_OPENROUTER,
                        'source' => 'admin_settings',
                    ];
                }
            }
        } catch (\Throwable $e) {
        }
    }

    // 3) أول موفر نشط من إدارة الـ AI
    try {
        $row = db_one(
            'SELECT provider_name, api_base_url, api_key_encrypted FROM ai_providers
             WHERE status = "active" AND api_key_encrypted IS NOT NULL AND api_key_encrypted != ""
             ORDER BY is_default DESC, priority ASC LIMIT 1'
        );
        if ($row && class_exists('Crypto')) {
            $k = Crypto::decrypt($row['api_key_encrypted']);
            if ($k) {
                $base = rtrim((string) $row['api_base_url'], '/');
                $url = $base !== ''
                    ? (str_contains($base, '/chat/completions') ? $base : $base . '/chat/completions')
                    : AI_API_URL_OPENROUTER;
                return $c = [
                    'key' => $k,
                    'provider' => $row['provider_name'],
                    'url' => $url,
                    'source' => 'ai_providers',
                ];
            }
        }
    } catch (\Throwable $e) {
    }

    return $c = ['key' => '', 'provider' => '', 'url' => '', 'source' => 'none'];
}

/**
 * OpenAI المباشر: max_tokens اتلغى في الموديلات الجديدة (gpt-5 · o-series …) → max_completion_tokens،
 * وموديلات الـ reasoning مابتقبلش temperature. OpenRouter وغيره زي ما هم.
 */
function ai_openai_payload(array $payload, string $url): array
{
    if (!str_contains($url, 'api.openai.com')) return $payload;
    if (isset($payload['max_tokens'])) {
        $payload['max_completion_tokens'] = $payload['max_tokens'];
        unset($payload['max_tokens']);
    }
    $m = strtolower(preg_replace('#^.*/#', '', (string) ($payload['model'] ?? '')));
    if (preg_match('/^(o\d|gpt-5)/', $m)) unset($payload['temperature']);
    return $payload;
}

function ai_has_key(): bool
{
    $c = ai_effective_credentials();
    return $c['key'] !== '';
}

/** هل مسموح بالمحتوى الوهمي؟ (افتراضيًا لأ — عشان ميحصلش خداع) */
function ai_mock_allowed(): bool
{
    return function_exists('get_setting') && get_setting('allow_mock', '0') === '1';
}

/**
 * High-level generation: real API when key exists; mock only if explicitly allowed.
 * Returns ['ok', 'response', 'error', 'usage', 'model']
 */

/** تسجيل نتيجة توليد نصي (بيقرا سياق النداء من ai_log_context) */
function ai_log_text_result(string $prompt, array $imageUrls, array $r, float $t0, string $model): array
{
    $ctx = ai_log_context();
    $cred = ai_effective_credentials();
    ai_log_request([
        'kind'           => $ctx['kind'] ?? 'text',
        'user_id'        => $ctx['user_id'] ?? null,
        'reference_type' => $ctx['reference_type'] ?? null,
        'reference_id'   => $ctx['reference_id'] ?? null,
        'options'        => $ctx['options'] ?? null,
        'route'          => 'legacy',
        'provider'       => $cred['provider'] ?: null,
        'model'          => $model,
        'endpoint'       => $cred['url'] ?: null,
        'prompt'         => $prompt,
        'images'         => $imageUrls,
        'status'         => $r['ok'] ? 'ok' : 'failed',
        'error'          => $r['ok'] ? null : ($r['error'] ?? null),
        'response'       => $r['ok'] ? (string) ($r['response'] ?? '') : null,
        'tokens_in'      => (int) ($r['usage']['in'] ?? 0),
        'tokens_out'     => (int) ($r['usage']['out'] ?? 0),
        'cost_usd'       => $r['usage']['cost'] ?? null,
        'cap'            => ($ctx['kind'] ?? '') === 'research_search' ? 'web' : 'text',
        'duration_ms'    => (int) round((microtime(true) - $t0) * 1000),
    ]);
    return $r;
}

/**
 * سياق النداء الحالي — بيتحط قبل الاستدعاء عشان السجل يعرف
 * البوست ده تبع مين وإيه نوعه.
 */
function ai_log_set_context(array $ctx): void
{
    $GLOBALS['__ai_log_ctx'] = $ctx;
}

function ai_log_context(): array
{
    return $GLOBALS['__ai_log_ctx'] ?? [];
}

function ai_generate(string $prompt, array $imageUrls = []): array
{
    // 8-أ: البوابة الموحدة بالبدائل (أولوية على أي مسار تاني لما تتفعّل)
    if (function_exists('ai_gw_enabled') && ai_gw_enabled()) {
        return ai_gw_generate_text($prompt, $imageUrls);
    }

    // Phase 1: Smart Router (لو مفعّل من الأدمن)
    if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
        return smart_ai_generate($prompt, $imageUrls, 'content_generation');
    }

    if (!ai_has_key()) {
        if (ai_mock_allowed()) {
            return [
                'ok' => true, 'response' => mock_ai_response($prompt), 'error' => null,
                'usage' => ['in' => 0, 'out' => 0], 'model' => 'mock'
            ];
        }
        return [
            'ok' => false, 'response' => null,
            'error' => 'محرك الذكاء الاصطناعي غير مضبوط — الإدارة لازم تضيف مفتاح API من «إدارة الـ AI».',
            'usage' => ['in' => 0, 'out' => 0], 'model' => 'unconfigured'
        ];
    }

    $__logT0 = microtime(true);
    $__cred0 = ai_effective_credentials();
    $messages = ai_build_messages($prompt, $imageUrls);

    // Primary model
    $primary = function_exists('get_setting') ? (get_setting('ai_model', AI_MODEL) ?: AI_MODEL) : AI_MODEL;
    $r = ai_call($primary, $messages);
    if ($r['ok']) {
        $r['model'] = $primary;
        return ai_log_text_result($prompt, $imageUrls, $r, $__logT0, $primary);
    }

    // Fallback model (feature 29)
    $fallback = function_exists('get_setting') ? get_setting('ai_model_fallback', '') : '';
    if ($fallback && $fallback !== $primary) {
        ai_log_error("primary [$primary] failed ({$r['error']}), trying fallback [$fallback]");
        $r2 = ai_call($fallback, $messages);
        if ($r2['ok']) {
            $r2['model'] = $fallback;
            return ai_log_text_result($prompt, $imageUrls, $r2, $__logT0, $fallback);
        }
    }

    return ai_log_text_result($prompt, $imageUrls, [
        'ok' => false, 'response' => null,
        'error' => 'محرك الذكاء الاصطناعي مشغول حاليًا. حاول تاني بعد دقيقة.',
        'usage' => ['in' => 0, 'out' => 0], 'model' => $primary
    ], $__logT0, $primary);
}

/**
 * Streaming generation (feature 24).
 * $onChunk is called with each text delta. Returns same shape as ai_generate
 * with the FULL final text in 'response'.
 * Mock mode: chunks the mock response to simulate streaming.
 */
function ai_generate_stream(string $prompt, callable $onChunk, array $imageUrls = []): array
{
    // 8-أ: البوابة (من غير stream — النص كله بيتبعت مرة واحدة)
    if (function_exists('ai_gw_enabled') && ai_gw_enabled()) {
        $r = ai_gw_generate_text($prompt, $imageUrls);
        if ($r['ok']) {
            $onChunk((string) $r['response']);
        }
        return $r;
    }
    if (!ai_has_key()) {
        $full = mock_ai_response($prompt);
        // simulate streaming: emit in small chunks
        $len = mb_strlen($full);
        for ($i = 0; $i < $len; $i += 12) {
            $onChunk(mb_substr($full, $i, 12));
            usleep(30000);
        }
        return ['ok' => true, 'response' => $full, 'error' => null, 'usage' => ['in' => 0, 'out' => 0], 'model' => 'mock'];
    }

    $url = AI_PROVIDER === 'openai' ? AI_API_URL_OPENAI : AI_API_URL_OPENROUTER;
    $model = function_exists('get_setting') ? (get_setting('ai_model', AI_MODEL) ?: AI_MODEL) : AI_MODEL;

    $payload = [
        'model' => $model,
        'messages' => ai_build_messages($prompt, $imageUrls),
        'temperature' => 0.8,
        'max_tokens' => 1000,
        'stream' => true,
        'stream_options' => ['include_usage' => true]
    ];

    $__cred = ai_effective_credentials();
    if (!empty($__cred['url'])) {
        $url = $__cred['url'];
    }
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . ($__cred['key'] ?: (defined('AI_API_KEY') ? AI_API_KEY : ''))
    ];
    if (($__cred['provider'] ?: (defined('AI_PROVIDER') ? AI_PROVIDER : '')) === 'openrouter') {
        $headers[] = 'HTTP-Referer: ' . APP_URL;
        $headers[] = 'X-Title: Spread AI';
    }

    @set_time_limit(AI_TIMEOUT + 30);
    $__streamT0 = microtime(true);

    $full = '';
    $usage = ['in' => 0, 'out' => 0];
    $buffer = '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(ai_openai_payload($payload, $url), JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => AI_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => AI_TIMEOUT + 20, // streaming needs longer total window
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$full, &$usage, &$buffer, $onChunk) {
            $buffer .= $chunk;
            // Process complete SSE lines
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || strpos($line, 'data:') !== 0) continue;
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') continue;
                $j = json_decode($data, true);
                if (!$j) continue;
                $delta = $j['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $full .= $delta;
                    $onChunk($delta);
                }
                if (isset($j['usage'])) {
                    $usage['in']  = (int) ($j['usage']['prompt_tokens'] ?? 0);
                    $usage['out'] = (int) ($j['usage']['completion_tokens'] ?? 0);
                }
            }
            return strlen($chunk);
        }
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($full !== '') {
        // 8-أ: الـ stream كمان بيتسجل (التكلفة والاستهلاك)
        return ai_log_text_result($prompt, $imageUrls, ['ok' => true, 'response' => $full, 'error' => null, 'usage' => $usage, 'model' => $model], $__streamT0, $model);
    }

    ai_log_error("[stream $model] failed HTTP $httpCode " . ($error ?: ''));

    // Fall back to non-streaming path (which itself has model fallback)
    $r = ai_generate($prompt, $imageUrls);
    if ($r['ok']) {
        $onChunk($r['response']);
    }
    return $r;
}

/**
 * Image / design generation (feature 21).
 * Returns ['ok', 'path' (relative to storage/), 'error', 'model']
 */
/**
 * توليد صورة.
 * @param array $refImages صور مرجعية كـ data URI (لوجو · صور البراند · مرجع ستايل)
 */
/**
 * بناء محتوى الرسالة: نص + صور مرجعية.
 * لو مفيش صور بيرجّع النص زي ما هو (نفس السلوك القديم بالظبط).
 */
function ai_image_parts(string $prompt, array $refImages)
{
    if (empty($refImages)) {
        return $prompt;
    }
    $parts = [['type' => 'text', 'text' => $prompt]];
    $n = 0;
    foreach ($refImages as $uri) {
        $uri = trim((string) $uri);
        if ($uri === '' || $n >= 4) continue;
        if (!preg_match('~^(data:image/|https?://)~i', $uri)) continue;
        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $uri]];
        $n++;
    }
    return $parts;
}


/** تسجيل نتيجة توليد صورة */
function ai_log_image_result(string $prompt, array $refImages, array $r, float $t0, string $endpoint, string $provider): array
{
    $ctx = ai_log_context();
    ai_log_request([
        'kind'           => $ctx['kind'] ?? 'design',
        'user_id'        => $ctx['user_id'] ?? null,
        'reference_type' => $ctx['reference_type'] ?? null,
        'reference_id'   => $ctx['reference_id'] ?? null,
        'options'        => $ctx['options'] ?? null,
        'route'          => 'legacy',
        'provider'       => $provider,
        'model'          => $r['model'] ?? null,
        'endpoint'       => $endpoint,
        'prompt'         => $prompt,
        'images'         => $ctx['images'] ?? $refImages,
        'status'         => !empty($r['ok']) ? 'ok' : 'failed',
        'error'          => !empty($r['ok']) ? null : ($r['error'] ?? null),
        'response'       => !empty($r['ok']) ? ('تم حفظ الصورة: ' . ($r['path'] ?? '')) : null,
        'cap'            => 'image',
        'duration_ms'    => (int) round((microtime(true) - $t0) * 1000),
    ]);
    return $r;
}

/** data:image/png;base64,... → ['mime'=>..,'data'=>binary] */
function ai_datauri_to_binary(string $uri): ?array
{
    if (!preg_match('~^data:(image/[a-z+]+);base64,(.+)$~is', trim($uri), $m)) {
        return null;
    }
    $bin = base64_decode($m[2], true);
    return $bin === false ? null : ['mime' => $m[1], 'data' => $bin];
}

function ai_generate_image(string $prompt, array $refImages = []): array
{
    // 8-أ: البوابة الموحدة بالبدائل
    if (function_exists('ai_gw_enabled') && ai_gw_enabled()) {
        return ai_gw_generate_image($prompt, $refImages);
    }

    // Phase 1: Smart Router (لو مفعّل من الأدمن)
    if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
        return smart_ai_image($prompt, ['reference_images' => $refImages]);
    }

    $dir = UPLOADS_PATH . '/designs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(12)) . '.png';
    $dest = $dir . '/' . $filename;
    $relative = 'uploads/designs/' . $filename;

    // Mock mode: generate a simple branded placeholder with GD
    if (!ai_has_key()) {
        $img = imagecreatetruecolor(1080, 1080);
        $c1 = imagecolorallocate($img, 124, 109, 242);
        $c2 = imagecolorallocate($img, 30, 27, 58);
        for ($y = 0; $y < 1080; $y++) {
            $ratio = $y / 1080;
            $col = imagecolorallocate(
                $img,
                (int) (124 + (30 - 124) * $ratio),
                (int) (109 + (27 - 109) * $ratio),
                (int) (242 + (58 - 242) * $ratio)
            );
            imageline($img, 0, $y, 1080, $y, $col);
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 440, 520, 'Spread AI - Mock Design', $white);
        imagepng($img, $dest);
        imagedestroy($img);
        return ['ok' => true, 'path' => $relative, 'error' => null, 'model' => 'mock'];
    }

    @set_time_limit(90);

    // 8-أ: المقاس الحقيقي (الستوري/الطولي) بدل 1024x1024 الثابت
    $__ctxOpts = (array) (ai_log_context()['options'] ?? []);
    $__size = in_array($__ctxOpts['size'] ?? '', ['1024x1024', '1024x1536', '1536x1024'], true) ? $__ctxOpts['size'] : '1024x1024';
    $__aspect = ['1024x1536' => '2:3', '1536x1024' => '3:2'][$__size] ?? '1:1';
    // النسبة الدقيقة لو معروفة (9:16 للستوري · 4:5 …) — موديلات Gemini بتدعمها مباشرة
    if (in_array($__ctxOpts['ratio'] ?? '', ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'], true)) {
        $__aspect = $__ctxOpts['ratio'];
    }

    $__imgT0 = microtime(true);
    $provider = ai_effective_credentials()['provider'] ?: (defined('AI_PROVIDER') ? AI_PROVIDER : 'openrouter');
    $__endpoint = $provider === 'openai'
        ? ('https://api.openai.com/v1/images/' . (!empty($refImages) ? 'edits' : 'generations'))
        : AI_API_URL_OPENROUTER;

    if ($provider === 'openai') {
        $apiKey = ai_effective_credentials()['key'] ?: AI_API_KEY;

        // ⚠️ مهم: /v1/images/generations نص فقط — بيتجاهل أي صور.
        // عشان الموديل يشوف اللوجو لازم /v1/images/edits بـ multipart.
        if (!empty($refImages)) {
            // بناء multipart بإيدينا — cURL مبيقبلش مفتاح image[] مكرر
            $boundary = '----SpreadAI' . bin2hex(random_bytes(8));
            $body = '';

            foreach (['model' => 'gpt-image-1', 'prompt' => $prompt,
                      'size' => $__size, 'n' => '1'] as $k => $v) {
                $body .= "--{$boundary}\r\n"
                    . "Content-Disposition: form-data; name=\"{$k}\"\r\n\r\n"
                    . $v . "\r\n";
            }

            $n = 0;
            foreach ($refImages as $uri) {
                if ($n >= 4) break;                 // حد OpenAI
                $bin = ai_datauri_to_binary((string) $uri);
                if (!$bin) continue;
                $body .= "--{$boundary}\r\n"
                    . "Content-Disposition: form-data; name=\"image[]\"; filename=\"ref{$n}.png\"\r\n"
                    . "Content-Type: {$bin['mime']}\r\n\r\n"
                    . $bin['data'] . "\r\n";
                $n++;
            }
            $body .= "--{$boundary}--\r\n";

            if ($n === 0) {
                ai_log_error('[image openai] مفيش صورة صالحة — رجعنا للتوليد النصي');
                $refImages = [];
            }
        }

        if (!empty($refImages)) {
            $ch = curl_init('https://api.openai.com/v1/images/edits');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: multipart/form-data; boundary=' . $boundary,
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => AI_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => 110,
            ]);
        } else {
        // من غير صور: التوليد النصي العادي
        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-image-1',
                'prompt' => $prompt,
                'size' => $__size,
                'n' => 1
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_CONNECTTIMEOUT => AI_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => 75
        ]);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode !== 200) {
            ai_log_error('[image openai] HTTP ' . $httpCode . ' ' . ($err ?: substr((string) $response, 0, 1000)));
            return ai_log_image_result($prompt, $refImages, ['ok' => false, 'path' => null, 'error' => 'تعذر توليد التصميم حاليًا. حاول تاني بعد دقيقة.', 'model' => 'gpt-image-1'], $__imgT0, $__endpoint, $provider);
        }
        $data = json_decode($response, true);
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!$b64 || !file_put_contents($dest, base64_decode($b64))) {
            ai_log_error('[image openai] no b64 in response');
            return ai_log_image_result($prompt, $refImages, ['ok' => false, 'path' => null, 'error' => 'تعذر حفظ التصميم.', 'model' => 'gpt-image-1'], $__imgT0, $__endpoint, $provider);
        }
        return ai_log_image_result($prompt, $refImages, ['ok' => true, 'path' => $relative, 'error' => null, 'model' => 'gpt-image-1'], $__imgT0, $__endpoint, $provider);
    }

    // OpenRouter: chat completions with image modality
    $imageModel = function_exists('get_setting')
        ? (get_setting('ai_image_model', 'google/gemini-2.5-flash-image') ?: 'google/gemini-2.5-flash-image')
        : 'google/gemini-2.5-flash-image';

    $ch = curl_init(AI_API_URL_OPENROUTER);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $imageModel,
            // الصور المرجعية بتتبعت مع النص عشان الموديل يشوف اللوجو فعليًا
            'messages' => [['role' => 'user', 'content' => ai_image_parts($prompt, $refImages)]],
            'modalities' => ['image', 'text'],
            'image_config' => ['aspect_ratio' => $__aspect]
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (ai_effective_credentials()['key'] ?: AI_API_KEY),
            'HTTP-Referer: ' . APP_URL,
            'X-Title: Spread AI'
        ],
        CURLOPT_CONNECTTIMEOUT => AI_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => 75
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $httpCode !== 200) {
        ai_log_error('[image openrouter] HTTP ' . $httpCode . ' ' . ($err ?: substr((string) $response, 0, 1000)));
        return ai_log_image_result($prompt, $refImages, ['ok' => false, 'path' => null, 'error' => 'تعذر توليد التصميم حاليًا. حاول تاني بعد دقيقة.', 'model' => $imageModel], $__imgT0, $__endpoint, $provider);
    }

    $data = json_decode($response, true);
    // OpenRouter returns images in message.images[].image_url.url as data URLs
    $dataUrl = $data['choices'][0]['message']['images'][0]['image_url']['url'] ?? null;
    if (!$dataUrl || !preg_match('/^data:image\/(png|jpeg|webp);base64,(.+)$/s', $dataUrl, $m)) {
        ai_log_error('[image openrouter] no image in response: ' . substr((string) $response, 0, 500));
        return ai_log_image_result($prompt, $refImages, ['ok' => false, 'path' => null, 'error' => 'الموديل لم يرجع صورة. جرب تاني.', 'model' => $imageModel], $__imgT0, $__endpoint, $provider);
    }
    if (!file_put_contents($dest, base64_decode($m[2]))) {
        return ai_log_image_result($prompt, $refImages, ['ok' => false, 'path' => null, 'error' => 'تعذر حفظ التصميم.', 'model' => $imageModel], $__imgT0, $__endpoint, $provider);
    }
    return ai_log_image_result($prompt, $refImages, ['ok' => true, 'path' => $relative, 'error' => null, 'model' => $imageModel], $__imgT0, $__endpoint, $provider);
}

/**
 * Record actual API usage (feature 25). Best effort.
 */
function ai_log_usage(?int $userId, string $action, string $model, array $usage, ?int $referenceId = null): void
{
    try {
        db_run(
            'INSERT INTO ai_usage_log (user_id, action, model, tokens_in, tokens_out, reference_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $action, $model, $usage['in'] ?? 0, $usage['out'] ?? 0, $referenceId, date('Y-m-d H:i:s')]
        );
    } catch (\Throwable $e) {
        // best effort
    }
}

/**
 * Mock AI response (when no API key available, useful for development & demo)
 */
function mock_ai_response(string $prompt): string
{
    // Try to extract content type from the prompt for variety
    $type = 'تعريفي';
    if (preg_match('/نوع المحتوى:\s*(\S+)/u', $prompt, $m)) {
        $type = trim($m[1]);
    }

    $hooks = [
        'تعريفي' => '✨ خلينا نعرّفك على حاجة هتغير طريقة شغلك!',
        'تسويقي' => '🚀 العرض اللي مستنينه وصل أخيرًا!',
        'تعليمي' => '💡 ٣ نصايح ذهبية هتفرق معاك جدًا',
        'تفاعلي' => '🤔 سؤال بسيط بس إجابته هتفاجئك!',
        'عرض خاص' => '🎉 خصم خاص لفترة محدودة!',
        'ترند' => '🔥 الترند ده وصل لكل حد، أنت لسه مجربتوش؟'
    ];
    $hook = $hooks[$type] ?? '✨ محتوى جديد لإلهامك اليوم';

    $body = "إحنا بنحب نشتغل بشكل مختلف، وده اللي بيخلي نتايجنا مميزة. من خلال التركيز على التفاصيل اللي بتفرق فعلًا، بنوصل لنتايج بتتكلم عن نفسها.\n\n"
        . "كل عميل عندنا ليه حكاية، وكل حكاية ليها قصة بنروّيها بأسلوبنا الخاص.\n\n"
        . "جربنا واتعرف على الفرق بنفسك ✨";

    $cta = 'اتواصل معانا دلوقتي وخد استشارتك المجانية! 📲';
    $hashtags = '#تسويق_رقمي #محتوى_احترافي #سوشيال_ميديا #تصميم_جرافيك #براند_قوي #SpreadAI';

    return "[CONTENT]\n$hook\n\n$body\n[/CONTENT]\n\n[HASHTAGS]\n$hashtags\n[/HASHTAGS]\n\n[CTA]\n$cta\n[/CTA]";
}
