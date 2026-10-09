<?php
declare(strict_types=1);

/**
 * Key/value settings stored in the `settings` table.
 *
 * Secrets (API keys, tokens) are stored as "enc:<base64>" using
 * AES-256-GCM keyed from APP_KEY. A plain value is also accepted so a key
 * can be pasted from phpMyAdmin before the admin panel exists; saving it
 * through Settings::set() (or install/set-setting.php) encrypts it.
 */
final class Settings
{
    public const SECRET_KEYS = ['gemini_api_key', 'openai_api_key', 'openrouter_api_key', 'meta_capi_token', 'turnstile_secret', 'webhook_secret'];

    private static ?array $cache = null;

    /** Fallbacks used when a key is missing from the table. */
    private const DEFAULTS = [
        'timezone' => 'Africa/Cairo',
        'locale' => 'ar_EG',
        'hero_badge' => 'محاكاة مجانية بالذكاء الاصطناعي',
        'hero_title' => 'شوف نفسك بعد التخسيس',
        'hero_subtitle' => 'ارفع صورتك واحصل على محاكاة تقريبية بالذكاء الاصطناعي في ثواني — مجانًا',
        'hero_button' => 'ابدأ دلوقتي',
        'logo' => 'assets/img/logo.png',
        'doctor_photo' => 'assets/img/doctor.webp',
        'whatsapp_message' => 'مساء الخير، أنا {name}، جربت محاكاة التخسيس وعايز أستفسر عن عملية التكميم.',
        'color_primary' => '#1F73B7',
        'color_secondary' => '#0E2B45',
        'color_accent' => '#C9A24B',
        'meta_graph_version' => 'v21.0',
        'pixel_event_lead' => '1',
        'pixel_event_viewcontent' => '1',
        'pixel_event_contact' => '1',
        'pixel_event_share' => '1',
        'ai_provider' => 'gemini',
        'openai_model' => 'gpt-image-1',
        'openai_safety_model' => 'gpt-4.1-mini',
        'openai_quality' => 'medium',
        'openrouter_model' => 'google/gemini-2.5-flash-image',
        'openrouter_safety_model' => 'google/gemini-2.5-flash',
        'gemini_model' => 'gemini-2.5-flash-image',
        'gemini_safety_model' => 'gemini-2.5-flash',
        'gemini_timeout' => '90',
        'gemini_auto_retry' => '1',
        'limit_daily_global' => '300',
        'limit_per_ip' => '3',
        'limit_per_cookie' => '2',
        'limit_per_phone' => '1',
        'limit_leads_per_ip_hour' => '10',
        'webhook_on_lead' => '1',
        'webhook_on_whatsapp' => '1',
        'retention_originals_days' => '30',
        'retention_results_days' => '90',
        'share_show_before' => '0',
        'show_logo_on_result' => '1',
        'robots_txt' => "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /r/\nDisallow: /api/",
    ];

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (q('SELECT `key`, `value` FROM settings')->fetchAll() as $row) {
                    self::$cache[$row['key']] = (string) $row['value'];
                }
            } catch (PDOException $e) {
                log_error('Settings load failed: ' . $e->getMessage());
            }
        }
        return self::$cache + self::DEFAULTS;
    }

    public static function get(string $key, ?string $default = ''): ?string
    {
        $all = self::all();
        $v = $all[$key] ?? null;
        if ($v === null || $v === '') {
            return ($default !== null && $default !== '') ? $default : (self::DEFAULTS[$key] ?? $default);
        }
        if (str_starts_with($v, 'enc:')) {
            return self::decrypt($v) ?? $default;
        }
        return $v;
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower((string) self::get($key, '0')), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function set(string $key, ?string $value): void
    {
        if ($value !== null && $value !== '' && in_array($key, self::SECRET_KEYS, true)) {
            $value = self::encrypt($value);
        }
        q('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
        self::$cache = null;
    }

    /** Last 4 characters, for masked display in the admin panel. */
    public static function masked(string $key): string
    {
        $v = (string) self::get($key, '');
        return $v === '' ? '' : '••••' . mb_substr($v, -4);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): ?string
    {
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            log_error('Settings: could not decrypt a secret (APP_KEY changed?)');
            return null;
        }
        return $plain;
    }

    private static function key(): string
    {
        return hash('sha256', 'settings|' . config('app_key'), true);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
