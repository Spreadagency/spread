<?php
/**
 * Spread AI — توليد تصميم لمحتوى (كان جوه public/ajax/generate-design.php — اتنقل هنا بالحرف)
 * علشان الصفحة وشغل الخلفية (خطة الحملات §4: camp_design) يمشوا على نفس المسار بالظبط.
 *
 *   design_generate($user, $in) ← نفس الرد اللي الصفحة بترجّعه (ok · design_id · image_url · …)
 *   $in = حقول الطلب (content_id · slide_no · include_logo · custom_prompt · style_ref · design_edit · …)
 *         + source_image_path (ملف مرفوع اتحفظ) · brand (اختياري) · no_rate_limit (شغل الخلفية)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/prompt-builder.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/uploader.php';
require_once __DIR__ . '/plan-functions.php';
require_once __DIR__ . '/watermark.php';
if (is_file(__DIR__ . '/smart-ai.php')) require_once __DIR__ . '/smart-ai.php';

function design_generate(array $user, array $in): array
{
    $brand = $in['brand'] ?? db_one('SELECT * FROM brand_profiles WHERE user_id = ? LIMIT 1', [(int) $user['id']]);   // زي user_brand() — شغل الخلفية مالوش جلسة
    $contentId = (int) ($in['content_id'] ?? 0);

    if (!$brand) {
        return (['ok' => false, 'error' => 'كمّل بيانات الهوية أولًا']);
    }

    $content = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$contentId, $user['id']]);
    if (!$content) {
        return (['ok' => false, 'error' => 'المحتوى غير موجود']);
    }

    // ⑦-ج أشكال المحتوى: الفيديو بيتنفذ يدوي · الكاروسيل تصميم لكل شريحة · الستوري رأسي
    require_once __DIR__ . '/content-formats.php';
    $format = content_format_key($content['format'] ?? 'post');
    if ($format === 'video') {
        return (['ok' => false, 'error' => 'الفيديو مش بيتصمم بالـ AI — راجع السكريبت واطلب التنفيذ من «🎬 إنشاء الفيديو»']);
    }
    // شرط الاشتراك — قبل أي حاجة بتتخزن أو بتتخصم (مفيش تصميم ولا Credits ولا Job للي مش مشترك)
    if ($__sub = design_subscription_gate((int) $user['id'])) {
        return ($__sub);
    }
    $slideNo = null;
    $slide = null;
    if ($format === 'carousel') {
        $slidesTotal = max(1, (int) $content['slides_count']);
        $slideNo = (int) ($in['slide_no'] ?? 0);
        if ($slideNo < 1) {
            // من غير رقم (مسار قديم / الرحلة): أول شريحة ناقصة
            $slideNo = content_design_progress($content)['missing'][0] ?? 1;
        }
        if ($slideNo > $slidesTotal) {
            return (['ok' => false, 'error' => 'الكاروسيل ده فيه ' . $slidesTotal . ' شرائح بس']);
        }
        $slide = content_slides($content)[$slideNo - 1] ?? null;
    }

    // التصميم الجماعي من الحملة (⑥-ب): حد أعلى — كل تصميم بيتخصم من الكريدت برضه
    $__bulk = ($in['bulk'] ?? '') === '1';
    if (empty($in['no_rate_limit']) && !($__bulk ? rate_limit('design_bulk', 'u' . $user['id'], 30, 600) : rate_limit('design', 'u' . $user['id'], 6, 600))) {
        return (['ok' => false, 'error' => 'وصلت للحد الأقصى لتوليد التصميمات. استنى شوية.']);
    }


    // ── الخيارات من الواجهة ──
    $includeLogo   = ($in['include_logo'] ?? '1') === '1' && !empty($brand['logo_path']);
    $includeImages = ($in['include_brand_images'] ?? '0') === '1';
    $customPrompt  = mb_substr(trim($in['custom_prompt'] ?? ''), 0, 1200);

    // ── صورة مرجعية مرفوعة لهذا التصميم (اختياري) ──
    $sourceImagePath = !empty($in['source_image_path']) ? (string) $in['source_image_path'] : null;   // الملف المرفوع (الصفحة بترفعه قبل ما تنادي)
    // أو مرجع مختار من معرض الإلهام / صوره (الملف المرفوع له الأولوية)
    $externalStyleUri = null;
    $refLog = [];     // وصف الصور المرجعية اللي اتبعتت (للأدمن) — مسارات مش data URIs
    if (!$sourceImagePath && !empty($in['style_ref'])) {
        $resolved = resolve_style_ref((string) $in['style_ref'], (int) $user['id'], $brand);
        if ($resolved && strpos($resolved, 'url:') === 0) {
            $externalStyleUri = remote_image_data_uri(substr($resolved, 4));
        } else {
            $sourceImagePath = $resolved;
        }
    }

    // الكاروسيل: الشريحة 2+ بتاخد الشريحة الأولى (أو أقرب شريحة قبلها) مرجع — علشان يطلعوا كاروسيل واحد متناسق
    $carouselRef = false;
    $carouselRatio = null;
    if ($format === 'carousel' && $slideNo > 1 && !$sourceImagePath && !$externalStyleUri && ($in['design_edit'] ?? '') !== '1') {
        $prev = db_one('SELECT image_path, ratio, slide_no FROM content_designs WHERE content_id = ? AND slide_no BETWEEN 1 AND ? ORDER BY slide_no = 1 DESC, slide_no DESC, id DESC LIMIT 1',
            [$contentId, $slideNo - 1]);
        if ($prev) {
            $sourceImagePath = $prev['image_path'];
            $carouselRef = (int) $prev['slide_no'];
            $carouselRatio = (string) $prev['ratio'];
        }
    }

    // الكاروسيل: أول شريحة ناقصة بتتأكد إن الحصة تكفي باقي الشرائح (علشان مايقفش في النص)
    $__need = 1;
    if ($format === 'carousel') {
        $__miss = content_design_progress($content)['missing'] ?? [];
        if (in_array($slideNo, $__miss, true)) $__need = max(1, count($__miss));
    }
    // خطة الحملات §2.3: تصميم منشور من حملة في الباقة ← من حصة الحملة (العدّاد العادي والحد الأسبوعي بيستثنوه)
    //   الإعادة من رصيد التعديلات ← ولو خلص بالكريدت بس · غير كده المسار العادي زي ما هو
    require_once __DIR__ . '/campaign-allowance.php';
    $__cad = ca_design_allow($content, $slideNo, ($in['design_edit'] ?? '') === '1');
    if ($__cad['mode'] === 'none') {
        return (['ok' => false, 'error' => $__cad['error'] ?: 'الحملة دي مش معتمدة']);
    }
    if ($__cad['mode'] === 'off' && ($__g = plan_gate((int) $user['id'], 'designs', $__need))) {
        return (['ok' => false, 'error' => $__g, 'code' => 'quota']);
    }
    $cost = $__cad['mode'] === 'off' ? design_credit_cost() : (int) $__cad['cost'];
    if ($cost > 0 && !credits_consume((int) $user['id'], $cost, 'توليد تصميم للمحتوى #' . $contentId . ($slideNo ? ' — شريحة ' . $slideNo : ''), 'design', $contentId)) {
        ca_design_release($__cad);
        return (['ok' => false, 'error' => credits_short_msg($cost)]);
    }

    // ═══ تجهيز التصميم (المدخلات + البرومبت) — includes/design-builder.php (نفس المنطق للمحرك) ═══
    require_once __DIR__ . '/design-builder.php';
    // الـ Skill (قسم «التصميم» و«ممنوع في الصورة») لتصميمات الحملات بس — المنشور السريع والاستوديو بعدين (خطة الحملات §7)
    $__skill = null;
    if (!empty($content['campaign_id'])) {
        require_once __DIR__ . '/campaign-flow.php';
        $__camp = db_one('SELECT * FROM campaigns WHERE id = ? AND user_id = ?', [(int) $content['campaign_id'], (int) $user['id']]);
        if ($__camp) $__skill = campaign_skill($brand, $__camp);
    } else {
        // المنشور السريع (من غير حملة): الـ Skill بتاعة البراند — post_skills_enabled
        require_once __DIR__ . '/post-skills.php';
        $__skill = post_skill($brand ?: []);
    }
    $__built = design_build([
        'skill' => $__skill,
        'uid' => (int) $user['id'], 'brand' => $brand, 'content' => $content, 'format' => $format,
        'slide' => $slide, 'slide_no' => $slideNo, 'slides_total' => $slidesTotal ?? 1,
        'include_logo' => $includeLogo, 'include_images' => $includeImages, 'custom_prompt' => $customPrompt,
        'source_image_path' => $sourceImagePath, 'external_style_uri' => $externalStyleUri,
        'carousel_ref' => $carouselRef, 'carousel_ratio' => $carouselRatio, 'edit' => ($in['design_edit'] ?? '') === '1',
        'character_ids' => isset($in['character_ids']) ? (string) $in['character_ids'] : null,
        'style_ref' => (string) ($in['style_ref'] ?? ''), 'style_preset_id' => $in['style_preset_id'] ?? null,
        'size_wh' => (string) ($in['size_wh'] ?? ''), 'ratio' => (string) ($in['ratio'] ?? ''),
    ]);
    $inputImages = $__built['inputs'];
    $refLog = array_merge($refLog, $__built['refs']);
    $designPrompt = $__built['prompt'];
    $ratioKey = $__built['ratio_key'];
    $ratioMeta = $__built['ratio_meta'];
    $__exact = $__built['exact'];

    // ═══ التوليد ═══
    $modelReceivedImages = false;

    if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
        $result = smart_ai_image($designPrompt, [
            'size' => $ratioMeta['size'],
            'user_id' => (int) $user['id'],
            'reference_type' => 'content',
            'reference_id' => $contentId,
            'reference_images' => array_column($inputImages, 'uri'),
        ]);
        $modelReceivedImages = !empty($inputImages) && $result['ok'];
    } else {
        ai_log_set_context([
            'kind' => 'design', 'user_id' => (int) $user['id'],
            'reference_type' => 'contents', 'reference_id' => $contentId,
            'options' => ['ratio' => $ratioKey, 'size' => $ratioMeta['size'] ?? null, 'format' => $format, 'slide' => $slideNo],
            'images' => $inputImages,
        ]);
        $result = ai_generate_image($designPrompt, array_column($inputImages, 'uri'));
        $modelReceivedImages = !empty($inputImages) && $result['ok'];
    }

    if (!$result['ok']) {
        if ($cost > 0) credits_add((int) $user['id'], $cost, 'استرداد: فشل توليد التصميم #' . $contentId, null, 'refund', $contentId);
        ca_design_release($__cad);
        return (['ok' => false, 'error' => $result['error']]);
    }

    // المقاس المخصص: الصورة بالمقاس المطلوب بالظبط (قص من النص من غير مط)
    if ($__exact) {
        design_fit_exact($result['path'], $__exact[0], $__exact[1]);
    }

    // ═══ Fallback: لو الموديل ما استلمش الصور (مسار قديم) → اللوجو بـ GD ═══
    if ($includeLogo && !$modelReceivedImages) {
        brand_logo_apply($result['path'], $brand['logo_path']);
    }

    // العلامة المائية للمنصة (لو مفعّلة)
    if (watermark_for_user((int) $user['id'])) {
        watermark_apply($result['path']);
    }

    $designId = db_insert_row('content_designs', [
        'content_id' => $contentId,
        'user_id' => $user['id'],
        'image_path' => $result['path'],
        'prompt' => $designPrompt,
        'model' => $result['model'],
        'credits_used' => $cost,
        'ratio' => $__exact ? mb_substr($__exact[0] . 'x' . $__exact[1], 0, 10) : $ratioKey,
    ] + (formats_ready() ? ['slide_no' => $slideNo, 'refs_json' => $refLog ? json_encode($refLog, JSON_UNESCAPED_UNICODE) : null] : [])
      + ($__cad['mode'] !== 'off' ? ['campaign_alloc' => 1] : []));

    ai_stamp('content_designs', (int) $designId, 'design_base', $result);   // الإصدار والمزود (نظام الجودة)

    // تعديل تصميم موجود بالكلام: طلب التعديل مش «اتجاه التصميم» — ومايتكتبش مكانه
    $isDesignEdit = ($in['design_edit'] ?? '') === '1';
    if ($customPrompt !== '' && !$isDesignEdit) {
        db_run('UPDATE contents SET design_direction = ? WHERE id = ?', [$customPrompt, $contentId]);
    }
    if ($isDesignEdit) {
        // النسخة الجديدة بتبقى الغلاف — والقديمة موجودة في «التصميمات السابقة» لو عايز ترجعلها
        db_run('UPDATE contents SET selected_image_id = ? WHERE id = ? AND user_id = ?', [$designId, $contentId, $user['id']]);
    }

    ai_log_usage((int) $user['id'], 'design', $result['model'], [], $contentId);

    // واتساب + داخل المنصة (مرة كل 30 دقيقة بالكتير لنفس العميل — منع التكرار في Notifier)
    require_once __DIR__ . '/Notifier.php';
    Notifier::notify((int) $user['id'], 'designs_ready', ['count' => 1], ['url' => 'content-view.php?id=' . (int) $contentId]);

    return ([
        'ok' => true,
        'design_id' => $designId,
        'image_url' => url('storage/' . $result['path']),
        'slide_no' => $slideNo,
        'ratio' => $__exact ? $__exact[0] . 'x' . $__exact[1] : $ratioKey,
    ]);
}
