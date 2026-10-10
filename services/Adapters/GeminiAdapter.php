<?php
namespace Spread\Adapters;

use Spread\Contracts\AIProviderContract;

class GeminiAdapter extends BaseAdapter implements AIProviderContract
{
    public function name(): string
    {
        return 'gemini';
    }

    public function chat(string $model, array $messages, array $options = []): array
    {
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemInstruction = is_string($m['content']) ? $m['content'] : '';
                continue;
            }

            $parts = [];
            if (is_string($m['content'])) {
                $parts[] = ['text' => $m['content']];
            } else {
                // Multi-part (vision): نحول image_url لـ inline_data base64
                foreach ($m['content'] as $part) {
                    if (($part['type'] ?? '') === 'text') {
                        $parts[] = ['text' => $part['text']];
                    } elseif (($part['type'] ?? '') === 'image_url') {
                        $inline = $this->urlToInlineData($part['image_url']['url'] ?? '');
                        if ($inline) {
                            $parts[] = $inline;
                        }
                    }
                }
            }
            if ($parts) {
                $contents[] = [
                    'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => $parts,
                ];
            }
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens']  ?? 4000,
                'temperature'     => $options['temperature'] ?? 0.8,
            ],
        ];

        if ($systemInstruction) {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }
        if (!empty($options['json_mode'])) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $url = "{$this->baseUrl}/models/{$model}:generateContent?key=" . urlencode($this->apiKey);
        $res = $this->http('POST', $url, ['Content-Type: application/json'], $payload);

        if ($res['error'] || $res['status'] !== 200) {
            return $this->fail($res['error'] ?: "HTTP {$res['status']}: " . substr((string) $res['body'], 0, 300), $res['duration_ms']);
        }

        $data = $this->parseJson($res['body']);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            return $this->fail('No text in response', $res['duration_ms']);
        }

        return [
            'ok'          => true,
            'content'     => $text,
            'raw'         => $data,
            'tokens_in'   => $data['usageMetadata']['promptTokenCount']     ?? 0,
            'tokens_out'  => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            'cost_usd'    => 0,
            'duration_ms' => $res['duration_ms'],
            'error'       => null,
        ];
    }

    public function image(string $model, string $prompt, array $options = []): array
    {
        return ['ok' => false, 'images' => [], 'raw' => [], 'cost_usd' => 0,
                'error' => 'توليد الصور من Gemini يتطلب Imagen API — استخدم OpenAI أو OpenRouter لتوليد التصميمات'];
    }

    public function ping(): bool
    {
        $res = $this->http('GET', "{$this->baseUrl}/models?key=" . urlencode($this->apiKey), []);
        return $res['status'] === 200;
    }

    /**
     * تحميل صورة من URL وتحويلها inline_data (Gemini لا يقبل URLs مباشرة)
     */
    private function urlToInlineData(string $url): ?array
    {
        if (!$url) {
            return null;
        }
        // data URI جاهزة
        if (preg_match('#^data:(image/\w+);base64,(.+)$#s', $url, $m)) {
            return ['inline_data' => ['mime_type' => $m[1], 'data' => $m[2]]];
        }
        $res = $this->http('GET', $url, [], null, 15);
        if ($res['status'] !== 200 || !$res['body']) {
            return null;
        }
        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_buffer($f, $res['body']);
            finfo_close($f);
            if (strpos((string) $detected, 'image/') === 0) {
                $mime = $detected;
            }
        }
        return ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($res['body'])]];
    }
}
