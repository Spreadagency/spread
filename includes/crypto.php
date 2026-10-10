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
    private static function key(): string
    {
        if (!defined('ENCRYPTION_KEY') || strlen(ENCRYPTION_KEY) < 64) {
            throw new RuntimeException('ENCRYPTION_KEY غير معرّف في config.php — ولّده بـ bin2hex(random_bytes(32))');
        }
        return hex2bin(substr(ENCRYPTION_KEY, 0, 64));
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
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (auth tag mismatch)');
        }
        return $plain;
    }
}
