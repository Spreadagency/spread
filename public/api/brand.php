<?php
/**
 * API الـ Brand Brain
 *   GET  ?action=get
 *   POST action=analyze_url   {url}                  تحليل موقع أو صفحة سوشيال
 *   POST action=decide        {id, decision: apply|reject, value?}
 *   POST action=apply_all                            تأكيد كل الاقتراحات
 *   POST action=logo_colors                          استخراج ألوان اللوجو (مقترحة)
 *   POST action=approve                              اعتماد الهوية
 *   GET  ?action=overview                            اللي اتعمل + الناقص بالترتيب + آخر الأبحاث
 *   POST action=save_field    {field, value}         تعديل حقل مباشرة من Brand Brain
 *   POST action=upload_logo   (multipart: logo)      رفع اللوجو
 *   GET  ?action=insp_list · POST insp_link {url, note} · insp_upload (multipart: file, note) · insp_delete {id}
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/brand-brain.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/uploader.php';

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

    /* ── نظرة عامة: اللي اتعمل + الناقص (المساعد بيبدأ منه) ── */
    case 'overview':
        api_ok(['overview' => brand_overview($brand, $uid), 'brand' => brand_to_api($brand)]);

    case 'save_field':
        if (!rate_limit('brand_save', 'u' . $uid, 120, 600)) api_fail('تعديلات كتير بسرعة — استنى لحظة', 'rate_limit', 429);
        $in = api_input();
        $r = brand_field_save($brand, $uid, api_str('field', 40), is_scalar($in['value'] ?? null) ? (string) $in['value'] : '');
        if (!$r['ok']) api_fail($r['error'], 'invalid', 422);
        $b = $reload();
        api_ok(['brand' => brand_to_api($b), 'overview' => brand_overview($b, $uid)]);

    case 'upload_logo':
        if (!rate_limit('upload_image', 'u' . $uid, 20, 600)) api_fail('رفعت كتير بسرعة — استنى شوية', 'rate_limit', 429);
        if (empty($_FILES['logo']['name'])) api_fail('اختار صورة اللوجو', 'empty', 422);
        $up = upload_image($_FILES['logo'], 'logos');
        if (!$up['ok']) api_fail($up['error'], 'upload', 422);
        if (!empty($brand['logo_path'])) delete_upload($brand['logo_path']);
        db_run('UPDATE brand_profiles SET logo_path = ?, updated_at = NOW() WHERE id = ?', [$up['path'], $brand['id']]);
        $b = $reload();
        api_ok(['brand' => brand_to_api($b), 'overview' => brand_overview($b, $uid)]);

    /* ── تصميمات بتعجبك ── */
    case 'insp_list':
        api_ok(['items' => brand_insp_list((int) $brand['id']), 'max' => brand_insp_max()]);

    case 'insp_link':
    case 'insp_upload':
        if (!rate_limit('brand_insp', 'u' . $uid, 30, 600)) api_fail('ضفت كتير بسرعة — استنى شوية', 'rate_limit', 429);
        if (brand_insp_count((int) $brand['id']) >= brand_insp_max()) api_fail('وصلت للحد (' . brand_insp_max() . ') — امسح تصميم الأول', 'limit', 422);
        $note = api_str('note', 300);
        if ($action === 'insp_link') {
            $url = api_str('url', 1000);
            if ($url === '') api_fail('حط الرابط الأول', 'empty', 422);
            $r = brand_insp_add_link($brand, $uid, $url, $note);
        } else {
            if (empty($_FILES['file']['name'])) api_fail('اختار صورة', 'empty', 422);
            $r = brand_insp_add_upload($brand, $uid, $_FILES['file'], $note);
        }
        if (!$r['ok']) api_fail($r['error'], 'invalid', 422);
        api_ok(['items' => brand_insp_list((int) $brand['id']), 'image' => $r['image'] ?? true]);

    case 'insp_delete':
        if (!brand_insp_delete($brand, api_int('id'))) api_fail('مش موجود', 'not_found', 404);
        api_ok(['items' => brand_insp_list((int) $brand['id'])]);

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
