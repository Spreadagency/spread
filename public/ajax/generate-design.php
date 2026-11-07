<?php
/**
 * Spread AI v2 — توليد التصميم v5
 *
 * اللوجو + صور العميل + الصورة المرجعية بيتبعتوا **للموديل نفسه** كمدخلات:
 * - اللوجو → الموديل يدمجه جوه التصميم كما هو
 * - صور العميل / المنتج → تظهر في التصميم
 * - الصورة المرجعية → الموديل يلتزم بستايلها
 *
 * fallback: لو المسار القديم (بدون دعم صور) → اللوجو بيتحط بـ GD بعد التوليد
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/uploader.php';
require_once __DIR__ . '/../../includes/plan-functions.php';
require_once __DIR__ . '/../../includes/watermark.php';
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) {
    require_once __DIR__ . '/../../includes/smart-ai.php';
}

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص
decode_b64_fields();

$user = current_user();
// الملف المرفوع للتصميم ده (اختياري) — بيترفع هنا والباقي في includes/design-generate.php
$__in = $_POST;
$__in['source_image_path'] = null;
if (!empty($_FILES['source_image']['name'])) {
    $up = upload_image($_FILES['source_image'], 'references');
    if ($up['ok']) $__in['source_image_path'] = $up['path'];
}
require_once __DIR__ . '/../../includes/design-generate.php';
json_response(design_generate($user, $__in));
