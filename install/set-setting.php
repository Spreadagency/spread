<?php
declare(strict_types=1);

/**
 * Set a setting from the command line (secrets are encrypted with APP_KEY).
 *   php install/set-setting.php gemini_api_key "AIza..."
 *   php install/set-setting.php whatsapp_number 2010XXXXXXXX
 *   php install/set-setting.php --list
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

if (($argv[1] ?? '') === '--list') {
    foreach (Settings::all() as $k => $v) {
        $shown = in_array($k, Settings::SECRET_KEYS, true) ? Settings::masked($k) : mb_strimwidth(str_replace("\n", ' ', (string) $v), 0, 70, '…');
        printf("%-28s %s\n", $k, $shown);
    }
    exit(0);
}
if ($argc < 3) {
    fwrite(STDERR, "Usage: php install/set-setting.php KEY VALUE | --list\n");
    exit(1);
}
Settings::set($argv[1], $argv[2]);
echo "Saved {$argv[1]}" . (in_array($argv[1], Settings::SECRET_KEYS, true) ? ' (encrypted)' : '') . PHP_EOL;
