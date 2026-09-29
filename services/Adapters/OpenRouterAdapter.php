<?php
namespace Spread\Adapters;

use Spread\Contracts\AIProviderContract;

class OpenRouterAdapter extends BaseAdapter implements AIProviderContract
{
    public function name(): string
    {
        return 'openrouter';
    }

    public function chat(string $model, array $messages, array $options = []): array
    {
        $payload = [
            'model'       => $model,
            'messages'    => $messages, // multi-part vision content passes through as-is
            'max_tokens'  => $options['max_tokens']  ?? 4000,
            'temperature' => $options['temperature'] ?? 0.8,
        ];

        if (!empty($options['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $res = $this->http('POST', "{$this->baseUrl}/chat/completions", [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : 'https://ai.spreadagency.net'),
            'X-Title: Spread AI',
        ], $payload);

        if ($res['error']) {
            return $this->fail('cURL: ' . $res['error'], $res['duration_ms']);
        }
        if ($res['status'] !== 200) {
            return $this->fail("HTTP {$res['status']}: " . substr((string) $res['body'], 0, 300), $res['duration_ms']);
        }

        $data = $this->parseJson($res['body']);
        if (!$data || !isset($data['choices'][0]['message']['content'])) {
            return $this->fail('Invalid response shape', $res['duration_ms']);
        }

        return [
            'ok'          => true,
            'content'     => $data['choices'][0]['message']['content'],
            'raw'         => $data,
            'tokens_in'   => $data['usage']['prompt_tokens']     ?? 0,
            'tokens_out'  => $data['usage']['completion_tokens'] ?? 0,
            'cost_usd'    => (float) ($data['usage']['cost'] ?? 0), // OpenRouter بيرجع التكلفة الفعلية
            'duration_ms' => $res['duration_ms'],
            'error'       => null,
        ];
    }

    public function image(string $model, string $prompt, array $options = []): array
    {
        // OpenRouter: توليد الصور عبر chat completions بـ modalities
        // reference_images: مراجع بصرية (ذوق العميل من الستوديو) — الموديلات اللي بتدعم image input بتستفيد منها
        $content = $prompt;
        if (!empty($options['reference_images']) && is_array($options['reference_images'])) {
            // الأدوار (لوجو / صورة عميل / مرجع ستايل) بتتوصف داخل البرومبت من الطرف المستدعي
            $parts = [['type' => 'text', 'text' => $prompt]];
            foreach (array_slice($options['reference_images'], 0, 4) as $u) {
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $u]];
            }
            $content = $parts;
        }

        $payload = [
            'model'      => $model,
            'messages'   => [['role' => 'user', 'content' => $content]],
            'modalities' => ['image', 'text'],
        ];

        $res = $this->http('POST', "{$this->baseUrl}/chat/completions", [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . (defined('APP_URL') ? APP_URL : 'https://ai.spreadagency.net'),
            'X-Title: Spread AI',
        ], $payload, 75);

        if ($res['error'] || $res['status'] !== 200) {
            return ['ok' => false, 'images' => [], 'raw' => [], 'cost_usd' => 0,
                    'error' => $res['error'] ?: "HTTP {$res['status']}: " . substr((string) $res['body'], 0, 300)];
        }

        $data = $this->parseJson($res['body']);
        $images = [];
        foreach (($data['choices'][0]['message']['images'] ?? []) as $img) {
            $u = $img['image_url']['url'] ?? null; // data:image/png;base64,... أو URL
            if ($u) {
                $images[] = $u;
            }
        }

        return ['ok' => !empty($images), 'images' => $images, 'raw' => $data,
                'cost_usd' => (float) ($data['usage']['cost'] ?? 0),
                'error' => empty($images) ? 'No image in response' : null];
    }

    public function ping(): bool
    {
        // /models عند OpenRouter عامة (بترجع 200 حتى من غير مفتاح) — فالاختبار لازم يبقى على بيانات المفتاح نفسه
        foreach (['/key', '/auth/key'] as $ep) {
            $res = $this->http('GET', "{$this->baseUrl}{$ep}", [
                'Authorization: Bearer ' . $this->apiKey,
            ]);
            if ($res['status'] !== 404) return $res['status'] === 200;
        }
        return false;
    }
}
