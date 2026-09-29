<?php
/**
 * Spread AI v2 — أدوات الإطار الجديد (المرحلة 3 من إعادة التصميم: الإطار)
 *  • أيقونات التصميم (نفس الأسلوب: خط 1.8 على 24×24)
 *  • الأقسام السبعة والصفحات المدمجة تحتها
 *  • نسبة الاستخدام (Usage) ومحتوى جرس الإشعارات
 */

// الجرس بيحسب «محتاج تعديل» من الحالات الموحّدة — لازم تكون محمّلة في كل صفحة
if (!function_exists('content_status_sql') && is_file(__DIR__ . '/lifecycle.php')) {
    require_once __DIR__ . '/lifecycle.php';
}

/* ═══════════ الأيقونات ═══════════ */
function ui_icons(): array
{
    return [
        // من ملفات التصميم مباشرة
        'home'      => '<path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'folder'    => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'brain'     => '<circle cx="12" cy="12" r="9"/><path d="M12 3a15 15 0 0 1 0 18"/><path d="M12 3a15 15 0 0 0 0 18"/><path d="M3 12h18"/>',
        'plus'      => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'studio'    => '<rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>',
        'star'      => '<path d="M12 2l3 6.3 7 1-5 4.8 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.8 7-1z"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'bell'      => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'help'      => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.3 2.4c-.7.3-1.3.9-1.3 1.7v.4"/><path d="M12 17h.01"/>',
        'megaphone' => '<path d="M3 11l18-8v18l-18-8z"/><path d="M7 13v5a2 2 0 0 0 4 0v-3"/>',
        'send'      => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>',
        'trend'     => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'lock'      => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        // بنفس الأسلوب (مش موجودة في التصميم بشكل منفصل)
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3"/><path d="M12 19v3"/><path d="M4.9 4.9l2.1 2.1"/><path d="M17 17l2.1 2.1"/><path d="M2 12h3"/><path d="M19 12h3"/><path d="M4.9 19.1L7 17"/><path d="M17 7l2.1-2.1"/>',
        'bulb'      => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.3h6c0-1 .4-1.8 1-2.3A7 7 0 0 0 12 2z"/>',
        'doc'       => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'coin'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10"/><path d="M15 9.5c0-1.4-1.3-2.5-3-2.5s-3 1.1-3 2.5 1.3 2 3 2.5 3 1.1 3 2.5-1.3 2.5-3 2.5-3-1.1-3-2.5"/>',
        'gift'      => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'check'     => '<path d="M20 6L9 17l-5-5"/>',
        'shield'    => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'camera'    => '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
        'sparkles'  => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 17l.8 2.2L22 20l-2.2.8L19 23l-.8-2.2L16 20l2.2-.8z"/>',
        'chevron'   => '<path d="M15 18l-6-6 6-6"/>',
        'x'         => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'alert'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'chat'      => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 21l1.9-5.4A8 8 0 1 1 21 12z"/>',
        'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'phone'     => '<rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="M11 18h2"/>',
        'eye'       => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'file'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        // ⑦-ب البحث العميق
        'research'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/><path d="M11 8v6"/><path d="M8 11h6"/>',
        'clock'     => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        'bookmark'  => '<path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1z"/>',
        'arrow'     => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
    ];
}

