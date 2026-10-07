<?php
/** إيصال الدفع — لصاحب الطلب بس (الملفات نفسها ممنوعة من المتصفح مباشرة) */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/billing.php';

require_login();
$r = db_one('SELECT proof_path FROM payment_requests WHERE id = ? AND user_id = ?', [(int) ($_GET['id'] ?? 0), (int) current_user()['id']]);
billing_send_proof($r['proof_path'] ?? null);
