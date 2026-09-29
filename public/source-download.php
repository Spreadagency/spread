<?php
/**
 * Spread AI v2 — تنزيل نص/ملخص مستند الهوية
 * ?id=SOURCE_ID&type=summary|raw
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();
$id = (int) ($_GET['id'] ?? 0);
$type = ($_GET['type'] ?? 'summary') === 'raw' ? 'raw' : 'summary';

$src = db_one('SELECT * FROM brand_sources WHERE id = ? AND user_id = ?', [$id, $user['id']]);
if (!$src) {
    http_response_code(404);
    exit('المستند غير موجود');
}

$text = $type === 'summary'
    ? (string) ($src['summary'] ?? '')
    : (string) ($src['raw_text'] ?? '');

if (trim($text) === '') {
    // fallback: لو مفيش ملخص، نزّل النص الخام
    $text = (string) ($src['raw_text'] ?? '');
    $type = 'raw';
}

if (trim($text) === '') {
    http_response_code(404);
    exit('لا يوجد نص متاح للتنزيل');
}

// اسم ملف آمن
$title = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s\-_]/u', '', (string) ($src['title'] ?? 'مستند'));
$title = trim(preg_replace('/\s+/u', '-', $title)) ?: 'مستند';
$suffix = $type === 'summary' ? 'ملخص' : 'نص-كامل';
$filename = mb_substr($title, 0, 60) . '-' . $suffix . '.txt';

$header = "# " . ($src['title'] ?? '') . "\n"
    . '# ' . ($type === 'summary' ? 'ملخص المستند' : 'النص المستخرج') . "\n"
    . '# ' . date('Y-m-d H:i') . "\n"
    . str_repeat('─', 40) . "\n\n";

$body = "\xEF\xBB\xBF" . $header . $text;   // BOM عشان العربي يفتح صح في ويندوز

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($body));
header('X-Content-Type-Options: nosniff');
echo $body;
