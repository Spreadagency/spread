<?php
/**
 * Spread AI — الأدمن: معاينة قالب نص (PNG) على صورة تجريبية
 *   admin/ce-template-preview.php?id=3&aspect=1:1[&zone=bottom][&bg=text]
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ce-overlay.php';

require_admin();
require_admin_can('ai_settings');
session_release();

$aspect = isset(CE_CANVAS[$_GET['aspect'] ?? '']) ? $_GET['aspect'] : '1:1';
$zone = in_array($_GET['zone'] ?? '', ['top', 'bottom', 'left', 'right', 'center'], true) ? $_GET['zone'] : 'bottom';
$tpl = null;
foreach (ce_templates(false) as $t) if ($t['id'] === (int) ($_GET['id'] ?? 0)) $tpl = $t;
if (!$tpl) { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/ce-tpl-' . bin2hex(random_bytes(6));
$base = null;
if (($_GET['bg'] ?? '') !== 'text') {
    // صورة تجريبية: سما + شمس + أرضية (من غير ملفات خارجية)
    $im = imagecreatetruecolor(1024, 1280);
    for ($y = 0; $y < 1280; $y++) {
        $t = $y / 1280;
        imageline($im, 0, $y, 1024, $y, imagecolorallocate($im, (int) (150 - 110 * $t), (int) (196 - 120 * $t), (int) (236 - 120 * $t)));
    }
    imagefilledellipse($im, 560, 430, 420, 420, imagecolorallocate($im, 248, 206, 168));
    imagefilledrectangle($im, 0, 900, 1024, 1280, imagecolorallocate($im, 58, 80, 110));
    $base = $tmp . '-b.png';
    imagepng($im, $base);
    imagedestroy($im);
}
$r = ce_overlay_render([
    'base' => $base, 'aspect' => $aspect, 'zone' => $zone, 'template' => $tpl['style'],
    'headline' => 'ابتسامتك تستاهل اهتمام كل يوم', 'subline' => 'احجز كشفك النهارده على واتساب',
    'colors' => ['#0C87EF', '#2EE3CC', '#FFB300'], 'out' => $tmp . '.png',
]);
if ($base) @unlink($base);
if (!$r['ok']) { http_response_code(500); exit; }
header('Content-Type: image/png');
header('Cache-Control: no-store');
readfile($tmp . '.png');
@unlink($tmp . '.png');

