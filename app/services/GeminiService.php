<?php
declare(strict_types=1);

final class GeminiException extends AiException
{
}

/**
 * Google Gemini REST client (generateContent).
 * The API key is read server-side from settings and never reaches the browser.
 */
final class GeminiService implements AiProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Base URL (overridable in config for a proxy or a local mock). */
    private static function endpoint(): string
    {
        return rtrim((string) config('gemini_base_url', self::ENDPOINT), '/') . '/';
    }

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $safetyModel,
        private int $timeout = 90,
    ) {
    }

    public static function fromSettings(): self
    {
        $key = (string) Settings::get('gemini_api_key', '');
        if ($key === '') {
            throw new GeminiException('Gemini API key is not set');
        }
        return new self(
            $key,
            (string) Settings::get('gemini_model', 'gemini-2.5-flash-image'),
            (string) Settings::get('gemini_safety_model', 'gemini-2.5-flash'),
            max(20, min(170, Settings::int('gemini_timeout', 90))),
        );
    }

    /**
     * Reject photos with no clear person, several people, a minor, or nudity.
     * Fails closed: if the check itself errors, the generation is not attempted.
     */
    public function safetyCheck(string $jpeg): void
    {
        $prompt = (string) Settings::get('gemini_safety_prompt', '');
        if ($prompt === '') {
            return;
        }
        $res = $this->call($this->safetyModel, [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0],
        ], 30);

        if (!empty($res['promptFeedback']['blockReason'])) {
            throw new GeminiException('Safety: prompt blocked (' . $res['promptFeedback']['blockReason'] . ')', 'rejected', 'blocked');
        }
        $text = $this->firstText($res);
        $json = json_decode(trim((string) preg_replace('/^```(json)?|```$/m', '', $text)), true);
        if (!is_array($json) || !array_key_exists('ok', $json)) {
            throw new GeminiException('Safety: unreadable answer: ' . mb_substr($text, 0, 200));
        }
        if ($json['ok'] !== true) {
            $reason = preg_replace('/[^a-z_]/', '', strtolower((string) ($json['reason'] ?? 'unclear')));
            throw new GeminiException('Safety rejected: ' . $reason, 'rejected', $reason);
        }
    }

    /** Edit the photo with the generation prompt. Returns raw image bytes. */
    public function edit(string $jpeg, string $prompt): string
    {
        $res = $this->call($this->model, [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                ],
            ]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ], $this->timeout);

        if (!empty($res['promptFeedback']['blockReason'])) {
            throw new GeminiException('Edit blocked: ' . $res['promptFeedback']['blockReason'], 'rejected', 'blocked');
        }
        foreach ($res['candidates'] ?? [] as $cand) {
            foreach ($cand['content']['parts'] ?? [] as $part) {
                $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                if ($inline && !empty($inline['data'])) {
                    $bytes = base64_decode($inline['data'], true);
                    if ($bytes !== false && strlen($bytes) > 1000) {
                        return $bytes;
                    }
                }
            }
            $finish = (string) ($cand['finishReason'] ?? '');
            if (in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_SAFETY', 'BLOCKLIST', 'SPII'], true)) {
                throw new GeminiException('Edit refused: ' . $finish, 'rejected', strtolower($finish));
            }
        }
        throw new GeminiException('No image in response: ' . mb_substr($this->firstText($res), 0, 200));
    }

    /** Lightweight check for the admin "Test connection" button. */
    public function test(): array
    {
        $r = http_request('GET', self::endpoint() . rawurlencode($this->model), null, ['x-goog-api-key: ' . $this->apiKey], 15);
        $ok = $r['status'] === 200;
        return ['ok' => $ok, 'status' => $r['status'], 'ms' => $r['ms'], 'message' => $ok ? 'OK' : $this->errorMessage($r)];
    }

    /* ---------------- internals ---------------- */

    private function call(string $model, array $payload, int $timeout): array
    {
        $r = http_post_json(self::endpoint() . rawurlencode($model) . ':generateContent', $payload, ['x-goog-api-key: ' . $this->apiKey], $timeout);
        if ($r['error']) {
            throw new GeminiException('HTTP error: ' . $r['error']);
        }
        if ($r['status'] !== 200) {
            throw new GeminiException('Gemini ' . $r['status'] . ': ' . $this->errorMessage($r));
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data)) {
            throw new GeminiException('Invalid JSON from Gemini');
        }
        return $data;
    }

    private function firstText(array $res): string
    {
        foreach ($res['candidates'] ?? [] as $cand) {
            foreach ($cand['content']['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    return (string) $part['text'];
                }
            }
        }
        return '';
    }

    private function errorMessage(array $r): string
    {
        $j = json_decode($r['body'], true);
        return mb_substr((string) ($j['error']['message'] ?? $r['error'] ?? $r['body']), 0, 300);
    }
}
