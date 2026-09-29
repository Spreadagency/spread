<?php
/**
 * API الـ Brand Brain
 *   GET  ?action=get
 *   POST action=analyze_url   {url}                  تحليل موقع أو صفحة سوشيال
 *   POST action=decide        {id, decision: apply|reject, value?}
 *   POST action=apply_all                            تأكيد كل الاقتراحات
 *   POST action=logo_colors                          استخراج ألوان اللوجو (مقترحة)
 *   POST action=approve                              اعتماد الهوية
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/brand-brain.php';
require_once __DIR__ . '/../../includes/ai.php';

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'get';

$brand = brand_for_user($uid);
if (!$brand) {
    // كل مستخدم بيتعمله ملف براند عند التسجيل — ده احتياط
    db_insert('INSERT INTO brand_profiles (user_id) VALUES (?)', [$uid]);
    $brand = brand_for_user($uid);
}
$reload = fn() => brand_for_user($uid);

switch ($action) {

    case 'get':
        api_ok(['brand' => brand_to_api($brand)]);

    case 'analyze_url':
        if (get_setting('brand_analyze_enabled', '1') !== '1') {
            api_fail('التحليل متوقف مؤقتًا', 'disabled', 403);
        }
        if (!rate_limit('brand_analyze', 'u' . $uid, 6, 600)) {
            api_fail('حللت روابط كتير — استنى عشر دقايق', 'rate_limit', 429);
        }
        $url = api_str('url', 1000);
        if ($url === '') {
            api_fail('اكتب رابط الموقع أو الصفحة', 'empty', 422);
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $v = safe_http_validate($url);
        if (!$v['ok']) {
            api_fail($v['error'], 'bad_url', 422);
        }

        // الكريدت بيتخصم قبل وبيرجع لو مفيش نتيجة
        $cost = max(0, (int) get_setting('brand_analyze_cost', 1));
        if ($cost > 0) {
            if (credits_balance($uid) < $cost) {
                api_fail(credits_short_msg(), 'credits', 402);
            }
            if (!credits_consume($uid, $cost, 'تحليل براند من رابط', 'brand_analyze', null)) {
                api_fail(credits_short_msg(), 'credits', 402);
            }
        }
        @set_time_limit(90);
        $r = brand_analyze_url($brand, $uid, $url);
        if ($cost > 0 && (!$r['ok'] || $r['suggested'] === 0)) {
            credits_add($uid, $cost, 'استرداد — تحليل الرابط ما طلعش معلومات', null, 'refund', null);
        }
        if (!$r['ok']) {
            api_fail($r['error'] ?: 'تعذّر تحليل الرابط', 'analyze_failed', 422);
        }
        api_ok([
            'suggested' => $r['suggested'],
            'type'      => $r['type'],
            'ai'        => $r['ai'],
            'note'      => !$r['ai'] && in_array($r['type'], ['facebook', 'instagram', 'tiktok', 'linkedin'], true)
                ? 'صفحات السوشيال مقفولة قدام الزوار — سجّلنا الرابط، وللتحليل الكامل ضيف موقعك أو ارفع ملف عن البراند.'
                : null,
            'brand'     => brand_to_api($reload()),
        ]);

    case 'decide':
        $id = api_int('id');
        $decision = api_str('decision', 10);
        if ($decision === 'apply') {
            $in = api_input();
            $edited = array_key_exists('value', $in) && is_string($in['value']) ? mb_substr($in['value'], 0, 3000) : null;
            $r = brand_fact_apply($id, $uid, $edited);
            if (!$r['ok']) api_fail($r['error'], 'apply_failed', 422);
        } elseif ($decision === 'reject') {
            if (!brand_fact_reject($id, $uid)) api_fail('المعلومة دي مش موجودة', 'not_found', 404);
        } else {
            api_fail('قرار غير معروف', 'bad_decision', 422);
        }
        api_ok(['brand' => brand_to_api($reload())]);

    case 'apply_all':
        $ids = db_all('SELECT id, field FROM brand_facts WHERE brand_profile_id = ? AND status = "suggested" ORDER BY confidence DESC, id DESC',
            [$brand['id']]);
        $done = [];
        $n = 0;
        foreach ($ids as $f) {
            if (isset($done[$f['field']])) continue;       // أعلى ثقة لكل حقل بس
            $r = brand_fact_apply((int) $f['id'], $uid);
            if ($r['ok']) { $done[$f['field']] = true; $n++; }
        }
        api_ok(['applied' => $n, 'brand' => brand_to_api($reload())]);

    case 'logo_colors':
        if (empty($brand['logo_path'])) {
            api_fail('ارفع اللوجو الأول', 'no_logo', 422);
        }
        // المسارات متخزنة نسبة لـ storage/ (زي upload_url)
        $base = realpath(STORAGE_PATH);
        $abs = realpath(STORAGE_PATH . '/' . ltrim((string) $brand['logo_path'], '/'));
        if ($abs && $base && strpos($abs, $base) !== 0) {
            $abs = false;   // حماية: مايطلعش بره storage
        }
        $colors = $abs ? brand_logo_colors($abs) : [];
        if (!$colors) {
            api_fail('معرفناش نستخرج ألوان من اللوجو ده', 'no_colors', 422);
        }
        brand_fact_suggest($brand, $uid, 'colors', implode(', ', $colors), 'logo', 'اللوجو', 95);
        api_ok(['colors' => $colors, 'brand' => brand_to_api($reload())]);

    case 'approve':
        $h = brand_health($brand);
        if ($h['pct'] < max(50, $h['gate'])) {
            api_fail('كمّل الهوية لحد ' . max(50, $h['gate']) . '% الأول', 'too_early', 422, ['pct' => $h['pct']]);
        }
        db_run('UPDATE brand_profiles SET brand_approved_at = NOW() WHERE id = ?', [$brand['id']]);
        api_ok(['brand' => brand_to_api($reload())]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
