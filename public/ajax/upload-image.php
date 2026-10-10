<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/uploader.php';

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 20 كل 10 دقيقة لكل مستخدم
if (!rate_limit('upload_img', 'u' . ($user['id'] ?? 0), 20, 600)) {
    json_response(['ok' => false, 'error' => 'رفعت صور كتير بسرعة — استنى شوية']);
}
$brand = user_brand();

if (!$brand) {
    json_response(['ok' => false, 'error' => 'لا يوجد ملف هوية للمستخدم']);
}

$existing = db_count('SELECT COUNT(*) FROM brand_images WHERE brand_profile_id = ?', [$brand['id']]);
if ($existing >= MAX_BRAND_IMAGES) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى من الصور (' . MAX_BRAND_IMAGES . ')']);
}

$type = $_POST['image_type'] ?? 'reference';
if (!in_array($type, ['personal', 'reference', 'design'], true)) {
    $type = 'reference';
}

if (empty($_FILES['image']['name'])) {
    json_response(['ok' => false, 'error' => 'ما تم اختيار صورة']);
}

$up = upload_image($_FILES['image'], 'brand-images');
if (!$up['ok']) {
    json_response(['ok' => false, 'error' => $up['error']]);
}

$id = db_insert_row('brand_images', [
    'brand_profile_id' => $brand['id'],
    'image_path'       => $up['path'],
    'image_type'       => $type,
    'title'            => $_POST['title'] ?? null,
]);

json_response([
    'ok' => true,
    'id' => $id,
    'path' => $up['path'],
    'url' => upload_url($up['path']),
]);
