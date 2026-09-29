<?php
/**
 * Spread AI v2 — سجل الإعدادات (المرحلة 8-ج)
 *
 * مكان واحد بيعرّف كل إعداد: اسمه · نوعه · قيمته الافتراضية · هل هو سري · بيتخزن مشفّر ولا لأ.
 * - «مركز الإعدادات» (admin/settings.php) بيرسم الفورمز منه ويحفظ بيه.
 * - set_setting() بتسجّل أي تغيير من الأدمن في settings_audit (والأسرار مخفية).
 * - التصدير/الاستيراد بيستبعد الأسرار.
 */

/**
 * التبويبات ← الأقسام ← الحقول
 * الحقل: key · label · type (text|number|bool|select|secret|url|email|tel|digits|textarea|info)
 *        default · help · ph · options · min · max · len · enc (social|crypto) · input (اسم الحقل في الفورم لو مختلف)
 */
function settings_registry(): array
{
    static $R = null;
    if ($R !== null) return $R;
    $appName = defined('APP_NAME') ? APP_NAME : 'Spread AI';
    $R = [
        'general' => [
            'label' => 'عام', 'en' => 'General', 'icon' => 'gear',
            'desc' => 'اسم المنصة وحدود الحساب والموافقة على العملاء الجدد',
            'sections' => [
                ['title' => 'المنصة', 'fields' => [
                    ['key' => 'site_name', 'label' => 'اسم المنصة', 'type' => 'text', 'default' => $appName, 'len' => 100, 'required' => true],
                    ['key' => 'site_tagline', 'label' => 'السطر التعريفي', 'type' => 'text', 'default' => '', 'len' => 150, 'help' => 'بيظهر تحت الاسم في صفحات الدخول والتسجيل'],
                    ['key' => 'max_brand_images', 'label' => 'أقصى عدد لصور الهوية', 'type' => 'number', 'default' => defined('MAX_BRAND_IMAGES') ? (string) MAX_BRAND_IMAGES : '10', 'min' => 1, 'max' => 50],
                ]],
                ['title' => 'العملاء الجدد', 'fields' => [
                    ['key' => 'manual_approval', 'label' => 'الحسابات الجديدة محتاجة موافقة من الإدارة', 'type' => 'bool', 'default' => '0', 'sensitive' => true, 'help' => 'لو شغال، العميل الجديد بيستنى في «الموافقات» قبل ما يستخدم المنصة'],
                ]],
            ],
            'links' => [
                ['المظهر', 'admin/appearance.php', 'اللوجو والألوان والواجهة الجديدة وزرار الواتساب', 'site_settings'],
                ['الإعلانات', 'admin/announcements.php', 'رسائل بتظهر للعملاء جوه المنصة', 'site_settings'],
                ['الموافقات', 'admin/approvals.php', 'الحسابات المستنية موافقة', 'approve_users'],
            ],
        ],
        'payments' => [
            'label' => 'الدفع', 'en' => 'Payments', 'icon' => 'card',
            'desc' => 'التحويل اليدوي (إنستاباي · فودافون كاش) وبوابة Paymob',
            'sections' => [
                ['title' => 'الدفع اليدوي (شحن الرصيد)', 'fields' => [
                    ['key' => 'manual_pay_enabled', 'label' => 'تفعيل الشحن بالتحويل اليدوي في صفحة الباقات', 'type' => 'bool', 'default' => '1'],
                    ['key' => 'pay_instapay', 'label' => 'رقم / عنوان إنستاباي', 'type' => 'text', 'default' => '', 'ltr' => true, 'ph' => 'username@instapay أو 01xxxxxxxxx'],
                    ['key' => 'pay_instapay_link', 'label' => 'لينك الدفع المباشر بإنستاباي', 'type' => 'url', 'default' => '', 'ph' => 'https://ipn.eg/S/username/instapay/…', 'help' => 'لو اتحط، العميل بيشوف زرار «ادفع بإنستاباي» بيوديه للدفع مباشرة'],
                    ['key' => 'pay_vodafone', 'label' => 'رقم فودافون كاش', 'type' => 'tel', 'default' => '', 'ph' => '01xxxxxxxxx'],
                    ['key' => 'pay_whatsapp', 'label' => 'واتساب استقبال إثباتات الدفع (بكود الدولة)', 'type' => 'tel', 'default' => '', 'ph' => '201xxxxxxxxx', 'help' => 'العميل بيبعت عليه رسالة جاهزة فيها اسمه والباقة'],
                ]],
                ['title' => 'Paymob — الدفع أونلاين', 'fields' => [
                    ['key' => 'paymob_api_key', 'label' => 'API Key', 'type' => 'secret', 'default' => ''],
                    ['key' => 'paymob_integration_id', 'label' => 'Integration ID (كروت أونلاين)', 'type' => 'digits', 'default' => ''],
                    ['key' => 'paymob_iframe_id', 'label' => 'Iframe ID', 'type' => 'digits', 'default' => ''],
                    ['key' => 'paymob_hmac', 'label' => 'HMAC Secret', 'type' => 'secret', 'default' => '', 'help' => 'من إعدادات Paymob — ضروري للتحقق من عمليات الدفع'],
                ]],
            ],
            'links' => [
                ['الباقات والأسعار', 'admin/packages.php', 'الباقات وحصصها وطريقة عرض الاستهلاك للعميل', 'manage_packages'],
                ['تسعير العمليات', 'admin/pricing.php', 'تكلفة كل عملية بالكريدت وصلاحية الرصيد', 'manage_packages'],
                ['إعدادات العروض والأفلييت', 'admin/offer-settings.php', 'مكافآت الإحالة وتوقيتها', 'manage_packages'],
            ],
        ],
        'ai' => [
            'label' => 'الذكاء الاصطناعي', 'en' => 'AI', 'icon' => 'cpu',
            'desc' => 'الموديلات الافتراضية والبحث العميق — المزودين والبدائل من «مركز الـ AI»',
            'sections' => [
                ['title' => 'الموديلات الافتراضية', 'fields' => [
                    ['key' => 'ai_model', 'label' => 'موديل الكتابة الأساسي', 'type' => 'text', 'default' => defined('AI_MODEL') ? AI_MODEL : '', 'ltr' => true, 'ph' => 'openai/gpt-4o-mini', 'help' => 'بيتستخدم لو المهمة مالهاش سلسلة في «الموديلات والـ Router»'],
                    ['key' => 'ai_model_fallback', 'label' => 'الموديل الاحتياطي', 'type' => 'text', 'default' => '', 'ltr' => true, 'ph' => 'anthropic/claude-3-5-haiku'],
                    ['key' => 'ai_image_model', 'label' => 'موديل توليد الصور', 'type' => 'text', 'default' => '', 'ltr' => true, 'ph' => 'google/gemini-2.5-flash-image'],
                ]],
                ['title' => 'البحث العميق', 'fields' => [
                    ['key' => 'research_enabled', 'label' => 'البحث العميق للعملاء', 'type' => 'select', 'default' => '1', 'options' => ['1' => 'مفعّل', '0' => 'مقفول']],
                    ['key' => 'research_auto_brain', 'label' => 'البحث بيسجّل في Brand Brain تلقائيًا', 'type' => 'select', 'default' => '1', 'options' => ['1' => 'مفعّل — الرؤى تتضاف للهوية + اقتراحات للحقول الناقصة', '0' => 'العميل يختار بنفسه'], 'help' => 'البحث العميق شغّال على الـ Smart Router بس — الموديل بيتحدد من «الموديلات والـ Router» (مهمة بحث الويب)'],
                ]],
            ],
            'links' => [
                ['مزودين الـ AI', 'admin/ai-providers.php', 'المفاتيح واختبار الاتصال', 'ai_settings'],
                ['الموديلات والـ Router', 'admin/ai-router.php', 'سلسلة البدائل لكل مهمة وتشغيل البوابة', 'ai_settings'],
                ['تكاليف الـ AI', 'admin/ai-costs.php', 'أسعار الموديلات وسعر الدولار', 'view_costs'],
                ['مكتبة البرومبتات', 'admin/prompts.php', 'برومبت كل نوع محتوى وتصميم', 'ai_settings'],
                ['إعدادات التصميم', 'admin/studio-settings.php', 'المقاسات والأنماط', 'site_settings'],
            ],
        ],
        'social' => [
            'label' => 'النشر والسوشيال', 'en' => 'Publishing', 'icon' => 'link',
            'desc' => 'تطبيق ميتا لربط صفحات العملاء بضغطة، والصفحة الافتراضية القديمة',
            'sections' => [
                ['title' => 'تطبيق ميتا (ربط العملاء)', 'fields' => [
                    ['key' => 'meta_app_id', 'label' => 'App ID', 'type' => 'digits', 'default' => ''],
                    ['key' => 'meta_app_secret_enc', 'input' => 'meta_app_secret', 'label' => 'App Secret', 'type' => 'secret', 'enc' => 'social', 'default' => '', 'help' => 'بيتخزن مشفّر'],
                    ['key' => 'meta_webhook_verify_token', 'label' => 'Webhook Verify Token (اختياري)', 'type' => 'secret', 'reveal' => true, 'default' => '', 'help' => 'نفس الكلمة اللي بتحطها في إعدادات Webhooks في ميتا'],
                    ['key' => '_meta_redirect', 'label' => 'Valid OAuth Redirect URI — انسخه بالظبط في إعدادات Facebook Login', 'type' => 'info', 'value' => 'social_callback_url'],
                ]],
                ['title' => 'الصفحة الافتراضية (النظام القديم)', 'fields' => [
                    ['key' => 'meta_page_id', 'label' => 'Facebook Page ID', 'type' => 'digits', 'default' => ''],
                    ['key' => 'meta_page_token', 'label' => 'Page Access Token', 'type' => 'secret', 'default' => ''],
                    ['key' => 'meta_ig_user_id', 'label' => 'Instagram Business User ID', 'type' => 'digits', 'default' => '', 'help' => 'مطلوب بس للنشر على إنستجرام'],
                ]],
            ],
            'links' => [
                ['المزايا والنشر', 'admin/features.php', 'مين من العملاء مسموح له بالنشر التلقائي وعدد الصفحات', 'features'],
                ['حسابات السوشيال', 'admin/social-connections.php', 'كل الصفحات المربوطة وحالتها', 'view_users'],
            ],
        ],
        'access' => [
            'label' => 'الدخول والتكاملات', 'en' => 'Access & Integrations', 'icon' => 'lock',
            'desc' => 'الدخول بجوجل · Spread CRM · مفتاح المهام المجدولة (Cron)',
            'sections' => [
                ['title' => 'الدخول بجوجل', 'fields' => [
                    ['key' => 'google_login_enabled', 'label' => 'زرار «الدخول بجوجل» ظاهر للعملاء', 'type' => 'bool', 'default' => '0'],
                    ['key' => 'google_auto_approve', 'label' => 'حسابات جوجل الجديدة بتتفعّل من غير موافقة', 'type' => 'bool', 'default' => '0', 'sensitive' => true],
                    ['key' => 'google_client_id', 'label' => 'Client ID', 'type' => 'text', 'default' => '', 'ltr' => true, 'len' => 255],
                    ['key' => 'google_client_secret_enc', 'input' => 'google_client_secret', 'label' => 'Client Secret', 'type' => 'secret', 'enc' => 'crypto', 'default' => '', 'help' => 'بيتخزن مشفّر — الاختبار ودليل الإعداد في صفحة «الدخول بجوجل»'],
                ]],
                ['title' => 'Spread CRM', 'fields' => [
                    ['key' => 'crm_webhook_url', 'label' => 'CRM Webhook URL', 'type' => 'url', 'default' => '', 'sensitive' => true, 'ph' => 'https://crm.spreadagency.net/handlers/spread-ai-webhook.php', 'help' => 'كل تسجيل جديد في المنصة بيتبعت له تلقائيًا'],
                    ['key' => 'crm_webhook_secret', 'label' => 'Webhook Secret', 'type' => 'secret', 'default' => ''],
                ]],
                ['title' => 'المهام المجدولة (Cron)', 'fields' => [
                    ['key' => 'cron_secret', 'label' => 'Cron Secret', 'type' => 'secret', 'reveal' => true, 'default' => '', 'help' => 'نفس الكلمة اللي في أوامر الـ Cron: php …/cron/publish-scheduled.php cron_secret=SECRET'],
                ]],
            ],
            'links' => [
                ['الدخول بجوجل', 'admin/google-auth.php', 'اختبار الاتصال ودليل الإعداد خطوة بخطوة', 'site_settings'],
                ['الفريق والصلاحيات', 'admin/admins.php', 'حسابات الإدارة وأدوارها', 'manage_admins'],
                ['الإشعارات والتنبيهات', 'admin/alerts.php', 'إيميل التنبيهات', 'view_costs'],
            ],
        ],
    ];
    return $R;
}

