<?php
namespace Spread\Adapters;

use Spread\Contracts\AIProviderContract;

class OpenAIAdapter extends BaseAdapter implements AIProviderContract
{
    public function name(): string
    {
        return 'openai';
    }

    public function chat(string $model, array $messages, array $options = []): array
    {
        $payload = [
            'model'       => $model,
            'messages'    => $messages, // multi-part vision content passes through as-is
            // max_tokens اتلغى في موديلات OpenAI الجديدة — max_completion_tokens شغال مع كلهم
            'max_completion_tokens' => $options['max_tokens'] ?? 4000,
            'temperature' => $options['temperature'] ?? 0.8,
        ];
        if (preg_match('/^(o\d|gpt-5)/', strtolower(preg_replace('#^.*/#', '', $model)))) {
            unset($payload['temperature']); // موديلات الـ reasoning بتقبل القيمة الافتراضية بس
        }

        if (!empty($options['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $res = $this->http('POST', "{$this->baseUrl}/chat/completions", [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ], $payload);

        if ($res['error'] || $res['status'] !== 200) {
            return $this->fail($res['error'] ?: "HTTP {$res['status']}: " . substr((string) $res['body'], 0, 300), $res['duration_ms']);
        }

        $data = $this->parseJson($res['body']);
        if (!$data || !isset($data['choices'][0]['message']['content'])) {
            return $this->fail('Invalid response shape', $res['duration_ms']);
        }

        return [
            'ok'          => true,
            'content'     => $data['choices'][0]['message']['content'],
            'raw'         => $data,
            'tokens_in'   => $data['usage']['prompt_tokens'] ?? 0,
            'tokens_out'  => $data['usage']['completion_tokens'] ?? 0,
            'cost_usd'    => 0,
            'duration_ms' => $res['duration_ms'],
            'error'       => null,
        ];
    }

    public function image(string $model, string $prompt, array $options = []): array
    {
        $refs = array_values(array_filter((array) ($options['reference_images'] ?? [])));

        // ⚠️ /images/generations نص فقط — بيتجاهل أي صور مرجعية.
        // عشان الموديل يشوف اللوجو لازم /images/edits بصيغة multipart.
        if ($refs) {
            return $this->imageEdit($model, $prompt, $refs, $options);
        }

        $payload = [
            'model'  => $model, // gpt-image-1 / dall-e-3
            'prompt' => $prompt,
            'n'      => $options['n']    ?? 1,
            'size'   => $options['size'] ?? '1024x1024',
        ];

        // توليد الصور بياخد وقت أطول
        $res = $this->http('POST', "{$this->baseUrl}/images/generations", [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ], $payload, 75);

        return $this->parseImageResponse($res);
    }

    /**
     * توليد صورة مع صور مرجعية (اللوجو · مرجع الستايل)
     * multipart بإيدينا لأن cURL مبيقبلش مفتاح image[] مكرر.
     */
    private function imageEdit(string $model, string $prompt, array $refs, array $options): array
    {
        $boundary = '----SpreadAI' . bin2hex(random_bytes(8));
        $body = '';

        foreach ([
            'model'  => $model,
            'prompt' => $prompt,
            'size'   => $options['size'] ?? '1024x1024',
            'n'      => (string) ($options['n'] ?? 1),
        ] as $k => $v) {
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$k}\"\r\n\r\n"
                . $v . "\r\n";
        }

        $n = 0;
        foreach ($refs as $ref) {
            if ($n >= 4) break;                       // حد OpenAI
            $bin = $this->decodeImageRef((string) (is_array($ref) ? ($ref['uri'] ?? '') : $ref));
            if (!$bin) continue;
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"image[]\"; filename=\"ref{$n}.png\"\r\n"
                . "Content-Type: {$bin['mime']}\r\n\r\n"
                . $bin['data'] . "\r\n";
            $n++;
        }
        $body .= "--{$boundary}--\r\n";

        // مفيش صورة صالحة → رجوع للتوليد النصي بدل الفشل
        if ($n === 0) {
            return $this->image($model, $prompt, array_merge($options, ['reference_images' => []]));
        }

        $res = $this->http('POST', "{$this->baseUrl}/images/edits", [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ], $body, 110);

        return $this->parseImageResponse($res);
    }

    /** data URI أو مسار ملف → بايتس */
    private function decodeImageRef(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') return null;

        if (preg_match('~^data:(image/[a-z+]+);base64,(.+)$~is', $ref, $m)) {
            $bin = base64_decode($m[2], true);
            return $bin === false ? null : ['mime' => $m[1], 'data' => $bin];
        }
        if (is_file($ref) && is_readable($ref)) {
            $bin = (string) file_get_contents($ref);
            $info = @getimagesizefromstring($bin);
            return ['mime' => $info['mime'] ?? 'image/png', 'data' => $bin];
        }
        return null;
    }

    private function parseImageResponse(array $res): array
    {
        if ($res['error'] || $res['status'] !== 200) {
            return ['ok' => false, 'images' => [], 'raw' => [], 'cost_usd' => 0,
                    'error' => $res['error'] ?: "HTTP {$res['status']}: " . substr((string) $res['body'], 0, 300)];
        }
        $data = $this->parseJson($res['body']);
        $images = array_map(fn ($i) => $i['url'] ?? $i['b64_json'] ?? null, $data['data'] ?? []);
        return ['ok' => true, 'images' => array_values(array_filter($images)), 'raw' => $data, 'cost_usd' => 0, 'error' => null];
    }

    public function ping(): bool
    {
        $res = $this->http('GET', "{$this->baseUrl}/models", [
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        return $res['status'] === 200;
    }
}
