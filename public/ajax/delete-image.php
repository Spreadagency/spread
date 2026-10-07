<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/uploader.php';

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 30 كل 5 دقيقة لكل مستخدم
if (!rate_limit('del_img', 'u' . ($user['id'] ?? 0), 30, 300)) {
    json_response(['ok' => false, 'error' => 'استنى شوية وحاول تاني']);
}
$brand = user_brand();
$id = (int) ($_POST['id'] ?? 0);

if (!$brand) {
    json_response(['ok' => false, 'error' => 'لا يوجد ملف هوية للمستخدم']);
}

$img = db_one('SELECT * FROM brand_images WHERE id = ? AND brand_profile_id = ?', [$id, $brand['id']]);
if (!$img) {
    json_response(['ok' => false, 'error' => 'الصورة غير موجودة']);
}

delete_upload($img['image_path']);
db_run('DELETE FROM brand_images WHERE id = ?', [$id]);

json_response(['ok' => true]);