/** كل الحقول مفهرسة بالمفتاح (من غير info) */
function settings_fields(): array
{
    static $F = null;
    if ($F !== null) return $F;
    $F = [];
    foreach (settings_registry() as $tk => $tab) {
        foreach ($tab['sections'] as $sec) {
            foreach ($sec['fields'] as $f) {
                if (($f['type'] ?? '') === 'info') continue;
                $F[$f['key']] = $f + ['tab' => $tk, 'section' => $sec['title']];
            }
        }
    }
    return $F;
}

/** أسماء عربي لإعدادات بتتظبط من صفحات متخصصة (للسجل) */
function settings_label(string $key): ?string
{
    $f = settings_fields()[$key] ?? null;
    if ($f) return $f['label'];
    static $L = [
        'ui_v2_mode' => 'الواجهة الجديدة للعملاء', 'ui_v2_thinking' => 'شاشة «Spread AI يفكر»', 'site_logo' => 'اللوجو',
        'theme_primary' => 'اللون الأساسي', 'theme_primary_2' => 'اللون التاني', 'theme_primary_ink' => 'لون النص الأساسي',
        'header_message' => 'رسالة أعلى الموقع', 'footer_text' => 'سطر الفوتر', 'whatsapp_float' => 'رقم زرار الواتساب العائم', 'whatsapp_float_msg' => 'رسالة زرار الواتساب',
        'credits_display' => 'العميل بيشوف (نسبة % / أرقام)', 'quotas_enforced' => 'الحصص بتوقف الخدمة', 'upgrade_whatsapp_msg' => 'رسالة واتساب الترقية',
        'health_inactive_days' => 'قاعدة «مهدد بالإلغاء» (أيام)', 'health_usage_high' => 'قاعدة «قرّب يخلص» (%)', 'health_renew_days' => 'قاعدة «التجديد قريب» (أيام)',
        'default_credit_validity_days' => 'صلاحية الكريدت الافتراضية (أيام)', 'starter_credits' => 'كريدت التسجيل', 'menu_hidden' => 'عناصر القائمة المخفية',
        'content_generation_cost' => 'تكلفة توليد محتوى', 'content_regeneration_cost' => 'تكلفة إعادة التوليد', 'content_design_cost' => 'تكلفة التصميم',
        'plan_ideas_cost' => 'تكلفة الأفكار', 'ai_edit_cost' => 'تكلفة التعديل بالكلام', 'brand_analyze_cost' => 'تكلفة تحليل البراند', 'source_summary_cost' => 'تكلفة تلخيص مصدر',
        'ai_gateway_enabled' => 'بوابة الـ AI', 'ai_breaker_threshold' => 'حد أعطال المزود', 'ai_breaker_window_min' => 'نافذة الأعطال (دقايق)', 'ai_breaker_cooldown_min' => 'إيقاف المزود (دقايق)',
        'ai_failover_alert_per_hour' => 'تنبيه التحويل للبديل (في الساعة)', 'credit_value_egp' => 'قيمة الكريدت (ج.م)', 'margin_alert_pct' => 'تنبيه الهامش (%)',
        'alerts_email' => 'إيميل التنبيهات', 'alerts_email_min_level' => 'مستوى تنبيهات الإيميل', 'watermark_enabled' => 'العلامة المائية',
        'video_whatsapp' => 'واتساب طلبات الفيديو', 'use_smart_router' => 'الراوتر الذكي (قديم)', 'ai_provider' => 'مزود الـ AI (قديم)', 'ai_api_key_enc' => 'مفتاح الـ AI (قديم)',
        'allow_mock' => 'السماح بالوضع التجريبي', 'design_ratios_json' => 'مقاسات التصميم',
    ];
    return $L[$key] ?? null;
}

