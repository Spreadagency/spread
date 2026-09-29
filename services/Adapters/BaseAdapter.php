<?php
namespace Spread\Adapters;

/**
 * BaseAdapter — cURL موحد لكل الموفرين
 * يعتمد على ثوابت v2: AI_CONNECT_TIMEOUT / AI_TIMEOUT
 */
abstract class BaseAdapter
{
    protected string $apiKey;
    protected string $baseUrl;
    protected int $timeout;
    protected int $connectTimeout;

    public function __construct(string $apiKey, string $baseUrl)
    {
        $this->apiKey  = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout        = defined('AI_TIMEOUT') ? AI_TIMEOUT : 25;
        $this->connectTimeout = defined('AI_CONNECT_TIMEOUT') ? AI_CONNECT_TIMEOUT : 5;
    }

    /**
     * HTTP request — يرجع [status, body, duration_ms, error]
     */
    protected function http(string $method, string $url, array $headers = [], $body = null, ?int $timeoutOverride = null): array
    {
        $ch = curl_init();
        $start = microtime(true);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutOverride ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        $duration = (int) ((microtime(true) - $start) * 1000);
        curl_close($ch);

        return [
            'status'      => $status,
            'body'        => $response,
            'duration_ms' => $duration,
            'error'       => $error ?: null,
        ];
    }

    protected function parseJson($body): ?array
    {
        if (!$body) {
            return null;
        }
        $decoded = json_decode($body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    protected function fail(string $msg, int $duration): array
    {
        return [
            'ok' => false, 'content' => null, 'raw' => [],
            'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd' => 0,
            'duration_ms' => $duration, 'error' => $msg,
        ];
    }
}
