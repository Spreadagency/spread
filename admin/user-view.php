<?php
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/uploader.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';

require_admin();

$admin = current_admin();
$id = (int) ($_GET['id'] ?? 0);
$user = db_one('SELECT * FROM users WHERE id = ?', [$id]);

if (!$user) {
    flash_set('danger', 'المستخدم غير موجود');
    redirect('admin/users.php');
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_active') {
        require_admin_can('approve_users');
        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        db_run('UPDATE users SET status = ? WHERE id = ?', [$newStatus, $id]);
        admin_log($newStatus === 'active' ? 'activate_user' : 'deactivate_user', 'user', $id);
        flash_set('success', $newStatus === 'active' ? 'تم تفعيل المستخدم' : 'تم تعطيل المستخدم');
        redirect('admin/user-view.php?id=' . $id);
    }

    // ⑥-أ: أمان الحساب — العميل مش قادر يستلم كود الدخول / طلب حذف بالغلط
    if ($action === 'security') {
        require_admin_can('approve_users');
        $op = $_POST['op'] ?? '';
        if ($op === 'twofa_off') {
            db_run('UPDATE users SET two_fa_enabled = 0 WHERE id = ?', [$id]);
            admin_log('twofa_off', 'user', $id, 'إيقاف التحقق بخطوتين من الأدمن');
            flash_set('success', 'اتقفل التحقق بخطوتين — العميل يقدر يدخل بالباسورد بس');
        } elseif ($op === 'cancel_delete') {
            db_run('UPDATE users SET deletion_requested_at = NULL WHERE id = ?', [$id]);
            admin_log('cancel_account_delete', 'user', $id);
            flash_set('success', 'اتلغى طلب حذف الحساب ✓');
        } elseif ($op === 'end_sessions') {
            db_run('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$id]);
            admin_log('end_sessions', 'user', $id);
            flash_set('success', 'اتنهت كل جلسات العميل — هيحتاج يسجّل دخول تاني');
        }
        redirect('admin/user-view.php?id=' . $id);
    }

    if ($action === 'verify_email') {
        if (empty($user['email_verified_at'])) {
            db_run('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [$id]);
            // Clean up any pending verification tokens for this user
            db_run('DELETE FROM email_verifications WHERE user_id = ?', [$id]);
            admin_log('verify_email_manual', 'user', $id, 'تفعيل الإيميل يدويًا');
            flash_set('success', 'تم تفعيل البريد الإلكتروني للمستخدم ✓');
        } else {
            db_run('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$id]);
            admin_log('unverify_email', 'user', $id, 'إلغاء تفعيل الإيميل يدويًا');
            flash_set('info', 'تم إلغاء تفعيل البريد');
        }
        redirect('admin/user-view.php?id=' . $id);
    }

    if ($action === 'resend_verification') {
        require_once __DIR__ . '/../includes/mailer.php';
        $token = create_verification_token((int) $id);
        $sent = send_verification_email($user['name'], $user['email'], $token);
        admin_log('resend_verification', 'user', $id);
        flash_set($sent ? 'success' : 'warning',
            $sent ? 'تم إعادة إرسال رابط التفعيل ✓' : 'الرسالة اتسجلت في الـ logs (وضع الـ Debug). شيك على storage/logs/mail.log'
        );
        redirect('admin/user-view.php?id=' . $id);
    }

    if ($action === 'add_credits') {
        require_admin_can('add_credits');
        $amt = (int) ($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        // 8-أ: مصدر الكريدت (مدفوع بمبلغ · هدية · تعويض) — علشان الإيراد يتحسب صح
        $src = in_array($_POST['source'] ?? '', ['paid', 'bonus', 'compensation', 'promo'], true) ? $_POST['source'] : 'bonus';
        $egp = max(0, (float) str_replace(',', '.', (string) ($_POST['amount_egp'] ?? '0')));
        if ($amt > 0 && $src === 'paid' && $egp <= 0) {
            flash_set('danger', 'الكريدت المدفوع محتاج المبلغ اللي اتدفع (ج.م)');
            redirect('admin/user-view.php?id=' . $id . '&tab=credits');
        }
        if ($amt > 0) {
            $okAdd = credits_add($id, $amt, $note ?: 'إضافة يدوية من الإدارة', $admin['id'], 'manual', null, (int) ($_POST['validity_days'] ?? 0) ?: null,
                ['source' => $src, 'amount_egp' => $src === 'paid' ? $egp : 0]);
            if ($okAdd) {
                admin_log('add_credits', 'user', $id, "+$amt ($src" . ($src === 'paid' ? " {$egp}EGP" : '') . "): $note");
                flash_set('success', "تمت إضافة $amt كريدت");
            } else {
                flash_set('danger', 'الإضافة ماتمتش — جرّب تاني');
            }
        } else {
            flash_set('danger', 'القيمة غير صحيحة');
        }
        redirect('admin/user-view.php?id=' . $id);
    }

    // 8-ب: تفعيل باقة (دورة جديدة بحصصها) — شحن الكريدت + بداية الدورة
    if ($action === 'assign_plan') {
        require_admin_can('add_credits');
        $pkgId = (int) ($_POST['package_id'] ?? 0);
        $pkg = $pkgId ? db_one('SELECT * FROM credit_packages WHERE id = ?', [$pkgId]) : null;
        $amt = max(0, (int) ($_POST['credits'] ?? ($pkg['credits'] ?? 0)));
        $days = max(1, min(3650, (int) ($_POST['days'] ?? ($pkg['validity_days'] ?? 30))));
        $src = in_array($_POST['source'] ?? '', ['paid', 'bonus', 'promo'], true) ? $_POST['source'] : 'paid';
        $egp = max(0, (float) str_replace(',', '.', (string) ($_POST['amount_egp'] ?? '0')));
        if (!$pkg || $amt < 1) {
            flash_set('danger', 'اختار باقة وعدد كريدت صحيح');
        } elseif ($src === 'paid' && $egp <= 0) {
            flash_set('danger', 'الباقة المدفوعة محتاجة المبلغ اللي اتدفع');
        } else {
            $okAdd = credits_add($id, $amt, 'تفعيل باقة «' . $pkg['name'] . '» من الإدارة', $admin['id'], 'manual', null, $days,
                ['source' => $src, 'amount_egp' => $src === 'paid' ? $egp : 0]);
            $__cy = null;
            if ($okAdd && function_exists('plan_start')) {
                $__cy = plan_start($id, $pkgId, $amt, $days, $src === 'paid' ? 'paid' : 'gift', (int) $admin['id'], trim((string) ($_POST['note'] ?? '')) ?: null);
            }
            admin_log('assign_plan', 'user', $id, json_encode(['package' => $pkgId, 'credits' => $amt, 'source' => $src, 'egp' => $egp, 'days' => $days], JSON_UNESCAPED_UNICODE));
            if (!$okAdd) flash_set('danger', 'التفعيل ماتمش — جرّب تاني');
            elseif (!$__cy) flash_set('warning', 'الكريدت اتضاف ✓ بس الدورة ماتسجلتش (شغّل ترحيل «8-ب») — الحصص مش هتشتغل');
            else flash_set('success', 'اتفعّلت باقة «' . $pkg['name'] . '» ✓ — دورة جديدة بدأت');
        }
        redirect('admin/user-view.php?id=' . $id . '&tab=usage');
    }

    // 8-ب: زيادة حصة لعميل معيّن (بسبب وتاريخ انتهاء)
    if ($action === 'quota_override') {
        require_admin_can('add_credits');
        if (!plans_ready()) { flash_set('danger', 'شغّل ترحيل «8-ب» الأول من الترحيلات'); redirect('admin/user-view.php?id=' . $id . '&tab=usage'); }
        $unit = (string) ($_POST['unit'] ?? '');
        $extra = (int) ($_POST['extra'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $exp = trim((string) ($_POST['expires_at'] ?? ''));
        if (!isset(plan_units()[$unit]) || $extra === 0 || $extra < -10000 || $extra > 10000 || $reason === '') {
            flash_set('danger', 'اختار الخدمة واكتب العدد والسبب');
        } else {
            // من غير تاريخ: الزيادة بتخلص مع الدورة الحالية (مش بتتنقل للدورات الجاية)
            $__pa = plan_active($id);
            $__cycEnd = empty($__pa['synthetic']) ? (string) $__pa['ends'] : null;
            db_insert('INSERT INTO quota_overrides (user_id, unit, extra, reason, expires_at, created_by) VALUES (?,?,?,?,?,?)',
                [$id, $unit, $extra, mb_substr($reason, 0, 255), $exp !== '' && strtotime($exp) ? date('Y-m-d 23:59:59', strtotime($exp)) : $__cycEnd, (int) $admin['id']]);
            admin_log('quota_override', 'user', $id, json_encode(['unit' => $unit, 'extra' => $extra, 'reason' => $reason, 'expires' => $exp], JSON_UNESCAPED_UNICODE));
            flash_set('success', 'اتضافت ' . ($extra > 0 ? '+' : '') . $extra . ' على «' . plan_units()[$unit][1] . '» ✓');
        }
        redirect('admin/user-view.php?id=' . $id . '&tab=usage');
    }
    if ($action === 'quota_override_del') {
        require_admin_can('add_credits');
        if (!plans_ready()) { flash_set('danger', 'شغّل ترحيل «8-ب» الأول من الترحيلات'); redirect('admin/user-view.php?id=' . $id . '&tab=usage'); }
        db_run('DELETE FROM quota_overrides WHERE id = ? AND user_id = ?', [(int) ($_POST['oid'] ?? 0), $id]);
        admin_log('quota_override_delete', 'user', $id, (string) (int) ($_POST['oid'] ?? 0));
        flash_set('success', 'اتلغت الزيادة');
        redirect('admin/user-view.php?id=' . $id . '&tab=usage');
    }

    if ($action === 'deduct_credits') {
        require_admin_can('add_credits');
        $amt = (int) ($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($amt > 0) {
            $result = credits_deduct($id, $amt, $note ?: 'خصم يدوي من الإدارة', $admin['id']);
            if ($result) {
                admin_log('deduct_credits', 'user', $id, "-$amt: $note");
                flash_set('success', "تم خصم $amt كريدت");
            } else {
                flash_set('danger', 'فشل الخصم — الرصيد قد يكون غير كافي');
            }
        }
        redirect('admin/user-view.php?id=' . $id);
    }
}

// ═══ 8-ب: ملف العميل 360 ═══
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/content-formats.php';
if (is_file(__DIR__ . '/../includes/brand-brain.php')) require_once __DIR__ . '/../includes/brand-brain.php';

$tabs = ['overview' => 'نظرة عامة', 'usage' => 'الباقة والحصص', 'economics' => 'التكلفة والربح', 'content' => 'المحتوى', 'credits' => 'الكريدت', 'account' => 'الحساب والأمان'];
if (!admin_can('view_costs')) unset($tabs['economics']);
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!isset($tabs[$tab])) $tab = 'overview';

$brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$id]);
$balance = credits_balance($id);
$pu = function_exists('plan_usage') ? plan_usage($id) : null;
$signals = ($pu && function_exists('customer_signals')) ? customer_signals($id, $pu) : [];
$plan = $pu['plan'] ?? null;
$contentsCount = db_count('SELECT COUNT(*) FROM contents WHERE user_id = ?', [$id]);
$wallet = db_one('SELECT * FROM credit_wallets WHERE user_id = ?', [$id]);
$uv_q = static function (string $sql, array $p = [], $d = 0) { try { $r = db_one($sql, $p); return $r ? (reset($r) ?? $d) : $d; } catch (\Throwable $e) { return $d; } };
$uv_all = static function (string $sql, array $p = []) { try { return db_all($sql, $p); } catch (\Throwable $e) { return []; } };

$lastActive = $uv_q('SELECT GREATEST(COALESCE((SELECT MAX(created_at) FROM contents WHERE user_id = ?), "1970-01-01"), COALESCE((SELECT MAX(last_seen_at) FROM user_sessions WHERE user_id = ?), "1970-01-01")) t', [$id, $id], null);
$lastActive = ($lastActive && strtotime((string) $lastActive) > 86400) ? (string) $lastActive : null;
$pctTone = static fn($p) => $p === null ? '' : ($p >= 90 ? 'bad' : ($p >= 70 ? 'warn' : ''));
$sigChip = ['bad' => 'chip-coral', 'warn' => 'chip-amber', 'info' => 'chip-sky'];

// ── بيانات التبويب
if ($tab === 'overview' || $tab === 'economics') {
    $cost30 = $uv_all('SELECT COUNT(*) runs, SUM(status = "failed") failed, COALESCE(SUM(cost_usd + COALESCE(cost_failed_usd,0)),0) usd, COALESCE(SUM(cost_egp),0) egp,
                              COALESCE(SUM(credits_charged),0) cr, COALESCE(SUM(revenue_egp),0) rev FROM ai_runs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', [$id])[0] ?? [];
    $fxNow = function_exists('usage_fx_rate') ? usage_fx_rate() : 50.0;
}
if ($tab === 'overview') {
    $hp = ($brand && function_exists('brand_health')) ? (int) (brand_health($brand)['pct'] ?? 0) : ($brand && !empty($brand['business_name']) ? 50 : 0);
    $social = (int) $uv_q('SELECT COUNT(*) FROM social_connections WHERE user_id = ? AND status = "active"', [$id]);
    $camp = (int) $uv_q('SELECT COUNT(*) FROM campaigns WHERE user_id = ?', [$id]);
    $pubs = (int) $uv_q('SELECT COUNT(*) FROM contents WHERE user_id = ? AND published_at IS NOT NULL', [$id]);
    $designs = (int) $uv_q('SELECT COUNT(*) FROM content_designs WHERE user_id = ?', [$id]) + (int) $uv_q('SELECT COUNT(*) FROM studio_designs WHERE user_id = ?', [$id]);
    $researches = (int) $uv_q('SELECT COUNT(*) FROM researches WHERE user_id = ?', [$id]);
    $latest = $uv_all('SELECT id, content_type, platform, status, format, created_at FROM contents WHERE user_id = ? ORDER BY id DESC LIMIT 6', [$id]);
    $checklist = [
        ['هوية البراند مكتملة', $hp >= 70, $hp . '%'],
        ['ربط صفحة سوشيال', $social > 0, $social ? $social . ' صفحة' : 'مفيش'],
        ['أول حملة', $camp > 0, $camp ? $camp . ' حملة' : 'لسه'],
        ['أول نشر', $pubs > 0, $pubs ? $pubs . ' منشور' : 'لسه'],
    ];
    $doneSteps = count(array_filter($checklist, fn($c) => $c[1]));
}
if ($tab === 'usage') {
    $packages = $uv_all('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY order_num, credits');
    $overrides = $uv_all('SELECT o.*, a.name admin_name FROM quota_overrides o LEFT JOIN admin_users a ON a.id = o.created_by WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 30', [$id]);
    if (!$overrides) $overrides = $uv_all('SELECT o.*, NULL admin_name FROM quota_overrides o WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 30', [$id]);
    $cycles = $uv_all('SELECT * FROM user_plans WHERE user_id = ? ORDER BY id DESC LIMIT 12', [$id]);
}
if ($tab === 'economics') {
    $byTask = $uv_all('SELECT task, COUNT(*) runs, SUM(status = "failed") failed, SUM(failovers > 0) fo, COALESCE(SUM(cost_usd + COALESCE(cost_failed_usd,0)),0) usd, COALESCE(SUM(cost_egp),0) egp,
                              COALESCE(SUM(credits_charged),0) cr, COALESCE(SUM(revenue_egp),0) rev FROM ai_runs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY task ORDER BY egp DESC', [$id]);
    $lots = $uv_all('SELECT source, COUNT(*) n, SUM(credits) credits, SUM(remaining) remaining, SUM(frozen) frozen, SUM(forfeited) forfeited, SUM(credits * unit_egp) paid_egp FROM credit_lots WHERE user_id = ? GROUP BY source ORDER BY credits DESC', [$id]);
    $lifeRev = (float) $uv_q('SELECT COALESCE(SUM(revenue_egp),0) FROM ai_runs WHERE user_id = ?', [$id]);
    $lifeCost = (float) $uv_q('SELECT COALESCE(SUM(cost_egp),0) FROM ai_runs WHERE user_id = ?', [$id]);
    $lifePaid = (float) $uv_q('SELECT COALESCE(SUM(credits * unit_egp),0) FROM credit_lots WHERE user_id = ? AND source = "paid"', [$id]);
}
if ($tab === 'content') {
    $contents = db_all('SELECT * FROM contents WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$id]);
    $usage = [];
    if (formats_ready()) {
        foreach (db_all('SELECT c.*, (SELECT COUNT(DISTINCT COALESCE(d.slide_no, 0)) FROM content_designs d WHERE d.content_id = c.id) dslots
                         FROM contents c WHERE c.user_id = ?', [$id]) as $r) {
            $f = content_format_key($r['format']);
            $u = &$usage[$f];
            $u['n'] = ($u['n'] ?? 0) + 1;
            if ($f === 'carousel') {
                $u['slides'] = ($u['slides'] ?? 0) + (int) $r['slides_count'];
                $u['done_slides'] = ($u['done_slides'] ?? 0) + min((int) $r['slides_count'], (int) $r['dslots']);
                if ((int) $r['dslots'] >= (int) $r['slides_count'] && (int) $r['slides_count'] > 0) $u['complete'] = ($u['complete'] ?? 0) + 1;
            } elseif ($f === 'video') {
                $k = $r['video_status'] ?: 'script';
                $u['st'][$k] = ($u['st'][$k] ?? 0) + 1;
            } elseif ((int) $r['dslots'] > 0) {
                $u['complete'] = ($u['complete'] ?? 0) + 1;
            }
            unset($u);
        }
    }
    $contentProg = [];
    foreach ($contents as $c) $contentProg[(int) $c['id']] = content_design_progress($c);
}
if ($tab === 'credits') {
    $ledger = $uv_all('SELECT * FROM credit_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$id]);
}
if ($tab === 'account') {
    $sessions = $uv_all('SELECT * FROM user_sessions WHERE user_id = ? ORDER BY COALESCE(last_seen_at, created_at) DESC LIMIT 10', [$id]);
    $imps = $uv_all('SELECT l.*, a.name admin_name FROM impersonation_logs l LEFT JOIN admin_users a ON a.id = l.admin_id WHERE l.user_id = ? ORDER BY l.id DESC LIMIT 15', [$id]);
    $socials = $uv_all('SELECT platform, page_name, ig_username, status, expires_at, last_error FROM social_connections WHERE user_id = ? ORDER BY id DESC', [$id]);
}
$srcLbl = ['paid' => '💳 مدفوع', 'free' => '🆓 مجاني', 'bonus' => '🎁 هدية', 'promo' => '🎟 عرض', 'compensation' => '🛠 تعويض', 'legacy' => '📦 قديم'];
$taskLbl = ['content' => 'كتابة محتوى', 'ideas' => 'أفكار', 'eval' => 'تقييم', 'brand' => 'هوية', 'research_analyze' => 'تحليل بحث', 'research_search' => 'بحث ويب', 'design' => 'تصميم', 'general' => 'عام'];

$active = 'users';
$page_title = 'ملف العميل: ' . $user['name'];
include __DIR__ . '/../templates/admin-header.php';
$tabUrl = fn($t) => url('admin/user-view.php?id=' . $id . '&tab=' . $t);
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>

        <div class="page-head with-actions">
            <div>
                <a href="<?= url('admin/users.php') ?>" class="a2-muted" style="font-size:13px">← العملاء</a>
                <h1>ملف العميل</h1>
                <div class="sub">Customer 360 · الباقة والاستهلاك والتكلفة والنشاط في مكان واحد</div>
            </div>
        </div>

        <!-- رأس الملف -->
        <div class="card c360-head">
            <div class="a2-row" style="gap:14px;flex-wrap:nowrap;min-width:0">
                <?php if ($brand && !empty($brand['logo_path'])): ?>
                    <img class="c360-av" src="<?= e(upload_url($brand['logo_path'])) ?>" alt="">
                <?php else: ?>
                    <div class="c360-av" style="background:<?= e(color_from_string($user['name'])) ?>"><?= e(initials($user['name'])) ?></div>
                <?php endif; ?>
                <div style="min-width:0">
                    <div class="a2-row" style="gap:8px">
                        <b style="font-size:18px"><?= e($user['name']) ?></b>
                        <span class="chip <?= $user['status'] === 'active' ? 'chip-mint' : 'chip-coral' ?>"><?= $user['status'] === 'active' ? 'نشط' : 'موقوف' ?></span>
                        <?php if (empty($user['email_verified_at'])): ?><span class="chip chip-amber">الإيميل مش متفعّل</span><?php endif; ?>
                    </div>
                    <div class="a2-muted" style="margin-top:3px;overflow-wrap:anywhere">
                        <?= $brand && !empty($brand['business_name']) ? e($brand['business_name']) . ' · ' : '' ?><span dir="ltr"><?= e($user['email']) ?></span><?= !empty($user['phone']) ? ' · <span dir="ltr">' . e($user['phone']) . '</span>' : '' ?>
                        · عميل من <?= e(fmt_date($user['created_at'])) ?><?= $lastActive ? ' · آخر نشاط ' . e(time_ago($lastActive)) : '' ?>
                    </div>
                </div>
            </div>
            <div class="a2-row" style="gap:8px">
                <?php if (admin_can('impersonate')): ?><a class="btn" href="<?= url('admin/impersonate.php?user_id=' . $id) ?>">👁 ادخل بحسابه</a><?php endif; ?>
                <?php if (admin_can('add_credits')): ?><a class="btn soft" href="<?= $tabUrl('usage') ?>#assign">+ باقة / كريدت</a><?php endif; ?>
                <a class="btn ghost" href="<?= url('admin/user-brand.php?user_id=' . $id) ?>">✎ الهوية</a>
            </div>
        </div>

        <!-- المؤشرات الأربعة -->
        <div class="a2-grid a2-g4" style="margin-top:14px">
            <div class="a2-kpi"><small>الباقة</small><b style="direction:rtl;text-align:start;font-size:18px"><?= e($plan['name'] ?? '—') ?></b><em><?= $plan && !$plan['synthetic'] ? 'من ' . e(fmt_date(substr($plan['starts'], 0, 10))) : 'مفيش دورة مسجّلة' ?></em></div>
            <div class="a2-kpi"><small>الرصيد (Credits)</small><b><?= a2n($balance) ?></b><em><?= $pu ? 'استهلك ' . a2n($pu['credits']['used']) . ' من ' . a2n($pu['credits']['total']) . ' في الدورة' : '' ?></em></div>
            <div class="a2-kpi"><small>استهلاك الباقة</small><b><?= (int) ($pu['pct'] ?? 0) ?>%</b>
                <div class="a2-bar <?= $pctTone($pu['pct'] ?? 0) ?>" style="margin-top:8px"><i style="width:<?= (int) ($pu['pct'] ?? 0) ?>%"></i></div></div>
            <div class="a2-kpi"><small>التجديد / الانتهاء</small><b style="direction:rtl;text-align:start;font-size:18px"><?= $pu ? e($pu['ends_label']) : '—' ?></b><em><?= $pu ? 'فاضل ' . (int) $pu['days_left'] . ' يوم' : '' ?></em></div>
        </div>

        <?php if ($signals): ?>
        <div class="c360-sig">
            <?php foreach ($signals as [$sk, $sl, $slv, $sw]): ?>
                <span class="chip <?= $sigChip[$slv] ?? 'chip-line' ?>" title="<?= e($sw) ?>"><?= e($sl) ?><small> · <?= e($sw) ?></small></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <nav class="a2-tabs" style="margin-top:16px">
            <?php foreach ($tabs as $tk => $tl): ?><a href="<?= $tabUrl($tk) ?>" class="<?= $tab === $tk ? 'on' : '' ?>"><?= e($tl) ?></a><?php endforeach; ?>
        </nav>

<?php if ($tab === 'overview'): ?>
        <div class="a2-grid a2-side">
            <div class="a2-grid" style="gap:16px">
                <div class="card">
                    <div class="a2-h"><h3>الاستهلاك في الدورة</h3><a class="btn sm ghost" href="<?= $tabUrl('usage') ?>">التفاصيل</a></div>
                    <?php foreach ($pu['units'] ?? [] as $u): ?>
                        <div class="c360-unit">
                            <span><?= $u['emoji'] ?> <?= e($u['label']) ?></span>
                            <b><?= (int) $u['used'] ?><?= empty($u['open']) ? ' / ' . (int) $u['limit'] : '' ?></b>
                            <?php if (empty($u['open'])): ?><div class="a2-bar <?= $pctTone($u['pct']) ?>"><i style="width:<?= (int) $u['pct'] ?>%"></i></div>
                            <?php else: ?><div class="a2-muted" style="font-size:11.5px">مفتوح</div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card" style="padding:0">
                    <div class="a2-h" style="padding:16px 18px 0"><h3>أحدث المحتوى</h3><a class="btn sm ghost" href="<?= $tabUrl('content') ?>">الكل (<?= (int) $contentsCount ?>)</a></div>
                    <?php if (!$latest): ?><div class="a2-empty">لسه ماعملش محتوى</div><?php else: ?>
                    <div class="a2-tw" style="border:0"><table class="a2-tbl"><tbody>
                        <?php foreach ($latest as $c): ?>
                        <tr>
                            <td>#<?= (int) $c['id'] ?></td>
                            <td><?= e(content_type_label($c['content_type'])) ?> <span class="a2-muted">· <?= e(strtoupper(content_formats()[content_format_key($c['format'])]['en'] ?? 'post')) ?></span></td>
                            <td><span class="chip <?= e(status_chip($c['status'])) ?>"><?= e(status_label($c['status'])) ?></span></td>
                            <td class="a2-muted"><?= e(time_ago($c['created_at'])) ?></td>
                            <td><a class="btn sm ghost" href="<?= url('admin/content-view.php?id=' . (int) $c['id']) ?>">عرض</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="a2-grid" style="gap:16px">
                <div class="card">
                    <div class="a2-h"><h3>التفعيل</h3><span class="chip <?= $doneSteps === 4 ? 'chip-mint' : 'chip-amber' ?>"><?= $doneSteps ?>/4</span></div>
                    <?php foreach ($checklist as [$cl, $cok, $cv]): ?>
                        <div class="a2-kv"><span><?= $cok ? '✅' : '⬜' ?> <?= e($cl) ?></span><b style="font-weight:600;color:<?= $cok ? '#0B8F83' : '#8391A6' ?>"><?= e($cv) ?></b></div>
                    <?php endforeach; ?>
                </div>
                <div class="card">
                    <div class="a2-h"><h3>النشاط الكلي</h3></div>
                    <div class="a2-kv"><span>📝 محتوى</span><b><?= a2n($contentsCount) ?></b></div>
                    <div class="a2-kv"><span>🎨 تصميمات</span><b><?= a2n($designs) ?></b></div>
                    <div class="a2-kv"><span>📤 منشورات اتنشرت</span><b><?= a2n($pubs) ?></b></div>
                    <div class="a2-kv"><span>🔬 أبحاث</span><b><?= a2n($researches) ?></b></div>
                    <div class="a2-kv"><span>🧭 حملات</span><b><?= a2n($camp) ?></b></div>
                </div>
                <?php if (admin_can('view_costs')): $pr = (float) ($cost30['rev'] ?? 0) - (float) ($cost30['egp'] ?? 0); ?>
                <div class="card">
                    <div class="a2-h"><h3>آخر 30 يوم</h3><a class="btn sm ghost" href="<?= $tabUrl('economics') ?>">التفاصيل</a></div>
                    <div class="a2-kv"><span>تكلفة AI</span><b><?= a2egp($cost30['egp'] ?? 0) ?></b></div>
                    <div class="a2-kv"><span>إيراد الكريدت المستهلك</span><b><?= a2egp($cost30['rev'] ?? 0) ?></b></div>
                    <div class="a2-kv"><span>الفرق</span><b style="color:<?= $pr >= 0 ? '#0B8F83' : '#C2362E' ?>"><?= a2egp($pr) ?></b></div>
                    <div class="a2-kv"><span>عمليات / فشل</span><b><?= a2n($cost30['runs'] ?? 0) ?> / <?= a2n($cost30['failed'] ?? 0) ?></b></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

<?php elseif ($tab === 'usage'): ?>
        <div class="a2-grid a2-side">
            <div class="a2-grid" style="gap:16px">
                <div class="card" style="padding:0">
                    <div class="a2-h" style="padding:16px 18px 0">
                        <h3>حصص الدورة الحالية</h3>
                        <span class="a2-muted"><?= $plan ? e(fmt_date(substr($plan['starts'], 0, 10))) . ' ← ' . e($pu['ends_label']) : '' ?></span>
                    </div>
                    <?php if ($plan && $plan['synthetic']): ?>
                        <div class="a2-note" style="margin:10px 18px 0">العميل ده مالوش دورة باقة مسجّلة (رصيد قديم/مجاني) — الحصص مفتوحة. فعّل له باقة من الفورم علشان الحصص تشتغل.</div>
                    <?php endif; ?>
                    <div class="a2-tw" style="border:0"><table class="a2-tbl">
                        <thead><tr><th>الخدمة</th><th>المستخدم</th><th>الحد</th><th style="min-width:140px">النسبة</th><th>الباقي</th></tr></thead>
                        <tbody>
                        <?php foreach ($pu['units'] ?? [] as $u): ?>
                            <tr>
                                <td><?= $u['emoji'] ?> <?= e($u['label']) ?> <span class="a2-muted"><?= e($u['en']) ?></span></td>
                                <td><b><?= (int) $u['used'] ?></b></td>
                                <td><?= empty($u['open']) ? (int) $u['limit'] : '<span class="a2-muted">مفتوح</span>' ?></td>
                                <td><?php if (empty($u['open'])): ?><div class="a2-row" style="gap:8px;flex-wrap:nowrap"><div class="a2-bar <?= $pctTone($u['pct']) ?>" style="flex:1"><i style="width:<?= (int) $u['pct'] ?>%"></i></div><small><?= (int) $u['pct'] ?>%</small></div><?php else: ?>—<?php endif; ?></td>
                                <td><?= $u['left'] === null ? '—' : (int) $u['left'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr>
                                <td>◇ الكريدت <span class="a2-muted">Credits</span></td>
                                <td><b><?= a2n($pu['credits']['used'] ?? 0) ?></b></td>
                                <td><?= a2n($pu['credits']['total'] ?? 0) ?></td>
                                <td><div class="a2-row" style="gap:8px;flex-wrap:nowrap"><div class="a2-bar <?= $pctTone($pu['credits']['pct'] ?? 0) ?>" style="flex:1"><i style="width:<?= (int) ($pu['credits']['pct'] ?? 0) ?>%"></i></div><small><?= (int) ($pu['credits']['pct'] ?? 0) ?>%</small></div></td>
                                <td><?= a2n($balance) ?></td>
                            </tr>
                        </tbody>
                    </table></div>
                    <div class="a2-muted" style="padding:0 18px 14px;font-size:12px">العميل بيشوف <?= credits_show_numbers() ? 'أرقام الكريدت' : 'النسب % بس' ?> · الحصص <?= (string) get_setting('quotas_enforced', '1') === '1' ? 'بتوقف الخدمة لما تخلص' : 'للعرض بس' ?> — من <a href="<?= url('admin/packages.php') ?>">الباقات والأسعار</a></div>
                </div>

                <div class="card" style="padding:0">
                    <div class="a2-h" style="padding:16px 18px 0"><h3>زيادات الحصص</h3></div>
                    <?php if (!$overrides): ?><div class="a2-empty">مفيش زيادات</div><?php else: ?>
                    <div class="a2-tw" style="border:0"><table class="a2-tbl">
                        <thead><tr><th>الخدمة</th><th>الزيادة</th><th>السبب</th><th>لحد</th><th>بواسطة</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($overrides as $o): $exp = $o['expires_at'] && strtotime($o['expires_at']) < time(); ?>
                            <tr style="<?= $exp ? 'opacity:.55' : '' ?>">
                                <td><?= e((plan_units()[$o['unit']][0] ?? '') . ' ' . (plan_units()[$o['unit']][1] ?? $o['unit'])) ?></td>
                                <td><b><?= (int) $o['extra'] > 0 ? '+' : '' ?><?= (int) $o['extra'] ?></b></td>
                                <td><?= e($o['reason']) ?></td>
                                <td class="a2-muted"><?= $o['expires_at'] ? e(fmt_date(substr($o['expires_at'], 0, 10))) . ($exp ? ' (انتهت)' : '') : 'دايمًا' ?></td>
                                <td class="a2-muted"><?= e($o['admin_name'] ?? '—') ?></td>
                                <td><?php if (admin_can('add_credits')): ?><form method="post" style="margin:0" onsubmit="return confirm('إلغاء الزيادة دي؟')"><?= csrf_field() ?><input type="hidden" name="action" value="quota_override_del"><input type="hidden" name="oid" value="<?= (int) $o['id'] ?>"><button class="btn sm ghost">إلغاء</button></form><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div>

                <div class="card" style="padding:0">
                    <div class="a2-h" style="padding:16px 18px 0"><h3>سجل الدورات</h3></div>
                    <?php if (!$cycles): ?><div class="a2-empty">مفيش دورات مسجّلة</div><?php else: ?>
                    <div class="a2-tw" style="border:0"><table class="a2-tbl">
                        <thead><tr><th>الباقة</th><th>المصدر</th><th>من</th><th>لحد</th><th>الكريدت</th><th>الحالة</th></tr></thead>
                        <tbody>
                        <?php foreach ($cycles as $cy): ?>
                            <tr>
                                <td><b><?= e($cy['name']) ?></b><?= $cy['note'] ? '<div class="a2-muted">' . e($cy['note']) . '</div>' : '' ?></td>
                                <td><?= e(['paid' => '💳 مدفوع', 'gift' => '🎁 هدية', 'manual' => '✋ يدوي', 'trial' => '🧪 تجربة'][$cy['source']] ?? $cy['source']) ?></td>
                                <td class="a2-muted"><?= e(fmt_date(substr($cy['starts_at'], 0, 10))) ?></td>
                                <td class="a2-muted"><?= e(fmt_date(substr($cy['ends_at'], 0, 10))) ?></td>
                                <td><?= a2n($cy['credits_allowance']) ?></td>
                                <td><?php $live = $cy['status'] === 'active' && strtotime($cy['ends_at']) > time(); ?><span class="chip <?= $live ? 'chip-mint' : 'chip-line' ?>"><?= $live ? 'سارية' : ($cy['status'] === 'replaced' ? 'اتبدّلت' : 'انتهت') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="a2-grid" style="gap:16px">
                <?php if (admin_can('add_credits')): ?>
                <form method="post" class="card" id="assign">
                    <?= csrf_field() ?><input type="hidden" name="action" value="assign_plan">
                    <div class="a2-h"><h3>تفعيل باقة</h3></div>
                    <p class="a2-muted" style="margin-top:0">بيشحن الكريدت ويبدأ دورة جديدة بحصص الباقة (الدورة الحالية بتتقفل).</p>
                    <div class="a2-grid" style="gap:10px">
                        <div><label class="a2-lbl">الباقة</label>
                            <select class="input" name="package_id" id="uvPkg" required style="width:100%">
                                <option value="">— اختار —</option>
                                <?php foreach ($packages as $pk): ?>
                                    <option value="<?= (int) $pk['id'] ?>" data-cr="<?= (int) $pk['credits'] ?>" data-days="<?= (int) ($pk['validity_days'] ?: 30) ?>" data-egp="<?= e((string) (float) $pk['price_egp']) ?>"><?= e($pk['name']) ?> · <?= a2n($pk['credits']) ?> كريدت · <?= a2egp($pk['price_egp'], 0) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="a2-grid a2-g2" style="gap:10px">
                            <div><label class="a2-lbl">الكريدت</label><input class="input" type="number" name="credits" id="uvCr" min="1" required style="width:100%"></div>
                            <div><label class="a2-lbl">المدة (يوم)</label><input class="input" type="number" name="days" id="uvDays" min="1" max="3650" value="30" style="width:100%"></div>
                        </div>
                        <div class="a2-grid a2-g2" style="gap:10px">
                            <div><label class="a2-lbl">المصدر</label>
                                <select class="input" name="source" id="uvSrc" style="width:100%">
                                    <option value="paid">💳 مدفوع</option><option value="bonus">🎁 هدية</option><option value="promo">🎟 عرض</option>
                                </select></div>
                            <div id="uvEgpW"><label class="a2-lbl">المبلغ (ج.م)</label><input class="input" type="number" step="0.01" min="0" name="amount_egp" id="uvEgp" style="width:100%"></div>
                        </div>
                        <div><label class="a2-lbl">ملاحظة</label><input class="input" name="note" maxlength="255" placeholder="مثلًا: تحويل فودافون كاش" style="width:100%"></div>
                        <div><button class="btn" type="submit" onclick="return confirm('تفعيل الباقة وبدء دورة جديدة؟')">تفعيل</button></div>
                    </div>
                </form>

                <form method="post" class="card">
                    <?= csrf_field() ?><input type="hidden" name="action" value="quota_override">
                    <div class="a2-h"><h3>زيادة حصة</h3></div>
                    <p class="a2-muted" style="margin-top:0">لعميل معيّن بس — مثلًا تعويض أو عرض خاص. الزيادة بتتطبق على الخدمات اللي ليها حد.</p>
                    <div class="a2-grid" style="gap:10px">
                        <div class="a2-grid a2-g2" style="gap:10px">
                            <div><label class="a2-lbl">الخدمة</label>
                                <select class="input" name="unit" style="width:100%">
                                    <?php foreach (plan_units() as $uk => [$ue, $ul]): ?><option value="<?= e($uk) ?>"><?= $ue ?> <?= e($ul) ?></option><?php endforeach; ?>
                                </select></div>
                            <div><label class="a2-lbl">العدد (+/−)</label><input class="input" type="number" name="extra" value="5" min="-10000" max="10000" required style="width:100%"></div>
                        </div>
                        <div><label class="a2-lbl">السبب (إجباري)</label><input class="input" name="reason" required maxlength="255" style="width:100%"></div>
                        <div><label class="a2-lbl">تنتهي في (فاضي = مع نهاية الدورة الحالية)</label><input class="input" type="date" name="expires_at" style="width:100%"></div>
                        <div><button class="btn soft" type="submit">إضافة</button></div>
                    </div>
                </form>
                <?php else: ?>
                    <div class="card a2-muted">تفعيل الباقات والزيادات محتاج صلاحية «إضافة كريدت».</div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function () {
            var p = document.getElementById('uvPkg'); if (!p) return;
            var s = document.getElementById('uvSrc'), w = document.getElementById('uvEgpW'), eg = document.getElementById('uvEgp');
            function src() { var paid = s.value === 'paid'; w.style.display = paid ? '' : 'none'; eg.required = paid; }
            p.addEventListener('change', function () {
                var o = p.options[p.selectedIndex]; if (!o || !o.value) return;
                document.getElementById('uvCr').value = o.dataset.cr; document.getElementById('uvDays').value = o.dataset.days; eg.value = o.dataset.egp;
            });
            s.addEventListener('change', src); src();
        })();
        </script>

<?php elseif ($tab === 'economics'): ?>
        <?php $pr30 = (float) ($cost30['rev'] ?? 0) - (float) ($cost30['egp'] ?? 0); ?>
        <div class="a2-grid a2-g4">
            <div class="a2-kpi"><small>تكلفة AI (30 يوم)</small><b><?= a2egp($cost30['egp'] ?? 0) ?></b><em><?= a2usd($cost30['usd'] ?? 0) ?> · سعر الدولار الحالي <?= a2n($fxNow, 2) ?></em></div>
            <div class="a2-kpi"><small>إيراد الكريدت المستهلك</small><b><?= a2egp($cost30['rev'] ?? 0) ?></b><em><?= a2n($cost30['cr'] ?? 0) ?> كريدت</em></div>
            <div class="a2-kpi"><small>الفرق (30 يوم)</small><b style="color:<?= $pr30 >= 0 ? '#0B8F83' : '#C2362E' ?>"><?= a2egp($pr30) ?></b><em><?= ($cost30['rev'] ?? 0) > 0 ? 'هامش ' . a2n($pr30 / (float) $cost30['rev'] * 100, 0) . '%' : '—' ?></em></div>
            <div class="a2-kpi"><small>من أول يوم</small><b><?= a2egp($lifePaid, 0) ?></b><em>مدفوع · تكلفة <?= a2egp($lifeCost, 0) ?> · إيراد مستهلك <?= a2egp($lifeRev, 0) ?></em></div>
        </div>
        <div class="a2-grid a2-g2" style="margin-top:16px">
            <div class="card" style="padding:0">
                <div class="a2-h" style="padding:16px 18px 0"><h3>حسب المهمة (30 يوم)</h3><a class="btn sm ghost" href="<?= url('admin/ai-runs.php?p=90d&user=' . $id) ?>">سجل العمليات</a></div>
                <?php if (!$byTask): ?><div class="a2-empty">مفيش عمليات AI في آخر 30 يوم</div><?php else: ?>
                <div class="a2-tw" style="border:0"><table class="a2-tbl">
                    <thead><tr><th>المهمة</th><th>عمليات</th><th>فشل/بديل</th><th>التكلفة</th><th>كريدت</th><th>الإيراد</th><th>الفرق</th></tr></thead>
                    <tbody>
                    <?php foreach ($byTask as $bt): $d = (float) $bt['rev'] - (float) $bt['egp']; ?>
                        <tr>
                            <td><?= e($taskLbl[$bt['task']] ?? $bt['task']) ?></td>
                            <td><?= a2n($bt['runs']) ?></td>
                            <td class="a2-muted"><?= a2n($bt['failed']) ?> / <?= a2n($bt['fo']) ?></td>
                            <td><?= a2egp($bt['egp']) ?></td>
                            <td><?= a2n($bt['cr']) ?></td>
                            <td><?= a2egp($bt['rev']) ?></td>
                            <td style="color:<?= $d >= 0 ? '#0B8F83' : '#C2362E' ?>;font-weight:700"><?= a2egp($d) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
            <div class="card" style="padding:0">
                <div class="a2-h" style="padding:16px 18px 0"><h3>الكريدت حسب المصدر</h3></div>
                <?php if (!$lots): ?><div class="a2-empty">مفيش دفعات كريدت مسجّلة</div><?php else: ?>
                <div class="a2-tw" style="border:0"><table class="a2-tbl">
                    <thead><tr><th>المصدر</th><th>اتضاف</th><th>متبقي</th><th>متجمّد/منتهي</th><th>قيمته</th></tr></thead>
                    <tbody>
                    <?php foreach ($lots as $l): ?>
                        <tr>
                            <td><?= e($srcLbl[$l['source']] ?? $l['source']) ?> <span class="a2-muted">×<?= (int) $l['n'] ?></span></td>
                            <td><?= a2n($l['credits']) ?></td>
                            <td><b><?= a2n($l['remaining']) ?></b></td>
                            <td class="a2-muted"><?= a2n($l['frozen']) ?> / <?= a2n($l['forfeited']) ?></td>
                            <td><?= (float) $l['paid_egp'] > 0 ? a2egp($l['paid_egp'], 0) : '<span class="a2-muted">0</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
                <div class="a2-note" style="margin:0 18px 16px">الإيراد = الكريدت المستهلك × سعر الكريدت اللي اتدفع فعلًا (الهدايا والعروض إيرادها صفر). التكلفة محسوبة بسعر الدولار وقت العملية.</div>
            </div>
        </div>

<?php elseif ($tab === 'content'): ?>
        <?php if ($usage): ?>
        <div class="card" style="margin-bottom:16px">
            <div class="a2-h"><h3>استخدام الأشكال</h3></div>
            <div class="a2-grid a2-g4" style="gap:10px">
                <?php foreach (content_formats() as $fk => $fm): if (empty($usage[$fk])) continue; $u = $usage[$fk]; ?>
                    <div class="c360-fmt">
                        <b><?= $fm['emoji'] ?> <?= strtoupper($fm['en']) ?></b>
                        <div><?= (int) $u['n'] ?> <?= e($fm['label']) ?></div>
                        <?php if ($fk === 'carousel'): ?>
                            <div><?= (int) ($u['slides'] ?? 0) ?> شريحة · <?= (int) ($u['done_slides'] ?? 0) ?> متصممة</div>
                            <div style="color:#0A7A5C">● <?= (int) ($u['complete'] ?? 0) ?> مكتمل</div>
                        <?php elseif ($fk === 'video'): ?>
                            <?php foreach ($u['st'] ?? [] as $sk => $sn): ?><div>● <?= e(video_status_meta($sk)['label']) ?>: <?= (int) $sn ?></div><?php endforeach; ?>
                        <?php else: ?>
                            <div style="color:#0A7A5C">● <?= (int) ($u['complete'] ?? 0) ?> مكتمل</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="card" style="padding:0">
            <div class="a2-h" style="padding:16px 18px 0"><h3>أحدث المحتوى</h3><span class="a2-muted">آخر 20 من <?= a2n($contentsCount) ?></span></div>
            <?php if (!$contents): ?><div class="a2-empty">لسه ماعملش محتوى</div><?php else: ?>
            <div class="a2-tw" style="border:0"><table class="a2-tbl">
                <thead><tr><th>#</th><th>النوع</th><th>الشكل / التصميم</th><th>المنصة</th><th>الكريدت</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($contents as $c): $pg = $contentProg[(int) $c['id']]; ?>
                    <tr>
                        <td>#<?= (int) $c['id'] ?></td>
                        <td><span class="chip chip-primary"><?= e(content_type_label($c['content_type'])) ?></span></td>
                        <td style="font-size:12px;line-height:1.5"><b><?= strtoupper(content_formats()[$pg['format']]['en']) ?></b><br><span style="color:<?= $pg['complete'] ? '#0A7A5C' : '#8391A6' ?>">● <?= e($pg['label']) ?></span></td>
                        <td><span class="chip chip-line"><?= e(platform_label($c['platform'])) ?></span></td>
                        <td><?= (int) $c['credits_used'] ?></td>
                        <td><span class="chip <?= e(status_chip($c['status'])) ?>"><?= e(status_label($c['status'])) ?></span></td>
                        <td class="a2-muted"><?= e(time_ago($c['created_at'])) ?></td>
                        <td><a href="<?= url('admin/content-view.php?id=' . (int) $c['id']) ?>" class="btn ghost sm">عرض</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>

<?php elseif ($tab === 'credits'): ?>
        <div class="a2-grid a2-side">
            <div class="card" style="padding:0">
                <div class="a2-h" style="padding:16px 18px 0"><h3>دفتر الكريدت</h3><span class="a2-muted">آخر 60 حركة<?= !empty($wallet['expires_at']) ? ' · الصلاحية لحد ' . e(fmt_date(substr($wallet['expires_at'], 0, 10))) : '' ?></span></div>
                <?php if (!$ledger): ?><div class="a2-empty">مفيش حركات</div><?php else: ?>
                <div class="a2-tw" style="border:0"><table class="a2-tbl">
                    <thead><tr><th>الحركة</th><th>المصدر</th><th>البيان</th><th>الرصيد بعدها</th><?= admin_can('view_costs') ? '<th>الإيراد</th>' : '' ?><th>الوقت</th></tr></thead>
                    <tbody>
                    <?php foreach ($ledger as $t): $plus = $t['action_type'] === 'add'; ?>
                        <tr>
                            <td><b style="color:<?= $plus ? '#0B8F83' : '#C2362E' ?>"><?= $plus ? '+' : '−' ?><?= a2n($t['amount']) ?></b></td>
                            <td class="a2-muted"><?= e(($t['reference_type'] ?? '') === 'refund' ? '↩ استرجاع' : ($srcLbl[$t['source'] ?? ''] ?? ($t['action_type'] === 'consume' ? 'استهلاك' : ($t['action_type'] ?? '')))) ?></td>
                            <td style="max-width:280px"><?= e((string) ($t['notes'] ?? '')) ?></td>
                            <td><?= $t['balance_after'] !== null ? a2n($t['balance_after']) : '—' ?></td>
                            <?php if (admin_can('view_costs')): ?><td class="a2-muted"><?= (float) ($t['revenue_egp'] ?? 0) != 0 ? a2egp($t['revenue_egp']) : '—' ?></td><?php endif; ?>
                            <td class="a2-muted" style="white-space:nowrap"><?= e(time_ago($t['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
            <div class="a2-grid" style="gap:16px">
                <?php if (admin_can('add_credits')): ?>
                <form method="POST" class="card">
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_credits">
                    <div class="a2-h"><h3>إضافة كريدت</h3></div>
                    <p class="a2-muted" style="margin-top:0">إضافة من غير ما تبدأ دورة جديدة — لو بيشتري باقة استخدم «تفعيل باقة».</p>
                    <div class="a2-grid" style="gap:10px">
                        <div class="a2-grid a2-g2" style="gap:10px">
                            <div><label class="a2-lbl">الكريدت</label><input type="number" name="amount" class="input" min="1" required style="width:100%"></div>
                            <div><label class="a2-lbl">الصلاحية (يوم)</label><input type="number" name="validity_days" class="input" min="1" max="3650" value="<?= (int) get_setting('default_credit_validity_days', 30) ?>" style="width:100%"></div>
                        </div>
                        <div class="a2-grid a2-g2" style="gap:10px">
                            <div><label class="a2-lbl">المصدر</label>
                                <select name="source" class="input" style="width:100%" onchange="this.form.amount_egp.parentNode.style.display=this.value==='paid'?'':'none';this.form.amount_egp.required=this.value==='paid'">
                                    <option value="bonus">🎁 هدية (إيراد صفر)</option><option value="paid">💳 مدفوع</option><option value="compensation">🛠 تعويض</option><option value="promo">🎟 عرض</option>
                                </select></div>
                            <div style="display:none"><label class="a2-lbl">المبلغ (ج.م)</label><input type="number" name="amount_egp" class="input" min="0" step="0.01" style="width:100%"></div>
                        </div>
                        <div><label class="a2-lbl">ملاحظة</label><input type="text" name="note" class="input" style="width:100%"></div>
                        <div><button class="btn">+ إضافة</button></div>
                    </div>
                </form>
                <form method="POST" class="card" onsubmit="return confirm('متأكد من الخصم؟')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="deduct_credits">
                    <div class="a2-h"><h3>خصم كريدت</h3></div>
                    <div class="a2-grid" style="gap:10px">
                        <div><label class="a2-lbl">الكريدت</label><input type="number" name="amount" class="input" min="1" required style="width:100%"></div>
                        <div><label class="a2-lbl">السبب</label><input type="text" name="note" class="input" style="width:100%"></div>
                        <div><button class="btn danger">− خصم</button></div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

<?php elseif ($tab === 'account'): ?>
        <?php
        $__2fa = !empty($user['two_fa_enabled']);
        $__del = $user['deletion_requested_at'] ?? null;
        $__sess = count(array_filter($sessions, fn($s) => empty($s['revoked_at']) && strtotime((string) ($s['last_seen_at'] ?? $s['created_at'])) > time() - 30 * 86400));
        ?>
        <div class="a2-grid a2-g2">
            <div class="a2-grid" style="gap:16px">
                <div class="card">
                    <div class="a2-h"><h3>الحساب</h3></div>
                    <div class="a2-kv"><span>الحالة</span><b><?= $user['status'] === 'active' ? 'نشط' : 'موقوف' ?></b></div>
                    <div class="a2-kv"><span>الإيميل</span><b><?= !empty($user['email_verified_at']) ? '✓ متفعّل' : '⚠ مش متفعّل' ?></b></div>
                    <div class="a2-kv"><span>التحقق بخطوتين</span><b><?= $__2fa ? 'مفعّل' : 'مش مفعّل' ?></b></div>
                    <div class="a2-kv"><span>جلسات نشطة (30 يوم)</span><b><?= (int) $__sess ?></b></div>
                    <?php if ($__del): ?><div class="a2-note" style="margin-top:10px;color:#C2362E">⚠ طلب حذف الحساب يوم <?= e(fmt_date($__del)) ?> — هيتمسح نهائيًا بعد 14 يوم</div><?php endif; ?>
                    <div class="a2-row" style="gap:8px;margin-top:12px">
                        <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="verify_email">
                            <button class="btn sm <?= empty($user['email_verified_at']) ? '' : 'ghost' ?>" onclick="return confirm('<?= empty($user['email_verified_at']) ? 'تفعيل البريد يدويًا؟' : 'إلغاء تفعيل البريد؟ العميل مش هيقدر يدخل لحد ما يفعّل تاني.' ?>')"><?= empty($user['email_verified_at']) ? '✓ تفعيل البريد يدويًا' : '⊘ إلغاء تفعيل البريد' ?></button></form>
                        <?php if (empty($user['email_verified_at'])): ?>
                        <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="resend_verification"><button class="btn sm ghost" onclick="return confirm('إعادة إرسال رابط التفعيل؟')">✉ إعادة إرسال الرابط</button></form>
                        <?php endif; ?>
                        <?php if (admin_can('approve_users')): ?>
                        <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_active">
                            <button class="btn sm <?= $user['status'] === 'active' ? 'danger' : '' ?>" onclick="return confirm('<?= $user['status'] === 'active' ? 'تعطيل الحساب؟' : 'تفعيل الحساب؟' ?>')"><?= $user['status'] === 'active' ? '✕ تعطيل الحساب' : '✓ تفعيل الحساب' ?></button></form>
                        <?php foreach (array_filter([
                            $__2fa ? ['twofa_off', 'إيقاف التحقق بخطوتين', 'العميل مش بيستلم الكود؟ هيدخل بالباسورد بس'] : null,
                            $__sess ? ['end_sessions', 'إنهاء كل الجلسات', 'العميل هيخرج من كل الأجهزة'] : null,
                            $__del ? ['cancel_delete', 'إلغاء طلب الحذف', 'إلغاء طلب حذف الحساب؟'] : null,
                        ]) as [$__op, $__lbl, $__cf]): ?>
                            <form method="POST" style="margin:0" onsubmit="return confirm('<?= e($__cf) ?>')"><?= csrf_field() ?><input type="hidden" name="action" value="security"><input type="hidden" name="op" value="<?= $__op ?>"><button class="btn ghost sm"><?= e($__lbl) ?></button></form>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card">
                    <div class="a2-h"><h3>هوية البراند</h3><a href="<?= url('admin/user-brand.php?user_id=' . $id) ?>" class="btn sm ghost">✎ تعديل</a></div>
                    <?php if ($brand && !empty($brand['business_name'])): ?>
                        <div class="a2-kv"><span>الاسم</span><b><?= e($brand['business_name']) ?></b></div>
                        <div class="a2-kv"><span>المجال</span><b><?= e($brand['industry'] ?? '—') ?></b></div>
                        <?php if (!empty($brand['description'])): ?><div class="a2-muted" style="margin-top:8px;line-height:1.8"><?= e(mb_strimwidth((string) $brand['description'], 0, 320, '…')) ?></div><?php endif; ?>
                    <?php else: ?><div class="a2-muted">لسه ماكملش ملف الهوية</div><?php endif; ?>
                </div>
                <div class="card" style="padding:0">
                    <div class="a2-h" style="padding:16px 18px 0"><h3>صفحات السوشيال</h3></div>
                    <?php if (!$socials): ?><div class="a2-empty">مفيش صفحات مربوطة</div><?php else: ?>
                    <div class="a2-tw" style="border:0"><table class="a2-tbl"><tbody>
                        <?php foreach ($socials as $so): $okS = $so['status'] === 'active' && (!$so['expires_at'] || strtotime($so['expires_at']) > time()); ?>
                        <tr><td><?= e(platform_label($so['platform'])) ?></td><td><?= e($so['page_name'] ?: ($so['ig_username'] ? '@' . $so['ig_username'] : '—')) ?></td>
                            <td><span class="chip <?= $okS ? 'chip-mint' : 'chip-coral' ?>" title="<?= e((string) $so['last_error']) ?>"><?= $okS ? 'شغال' : 'محتاج ربط' ?></span></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="a2-grid" style="gap:16px">
                <div class="card" style="padding:0">
                    <div class="a2-h" style="padding:16px 18px 0"><h3>الأجهزة والجلسات</h3></div>
                    <?php if (!$sessions): ?><div class="a2-empty">مفيش جلسات مسجّلة</div><?php else: ?>
                    <div class="a2-tw" style="border:0"><table class="a2-tbl"><tbody>
                        <?php foreach ($sessions as $s): ?>
                        <tr style="<?= $s['revoked_at'] ? 'opacity:.5' : '' ?>">
                            <td style="max-width:260px;font-size:12px"><?= e(mb_strimwidth((string) $s['user_agent'], 0, 70, '…')) ?></td>
                            <td class="a2-muted" dir="ltr"><?= e((string) $s['ip']) ?></td>
                            <td class="a2-muted"><?= e(time_ago($s['last_seen_at'] ?: $s['created_at'])) ?><?= $s['revoked_at'] ? ' · منتهية' : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
                <div class="card" style="padding:0">
                    <div class="a2-h" style="padding:16px 18px 0"><h3>دخول الإدارة بحسابه</h3></div>
                    <?php if (!$imps): ?><div class="a2-empty">محدش من الإدارة دخل بحسابه</div><?php else: ?>
                    <div class="a2-tw" style="border:0"><table class="a2-tbl"><tbody>
                        <?php foreach ($imps as $im): ?>
                        <tr><td><?= e($im['admin_name'] ?? ('#' . $im['admin_id'])) ?></td>
                            <td class="a2-muted"><?= e(fmt_date($im['started_at'])) ?> · <?= e(substr((string) $im['started_at'], 11, 5)) ?></td>
                            <td class="a2-muted"><?= $im['ended_at'] ? 'مدة ' . max(1, (int) round((strtotime($im['ended_at']) - strtotime($im['started_at'])) / 60)) . ' د' : 'مفتوحة' ?></td>
                            <td class="a2-muted" dir="ltr"><?= e((string) $im['ip']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
<?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
