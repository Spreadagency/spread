<?php
declare(strict_types=1);

/**
 * POST test.php {what: gemini | pixel | webhook_n8n | webhook_crm} → JSON
 * Owner only. The last result is stored in settings (test_<what>) for the status badges.
 */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

admin_post('owner');
@set_time_limit(60);
$what = (string) ($_POST['what'] ?? '');

$result = match ($what) {
    'gemini' => (static function (): array {
        try {
            $r = GeminiService::fromSettings()->test();
            return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'الاتصال شغال · ' . Settings::get('gemini_model') . ' · ' . $r['ms'] . 'ms' : 'HTTP ' . $r['status'] . ' — ' . $r['message']];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    })(),
    'pixel' => (static function (): array {
        if (Settings::get('meta_pixel_id', '') === '' || Settings::get('meta_capi_token', '') === '') {
            return ['ok' => false, 'message' => 'ضيف Pixel ID و Access token الأول.'];
        }
        $fake = ['id' => 0, 'name' => 'Test Lead', 'phone' => '01000000000', 'fbp' => null, 'fbc' => null, 'fbclid' => null];
        $r = PixelService::send('Lead', $fake, 'test-' . new_event_id(), PixelService::context(url()), ['content_name' => 'admin test']);
        if (!empty($r['skipped'])) {
            return ['ok' => false, 'message' => 'حدث Lead مقفول من الإعدادات.'];
        }
        $body = json_decode((string) $r['body'], true);
        return ['ok' => $r['ok'], 'message' => $r['ok']
            ? 'اتبعت · events_received: ' . ($body['events_received'] ?? '?') . (Settings::get('meta_test_event_code', '') ? ' · شوفه في Test events' : ' · مفيش Test code، الحدث هيتحسب حقيقي')
            : 'HTTP ' . $r['status'] . ' — ' . ($body['error']['message'] ?? mb_substr((string) $r['body'], 0, 160))];
    })(),
    'webhook_n8n', 'webhook_crm' => (static function () use ($what): array {
        $name = substr($what, 8);
        $url = WebhookService::urls()[$name] ?? '';
        if ($url === '') {
            return ['ok' => false, 'message' => 'اللينك فاضي أو مش https.'];
        }
        $sample = ['id' => 0, 'name' => 'تجربة من لوحة التحكم', 'phone' => '01000000000', 'utm_source' => 'test', 'utm_medium' => null, 'utm_campaign' => 'admin_test', 'utm_content' => null, 'utm_term' => null, 'status' => 'new', 'created_at' => utc_now()];
        $r = WebhookService::send($name, $url, WebhookService::payload('test', $sample, ['status' => 'none']) + ['test' => true]);
        return ['ok' => $r['ok'], 'message' => 'HTTP ' . $r['status'] . ' · ' . $r['ms'] . 'ms' . ($r['body'] ? ' · ' . mb_substr($r['body'], 0, 120) : '')];
    })(),
    default => null,
};

if ($result === null) {
    json_response(['error' => 'Unknown test'], 422);
}
Settings::set('test_' . $what, json_encode(['ok' => $result['ok'], 'at' => utc_now(), 'message' => $result['message']], JSON_UNESCAPED_UNICODE));
admin_log('test_' . $what, ($result['ok'] ? 'ok' : 'fail') . ': ' . $result['message']);
json_response($result);
