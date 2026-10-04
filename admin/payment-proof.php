<?php
/** إيصال الدفع للأدمن */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/billing.php';

require_admin();
require_admin_can('view_users');
$r = db_one('SELECT proof_path FROM payment_requests WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
billing_send_proof($r['proof_path'] ?? null);
