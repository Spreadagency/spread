<?php
declare(strict_types=1);

/**
 * Meta Conversions API. Each server event reuses the browser event's
 * event_id so Events Manager deduplicates them (spec §7).
 * Called through defer() so it never delays the visitor.
 */
final class PixelService
{
    /** Map our event names to the per-event toggle in settings. */
    private const TOGGLES = [
        'Lead' => 'pixel_event_lead',
        'ViewContent' => 'pixel_event_viewcontent',
        'Contact' => 'pixel_event_contact',
        'ShareResult' => 'pixel_event_share',
    ];

    public static function enabled(string $event): bool
    {
        if (Settings::get('meta_pixel_id', '') === '' || Settings::get('meta_capi_token', '') === '') {
            return false;
        }
        $toggle = self::TOGGLES[$event] ?? null;
        return $toggle === null || Settings::bool($toggle);
    }

    /**
     * @param array|null $lead   row from `leads` (for user_data)
     * @param array      $ctx    ip, ua, url, fbp, fbc (request context captured before the response was sent)
     */
    public static function send(string $event, ?array $lead, string $eventId, array $ctx, array $customData = []): array
    {
        if (!self::enabled($event)) {
            return ['ok' => false, 'skipped' => true];
        }
        $pixel = preg_replace('/\D/', '', (string) Settings::get('meta_pixel_id'));
        $version = preg_match('/^v\d+\.\d+$/', (string) Settings::get('meta_graph_version')) ? Settings::get('meta_graph_version') : 'v21.0';

        $user = array_filter([
            'client_ip_address' => $ctx['ip'] ?? null,
            'client_user_agent' => $ctx['ua'] ?? null,
            'fbp' => $ctx['fbp'] ?? ($lead['fbp'] ?? null),
            'fbc' => $ctx['fbc'] ?? ($lead['fbc'] ?? null) ?: self::fbcFromClick($lead['fbclid'] ?? null),
        ]);
        if ($lead) {
            $user['ph'] = [hash('sha256', phone_international((string) $lead['phone']))];
            $fn = mb_strtolower(trim(first_name((string) $lead['name'])));
            if ($fn !== '') {
                $user['fn'] = [hash('sha256', $fn)];
            }
            $user['external_id'] = [hash('sha256', (string) $lead['id'])];
            $user['country'] = [hash('sha256', 'eg')];
        }

        $payload = [
            'data' => [[
                'event_name' => $event,
                'event_time' => time(),
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => $ctx['url'] ?? url(),
                'user_data' => $user,
                'custom_data' => $customData ?: new stdClass(),
            ]],
        ];
        $test = trim((string) Settings::get('meta_test_event_code', ''));
        if ($test !== '') {
            $payload['test_event_code'] = $test;
        }

        $endpoint = sprintf('https://graph.facebook.com/%s/%s/events?access_token=%s', $version, $pixel, rawurlencode((string) Settings::get('meta_capi_token')));
        $r = http_post_json($endpoint, $payload, [], 8);
        $ok = $r['status'] === 200;
        if (!$ok) {
            log_error('CAPI ' . $event . ' failed', ['status' => $r['status'], 'error' => $r['error'], 'body' => mb_substr($r['body'], 0, 300)]);
        }
        return ['ok' => $ok, 'status' => $r['status'], 'body' => mb_substr($r['body'], 0, 500), 'ms' => $r['ms']];
    }

    /** fb.1.{ms}.{fbclid} when the _fbc cookie is missing. */
    private static function fbcFromClick(?string $fbclid): ?string
    {
        return $fbclid ? 'fb.1.' . (int) (microtime(true) * 1000) . '.' . $fbclid : null;
    }

    /** Request context to capture before the response is flushed. */
    public static function context(?string $sourceUrl = null): array
    {
        $fbp = $_COOKIE['_fbp'] ?? null;
        $fbc = $_COOKIE['_fbc'] ?? null;
        return [
            'ip' => client_ip(),
            'ua' => user_agent(),
            'url' => $sourceUrl && str_starts_with($sourceUrl, base_url()) ? $sourceUrl : ($_SERVER['HTTP_REFERER'] ?? url()),
            'fbp' => is_string($fbp) && preg_match('/^fb\.\d\.\d+\.\d+$/', $fbp) ? $fbp : null,
            'fbc' => is_string($fbc) && str_starts_with($fbc, 'fb.') ? mb_substr($fbc, 0, 255) : null,
        ];
    }
}