/** الإعداد ده سري؟ (من السجل، أو من اسمه لو مش متسجل) */
function settings_is_secret(string $key): bool
{
    $f = settings_fields()[$key] ?? null;
    if ($f) return ($f['type'] ?? '') === 'secret';
    return (bool) preg_match('/(secret|token|password|passwd|hmac|api_key|apikey|_enc$|private)/i', $key);
}

/** إعدادات النظام اللي بتتغير لوحدها (مابتتسجلش ولا بتتصدّر) */
function settings_is_noise(string $key): bool
{
    return (bool) preg_match('/(_last_run$|^openrouter_balance_|^trends_seeded$|_last_at$|^cron_.*_at$)/', $key);
}

/* ═══════════ سجل التغييرات ═══════════ */

function settings_audit_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM settings_audit LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** التغيير ده يتسجّل؟ — تغييرات الأدمن بس (مش الكرون) */
function settings_audit_wanted(string $key): bool
{
    return !empty($_SESSION['admin_id']) && !settings_is_noise($key) && settings_audit_ready();
}

/** قيمة للعرض في السجل: الأسرار مخفية، والطويل متقصّر */
function settings_mask(string $key, ?string $v): ?string
{
    if ($v === null) return null;
    if (settings_is_secret($key)) return $v === '' ? '' : '••••••••';
    return mb_strlen($v) > 500 ? mb_substr($v, 0, 500) . '…' : $v;
}

