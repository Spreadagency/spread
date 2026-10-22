<?php
/**
 * Spread AI v2 — Crypto
 * تشفير الـ API keys في قاعدة البيانات بـ AES-256-GCM
 *
 * يتطلب إضافة السطر التالي في includes/config.php:
 *   define('ENCRYPTION_KEY', '<64 hex chars>');
 * توليد مفتاح:  php -r "echo bin2hex(random_bytes(32));"
 */

class Crypto
{
    /** المفتاح اللي كان منشور في config.sample.php قبل كده (sha256 بس — مش المفتاح نفسه) */
    public const PUBLIC_SAMPLE_KEY_SHA256 = 'd8c4ffbdb41e0a7590f8484eea62fc8685eccea2a090523a70f9ee5c71f95076';

    private static function key(): string
    {
        if (!defined('ENCRYPTION_KEY') || strlen(ENCRYPTION_KEY) < 64 || !ctype_xdigit(substr(ENCRYPTION_KEY, 0, 64))) {
            throw new RuntimeException('ENCRYPTION_KEY غير معرّف في config.php — ولّده بـ bin2hex(random_bytes(32))');
        }
        return hex2bin(substr(ENCRYPTION_KEY, 0, 64));
    }

    /** المفتاح القديم (وقت تغيير المفتاح بس): بيتستخدم في فك التشفير لو الجديد مانفعش */
    private static function prevKey(): ?string
    {
        if (!defined('ENCRYPTION_KEY_PREV') || strlen(ENCRYPTION_KEY_PREV) < 64 || !ctype_xdigit(substr(ENCRYPTION_KEY_PREV, 0, 64))) return null;
        return hex2bin(substr(ENCRYPTION_KEY_PREV, 0, 64));
    }

    /** الموقع شغال بالمفتاح اللي كان منشور في الريبو؟ (لازم يتغيّر) */
    public static function usingPublicKey(): bool
    {
        return defined('ENCRYPTION_KEY') && hash_equals(self::PUBLIC_SAMPLE_KEY_SHA256, hash('sha256', strtolower(substr((string) ENCRYPTION_KEY, 0, 64))));
    }

    public static function hasPrevKey(): bool
    {
        return self::prevKey() !== null;
    }

    /** القيمة دي بتتفك بالمفتاح الحالي؟ ('current' · 'prev' · 'none') */
    public static function which(string $payload): string
    {
        $bin = base64_decode($payload, true);
        if ($bin === false || strlen($bin) < 28) return 'none';
        foreach (['current' => self::key(), 'prev' => self::prevKey()] as $name => $k) {
            if ($k === null) continue;
            if (openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16)) !== false) return $name;
        }
        return 'none';
    }

    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $bin = base64_decode($payload, true);
        if ($bin === false || strlen($bin) < 28) {
            throw new RuntimeException('Invalid ciphertext');
        }
        $iv     = substr($bin, 0, 12);
        $tag    = substr($bin, 12, 16);
        $cipher = substr($bin, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        // وقت تغيير المفتاح: القيم القديمة لسه بالمفتاح السابق لحد ما تتعاد تشفيرها
        if ($plain === false && ($prev = self::prevKey()) !== null) {
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $prev, OPENSSL_RAW_DATA, $iv, $tag);
        }
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (auth tag mismatch)');
        }
        return $plain;
    }
}
