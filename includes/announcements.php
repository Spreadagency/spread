<?php
/**
 * Spread AI — سلايدر الإعلانات في الرئيسية
 *
 * نفس جدول «الإعلانات» بتاع الأدمن + زرار · جمهور (كل العملاء / باقة معيّنة) · بداية ونهاية بالساعة.
 * لو مفيش إعلانات شغالة للعميل: الأساسي «تواصل مع خدمة العملاء».
 * لو فيه إعلانات: بتظهر مع الأساسي (مش بداله).
 */

/** الأعمدة الجديدة (زرار · الجمهور · الباقة · مواعيد بالساعة) — مرة واحدة */
function ann_ensure_schema(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if ((string) get_setting('ann_schema_v', '') === '2') return $ok = true;
        $cols = array_column(db_all('SELECT column_name AS c, data_type AS t FROM information_schema.columns
                                     WHERE table_schema = DATABASE() AND table_name = "announcements"'), 't', 'c');
        if (!$cols) return $ok = false;
        $ddl = [];
        if (!isset($cols['btn_label']))  $ddl[] = 'ADD COLUMN `btn_label` VARCHAR(60) NULL AFTER `link_url`';
        if (!isset($cols['audience']))   $ddl[] = "ADD COLUMN `audience` VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `btn_label`";
        if (!isset($cols['package_id'])) $ddl[] = 'ADD COLUMN `package_id` INT NULL AFTER `audience`';
        if (($cols['starts_at'] ?? '') === 'date') $ddl[] = 'MODIFY COLUMN `starts_at` DATETIME NULL';
        if (($cols['ends_at'] ?? '') === 'date')   $ddl[] = 'MODIFY COLUMN `ends_at` DATETIME NULL';
        if ($ddl) {
            db_run('ALTER TABLE `announcements` ' . implode(', ', $ddl));
            // الإعلانات القديمة كانت بتاريخ بس — النهاية تبقى آخر اليوم مش أوله
            if (($cols['ends_at'] ?? '') === 'date') {
                db_run("UPDATE `announcements` SET `ends_at` = DATE_ADD(`ends_at`, INTERVAL '23:59:59' HOUR_SECOND) WHERE `ends_at` IS NOT NULL AND TIME(`ends_at`) = '00:00:00'");
            }
        }
        set_setting('ann_schema_v', '2');
        return $ok = true;
    } catch (\Throwable $e) {
        error_log('[announcements] ' . $e->getMessage());
        return $ok = false;
    }
}

/** باقة العميل الحالية (للإعلانات الخاصة بباقة) */
function ann_user_package(int $uid): ?int
{
    if (!function_exists('plan_active')) {
        if (is_file(__DIR__ . '/plans.php')) require_once __DIR__ . '/plans.php';
    }
    if (!function_exists('plan_active')) return null;
    try {
        $p = plan_active($uid);
        return !empty($p['package_id']) ? (int) $p['package_id'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** رابط آمن للزرار: https://… أو صفحة داخلية (credits.php) — غير كده بيتشال */
function ann_link(?string $link): string
{
    $link = trim((string) $link);
    if ($link === '') return '';
    if (preg_match('#^https?://#i', $link)) return $link;
    if (preg_match('#^[a-z0-9_\-/]+\.php([?\#].*)?$#i', $link)) return url(ltrim($link, '/'));
    return '';
}

/** السلايد الأساسي: تواصل مع خدمة العملاء (دايمًا موجود) */
function ann_default_slide(): array
{
    $wa = preg_replace('/[^0-9]/', '', (string) get_setting('support_whatsapp', ''))
        ?: preg_replace('/[^0-9]/', '', function_exists('site_setting') ? site_setting('whatsapp_float', '') : '')
        ?: preg_replace('/[^0-9]/', '', (string) get_setting('pay_whatsapp', ''));
    $msg = rawurlencode((string) get_setting('support_whatsapp_msg', 'أهلًا، محتاج مساعدة في Spread AI'));
    return [
        'id' => 0, 'default' => true,
        'title' => (string) get_setting('support_slide_title', 'محتاج مساعدة؟ فريقنا معاك'),
        'body'  => (string) get_setting('support_slide_body', 'كلّم خدمة العملاء وهنرد عليك في أسرع وقت — أي سؤال عن المنصة أو باقتك.'),
        'image' => null,
        'btn'   => 'تواصل مع خدمة العملاء',
        'url'   => $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . $msg : url('help.php'),
        'external' => $wa !== '',
    ];
}

/**
 * سلايدات العميل: الإعلانات الشغالة (للكل أو لباقته) + الأساسي في الآخر
 * @return array<int, array{id:int, default:bool, title:string, body:string, image:?string, btn:string, url:string, external:bool}>
 */
function ann_slides_for_user(int $uid): array
{
    $out = [];
    try {
        $v2 = ann_ensure_schema();
        $rows = db_all('SELECT * FROM announcements WHERE is_active = 1
                        AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())
                        ORDER BY sort_order ASC, id DESC LIMIT 8');
        $pkg = null;
        foreach ($rows as $a) {
            if ($v2 && ($a['audience'] ?? 'all') === 'package') {
                $pkg = $pkg ?? (ann_user_package($uid) ?? 0);
                if (!$pkg || (int) $a['package_id'] !== $pkg) continue;
            }
            $url = ann_link($a['link_url'] ?? '');
            $out[] = [
                'id' => (int) $a['id'], 'default' => false,
                'title' => (string) $a['title'],
                'body'  => mb_substr(trim(strip_tags((string) ($a['body'] ?? ''))), 0, 160),
                'image' => !empty($a['image_path']) ? (function_exists('upload_url') ? upload_url($a['image_path']) : url('storage/' . $a['image_path'])) : null,
                'btn'   => $url !== '' ? (trim((string) ($a['btn_label'] ?? '')) ?: 'اعرف أكتر') : '',
                'url'   => $url,
                'external' => (bool) preg_match('#^https?://#i', (string) ($a['link_url'] ?? '')),
            ];
        }
    } catch (\Throwable $e) { /* مفيش جدول إعلانات */ }
    $out[] = ann_default_slide();
    return $out;
}
