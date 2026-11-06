<?php
/**
 * Spread AI — تجهيز التصميم (خطة الحملات · المرحلة 1): نفس منطق التصميم القديم في مكان واحد
 *
 * الموديل بيعمل التصميم **كامل**: الكتابة العربية والـ CTA جوه التصميم، واللوجو والشخصية و«تصميمات بتعجبك»
 * وصور البراند ومرجع الاستايل (أو الشريحة الأولى للكاروسيل) بتتبعت معاه كمدخلات بدورها.
 * بيستخدمه: public/ajax/generate-design.php (القديم) · includes/ce-design.php (المحرك — وضع «تصميم كامل»).
 *
 * design_build($o) ← ['prompt', 'inputs' => [['uri','role']], 'refs' => [...], 'ratio_key', 'ratio_meta', 'exact']
 *   $o: uid · brand · content (صف contents أو شبهه) · format (post|carousel|story) · slide (['title','text','design']) · slide_no · slides_total
 *       include_logo · include_images · custom_prompt · source_image_path · external_style_uri · carousel_ref · carousel_ratio
 *       edit (تعديل تصميم موجود) · character_ids (نص «1,2» أو null) · style_ref · style_preset_id · size_wh · ratio
 *       brief (design_brief من المحرك — اختياري) · skill (الـ Skill — اختياري: قسم «التصميم» و«ممنوع في الصورة»)
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/prompt-builder.php';
require_once __DIR__ . '/prompt-engine.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/uploader.php';
require_once __DIR__ . '/plan-functions.php';
require_once __DIR__ . '/studio-config.php';
require_once __DIR__ . '/brand-kit.php';

/** design_brief (حقول CE_BRIEF_FIELDS) ← سطور إنجليزي للموديل */
function design_brief_text(array $b): string
{
    $map = ['focal_subject' => 'Main subject', 'layout' => 'Layout', 'mood' => 'Mood', 'lighting' => 'Lighting', 'camera_angle' => 'Camera angle'];
    $out = [];
    foreach ($map as $k => $l) {
        $v = trim((string) ($b[$k] ?? ''));
        if ($v !== '') $out[] = $l . ': ' . mb_substr($v, 0, 300);
    }
    $h = trim((string) ($b['headline'] ?? ''));
    $s = trim((string) ($b['subline'] ?? ''));
    if ($h !== '') $out[] = 'Headline on the design (Arabic — render exactly, correct Arabic letters, right-to-left): ' . mb_substr($h, 0, 120);
    if ($s !== '') $out[] = 'Supporting line (Arabic, smaller): ' . mb_substr($s, 0, 160);
    $cols = array_values(array_filter((array) ($b['colors_hex'] ?? []), fn($c) => is_string($c) && preg_match('/^#[0-9a-f]{6}$/i', $c)));
    if ($cols) $out[] = 'Brand colors to use: ' . implode(' ', array_slice($cols, 0, 4));
    return $out ? "Design brief:\n- " . implode("\n- ", $out) : '';
}

/** قسم «التصميم والصور» + «ممنوع في الصورة» من الـ Skill ← سطور للموديل */
function design_skill_text(?array $skill): string
{
    if (!$skill) return '';
    $vis = trim((string) ($skill['sections']['visual']['text'] ?? ''));
    $avoid = function_exists('ce_skill_items') ? ce_skill_items($skill, 'visual_avoid') : [];
    $out = '';
    if ($vis !== '') $out .= 'SECTOR DESIGN RULES (' . ($skill['name'] ?? '') . "):\n" . mb_substr($vis, 0, 1200);
    if ($avoid) $out .= ($out !== '' ? "\n" : '') . 'Never show in the image: ' . implode(', ', array_slice($avoid, 0, 12));
    return $out;
}

