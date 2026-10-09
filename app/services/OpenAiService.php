<?php
declare(strict_types=1);

/**
 * OpenAI and OpenRouter (OpenAI-compatible) clients.
 *
 * - OpenAI:     image edit through /v1/images/edits (gpt-image-1…), safety check through /v1/chat/completions.
 * - OpenRouter: both through /api/v1/chat/completions; the image model answers with
 *               message.images[] when asked for modalities ["image", "text"].
 * Keys are read server-side from settings and never reach the browser.
 */
final class OpenAiService implements AiProvider
{
    private const BASE = [
        'openai' => 'https://api.openai.com/v1/',
        'openrouter' => 'https://openrouter.ai/api/v1/',
    ];

    public function __construct(
        private string $provider,
        private string $apiKey,
        private string $model,
        private string $safetyModel,
        private int $timeout = 90,
        private string $quality = 'medium',
    ) {
    }

    public static function fromSettings(string $provider): self
    {
        if (!isset(self::BASE[$provider])) {
            throw new AiException('Unknown AI provider: ' . $provider);
        }
        $key = (string) Settings::get($provider . '_api_key', '');
        if ($key === '') {
            throw new AiException(AiService::label($provider) . ' API key is not set');
        }
        return new self(
            $provider,
            $key,
            (string) Settings::get($provider . '_model', ''),
            (string) Settings::get($provider . '_safety_model', ''),
            max(20, min(170, Settings::int('gemini_timeout', 90))),
            (string) Settings::get('openai_quality', 'medium'),
        );
    }

    /** Base URL (overridable in config for a proxy or a local mock). */
    private function base(): string
    {
        return rtrim((string) config($this->provider . '_base_url', self::BASE[$this->provider]), '/') . '/';
    }

    private function headers(bool $json = true): array
    {
        $h = ['Authorization: Bearer ' . $this->apiKey];
        if ($json) {
            $h[] = 'Content-Type: application/json';
        }
        if ($this->provider === 'openrouter') {
            $h[] = 'HTTP-Referer: ' . base_url();
            $h[] = 'X-Title: ' . preg_replace('/[^\x20-\x7E]/', '', 'Slim Simulator');
        }
        return $h;
    }

    public function safetyCheck(string $jpeg): void
    {
        $prompt = (string) Settings::get('gemini_safety_prompt', '');
        if ($prompt === '' || $this->safetyModel === '') {
            return;
        }
        $res = $this->chat([
            'model' => $this->safetyModel,
            'messages' => [
                ['role' => 'system', 'content' => 'Answer with a single JSON object only.'],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
                ]],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
            'max_tokens' => 200,
        ], 40);

