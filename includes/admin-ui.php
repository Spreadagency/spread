<?php
/**
 * Spread AI — لوحة الأدمن (المرحلة 8): القائمة المجمّعة · الأيقونات · حالة النظام
 * تصميم PlatformAdmin — الأقسام زي التصميم، والروابط على الصفحات الموجودة فعلًا.
 */

require_once __DIR__ . '/usage.php';

/** أيقونات (نفس مسارات التصميم) */
function admin_icon(string $name, int $size = 19): string
{
    static $IC = [
        'home' => 'M3 11l9-7 9 7M5 10v10h14V10M10 20v-6h4v6',
        'users' => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6',
        'user' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
        'key' => 'M15 7a4 4 0 1 0-3.9 5L4 19v2h3v-2h2v-2h2l2.9-2.9A4 4 0 0 0 15 7z',
        'wallet' => 'M3 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H3zM3 7V5h13M16 13h.01',
        'pen' => 'M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4',
        'check' => 'M4 12l5 5L20 6',
        'palette' => 'M12 3a9 9 0 0 0 0 18c1.5 0 2-1 2-2s-1-2 0-3 3 0 4-1 2-2 2-4a9 9 0 0 0-8-8zM7.5 11h.01M10 7.5h.01M15 7.5h.01',
        'image' => 'M3 5h18v14H3zM8 10.5a1.5 1.5 0 1 0 0-.01M21 15l-5-5-9 9',
        'layout' => 'M4 4h16v16H4zM4 9h16M10 9v11',
        'spark' => 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z',
        'video' => 'M3 6h13v12H3zM16 10l5-3v10l-5-3',
        'drop' => 'M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z',
        'plug' => 'M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-12 0zM12 17v4',
        'cpu' => 'M7 7h10v10H7zM9 3v4M15 3v4M9 17v4M15 17v4M3 9h4M3 15h4M17 9h4M17 15h4',
        'text' => 'M4 6h16M4 12h10M4 18h14',
        'list' => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
        'coin' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM9 9.5c0-1 1.3-2 3-2s3 1 3 2-1 1.6-3 2-3 1-3 2.2 1.3 2 3 2 3-1 3-2M12 5.5v2M12 16.5v2',
        'link' => 'M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1',
        'card' => 'M3 6h18v12H3zM3 10h18M7 15h4',
        'diamond' => 'M12 3l8 9-8 9-8-9z',
        'gift' => 'M3 8h18v4H3zM5 12v9h14v-9M12 8v13M12 8c-2-4-6-4-6-1s6 1 6 1 6 2 6-1-4-3-6 1z',
        'share' => 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 4M15.4 6.5l-6.8 4',
        'chart' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'gauge' => 'M12 14l4-4M3 14a9 9 0 0 1 18 0M5 18h14',
        'download' => 'M12 4v12M6 10l6 6 6-6M4 20h16',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
        'lock' => 'M5 10h14v10H5zM8 10V7a4 4 0 0 1 8 0v3',
        'bell' => 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0',
        'gear' => 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19 12l2-1-1-3-2 .3-1.5-1.5L17 5l-3-1-1 2h-2L10 4 7 5l.5 1.8L6 8.3 4 8 3 11l2 1-2 1 1 3 2-.3 1.5 1.5L7 19l3 1 1-2h2l1 2 3-1-.5-1.8 1.5-1.5 2 .3 1-3z',
        'tool' => 'M14 6a4 4 0 0 0 5 5L11 19a2 2 0 0 1-3-3l8-8a4 4 0 0 1-2-2z',
        'megaphone' => 'M3 11v2a1 1 0 0 0 1 1h3l6 4V6L7 10H4a1 1 0 0 0-1 1zM17 8a5 5 0 0 1 0 8',
        'sliders' => 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M14 4v4M8 10v4M16 16v4',
        'toggle' => 'M7 7h10a5 5 0 0 1 0 10H7A5 5 0 0 1 7 7zM16 12a2 2 0 1 0 0-.01',
        'db' => 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
        'search' => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-3.5-3.5',
        'out' => 'M15 12H4M11 8l-4 4 4 4M14 4h5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5',
        'ext' => 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
        'chev' => 'M9 6l6 6-6 6',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'flask' => 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3',
        'send' => 'M21 3L10 14M21 3l-7 18-4-7-7-4z',
        'refresh' => 'M4 12a8 8 0 0 1 14-5.3L20 9M20 4v5h-5M20 12a8 8 0 0 1-14 5.3L4 15M4 20v-5h5',
        'plus' => 'M12 5v14M5 12h14',
        'trash' => 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
        'warn' => 'M12 3l10 18H2zM12 10v4M12 17h.01',
    ];
    $d = $IC[$name] ?? $IC['spark'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/**
 * القائمة: [group => [[key, label, en, icon, url, perm, actives[], count_fn], …]]
 * actives = قيم $active في الصفحات القديمة اللي بتعلّم العنصر ده
 */
function admin_nav(): array
{
    return [
        '' => [
            ['dashboard', 'لوحة التشغيل', 'Dashboard', 'home', 'admin/dashboard.php', null, ['dashboard']],
        ],
        'CUSTOMERS' => [
            ['users', 'العملاء', 'Customers', 'users', 'admin/users.php', 'view_users', ['users']],
            ['approvals', 'الموافقات', 'Approvals', 'check', 'admin/approvals.php', 'approve_users', ['approvals'], 'approvals'],
            ['credits', 'الـ Credits والمحافظ', 'Credits & Wallets', 'wallet', 'admin/credits.php', 'add_credits', ['credits']],
            ['referrals', 'الإحالات والأفلييت', 'Referrals', 'share', 'admin/referrals.php', 'manage_packages', ['referrals']],
        ],
        'CONTENT' => [
            ['content', 'المحتوى', 'Content', 'pen', 'admin/content-history.php', 'view_content', ['content']],
            ['gallery', 'التصميمات المُنتجة', 'Generated Designs', 'image', 'admin/designs-gallery.php', 'view_content', ['gallery']],
            ['video-requests', 'طلبات الفيديو', 'Video Requests', 'video', 'admin/video-requests.php', 'view_content', ['video-requests'], 'video'],
            ['studio', 'مكتبة التصميمات', 'Design Library', 'palette', 'admin/media-library.php', 'studio_media', ['studio']],
        ],
        'DESIGN' => [
            ['studio-settings', 'إعدادات التصميم', 'Design Settings', 'spark', 'admin/studio-settings.php', 'site_settings', ['studio-settings']],
            ['templates', 'القوالب', 'Templates', 'layout', 'admin/templates.php', 'view_content', ['templates']],
            ['watermark', 'العلامة المائية', 'Watermark', 'drop', 'admin/watermark.php', 'site_settings', ['watermark']],
        ],
        'AI CENTER' => [
            ['ai-providers', 'مزودين الـ AI', 'AI Providers', 'plug', 'admin/ai-providers.php', 'ai_settings', ['ai-providers', 'ai-connection', 'ai']],
            ['ai-router', 'الموديلات والـ Router', 'Models & Router', 'cpu', 'admin/ai-router.php', 'ai_settings', ['ai-router']],
            ['prompts', 'مكتبة البرومبتات', 'Prompt Library', 'text', 'admin/prompts.php', 'ai_settings', ['prompts', 'type-prompts']],
            ['ai-logs', 'سجل طلبات الـ AI', 'AI Request Logs', 'list', 'admin/ai-runs.php', 'view_costs', ['ai-logs', 'ai-runs']],
            ['ai-costs', 'تكاليف الـ AI', 'AI Costs', 'coin', 'admin/ai-costs.php', 'view_costs', ['ai-costs']],
        ],
        'PUBLISHING' => [
            ['social-connections', 'حسابات السوشيال', 'Social Accounts', 'link', 'admin/social-connections.php', 'view_users', ['social-connections']],
        ],
        'COMMERCIAL' => [
            ['payments', 'المدفوعات', 'Payments', 'card', 'admin/payments.php', 'view_users', ['payments'], 'payments'],
            ['packages', 'الباقات والأسعار', 'Plans & Pricing', 'card', 'admin/packages.php', 'manage_packages', ['packages']],
            ['pricing', 'تسعير العمليات', 'Credits Pricing', 'diamond', 'admin/pricing.php', 'manage_packages', ['pricing']],
            ['offers', 'العروض', 'Offers', 'gift', 'admin/offers.php', 'manage_packages', ['offers']],
        ],
        'REPORTS' => [
            ['users-export', 'تصدير العملاء', 'Customer Export', 'download', 'admin/users-export.php', 'manage_packages', ['users-export']],
            ['logs', 'سجل النشاط', 'Activity Logs', 'list', 'admin/logs.php', 'view_users', ['logs']],
        ],
        'SYSTEM' => [
            ['admins', 'الفريق والصلاحيات', 'Team & Roles', 'shield', 'admin/admins.php', 'manage_admins', ['admins']],
            ['alerts', 'الإشعارات والتنبيهات', 'Notifications', 'bell', 'admin/alerts.php', 'view_costs', ['alerts'], 'alerts'],
            ['announcements', 'الإعلانات', 'Announcements', 'megaphone', 'admin/announcements.php', 'site_settings', ['announcements']],
            ['features', 'المزايا والنشر', 'Features', 'toggle', 'admin/features.php', 'features', ['features']],
            ['appearance', 'المظهر', 'Appearance', 'sliders', 'admin/appearance.php', 'site_settings', ['appearance']],
            ['settings', 'مركز الإعدادات', 'Settings Center', 'gear', 'admin/settings.php', 'site_settings', ['settings', 'integrations', 'google-auth']],
            ['migrations', 'الترحيلات', 'Migrations', 'db', 'admin/migrations.php', 'site_settings', ['migrations']],
            ['ui-diagnose', 'فحص التحديث', 'Update Check', 'tool', 'admin/ui-diagnose.php', 'site_settings', ['ui-diagnose']],
        ],
    ];
}

/** عداد جنب العنصر */
function admin_nav_count(string $kind): int
{
    try {
        if ($kind === 'video') {
            return (int) (db_one('SELECT COUNT(*) n FROM contents WHERE format = "video" AND video_status IN ("sent","in_production")')['n'] ?? 0);
        }
        if ($kind === 'approvals') {
            return (int) (db_one('SELECT COUNT(*) n FROM users WHERE approval_status = "pending"')['n'] ?? 0);
        }
        if ($kind === 'payments') {
            return (int) (db_one('SELECT COUNT(*) n FROM payment_requests WHERE status IN ("pending","under_review")')['n'] ?? 0);
        }
        if ($kind === 'alerts') {
            return admin_alerts_open_count();
        }
    } catch (\Throwable $e) {
    }
    return 0;
}

/** العناصر المسموحة للأدمن الحالي (للبحث والقائمة) */
function admin_nav_allowed(): array
{
    $out = [];
    foreach (admin_nav() as $g => $items) {
        foreach ($items as $it) {
            if ($it[5] !== null && function_exists('admin_can') && !admin_can($it[5])) continue;
            $out[$g][] = $it;
        }
    }
    return $out;
}

/** حالة النظام للشريط العلوي: [label, level ok|warn|bad] */
function admin_system_status(): array
{
    try {
        $crit = (int) (db_one('SELECT COUNT(*) n FROM admin_alerts WHERE read_at IS NULL AND level = "critical"')['n'] ?? 0);
        $open = (int) (db_one('SELECT COUNT(*) n FROM ai_provider_state WHERE state IN ("open","half") AND (opened_until IS NULL OR opened_until > NOW())')['n'] ?? 0);
        if ($crit > 0) return ['محتاج تدخل', 'bad'];
        if ($open > 0) return ['شغال ببديل', 'warn'];
    } catch (\Throwable $e) {
    }
    return ['النظام شغال', 'ok'];
}

/** رقم منسّق */
function a2n($n, int $dec = 0): string
{
    return number_format((float) $n, $dec, '.', ',');
}

/** مبلغ بالجنيه */
function a2egp($n, int $dec = 2): string
{
    return a2n($n, $dec) . ' ج.م';
}

/** مبلغ بالدولار */
function a2usd($n, int $dec = 4): string
{
    return '$' . a2n($n, $dec);
}

/** Sparkline SVG من قيم */
function a2_spark(array $vals, string $color = '#0C87EF'): string
{
    $n = count($vals);
    if ($n < 2) $vals = array_pad($vals, 2, 0);
    $n = count($vals);
    $max = max($vals) ?: 1;
    $min = min($vals);
    $rng = ($max - $min) ?: 1;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i * 100 / ($n - 1), 2);
        $y = round(22 - (($v - $min) / $rng) * 18, 2);
        $pts[] = "$x,$y";
    }
    return '<svg class="a2-spark" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="' . $color
        . '" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/></svg>';
}
