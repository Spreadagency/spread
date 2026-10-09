<?php
declare(strict_types=1);

/** Cloudflare Turnstile server-side check. Skipped when no secret is set. */
final class Turnstile
{
    public static function enabled(): bool
    {
        return Settings::get('turnstile_site_key', '') !== '' && Settings::get('turnstile_secret', '') !== '';
    }

    public static function verify(?string $token): bool
    {
        if (!self::enabled()) {
            return true;
        }
        if (!$token) {
            return false;
        }
        $r = http_request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', http_build_query([
            'secret' => Settings::get('turnstile_secret'),
            'response' => $token,
            'remoteip' => client_ip(),
        ]), ['Content-Type: application/x-www-form-urlencoded'], 8);
        if ($r['error'] || $r['status'] !== 200) {
            // Cloudflare unreachable: let the visitor through, rate limits still apply.
            log_error('Turnstile unreachable', ['status' => $r['status'], 'error' => $r['error']]);
            return true;
        }
        $j = json_decode($r['body'], true);
        return !empty($j['success']);
    }
}