function design_build(array $o): array
{
    $o += ['include_logo' => false, 'include_images' => false, 'custom_prompt' => '', 'source_image_path' => null, 'external_style_uri' => null,
           'carousel_ref' => false, 'carousel_ratio' => null, 'edit' => false, 'style_ref' => '', 'style_preset_id' => null, 'size_wh' => '', 'ratio' => '',
           'slide' => null, 'slide_no' => null, 'slides_total' => 1, 'brief' => null, 'skill' => null];
    $uid = (int) $o['uid'];
    $brand = (array) $o['brand'];
    $content = (array) $o['content'];
    $format = (string) $o['format'];
    $slide = $o['slide'];
    $slideNo = $o['slide_no'];
    $slidesTotal = (int) $o['slides_total'];
    $includeLogo = (bool) $o['include_logo'];
    $includeImages = (bool) $o['include_images'];
    $customPrompt = (string) $o['custom_prompt'];
    $sourceImagePath = $o['source_image_path'];
    $externalStyleUri = $o['external_style_uri'];
    $carouselRef = $o['carousel_ref'];
    $carouselRatio = $o['carousel_ratio'];
    $edit = (bool) $o['edit'];
    $hasChars = array_key_exists('character_ids', $o) && $o['character_ids'] !== null;
    $refLog = [];

    // ═══ تجميع مدخلات الموديل: كل صورة بدورها ═══
    $inputImages = [];  // [['uri' => data uri, 'role' => وصف الدور بالإنجليزي]]

    if ($includeLogo) {
        $uri = image_data_uri($brand['logo_path']);
        if ($uri) {
            $refLog[] = ['role' => 'logo', 'path' => $brand['logo_path']];
            $inputImages[] = ['uri' => $uri, 'role' => "the brand's official LOGO — integrate this exact logo into the design naturally (top-right unless the design rules say otherwise). Do NOT redraw, alter, distort, or recreate it."];
        }
    }

    if ($includeImages) {
        // الصورة الشخصية / المنتج أولًا
        if (!empty($brand['personal_image_path'])) {
            $uri = image_data_uri($brand['personal_image_path']);
            if ($uri) {
                $refLog[] = ['role' => 'personal', 'path' => $brand['personal_image_path']];
                $inputImages[] = ['uri' => $uri, 'role' => "the client's personal/product photo — feature this person or product prominently in the design, keeping their appearance accurate."];
            }
        }
        foreach (array_slice(get_brand_images((int) $brand['id']), 0, 1) as $img) {
            $uri = image_data_uri($img['image_path']);
            if ($uri) {
                $refLog[] = ['role' => 'brand', 'path' => $img['image_path']];
                $inputImages[] = ['uri' => $uri, 'role' => "a brand photo — you may include or reference it in the design."];
            }
        }
    }

    // Brand Kit: بريف البوست + الشخصية الثابتة (صورتها المرجعية) — نفس الملامح في كل بوست
    require_once __DIR__ . '/brand-kit.php';
    $bkDesignBrief = bk_enabled() ? bk_arr($content['brief_json'] ?? '') : [];
    // الشخصيات: اختيار العميل في التصميم (واحدة أو اتنين · «0» = من غير) — وإلا شخصية البوست من الهوية
    $__charIds = [];
    if ($hasChars) {
        $__charIds = array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $o['character_ids'])))), 0, 2);
    } elseif (!empty($content['bk_character_id'])) {
        $__charIds = [(int) $content['bk_character_id']];
    }
    foreach (bk_enabled() && !$edit ? $__charIds : [] as $__cid) {
        $__ch = db_one('SELECT * FROM brand_characters WHERE id = ? AND user_id = ? AND is_active = 1', [$__cid, $uid]);
        $__useIn = $__ch ? array_filter(explode(',', (string) $__ch['use_in'])) : [];
        if ($__ch && ($__ch['type'] !== 'real_person' || (int) $__ch['consent_confirmed'] === 1) && ($hasChars || !$__useIn || in_array('image', $__useIn, true))) {
            $__img = db_one('SELECT image_path FROM brand_character_images WHERE character_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1', [$__ch['id']]);
            $__uri = $__img ? image_data_uri($__img['image_path']) : null;
            if ($__uri) {
                $refLog[] = ['role' => 'character', 'path' => $__img['image_path']];
                $inputImages[] = ['uri' => $__uri, 'role' => "the brand's recurring CHARACTER «" . $__ch['name'] . "» — show this exact person/character with the SAME face, features, hairstyle and outfit (do not change identity)."];
                $bkDesignBrief['character_desc'] = trim((isset($bkDesignBrief['character_desc']) && count($__charIds) > 1 ? $bkDesignBrief['character_desc'] . ' + ' : '') . $__ch['name'] . ($__ch['role'] ? ' (' . $__ch['role'] . ')' : '') . '. ' . (string) $__ch['description']);
                // شخص واحد بس في التصميم: الشخصية الثابتة بدل الصورة الشخصية القديمة
                foreach ($refLog as $__i => $__r) {
                    if (($__r['role'] ?? '') === 'personal') { unset($refLog[$__i], $inputImages[$__i]); }
                }
                $refLog = array_values($refLog);
                $inputImages = array_values($inputImages);
            }
        }
    }

    // «تصميمات بتعجبك» من الهوية: مرجع استايل خفيف لو العميل ماختارش مرجع
    $__inspUri = null;
    if (bk_enabled() && !$sourceImagePath && !$externalStyleUri && !$edit) {
        $__ds = bk_get($uid)['data']['design_style'];
        if (!empty($__ds['use_inspirations'])) {
            $__ins = db_one('SELECT image_path FROM brand_inspirations WHERE brand_profile_id = ? AND image_path IS NOT NULL ORDER BY RAND() LIMIT 1', [(int) $brand['id']]);
            $__inspUri = $__ins ? image_data_uri($__ins['image_path']) : null;
            if ($__inspUri) {
                $refLog[] = ['role' => 'inspiration', 'path' => $__ins['image_path']];
                $inputImages[] = ['uri' => $__inspUri, 'role' => "a design the client LIKES — take only mood, color harmony, typography feel and composition cues from it. Do NOT copy its content, text, people or logo."];
            }
        }
    }

    $styleUri = $externalStyleUri ?: ($sourceImagePath ? image_data_uri($sourceImagePath) : null);
    if ($styleUri) {
        // تعديل تصميم موجود: الصورة دي هي التصميم نفسه اللي هيتعدّل — مش مجرد إلهام بالستايل
        // (لو اتوصفت كـ STYLE REFERENCE الـ AI بيعمل تصميم جديد شبهها بدل ما يعدّل اللي اتطلب بس)
        $refLog[] = ['role' => $carouselRef ? 'carousel_slide_' . $carouselRef : (($edit) ? 'edit' : 'style'),
                     'path' => $sourceImagePath, 'url' => $externalStyleUri ? mb_substr((string) ($o['style_ref'] ?? ''), 0, 300) : null];
        $inputImages[] = ['uri' => $styleUri, 'role' => $carouselRef
            ? "SLIDE {$carouselRef} OF THIS SAME CAROUSEL — keep EXACTLY the same visual system: color palette, typography style, layout grid, margins, background treatment, decorative elements and logo placement. The new slide must look like the next page of the same carousel, NOT a copy: new headline/content, same design language."
            : (($edit)
            ? "the CURRENT DESIGN TO EDIT — reproduce this exact design (same layout, elements, texts, colors and composition) and apply ONLY the change requested in the instructions. Do not redesign or add anything else."
            : "the STYLE REFERENCE — strictly follow this image's visual style: colors, mood, composition, typography style, and overall aesthetic.")];
    }

    // حد أقصى 4 مدخلات (المرجع آخر واحد — بنحافظ عليه لو الكاروسيل)
    if (count($inputImages) > 4) {
        $last = array_pop($inputImages); $lastLog = array_pop($refLog);
        $inputImages = array_merge(array_slice($inputImages, 0, 3), [$last]);
        $refLog = array_merge(array_slice($refLog, 0, 3), [$lastLog]);
    }

    // ═══ بناء برومبت التصميم من الهوية ═══
    $identity = [];
    $__bkDesign = bk_brand_block($brand + ['user_id' => $uid], 'design', $bkDesignBrief ? $bkDesignBrief + ['service' => '', 'topic' => ''] : null);
    if ($__bkDesign !== null) {
        $identityBlock = $__bkDesign . "\n";
    } else {
    $identity[] = 'Brand: ' . ($brand['business_name'] ?? '');
    if (!empty($brand['industry'])) $identity[] = 'Industry: ' . $brand['industry'];
    if (!empty($brand['colors']))   $identity[] = 'BRAND COLORS (primary palette): ' . $brand['colors'];
    if (!empty($brand['design_rules'])) $identity[] = 'DESIGN RULES from the client (must follow strictly): ' . $brand['design_rules'];
    if (!empty($brand['visual_identity'])) $identity[] = 'VISUAL IDENTITY GUIDE (follow these style rules precisely): ' . mb_substr($brand['visual_identity'], 0, 1500);
    if (!empty($brand['ai_summary'])) $identity[] = 'Brand identity summary: ' . mb_substr($brand['ai_summary'], 0, 700);
    $identityBlock = implode("\n", $identity) . "\n";
    }

    if ($format === 'carousel' && $slide) {
        $pos = $slideNo === 1 ? 'the COVER slide (strong hook, invites swiping)' : ($slideNo === $slidesTotal ? 'the LAST slide (clear call-to-action)' : 'a middle slide');
        $concept = "This is slide {$slideNo} of {$slidesTotal} of ONE Instagram/Facebook carousel — {$pos}.\n"
            . ($slide['title'] !== '' ? "Slide headline (Arabic — show it as the main text): " . $slide['title'] . "\n" : '')
            . ($slide['text'] !== '' ? "Slide supporting text (Arabic, short): " . $slide['text'] . "\n" : '')
            . ($slide['design'] !== '' ? "Slide design idea: " . $slide['design'] . "\n" : '')
            . ($customPrompt !== '' ? "Extra instructions: " . $customPrompt . "\n" : '')
            . "All slides share the same size, palette and layout system; add a small page indicator ({$slideNo}/{$slidesTotal}).";
    } elseif ($customPrompt !== '') {
        $concept = "Design concept (follow closely): " . $customPrompt;
    } elseif (!empty($content['image_prompt'])) {
        $concept = "Design concept: " . $content['image_prompt'];
    } else {
        $concept = "The post text (Arabic): " . mb_substr($content['generated_text'], 0, 400)
            . "\nCreate a design that visually represents this post.";
    }

    // بريف التصميم من المحرك (design_brief) + قسم «التصميم» في الـ Skill — بيتضافوا بس لو اتبعتوا (القديم من غيرهم زي ما هو)
    if ($o['brief']) $concept .= "\n" . design_brief_text($o['brief']);

    // استايل الصورة (من استايلات الأدمن) — للتصميم ده بس
    $__styleLine = '';
    if (!empty($o['style_preset_id'])) {
        $__sp = db_one('SELECT name, data FROM style_presets WHERE id = ? AND is_active = 1', [(int) $o['style_preset_id']]);
        if ($__sp) {
            $__spd = json_decode((string) $__sp['data'], true) ?: [];
            $__styleLine = 'VISUAL STYLE for this design (follow it): ' . trim(($__spd['en'] ?? '') ?: (($__spd['desc'] ?? '') ?: $__sp['name']));
        }
    }

    if ($o['skill']) {
        $__skillV = design_skill_text($o['skill']);
        if ($__skillV !== '') $__styleLine = trim($__styleLine . "\n" . $__skillV);
    }

    // المقاس: المكتوب في طلب العميل هو الأساسي (⑤-ب)
    require_once __DIR__ . '/studio-config.php';
    // مقاس مخصص (عرض × ارتفاع): الصورة بتطلع بالمقاس ده بالظبط (أقرب مقاس للموديل ← قص وتظبيط)
    $__exact = null;
    if ($format !== 'story' && !empty($o['size_wh']) && preg_match('/^(\d{3,4})\s*[x×*]\s*(\d{3,4})$/u', trim((string) $o['size_wh']), $__m)) {
        $__w = (int) $__m[1]; $__h = (int) $__m[2];
        if ($__w >= 200 && $__h >= 200 && $__w <= 4096 && $__h <= 4096 && max($__w, $__h) / min($__w, $__h) <= 4) $__exact = [$__w, $__h];
    }
    // الستوري دايمًا 9:16 · شرايح الكاروسيل بنفس مقاس الشريحة المرجع
    $__ratioIn = $format === 'story' ? '9:16' : ($carouselRatio ?: (string) ($o['ratio'] ?? ''));
    $ratioRes = $__exact ? studio_ratio_resolve('', $__exact[0] . 'x' . $__exact[1]) : studio_ratio_resolve($__ratioIn, $format === 'post' ? $customPrompt : '');
    $ratioKey = $ratioRes['key'];
    $ratioMeta = $ratioRes['meta'];

    $designPrompt = 'Design a professional social media ' . ['post' => 'post image', 'story' => 'vertical STORY image (full-screen 9:16, keep text away from the top/bottom 14%)', 'carousel' => 'CAROUSEL SLIDE image'][$format] . ".\n" . studio_ratio_hint($ratioRes) . "\n"
        . $identityBlock
        . ($__styleLine !== '' ? $__styleLine . "\n" : '')
        . $concept . "\n";

    // وصف دور كل صورة مرفقة بالترتيب
    if ($inputImages) {
        $designPrompt .= "\nATTACHED IMAGES (in order):\n";
        foreach ($inputImages as $i => $img) {
            $designPrompt .= 'Image ' . ($i + 1) . ' is ' . $img['role'] . "\n";
        }
    }

    // برومبت نوع التصميم من الأدمن
    $__typeP = type_prompt('design', $format === 'story' ? 'story' : 'post');
    if ($__typeP === '' && $format === 'story') $__typeP = type_prompt('design', 'post');
    if ($__typeP !== '') {
        $designPrompt .= "\n" . $__typeP . "\n";
    }
    $designPrompt .= "Style: modern, clean, on-brand, suitable for Facebook/Instagram. No watermarks, no fake or invented logos.";

    // أسلوب الستوديو (نصيًا)
    $styleHints = studio_style_hints($uid);
    if ($styleHints !== '' && !$sourceImagePath) {
        $designPrompt .= "\nClient's preferred visual style (from selected references): " . $styleHints . ".";
    }

    // ═══ «أساس برومبت التصميم» الكامل (v1): الـ AI بيكتب برومبت الصورة الإنجليزي من كل التفاصيل الأول ═══
    // (تعديل تصميم موجود بيفضل بالبرومبت المباشر علشان مايتغيّرش غير المطلوب) · أي فشل ← البرومبت العادي
    $__dbase = get_active_prompt('design_base');
    if (prompt_is_full($__dbase) && get_setting('design_prompt_writer', '1') === '1' && !$edit) {
        $__roles = array_map(fn($im) => $im['role'], $inputImages);
        $__writer = prompt_apply($__dbase, [
            'post_text' => mb_substr((string) ($content['generated_text'] ?? ''), 0, 1500), 'design_idea' => $concept,
            'design_style' => trim($__styleLine . ($styleHints !== '' ? "\nClient's preferred visual style: " . $styleHints : '') . ($__typeP !== '' ? "\n" . $__typeP : '')),
            'size' => studio_ratio_hint($ratioRes), 'studio_template' => $__typeP,
            'has_logo_or_product' => $__roles ? "أيوه — الصور المرفقة بالترتيب:\n- " . implode("\n- ", $__roles) : 'لأ',
            'brand_identity' => trim($identityBlock), 'content_type' => ['post' => 'بوست', 'story' => 'ستوري 9:16', 'carousel' => 'شريحة كاروسيل'][$format],
        ] + prompt_brand_vars($uid, $brand, 'design', ['identity' => false]), []);
        $__w = ai_generate($__writer);
        $__out = !empty($__w['ok']) ? (string) $__w['response'] : '';
        if (preg_match('/PROMPT\s*:\s*(.+?)(?=\n\s*(ARABIC_TEXT|PROMPT_B|\[\/CONTENT\]|NEGATIVE)|$)/su', $__out, $__pm) && mb_strlen(trim($__pm[1])) > 40) {
            $__ar = preg_match('/ARABIC_TEXT\s*:\s*(.+)/u', $__out, $__am) ? trim($__am[1]) : '';
            $__neg = preg_match('/NEGATIVE\s*:\s*(.+)/u', $__out, $__nm) ? trim($__nm[1]) : '';
            $__cta = preg_match('/\[CTA\](.*?)\[\/CTA\]/su', $__out, $__cm) ? trim($__cm[1]) : '';
            $designPrompt = studio_ratio_hint($ratioRes) . "\n" . trim($__pm[1]) . "\n"
                . ($__ar !== '' && !preg_match('/^(بدون|none|no)$/iu', $__ar) ? 'Arabic text on the design (render exactly, correct Arabic letters, right-to-left): ' . $__ar . "\n" : '')
                . ($__cta !== '' && !preg_match('/^(بدون|none|no)$/iu', $__cta) ? 'CTA button text (Arabic): ' . $__cta . "\n" : '')
                . ($__neg !== '' ? 'Avoid: ' . $__neg . "\n" : '');
            if ($inputImages) {
                $designPrompt .= "\nATTACHED IMAGES (in order):\n";
                foreach ($inputImages as $i => $img) $designPrompt .= 'Image ' . ($i + 1) . ' is ' . $img['role'] . "\n";
            }
            $designPrompt .= "No watermarks, no fake or invented logos.";
        }
    }

    return ['prompt' => $designPrompt, 'inputs' => $inputImages, 'refs' => $refLog, 'ratio_key' => $ratioKey, 'ratio_meta' => $ratioMeta, 'exact' => $__exact];
}