/** أيقونة SVG جاهزة للطباعة */
function ui_icon(string $name, int $size = 20, string $class = ''): string
{
    $p = ui_icons()[$name] ?? ui_icons()['star'];
    return '<svg class="ui-ic ' . htmlspecialchars($class, ENT_QUOTES) . '" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/* ═══════════ الأقسام السبعة + الدمج ═══════════ */

/**
 * key => [label, icon, url, [active keys], [sub tabs: [label, url, active key, menu flag]]]
 */
function ui_sections(): array
{
    return [
        'dashboard' => ['الرئيسية', 'home', 'dashboard.php', ['dashboard'], []],
        'campaigns' => ['حملاتي', 'calendar', 'campaigns.php', ['campaigns', 'plan'], [
            ['حملاتي', 'campaigns.php', 'campaigns', null],
            ['خطة المحتوى', 'content-plan.php', 'plan', 'content-plan'],
        ]],
        'contents' => ['المحتويات', 'folder', 'content-history.php', ['history', 'create'], [
            ['كل المحتويات', 'content-history.php', 'history', 'content-history'],
            ['منشور سريع', 'create-content.php', 'create', 'create-content'],
        ]],
        'studio' => ['Design Studio', 'studio', 'design-studio.php', ['design-studio', 'studio'], [
            ['استوديو التصميم', 'design-studio.php', 'design-studio', 'design-studio'],
            ['معرض الإلهام', 'studio.php', 'studio', 'studio'],
        ]],
        'analytics' => ['التحليلات', 'chart', 'analytics.php', ['analytics'], []],
        // ⑦-ب البحث العميق: صفحة مستقلة زي ما هي — بس جوه Brand Brain (بيقرا الهوية وبيسجّل فيها)
        'brand' => ['Brand Brain · الهوية', 'brain', 'brand-brain.php', ['brand-brain', 'brand', 'brand-agent', 'sources', 'research'], [
            ['Brand Brain', 'brand-brain.php', 'brand-brain', null],
            ['البحث العميق', 'research.php', 'research', 'research'],
            ['المساعد الذكي', 'brand-agent.php', 'brand-agent', 'brand-agent'],
            ['المستندات', 'sources.php', 'sources', 'sources'],
        ]],
        // ⑦ رحلتك الأولى — بتختفي من القائمة لما تخلص (السايدبار)
        'journey' => ['رحلتك الأولى', 'star', 'journey.php', ['journey'], []],
        'settings' => ['الإعدادات', 'settings', 'profile.php', ['profile', 'credits', 'referrals', 'social-accounts', 'help'], [
            ['حسابي', 'profile.php', 'profile', null],
            ['الرصيد', 'credits.php', 'credits', 'credits'],
            [(function_exists('credits_show_numbers') && !credits_show_numbers()) ? 'ادعُ واكسب' : 'اربح كريدت', 'referrals.php', 'referrals', 'referrals'],
            ['ربط السوشيال', 'social-accounts.php', 'social-accounts', 'social-accounts'],
            ['دليل المنصة', 'help.php', 'help', 'help'],
        ]],
    ];
}

/** البحث العميق متاح وظاهر في القائمة؟ */
function ui_research_on(): bool
{
    if (!function_exists('get_setting')) return true;
    if (get_setting('research_enabled', '1') !== '1') return false;
    return !function_exists('menu_visible') || menu_visible('research');
}

/** الحملات متاحة؟ (الأدمن يقدر يقفلها) */
function ui_campaigns_on(): bool
{
    return !function_exists('get_setting') || get_setting('campaigns_enabled', '1') === '1';
}

/** القسم اللي الصفحة الحالية تبعه */
function ui_section_of(string $active): ?string
{
    foreach (ui_sections() as $k => $s) {
        if (in_array($active, $s[3], true)) return $k;
    }
    return null;
}

/** التبويبات الظاهرة لقسم (بتحترم إخفاء عناصر القائمة من الأدمن) */
function ui_subtabs(string $section): array
{
    $s = ui_sections()[$section] ?? null;
    if (!$s) return [];
    return array_values(array_filter($s[4], fn($t) =>
        ($t[3] === null || !function_exists('menu_visible') || menu_visible($t[3]))
        && !($t[2] === 'campaigns' && !ui_campaigns_on())
        && !($t[2] === 'research' && !ui_research_on())));
}

/* ═══════════ نسبة الاستخدام ═══════════ */

/**
 * الاستخدام من آخر شحن: المستهلك ÷ (المستهلك + المتبقي)
 * @return array{used:int, total:int, balance:int, pct:int, expires_at:?string, days_left:?int}
 */
function ui_credits_usage(int $userId): array
{
    // 8-ب: الدورة الحالية للباقة (الحصص + النسبة) — العرض بالنسبة % لو credits_display = percent
    if (function_exists('plan_usage')) {
        $p = plan_usage($userId);
        return [
            'used' => $p['credits']['used'], 'total' => $p['credits']['total'], 'balance' => $p['credits']['balance'],
            'pct' => $p['pct'], 'expires_at' => $p['plan']['ends'], 'days_left' => $p['days_left'],
            'show' => credits_show_numbers(), 'plan' => $p['plan']['name'], 'units' => $p['units'], 'ends_label' => $p['ends_label'],
        ];
    }
    $balance = function_exists('credits_balance') ? credits_balance($userId) : 0;
    $last = db_one('SELECT MAX(created_at) t FROM credit_transactions WHERE user_id = ? AND action_type = "add"', [$userId]);
    $since = $last['t'] ?? '1970-01-01';
    $used = (int) (db_one('SELECT COALESCE(SUM(ABS(amount)),0) n FROM credit_transactions
                           WHERE user_id = ? AND action_type IN ("consume","deduct") AND created_at >= ?', [$userId, $since])['n'] ?? 0);
    $total = $used + $balance;
    $w = db_one('SELECT * FROM credit_wallets WHERE user_id = ?', [$userId]) ?: [];
    $exp = $w['expires_at'] ?? null;
    return [
        'used'       => $used,
        'total'      => $total,
        'balance'    => $balance,
        'pct'        => $total > 0 ? (int) round($used / $total * 100) : 0,
        'expires_at' => $exp,
        'days_left'  => $exp ? max(0, (int) ceil((strtotime($exp) - time()) / 86400)) : null,
    ];
}

/* ═══════════ جرس الإشعارات ═══════════ */

/**
 * ① الحاجات المحتاجة تعديل · ② الكريدت المتبقي · ③ إعلان سريع
 */
function ui_notifications(int $userId): array
{
    $items = [];

    // ⓪ طلب حذف الحساب شغّال (⑥-أ) — لازم يبان فوق كل حاجة علشان يلحق يلغيه
    try {
        $del = db_one('SELECT deletion_requested_at FROM users WHERE id = ?', [$userId])['deletion_requested_at'] ?? null;
        if ($del) {
            $when = strtotime($del) + (defined('ACCOUNT_DELETE_GRACE_DAYS') ? ACCOUNT_DELETE_GRACE_DAYS : 14) * 86400;
            $items[] = ['type' => 'action', 'icon' => 'alert', 'tone' => 'danger', 'url' => 'profile.php',
                        'title' => 'حسابك هيتمسح يوم ' . (function_exists('fmt_date') ? fmt_date(date('Y-m-d', $when)) : date('Y-m-d', $when)),
                        'sub' => 'غيّرت رأيك؟ ألغِ الطلب من الإعدادات'];
        }
    } catch (\Throwable $e) { /* قبل ترحيل ⑥-أ */ }

    // ① محتاج تعديل — من الحالات الموحّدة (المرحلة 1)
    if (function_exists('content_status_sql')) {
        try {
            $rows = db_all('SELECT ' . content_status_sql('c') . ' st, COUNT(*) n FROM contents c
                            WHERE c.user_id = ? GROUP BY st', [$userId]);
            $by = array_column($rows, 'n', 'st');
            $map = [
                'publish_failed' => ['فشل نشر', 'alert', 'danger', 'content-history.php'],
                'needs_review'   => ['محتاج مراجعة', 'edit', 'warn', 'content-history.php'],
                'needs_design'   => ['محتاج تصميم', 'image', 'info', 'content-history.php'],
            ];
            foreach ($map as $st => [$lbl, $ic, $tone, $url]) {
                $n = (int) ($by[$st] ?? 0);
                if ($n > 0) {
                    $items[] = ['type' => 'action', 'icon' => $ic, 'tone' => $tone, 'url' => $url,
                                'title' => $n . ' ' . ($n === 1 ? 'منشور' : 'منشورات') . ' ' . $lbl];
                }
            }
        } catch (\Throwable $e) { /* قبل ترحيلات المرحلة 1 */ }
    }

    // ①-ب الفيديو اللي فريقنا خلّصه (⑦-ج) — لحد ما العميل يأكد الاستلام
    try {
        $vr = db_all('SELECT id FROM contents WHERE user_id = ? AND format = "video" AND video_status = "ready" ORDER BY video_status_at DESC LIMIT 5', [$userId]);
        if ($vr) {
            $n = count($vr);
            $items[] = ['type' => 'action', 'icon' => 'camera', 'tone' => 'ok', 'url' => 'content-history.php?open=' . (int) $vr[0]['id'],
                        'title' => $n === 1 ? 'الفيديو بتاعك جاهز 🎬' : $n . ' فيديوهات جاهزة 🎬', 'sub' => 'افتحه وأكّد الاستلام'];
        }
    } catch (\Throwable $e) { /* قبل ترحيل ⑦-ج */ }

    // ② الكريدت
    $u = ui_credits_usage($userId);
    $show = $u['show'] ?? true;
    $low = ($show ? $u['balance'] <= 3 : $u['pct'] >= 85) || ($u['days_left'] !== null && $u['days_left'] <= 7);
    $items[] = [
        'type' => 'credits', 'icon' => 'coin', 'tone' => $low ? 'warn' : 'ok', 'url' => 'credits.php',
        'title' => $show ? ('باقي لك ' . $u['balance'] . ' كريدت') : ('استخدمت ' . $u['pct'] . '% من باقة الشهر'),
        'sub' => $u['days_left'] !== null ? (($show ? 'بتنتهي' : 'بتتجدد') . ' بعد ' . $u['days_left'] . ' يوم') : ('استخدمت ' . $u['pct'] . '%'),
    ];

    // ③ إعلان سريع (آخر إعلان شغال للعميل — بيحترم «لكل العملاء / لباقة معيّنة»)
    try {
        require_once __DIR__ . '/announcements.php';
        foreach (ann_slides_for_user($userId) as $a) {
            if (!empty($a['default'])) continue;
            $items[] = ['type' => 'announcement', 'icon' => 'megaphone', 'tone' => 'brand',
                        'url' => $a['url'], 'title' => $a['title'], 'sub' => mb_substr($a['body'], 0, 90)];
            break;
        }
    } catch (\Throwable $e) { /* مفيش جدول إعلانات */ }

    $badge = count(array_filter($items, fn($i) => $i['type'] === 'action')) + ($low ? 1 : 0);
    return ['items' => $items, 'badge' => $badge, 'usage' => $u];
}

/** تاريخ عربي زي التصميم: «الثلاثاء، 22 سبتمبر 2026» */
function ui_arabic_date(?int $ts = null): string
{
    $ts = $ts ?? time();
    $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    $months = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    return $days[(int) date('w', $ts)] . '، ' . (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** «منذ دقيقتين» · «أمس» — نفس صيغة spreadTimeAgo في JS */
function ui_time_ago(?string $dt): string
{
    if (!$dt) return '';
    $s = max(0, time() - strtotime($dt));
    if ($s < 60) return 'منذ لحظات';
    $m = intdiv($s, 60);
    if ($m < 60) return $m === 1 ? 'منذ دقيقة' : ($m === 2 ? 'منذ دقيقتين' : "منذ {$m} دقيقة");
    $h = intdiv($m, 60);
    if ($h < 24) return $h === 1 ? 'منذ ساعة' : ($h === 2 ? 'منذ ساعتين' : "منذ {$h} ساعات");
    $d = intdiv($h, 24);
    if ($d === 1) return 'أمس';
    if ($d < 30) return $d === 2 ? 'منذ يومين' : "منذ {$d} يوم";
    return date('Y/m/d', strtotime($dt));
}