function settings_audit_record(string $key, ?string $old, string $new): void
{
    try {
        $src = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $src = $src !== '' ? basename(dirname($src)) . '/' . basename($src) : 'cli';
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        db_run('INSERT INTO settings_audit (setting_key, old_value, new_value, is_secret, admin_id, source, ip) VALUES (?,?,?,?,?,?,?)', [
            mb_substr($key, 0, 150), settings_mask($key, $old), settings_mask($key, $new), settings_is_secret($key) ? 1 : 0,
            (int) ($_SESSION['admin_id'] ?? 0) ?: null, mb_substr($src, 0, 80), mb_substr($ip, 0, 45),
        ]);
    } catch (\Throwable $e) {
        error_log('[settings-audit] ' . $e->getMessage());
    }
}

/* ═══════════ الحفظ من الفورم ═══════════ */

/**
 * تنظيف قيمة حقل واحد
 * @return array [ok(bool), value(?string: null = ماتتغيرش), error(?string)]
 */
function settings_clean(array $f, $raw, bool $clear = false): array
{
    $type = $f['type'] ?? 'text';
    if ($type === 'bool') return [true, !empty($raw) && $raw !== '0' ? '1' : '0', null];
    if ($type === 'secret') {
        if ($clear) return [true, '', null];
        $v = trim((string) $raw);
        if ($v === '' || $v === '••••••••') return [true, null, null]; // فاضي = سيبه زي ما هو
        if (mb_strlen($v) > 2000) return [false, null, 'طويل جدًا'];
        try {
            if (($f['enc'] ?? '') === 'social') {
                if (!function_exists('social_token_encrypt')) return [false, null, 'التشفير مش متاح على السيرفر ده'];
                $v = social_token_encrypt($v);
            } elseif (($f['enc'] ?? '') === 'crypto') {
                if (!class_exists('Crypto')) return [false, null, 'التشفير مش متاح على السيرفر ده'];
                $v = Crypto::encrypt($v);
            }
        } catch (\Throwable $e) {
            return [false, null, 'التشفير فشل — راجع ENCRYPTION_KEY في config.php'];
        }
        if ($v === '' || $v === false || $v === null) return [false, null, 'التشفير فشل'];
        return [true, (string) $v, null];
    }
    $v = trim((string) $raw);
    switch ($type) {
        case 'number':
            if ($v === '' || !preg_match('/^-?\d+$/', $v)) return [false, null, 'لازم رقم صحيح'];
            $n = (int) $v;
            if (isset($f['min']) && $n < $f['min']) return [false, null, 'أقل قيمة ' . $f['min']];
            if (isset($f['max']) && $n > $f['max']) return [false, null, 'أكبر قيمة ' . $f['max']];
            return [true, (string) $n, null];
        case 'select':
            return isset($f['options'][$v]) ? [true, $v, null] : [false, null, 'اختيار مش صحيح'];
        case 'url':
            if ($v === '') return [true, '', null];
            return (filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v)) ? [true, mb_substr($v, 0, 500), null] : [false, null, 'لينك مش صحيح (لازم يبدأ بـ https://)'];
        case 'email':
            if ($v === '') return [true, '', null];
            return filter_var($v, FILTER_VALIDATE_EMAIL) ? [true, $v, null] : [false, null, 'إيميل مش صحيح'];
        case 'tel':
        case 'digits':
            $d = preg_replace('/\D+/', '', $v);
            return [true, mb_substr($d, 0, 30), null];
        default: // text · textarea
            if (!empty($f['required']) && $v === '') return [false, null, 'مطلوب'];
            return [true, mb_substr($v, 0, (int) ($f['len'] ?? 1000)), null];
    }
}

