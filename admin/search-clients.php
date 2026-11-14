<?php
/**
 * Spread AI — الأدمن: بحث العملاء للبحث العام في الشريط العلوي (خريطة التطوير · المرحلة 5)
 *   GET admin/search-clients.php?q=…  ← {clients: [{name, sub, url}]} (أقصى 6)
 * بالاسم أو الإيميل أو الموبايل. الحساب المُدار اللي الأدمن يقدر يفتحه ← «مساحة العميل»، غير كده ← ملف العميل.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/managed-accounts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!is_admin_logged_in() || !admin_can('view_users')) {
    http_response_code(403);
    exit(json_encode(['clients' => []]));
}
$me = current_admin();
if (!rate_limit('admin_search', 'a' . (int) ($me['id'] ?? 0), 120, 60)) {
    http_response_code(429);
    exit(json_encode(['clients' => []]));
}
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
if (mb_strlen($q) < 2) exit(json_encode(['clients' => []]));

$like = '%' . addcslashes($q, '%_\\') . '%';
$digits = preg_replace('/\D+/', '', $q);
$w = '(u.name LIKE ? OR u.email LIKE ?';
$p = [$like, $like];
if (strlen($digits) >= 4) {
    $w .= ' OR REPLACE(REPLACE(u.phone, " ", ""), "+", "") LIKE ?';
    $p[] = '%' . substr($digits, -9) . '%';
}
$w .= ')';
$rows = db_all("SELECT u.id, u.name, u.email, u.phone FROM users u WHERE $w ORDER BY u.id DESC LIMIT 6", $p);
$managed = ma_ready() ? ma_ids() : [];
$out = [];
foreach ($rows as $r) {
    $uid = (int) $r['id'];
    $ws = in_array($uid, $managed, true) && ma_can_access($uid);
    $out[] = ['name' => ($ws ? '⭐ ' : '') . (string) $r['name'], 'sub' => (string) $r['email'] . ($r['phone'] ? ' · ' . $r['phone'] : ''),
              'url' => url($ws ? 'admin/workspace.php?id=' . $uid : 'admin/user-view.php?id=' . $uid)];
}
echo json_encode(['clients' => $out], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
