<?php
/**
 * /api/v1/app.php — بيانات شاشات التطبيق (قراءة من نفس دوال الموقع — مفيش منطق جديد للكريدت)
 *
 *   GET  action=bootstrap                 إعدادات التطبيق: الاختيارات · المقاسات · التكاليف · روابط الدعم والسياسات (من غير دخول)
 *   GET  action=dashboard                 الرئيسية (dashboard_v2_data)
 *   GET  action=notifications             الجرس (ui_notifications) + سجل إشعارات العميل
 *   POST action=notif_read   {id?}        قراءة إشعار (أو الكل)
 *   GET  action=credits                   الرصيد · الباقة · الحصص · سجل الحركات
 *   GET  action=packages                  الباقات المتاحة (من غير أسعار لو الشراء من التطبيق مقفول)
 *   POST action=web_handoff  {target}     رابط مرة واحدة يفتح الموقع بنفس الحساب (ربط الصفحات · …)
 */
require_once __DIR__ . '/_init.php';
$inc = __DIR__ . '/../../../includes/';
require_once $inc . 'functions.php';
require_once $inc . 'dashboard-v2.php';
require_once $inc . 'billing.php';
require_once $inc . 'uploader.php';
if (is_file($inc . 'plans.php')) require_once $inc . 'plans.php';
if (is_file($inc . 'content-formats.php')) require_once $inc . 'content-formats.php';

$action = api_action() ?: 'bootstrap';

/** طريقة الشراء من التطبيق: off (افتراضي — متوافق مع سياسات المتاجر) | web */
function v1_purchases_mode(): string
{
    return get_setting('mobile_purchases', 'off') === 'web' ? 'web' : 'off';
}

function v1_costs(): array
{
    $keys = ['content_generation_cost', 'content_regeneration_cost', 'content_design_cost', 'logo_generate_cost',
             'studio_idea_cost', 'ai_edit_cost', 'plan_ideas_cost'];
    $out = [];
    foreach ($keys as $k) $out[$k] = (int) cost_for($k);
    return $out;
}

/** روابط صفحات الموقع → شاشة في التطبيق (التطبيق بيفتح المناسب) */
function v1_route(?string $url): array
{
    $u = (string) $url;
    if (preg_match('~^https?://~i', $u)) return ['kind' => 'external', 'url' => $u];
    $path = basename((string) parse_url($u, PHP_URL_PATH));
    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
    $map = [
        'content-history.php' => 'projects', 'content-view.php' => 'content', 'credits.php' => 'credits',
        'packages.php' => 'packages', 'payments.php' => 'credits', 'profile.php' => 'settings',
        'brand-brain.php' => 'brand', 'brand-profile.php' => 'brand', 'create-content.php' => 'create',
        'design-studio.php' => 'studio', 'social-accounts.php' => 'publishing', 'campaigns.php' => 'projects',
        'campaign-new.php' => 'ideas', 'journey.php' => 'home', 'help.php' => 'help',
    ];
    $id = (int) ($q['id'] ?? $q['open'] ?? 0);
    if ($path === 'notif.php') return ['kind' => 'notification', 'id' => $id];
    return ['kind' => 'screen', 'screen' => $map[$path] ?? 'home', 'id' => $id ?: null];
}

function v1_usage(int $uid): array
{
    $u = ui_credits_usage($uid);
    return [
        'show_numbers' => (bool) ($u['show'] ?? true),
        'balance'      => (int) $u['balance'],
        'used'         => (int) $u['used'],
        'total'        => (int) $u['total'],
        'pct'          => (int) $u['pct'],
        'plan'         => $u['plan'] ?? null,
        'expires_at'   => $u['expires_at'] ?? null,
        'days_left'    => $u['days_left'] ?? null,
        'ends_label'   => $u['ends_label'] ?? null,
        'units'        => array_map(fn($x) => [
            'key' => $x['key'], 'emoji' => $x['emoji'], 'label' => $x['label'], 'limit' => (int) $x['limit'],
            'open' => (bool) $x['open'], 'used' => (int) $x['used'], 'pct' => $x['pct'], 'left' => $x['left'],
        ], $u['units'] ?? []),
    ];
}

