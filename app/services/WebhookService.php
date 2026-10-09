<?php
declare(strict_types=1);

/**
 * Sends lead events to n8n and Spread CRM.
 * Optional HMAC-SHA256 signature of the raw body in X-Spread-Signature.
 * One retry on failure; every failure is logged.
 */
final class WebhookService
{
    /** @param string $trigger 'lead' | 'whatsapp_click' | 'generation_done' */
    public static function dispatch(string $trigger, array $lead, ?array $gen = null): void
    {
        $toggle = ['lead' => 'webhook_on_lead', 'whatsapp_click' => 'webhook_on_whatsapp'][$trigger] ?? null;
        if ($toggle && !Settings::bool($toggle)) {
            return;
        }
        $payload = self::payload($trigger, $lead, $gen);
        foreach (self::urls() as $name => $url) {
            self::send($name, $url, $payload);
        }
    }

    public static function urls(): array
    {
        return array_filter([
            'n8n' => trim((string) Settings::get('webhook_n8n_url', '')),
            'crm' => trim((string) Settings::get('webhook_crm_url', '')),
        ], static fn ($u) => $u !== '' && filter_var($u, FILTER_VALIDATE_URL) && str_starts_with($u, 'https://'));
    }

    public static function payload(string $trigger, array $lead, ?array $gen = null): array
    {
        if ($gen === null) {
            $gen = q_row("SELECT * FROM generations WHERE lead_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$lead['id']]);
        }
        return [
            'event' => $trigger,
            'lead_id' => (int) $lead['id'],
            'name' => $lead['name'],
            'phone' => $lead['phone'],
            'phone_international' => phone_international((string) $lead['phone']),
            'utm_source' => $lead['utm_source'],
            'utm_medium' => $lead['utm_medium'],
            'utm_campaign' => $lead['utm_campaign'],
            'utm_content' => $lead['utm_content'],
            'utm_term' => $lead['utm_term'],
            'status' => $lead['status'],
            'result_url' => ($gen && $gen['status'] === 'done' && $gen['share_token']) ? share_url($gen['share_token']) : null,
            'created_at' => (new DateTimeImmutable($lead['created_at'] . ' UTC'))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM),
            'sent_at' => date(DATE_ATOM),
        ];
    }

    /** @return array{ok:bool,status:int,ms:int,body:string} */
    public static function send(string $name, string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type: application/json'];
        $secret = (string) Settings::get('webhook_secret', '');
        if ($secret !== '') {
            $headers[] = 'X-Spread-Signature: sha256=' . hash_hmac('sha256', (string) $body, $secret);
        }
        $r = ['status' => 0, 'error' => null, 'body' => '', 'ms' => 0];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $r = http_request('POST', $url, $body, $headers, 8);
            if ($r['status'] >= 200 && $r['status'] < 300) {
                return ['ok' => true, 'status' => $r['status'], 'ms' => $r['ms'], 'body' => mb_substr($r['body'], 0, 300)];
            }
            if ($attempt === 1) {
                usleep(800000);
            }
        }
        log_error("Webhook $name failed", ['status' => $r['status'], 'error' => $r['error'], 'body' => mb_substr($r['body'], 0, 300)]);
        return ['ok' => false, 'status' => $r['status'], 'ms' => $r['ms'], 'body' => mb_substr($r['error'] ?: $r['body'], 0, 300)];
    }
}