        $msg = $res['choices'][0]['message'] ?? [];
        if (!empty($msg['refusal'])) {
            throw new AiException('Safety: model refused (' . mb_substr((string) $msg['refusal'], 0, 120) . ')', 'rejected', 'blocked');
        }
        if (($res['choices'][0]['finish_reason'] ?? '') === 'content_filter') {
            throw new AiException('Safety: content filter', 'rejected', 'blocked');
        }
        $text = $this->messageText($msg);
        $json = preg_match('/\{.*\}/s', $text, $m) ? json_decode($m[0], true) : null;
        if (!is_array($json) || !array_key_exists('ok', $json)) {
            throw new AiException('Safety: unreadable answer: ' . mb_substr($text, 0, 200));
        }
        if ($json['ok'] !== true) {
            $reason = preg_replace('/[^a-z_]/', '', strtolower((string) ($json['reason'] ?? 'unclear')));
            throw new AiException('Safety rejected: ' . $reason, 'rejected', $reason);
        }
    }

    public function edit(string $jpeg, string $prompt): string
    {
        return $this->provider === 'openai' ? $this->openAiEdit($jpeg, $prompt) : $this->openRouterEdit($jpeg, $prompt);
    }

    public function test(): array
    {
        // OpenAI: the image model must exist for this key. OpenRouter: the key itself is checked.
        $url = $this->provider === 'openai' ? $this->base() . 'models/' . rawurlencode($this->model) : $this->base() . 'key';
        $r = http_request('GET', $url, null, $this->headers(false), 15);
        $ok = $r['status'] === 200;
        return ['ok' => $ok, 'status' => $r['status'], 'ms' => $r['ms'], 'message' => $ok ? 'OK' : $this->errorMessage($r)];
    }

    /* ---------------- OpenAI: /images/edits ---------------- */

    private function openAiEdit(string $jpeg, string $prompt): string
    {
        $fields = [
            'model' => $this->model,
            'prompt' => $prompt,
            'image' => new CURLStringFile($jpeg, 'photo.jpg', 'image/jpeg'),
            'n' => '1',
            'size' => 'auto',
        ];
        $extra = [];
        if (str_starts_with($this->model, 'gpt-image')) {
            $extra['quality'] = in_array($this->quality, ['low', 'medium', 'high', 'auto'], true) ? $this->quality : 'medium';
            $extra['output_format'] = 'jpeg';
            if (!str_contains($this->model, 'mini')) {
                $extra['input_fidelity'] = 'high'; // keeps the face closer to the original
            }
        }
        $r = http_request('POST', $this->base() . 'images/edits', $fields + $extra, $this->headers(false), $this->timeout);
        if ($r['status'] === 400 && $extra && preg_match('/input_fidelity|output_format|quality/i', $this->errorMessage($r))) {
            // Older or newer model that doesn't take one of the optional parameters: retry plain.
            $fields['image'] = new CURLStringFile($jpeg, 'photo.jpg', 'image/jpeg');
            $r = http_request('POST', $this->base() . 'images/edits', $fields, $this->headers(false), $this->timeout);
        }
        $data = $this->decode($r);
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if ($b64) {
            $bytes = base64_decode((string) $b64, true);
            if ($bytes !== false && strlen($bytes) > 1000) {
                return $bytes;
            }
        }
        if (!empty($data['data'][0]['url'])) {
            return $this->download((string) $data['data'][0]['url']);
        }
        throw new AiException('No image in OpenAI response');
    }

    /* ---------------- OpenRouter: chat with image output ---------------- */

    private function openRouterEdit(string $jpeg, string $prompt): string
    {
        $res = $this->chat([
            'model' => $this->model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]],
                ],
            ]],
            'modalities' => ['image', 'text'],
        ], $this->timeout);

        foreach ($res['choices'] ?? [] as $choice) {
            $msg = $choice['message'] ?? [];
            $candidates = [];
            foreach ($msg['images'] ?? [] as $img) {
                $candidates[] = (string) ($img['image_url']['url'] ?? $img['url'] ?? '');
            }
            foreach (is_array($msg['content'] ?? null) ? $msg['content'] : [] as $part) {
                if (($part['type'] ?? '') === 'image_url') {
                    $candidates[] = (string) ($part['image_url']['url'] ?? '');
                }
            }
            foreach ($candidates as $url) {
                if (preg_match('#^data:image/[a-z+.-]+;base64,(.+)$#is', $url, $m)) {
                    $bytes = base64_decode($m[1], true);
                    if ($bytes !== false && strlen($bytes) > 1000) {
                        return $bytes;
                    }
                } elseif (str_starts_with($url, 'https://')) {
                    return $this->download($url);
                }
            }
            if (($choice['finish_reason'] ?? '') === 'content_filter' || !empty($msg['refusal'])) {
                throw new AiException('Edit refused: content filter', 'rejected', 'blocked');
            }
        }
        $hint = $this->messageText($res['choices'][0]['message'] ?? []);
        throw new AiException('No image in response' . ($hint !== '' ? ': ' . mb_substr($hint, 0, 200) : ' — is "' . $this->model . '" an image-output model?'));
    }

    /* ---------------- internals ---------------- */

    private function chat(array $payload, int $timeout): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->decode(http_request('POST', $this->base() . 'chat/completions', $body, $this->headers(), $timeout));
    }

    private function decode(array $r): array
    {
        if ($r['error']) {
            throw new AiException('HTTP error: ' . $r['error']);
        }
        $data = json_decode($r['body'], true);
        if ($r['status'] !== 200) {
            $msg = $this->errorMessage($r);
            $code = strtolower((string) ($data['error']['code'] ?? ''));
            if ($code === 'moderation_blocked' || $code === 'content_policy_violation' || preg_match('/moderation|safety system|content policy|flagged/i', $msg)) {
                throw new AiException('Refused by ' . AiService::label($this->provider) . ': ' . $msg, 'rejected', 'blocked');
            }
            throw new AiException(AiService::label($this->provider) . ' ' . $r['status'] . ': ' . $msg);
        }
        if (!is_array($data)) {
            throw new AiException('Invalid JSON from ' . AiService::label($this->provider));
        }
        if (isset($data['error'])) { // OpenRouter can return 200 with an error object
            throw new AiException(AiService::label($this->provider) . ': ' . mb_substr((string) ($data['error']['message'] ?? 'error'), 0, 300));
        }
        return $data;
    }

    private function download(string $url): string
    {
        $r = http_request('GET', $url, null, [], 30);
        if ($r['status'] !== 200 || strlen($r['body']) < 1000) {
            throw new AiException('Could not download the generated image (' . $r['status'] . ')');
        }
        return $r['body'];
    }

    private function messageText(array $msg): string
    {
        $c = $msg['content'] ?? '';
        if (is_string($c)) {
            return $c;
        }
        foreach (is_array($c) ? $c : [] as $part) {
            if (isset($part['text'])) {
                return (string) $part['text'];
            }
        }
        return '';
    }

    private function errorMessage(array $r): string
    {
        $j = json_decode($r['body'], true);
        $m = $j['error']['message'] ?? $j['message'] ?? $r['error'] ?? $r['body'];
        return mb_substr(is_string($m) ? $m : json_encode($m), 0, 300);
    }
}