switch ($action) {

    case 'bootstrap': {
        $a = mobile_authenticate();
        $types = [];
        foreach (['introductory', 'marketing', 'educational', 'engaging', 'offer', 'trend'] as $k) {
            $types[] = ['key' => $k, 'label' => content_type_label($k)];
        }
        $templates = [];
        try {
            foreach (db_all('SELECT id, name FROM content_templates WHERE is_active = 1 ORDER BY id') as $t) {
                $templates[] = ['id' => (int) $t['id'], 'name' => (string) $t['name']];
            }
        } catch (\Throwable $e) {}
        $ratios = [];
        foreach (design_ratios() as $k => $r) {
            $ratios[] = ['key' => (string) $k, 'label' => (string) ($r['label'] ?? $k), 'hint' => (string) ($r['hint'] ?? '')];
        }
        $formats = [];
        if (function_exists('content_formats')) {
            foreach (content_formats() as $k => $f) $formats[] = ['key' => $k, 'label' => $f['label'], 'emoji' => $f['emoji']];
        }
        $support = function_exists('ann_default_slide') ? ann_default_slide() : null;
        api_ok([
            'api_version'   => 1,
            'app_name'      => APP_NAME,
            'min_app_version' => (string) get_setting('mobile_min_version', '1.0.0'),
            'purchases'     => v1_purchases_mode(),
            'site_url'      => APP_URL,
            'links' => [
                'privacy' => (string) (get_setting('mobile_privacy_url', '') ?: url('privacy')),
                'terms'   => (string) (get_setting('mobile_terms_url', '') ?: url('terms')),
                'help'    => url('help.php'),
                'support' => $support['url'] ?? url('help.php'),
                'reset_password' => url('forgot-password.php'),
            ],
            'options' => [
                'content_types' => $types,
                'platforms'     => [['key' => 'facebook', 'label' => 'فيسبوك'], ['key' => 'instagram', 'label' => 'إنستجرام'], ['key' => 'both', 'label' => 'الاتنين']],
                'lengths'       => [['key' => 'short', 'label' => 'قصير'], ['key' => 'medium', 'label' => 'متوسط'], ['key' => 'long', 'label' => 'طويل']],
                'tones'         => [['key' => 'simple', 'label' => 'بسيط'], ['key' => 'formal', 'label' => 'رسمي'], ['key' => 'fun', 'label' => 'مرح'], ['key' => 'professional', 'label' => 'احترافي']],
                'dialects'      => [['key' => 'egyptian', 'label' => 'مصري'], ['key' => 'gulf', 'label' => 'خليجي'], ['key' => 'msa', 'label' => 'فصحى']],
                'templates'     => $templates,
                'ratios'        => $ratios,
                'formats'       => $formats,
            ],
            'costs'         => v1_costs(),
            'signed_in'     => (bool) $a['user'],
        ]);
    }

    case 'dashboard': {
        $user = mobile_require_user();
        $uid = (int) $user['id'];
        $d = dashboard_v2_data($user);
        $recent = array_map(fn($r) => [
            'id' => (int) $r['id'], 'type' => (string) $r['content_type'], 'type_label' => content_type_label((string) $r['content_type']),
            'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r['generated_text'])), 0, 140),
            'status' => (string) $r['st'], 'status_label' => function_exists('content_status_meta') ? (content_status_meta((string) $r['st'])['label'] ?? '') : '',
            'cover' => mobile_media_url($r['cover'] ?? null), 'created_at' => (string) $r['created_at'],
        ], $d['recent'] ?? []);
        $designs = [];
        try {
            foreach (db_all('SELECT id, image_path, ratio, created_at FROM studio_designs WHERE user_id = ? ORDER BY id DESC LIMIT 6', [$uid]) as $s) {
                $designs[] = ['id' => (int) $s['id'], 'url' => mobile_media_url($s['image_path']), 'ratio' => (string) ($s['ratio'] ?? '1:1'), 'created_at' => (string) $s['created_at']];
            }
        } catch (\Throwable $e) {}
        $notif = ui_notifications($uid);
        api_ok([
            'greeting' => $d['greeting'], 'first_name' => $d['first'], 'business' => $d['business'],
            'brand_health' => ['pct' => (int) ($d['health']['pct'] ?? 0), 'gate' => (int) ($d['health']['gate'] ?? 75), 'unlocked' => !empty($d['health']['unlocked'])],
            'actions' => array_map(fn($x) => ['n' => (int) $x['n'], 'label' => $x['label'], 'tone' => $x['tone'], 'route' => v1_route($x['url'])], $d['actions']),
            'journey' => ['done' => $d['journey']['done'], 'total' => $d['journey']['total'], 'pct' => $d['journey']['pct'], 'show' => $d['journey']['show'],
                          'next' => $d['journey']['next'] ? ['label' => $d['journey']['next']['label'], 'route' => v1_route($d['journey']['next']['url'])] : null],
            'month' => ['posts' => $d['month']['posts'], 'designs' => $d['month']['designs'], 'active_campaigns' => $d['month']['active']],
            'usage' => v1_usage($uid),
            'subscribed' => function_exists('plan_subscribed') ? plan_subscribed($uid) : true,
            'recent' => $recent,
            'recent_designs' => $designs,
            'slides' => array_map(fn($s) => ['id' => (int) $s['id'], 'title' => (string) $s['title'], 'body' => (string) $s['body'],
                'image' => mobile_media_url($s['image'] ?? null), 'button' => (string) ($s['btn'] ?? ''), 'route' => v1_route($s['url'] ?? '')], $d['slides'] ?? []),
            'badge' => (int) $notif['badge'],
        ]);
    }

    case 'notifications': {
        $user = mobile_require_user();
        $uid = (int) $user['id'];
        $n = ui_notifications($uid);
        $history = array_map(fn($r) => [
            'id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'title' => (string) $r['title'], 'body' => (string) ($r['body'] ?? ''),
            'tone' => (string) ($r['tone'] ?? 'brand'), 'read' => $r['read_at'] !== null, 'created_at' => (string) $r['created_at'], 'route' => v1_route($r['url'] ?? ''),
        ], user_notifications($uid, 50, false));
        api_ok([
            'badge' => (int) $n['badge'],
            'items' => array_map(fn($i) => ['type' => $i['type'], 'icon' => $i['icon'], 'tone' => $i['tone'], 'title' => $i['title'],
                                            'sub' => $i['sub'] ?? '', 'route' => v1_route($i['url'] ?? '')], $n['items']),
            'history' => $history,
        ]);
    }

    case 'notif_read': {
        v1_require_post();
        $user = mobile_require_user();
        $id = api_int('id', 0, 0);
        if ($id > 0) {
            db_run('UPDATE user_notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$id, (int) $user['id']]);
        } else {
            db_run('UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [(int) $user['id']]);
        }
        api_ok(['read' => true]);
    }

    case 'credits': {
        $user = mobile_require_user();
        $uid = (int) $user['id'];
        $usage = v1_usage($uid);
        $labels = ['content' => 'توليد منشور', 'regenerate' => 'إعادة توليد', 'design' => 'تصميم منشور', 'studio' => 'تصميم من الاستوديو',
                   'logo' => 'توليد لوجو', 'refund' => 'استرجاع', 'signup' => 'هدية التسجيل', 'purchase' => 'شراء باقة',
                   'payment_request' => 'شحن باقة', 'plan' => 'أفكار خطة', 'plan_idea' => 'منشور من الخطة'];
        $hist = array_map(fn($t) => [
            'id' => (int) $t['id'],
            'type' => (string) $t['action_type'],
            'amount' => $usage['show_numbers'] ? (int) $t['amount'] : null,
            'label' => $labels[(string) $t['reference_type']] ?? ((string) ($t['notes'] ?? '') ?: (string) $t['reference_type']),
            'notes' => (string) ($t['notes'] ?? ''),
            'created_at' => (string) $t['created_at'],
        ], credits_history($uid, 60));
        $pending = [];
        try {
            foreach (db_all('SELECT r.id, r.status, r.created_at, p.name FROM payment_requests r LEFT JOIN credit_packages p ON p.id = r.package_id
                             WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 10', [$uid]) as $r) {
                $pending[] = ['id' => (int) $r['id'], 'status' => (string) $r['status'], 'package' => (string) ($r['name'] ?? ''), 'created_at' => (string) $r['created_at']];
            }
        } catch (\Throwable $e) {}
        api_ok([
            'usage' => $usage,
            'subscribed' => function_exists('plan_subscribed') ? plan_subscribed($uid) : true,
            'costs' => v1_costs(),
            'history' => $hist,
            'payments' => $pending,
            'purchases' => v1_purchases_mode(),
        ]);
    }

    case 'packages': {
        mobile_require_user();
        $mode = v1_purchases_mode();
        $items = [];
        foreach (billing_packages(true) as $p) {
            $q = function_exists('plan_quotas_decode') ? plan_quotas_decode($p['quotas_json'] ?? null) : [];
            $items[] = [
                'id' => (int) $p['id'], 'name' => (string) $p['name'], 'description' => (string) ($p['description'] ?? ''),
                'badge' => (string) ($p['badge'] ?? ''), 'featured' => !empty($p['is_featured']),
                'validity_days' => (int) ($p['validity_days'] ?? 30),
                'features' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($p['features'] ?? ''))))),
                'quotas' => $q,
                // الأسعار ورابط الدفع بس لو الشراء من الويب مسموح (نسخ داخلية/مباشرة) — نسخ المتاجر مابتبيعش
                'price_egp' => $mode === 'web' ? (float) $p['price_egp'] : null,
            ];
        }
        api_ok(['packages' => $items, 'purchases' => $mode]);
    }

    case 'web_handoff': {
        v1_require_post();
        $user = mobile_require_user();
        v1_rate('handoff', 20, 600, (int) $user['id']);
        $target = api_str('target', 300);
        $allowed = ['social-accounts.php', 'brand-brain.php', 'brand-profile.php', 'sources.php', 'content-plan.php',
                    'research.php', 'campaigns.php', 'help.php', 'profile.php', 'payments.php'];
        if (v1_purchases_mode() === 'web') {
            $allowed[] = 'packages.php';
            $allowed[] = 'checkout.php';
        }
        $page = basename((string) parse_url($target, PHP_URL_PATH));
        if (!in_array($page, $allowed, true) || !auth_safe_next($target)) {
            api_fail('الصفحة دي مش متاحة', 'forbidden', 403);
        }
        $code = mobile_issue_token((int) $user['id'], 'handoff', $target);
        api_ok(['url' => url('api/v1/handoff.php?code=' . rawurlencode($code)), 'expires_in' => MOBILE_HANDOFF_SEC]);
    }

    default:
        api_fail('إجراء غير معروف', 'unknown_action', 404);
}