/** القيمة الحالية للعرض في الفورم (الأسرار: '' دايمًا + هل محفوظة) */
function settings_current(array $f): array
{
    $v = get_setting($f['key'], null);
    $has = $v !== null && $v !== '';
    if (($f['type'] ?? '') === 'secret') return ['', $has];
    return [$v === null ? (string) ($f['default'] ?? '') : (string) $v, $has];
}

/* ═══════════ التصدير والاستيراد ═══════════ */

/**
 * الإعدادات اللي بتتصدّر وبتتستورد بره السجل — كل واحد بنفس قواعد صفحته.
 * أي مفتاح مش هنا ومش في السجل مابيتصدّرش ولا بيتستورد
 * (مسارات الملفات، عناوين الـ API الداخلية، الوضع التجريبي، …).
 */
function settings_portable_extra(): array
{
    $hex = ['type' => 'regex', 're' => '/^(#[0-9a-fA-F]{3,8})?$/'];
    $n = fn($min, $max) => ['type' => 'number', 'min' => $min, 'max' => $max];
    return [
        'header_message' => ['type' => 'text', 'len' => 200], 'footer_text' => ['type' => 'text', 'len' => 300],
        'whatsapp_float' => ['type' => 'digits'], 'whatsapp_float_msg' => ['type' => 'text', 'len' => 200],
        'theme_primary' => $hex, 'theme_primary_2' => $hex, 'theme_primary_ink' => $hex,
        'ui_v2_mode' => ['type' => 'select', 'options' => ['off' => 1, 'optin' => 1, 'all' => 1]], 'ui_v2_thinking' => ['type' => 'bool'],
        'credits_display' => ['type' => 'select', 'options' => ['percent' => 1, 'visible' => 1]], 'quotas_enforced' => ['type' => 'bool'],
        'upgrade_whatsapp_msg' => ['type' => 'text', 'len' => 300],
        'health_inactive_days' => $n(1, 90), 'health_usage_high' => $n(10, 100), 'health_renew_days' => $n(1, 30),
        'default_credit_validity_days' => $n(1, 3650), 'starter_credits' => $n(0, 100000),
        'content_generation_cost' => $n(0, 999), 'content_regeneration_cost' => $n(0, 999), 'content_design_cost' => $n(0, 999),
        'plan_ideas_cost' => $n(0, 999), 'ai_edit_cost' => $n(0, 999), 'brand_analyze_cost' => $n(0, 999), 'source_summary_cost' => $n(0, 999),
        'alerts_email' => ['type' => 'email'], 'alerts_email_min_level' => ['type' => 'select', 'options' => ['warn' => 1, 'critical' => 1]],
        'ai_breaker_threshold' => $n(1, 50), 'ai_breaker_window_min' => $n(1, 120), 'ai_breaker_cooldown_min' => $n(1, 1440), 'ai_failover_alert_per_hour' => $n(1, 1000),
        'margin_alert_pct' => $n(0, 100), 'video_whatsapp' => ['type' => 'digits'],
    ];
}

