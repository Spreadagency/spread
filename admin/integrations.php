<?php
/**
 * التكاملات — اتنقلت لـ «مركز الإعدادات» (المرحلة 8-ج).
 * الصفحة دي بتحوّل بس، علشان أي لينك قديم أو مفضّلة يفضل شغال.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();
$__to = ['meta' => 'social', 'paymob' => 'payments', 'crm' => 'access', 'ai' => 'ai'][is_string($_GET['s'] ?? null) ? $_GET['s'] : ''] ?? 'social';
flash_set('info', 'التكاملات بقت جوه «مركز الإعدادات» ✓');
redirect('admin/settings.php?tab=' . $__to);