/** مواصفات مفتاح قابل للنقل (null = مش مسموح) */
function settings_portable_spec(string $key): ?array
{
    if (settings_is_secret($key) || settings_is_noise($key)) return null;
    $f = settings_fields()[$key] ?? null;
    if ($f) return $f;
    $x = settings_portable_extra()[$key] ?? null;
    return $x ? $x + ['key' => $key, 'label' => settings_label($key) ?? $key] : null;
}

/** كل الإعدادات القابلة للتصدير (من غير أسرار ولا مسارات ولا عناوين داخلية) */
function settings_export(): array
{
    $out = [];
    foreach (db_all('SELECT setting_key k, setting_value v FROM settings ORDER BY setting_key, id') as $r) {
        $k = (string) $r['k'];
        if (!settings_portable_spec($k)) continue;
        $out[$k] = (string) $r['v'];
    }
    return $out;
}

/**
 * مقارنة ملف استيراد بالحالي
 * @return array [changes[[key, old, new, label, sensitive]], skipped[[key, why]]]
 */
function settings_import_diff(array $in): array
{
    $cur = settings_export();
    $changes = $skipped = [];
    foreach (array_slice($in, 0, 400, true) as $k => $v) {
        $k = (string) $k;
        if (!preg_match('/^[a-zA-Z0-9_]{1,150}$/', $k)) { $skipped[] = [mb_substr($k, 0, 60), 'اسم مش صحيح']; continue; }
        if (settings_is_secret($k)) { $skipped[] = [$k, 'سري — مابيتستوردش']; continue; }
        $spec = settings_portable_spec($k);
        if (!$spec) { $skipped[] = [$k, 'مش مسموح يتنقل بالملف (مسار/عنوان داخلي/مش موجود)']; continue; }
        if (!is_scalar($v) || mb_strlen((string) $v) > 5000) { $skipped[] = [$k, 'قيمة مش صحيحة']; continue; }
        if (($spec['type'] ?? '') === 'regex') {
            if (!preg_match($spec['re'], (string) $v)) { $skipped[] = [$k, 'قيمة مش صحيحة']; continue; }
            $clean = (string) $v;
        } else {
            [$ok, $clean, $err] = settings_clean($spec, (string) $v);
            if (!$ok) { $skipped[] = [$k, $err]; continue; }
            $clean = (string) $clean;
        }
        if (($cur[$k] ?? null) === $clean) continue;
        $changes[] = [$k, $cur[$k] ?? null, $clean, $spec['label'] ?? $k, !empty($spec['sensitive'])];
    }
    return [$changes, $skipped];
}
