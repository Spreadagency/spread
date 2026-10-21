<?php
/**
 * Spread AI — مكتبة البرومبتات اللي كانت مكتوبة في الكود
 *
 * كل برومبت هنا بيتحفظ في جدول prompts (نفس «مكتبة البرومبتات» في الأدمن) ويتعدّل من هناك بنسخ.
 * أول مرة بيتملى بالنص الافتراضي اللي تحت — فالسلوك مابيتغيّرش لحد ما الأدمن يعدّل.
 * لو البرومبت اتمسح أو فضي، الكود بيرجع للنص الافتراضي (مفيش حاجة بتقع).
 *
 * البرومبت = «التعليمات» بس. البيانات (الهوية · الفكرة · المحتوى) وشكل الرد المطلوب (اللي الكود بيقراه)
 * بيضيفهم الكود — فتعديل البرومبت مايكسرش القراءة.
 * المتغيرات بين {} بتتبدل بقيمها؛ ولو البرومبت فيه {data} البيانات بتتحط مكانه، غير كده بتتحط بعده.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/prompt-engine.php';

/** type => [العنوان, الوصف, المتغيرات المتاحة, النص الافتراضي] */
function prompt_library(): array
{
    return [
        'design_idea' => ['🎨 فكرة التصميم', 'تعليمات كتابة «فكرة التصميم» في الحملات والمنشور السريع والستوديو', '{format}',
            "تعليمات «فكرة التصميم» (بالعربي، عملية يقدر المصمم أو الـ AI ينفذها مباشرة):\n"
            . "- التكوين: إيه اللي في النص، وإيه في الخلفية، والعين تبدأ منين\n"
            . "- العناصر: المنتج أو الشخص أو الأيقونات أو المكان — حاجة حقيقية من نشاط العميل\n"
            . "- الألوان: من ألوان البراند، ولون واحد مسيطر\n"
            . "- النص الظاهر على التصميم: قصير جدًا (عنوان + سطر بالكتير)\n"
            . "- مكان اللوجو حسب الهوية، ومساحة فاضية تريّح العين\n"
            . "- ممنوع الزحمة والنصوص الطويلة والعناصر اللي مالهاش علاقة بالرسالة"],

        'campaign_ideas' => ['💡 أفكار الحملة', 'توليد أفكار الحملة (الكود بيضيف بيانات الحملة وشكل الـ JSON)', '{n} عدد الأفكار · {dialect} اللهجة · {formats} الأشكال · {data}',
            "أنت استراتيجي حملات سوشيال ميديا محترف. مطلوب {n} أفكار لحملة شهرية واحدة متماسكة.\n\n{data}\n\nقواعد:\n"
            . "- كل فكرة زاوية مختلفة (خلف الكواليس، عرض وقيمة، تفاعل، موسم ومناسبة، آراء العملاء، تعليمي، قصة، سؤال شائع…)\n"
            . "- محددة وقابلة للتنفيذ فورًا ومربوطة بالبيزنس الحقيقي — مش عناوين عامة\n"
            . "- الـ Hook جملة افتتاحية قوية بـ {dialect}\n"
            . "- formats من القايمة دي بس: {formats}\n"
            . "- لكل فكرة: service = الخدمة اللي بتخدمها (من خدمات العميل بالظبط أو فاضي)، topic = الموضوع، pillar = الاتجاه"],

        'campaign_content' => ['✍️ كتابة بوستات الحملة', 'كتابة المحتوى لكل فكرة (الكود بيضيف الفكرة والهوية وشكل الرد)', '{campaign} اسم الحملة · {basis} أساسها · {format} الشكل · {dialect} · {data}',
            "أنت كاتب محتوى تسويقي محترف. اكتب محتوى كامل لفكرة من حملة «{campaign}» (أساسها: {basis}).\nشكل المحتوى: {format}\n\n{data}\n\nاللهجة: {dialect}"],

        'campaign_eval' => ['⭐ تقييم المنشور', 'تقييم كل بوست قبل التصميم — المعايير ودرجة النجاح بتتعدّل تحت', '{cats} المعايير · {ready} درجة النجاح · {data}',
            "أنت مراجع محتوى تسويقي صارم ومنصف. قيّم المنشور ده قبل ما يتصمم.\n\n{data}\n\n"
            . "قيّم كل معيار من 100 بالترتيب ده: {cats}.\n"
            . "لو المتوسط أقل من {ready} اكتب issues: إيه بالظبط اللي محتاج يتحسن (جملتين، عملي ومحدد).\n"
            . "comment: تعليق قصير (جملة واحدة) بالعامية على نقطة القوة."],

        'campaign_plan_eval' => ['📋 تقييم الخطة كاملة', 'تقييم الحملة كلها مع بعض (توازن · تكرار · تسلسل · CTA · مناسبة الهدف)', '{ready} · {data}',
            "أنت مدير تسويق خبير. قيّم خطة الحملة دي كاملة مع بعض — مش كل بوست لوحده.\n\n{data}\n\n"
            . "قيّم من 100 الحاجات دي:\n"
            . "- التوازن بين الاتجاهات وأشكال المحتوى\n"
            . "- التكرار: أفكار أو Hooks أو جمل متكررة بين البوستات\n"
            . "- تغطية العملاء المثاليين والخدمات المختارة\n"
            . "- تسلسل الحملة (توعية ← ثقة ← بيع) وترتيب البوستات\n"
            . "- قوة وتنوّع الـ CTA ومناسبته للهدف\n"
            . "- مناسبة الحملة كلها لهدفها\n"
            . "اكتب من 3 لـ 5 ملاحظات عملية — كل ملاحظة مربوطة ببوست محدد (رقمه) لو ينفع، ومعاها التعديل المقترح بالظبط.\n"
            . "summary: جملة واحدة بالعامية عن الخطة."],

        'campaign_improve' => ['🛠 تحسين بعد التقييم', '«حسّنه» — بيتضاف لبرومبت كتابة الحملة', '{issues} ملاحظات المراجع',
            "ده تحسين لمنشور موجود.\nملاحظات المراجع (لازم تتعالج كلها): {issues}\nحافظ على الفكرة والمعلومات — حسّن الصياغة والـ Hook والـ CTA بس."],

        'ai_edit' => ['💬 التعديل بتعليق', 'لما العميل يكتب «غيّر كذا» والـ AI يعدّل (المحتوى · الحملة · صفحة المنشور)', '{instruction} طلب العميل · {data}',
            "انت كاتب محتوى سوشيال ميديا محترف.\n\n{data}\n\nطلب العميل: «{instruction}»\n\nالقواعد:\n"
            . "- عدّل اللي اتطلب **بس**. أي جزء مش مطلوب تغييره يفضل زي ما هو بالحرف.\n"
            . "- حافظ على نفس اللهجة ونبرة البراند.\n"
            . "- ممنوع تخترع أرقام أو أسعار أو مواعيد مش موجودة."],

        'quick_post' => ['⚡ المنشور السريع', 'منشور واحد من خدمة + شكل + عميل مثالي (الكود بيضيف الهوية وشكل الرد)', '{service} · {format} · {persona} · {dialect} · {data}',
            "أنت كاتب محتوى تسويقي محترف. اكتب {format} واحد جاهز للنشر.\n\n{data}\n\n"
            . "القواعد:\n- اكتب للعميل المثالي ده بالذات: وجعه واعتراضاته واللي يخليه يشتري\n"
            . "- Hook قوي في أول سطر، ورسالة واحدة واضحة\n- الـ CTA الأساسي للبراند في الآخر\n- اللهجة: {dialect}"],

        'agent_personas' => ['👥 العملاء المثاليين (Buyer Persona)', 'بناء الأشخاص اللي بيستخدموا خدمات العميل ومهتمين بيه', '{n} العدد · {data}',
            "أنت خبير تسويق وأبحاث عملاء. ابني {n} أشخاص (Buyer Personas) حقيقيين ومختلفين للنشاط ده — كل شخص مربوط بخدمة أو أكتر من خدماته.\n\n{data}\n\n"
            . "القواعد:\n- أشخاص واقعيين من السوق المصري/العربي — مش أوصاف عامة\n"
            . "- كل شخص: وجع محدد، ورغبة، واعتراض قبل الشراء، واللي يخليه يشتري\n"
            . "- اختار الخدمات من خدمات العميل بالظبط"],

        'agent_content_insights' => ['📊 تحليل المحتوى', 'أفضل المنشورات للعميل + شكل المنشورات + تعليمات التصميم + تحليل صوره', '{data}',
            "أنت استراتيجي محتوى ومدير فني. حلّل البراند ده وطلّع خطة محتوى عملية.\n\n{data}\n\n"
            . "المطلوب:\n- أفضل 4-6 أنواع منشورات للبراند ده بالذات (ليه + مثالين لعناوين)\n"
            . "- شكل المنشورات: النِسَب بين بوست وكاروسيل وستوري وفيديو، طول الكابشن، أفضل أنواع الـ Hook\n"
            . "- تعليمات تصميم واضحة (مكان اللوجو، مساحة النص، نوع الصور، الممنوعات، استخدام الشخصيات)\n"
            . "- لو فيه وصف لصوره الحالية: إيه الكويس وإيه اللي يتحسن"],

        'visual_analysis' => ['🔍 تحليل اللوجو والصور', 'استخراج الألوان والاستايل والخطوط من اللوجو والصور المرفوعة (موديل بيشوف الصور)', '{colors} الألوان المستخرجة · {data}',
            "أنت مصمم هوية بصرية محترف. اتفرّج على الصور المرفقة (لوجو البراند وتصميماته وصوره) وحلّلها.\n\n{data}\n\n"
            . "الألوان اللي طلعت بالبرمجة من الصور (استخدمها كمرجع): {colors}\n"
            . "المطلوب: الألوان الأساسية ودور كل لون، الاستايل العام، الخطوط المناسبة، ملاحظات تصميم، وممنوعات."],

        // Egyptian Content DNA — مرجع بيتضاف لبرومبتات الكتابة (حسب «طريقة إضافة المرجع» في الأدمن)
        'egyptian_dna' => ['🇪🇬 Egyptian Content DNA', 'طبقة «المصرية الحقيقية»: السجل · المواقف · تفكير الشراء · الحساسيات · المنصات · الفحص النهائي', '—',
            prompt_dna_default('egyptian_dna')],
        'egyptian_lexicon' => ['🇪🇬 القاموس المصري', 'عبارات جاهزة حسب الغرض + أمثلة قبل/بعد (بتتضاف لبرومبتات الكتابة)', '—',
            prompt_dna_default('egyptian_lexicon')],
    ];
}

/** النص المفعّل من الأدمن — وإلا الافتراضي */
function prompt_get(string $type): string
{
    try {
        $row = db_one('SELECT prompt_text FROM prompts WHERE prompt_type = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$type]);
        if ($row && trim((string) $row['prompt_text']) !== '') return (string) $row['prompt_text'];
    } catch (\Throwable $e) {
    }
    return prompt_library()[$type][3] ?? '';
}

/** البرومبت بعد تبديل المتغيرات — {data} مكانه، أو بعد التعليمات لو الأدمن شاله */
function prompt_fill(string $type, array $vars = [], array $opt = []): string
{
    $tpl = prompt_get($type);
    $data = (string) ($vars['data'] ?? '');
    unset($vars['data']);
    // برومبت كامل (v1): المتغيرات بتتملى بالبيانات الحقيقية · شكل الرد بيحطه الكود · البيانات الزيادة بس تحت
    if (prompt_is_full($tpl)) {
        $base = !empty($opt['uid']) ? prompt_brand_vars((int) $opt['uid'], $opt['brand'] ?? null, $opt['agent'] ?? 'copy', $opt + ['identity' => !isset($vars['brand_identity'])]) : [];
        $out = prompt_apply($tpl, $vars + $base, $opt + ['strip_output' => true]);
        $data = trim((string) ($opt['extra'] ?? ''));
        return $data === '' ? $out : rtrim($out) . "\n\n## بيانات إضافية من السيستم\n" . $data;
    }
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string) $v;
    $out = strtr($tpl, $map);
    if ($data === '') return trim(str_replace('{data}', '', $out));
    return str_contains($out, '{data}') ? str_replace('{data}', $data, $out) : rtrim($out) . "\n\n" . $data;
}

/** أول مرة: كل نوع بيتحفظ نسخة 1 بالنص الافتراضي (علشان يظهر في الأدمن ويتعدّل) */
function prompt_seed_library(): void
{
    try {
        $have = array_flip(array_column(db_all('SELECT DISTINCT prompt_type FROM prompts'), 'prompt_type'));
        foreach (prompt_library() as $type => [$label, , , $text]) {
            if (isset($have[$type])) continue;
            db_insert('INSERT INTO prompts (prompt_type, title, prompt_text, version, is_active) VALUES (?,?,?,1,1)', [$type, 'النسخة الأصلية', $text]);
        }
    } catch (\Throwable $e) {
    }
}

/* ═══════════ معايير التقييم (بتتعدّل من الأدمن مع برومبت التقييم) ═══════════ */

function eval_criteria(): array
{
    $raw = trim((string) get_setting('campaign_eval_cats', ''));
    $list = $raw !== '' ? array_values(array_filter(array_map('trim', preg_split('/\R|،|,/u', $raw)))) : [];
    return $list ? array_slice($list, 0, 10)
        : ['قوة الفكرة', 'قوة الـ Hook', 'مناسبة الجمهور', 'وضوح الرسالة', 'قوة CTA', 'التوافق مع البراند', 'مناسبة للعميل المثالي'];
}

function eval_ready_score(): int
{
    return max(50, min(95, (int) get_setting('campaign_eval_ready', '80')));
}

/* ═══════════ مكتبة v1 (Egyptian Content DNA) — استيراد كنسخ جديدة ═══════════ */

/** نوع الموقع => [المفتاح الأصلي, العنوان, المتغيرات, LEARNINGS, النص] */
function prompt_pack(): array
{
    static $p = null;
    return $p ??= (array) (include __DIR__ . '/prompt-pack-v1.php');
}

/** النص المفعّل في الجدول (من غير الافتراضي) */
function prompt_db_active(string $type): string
{
    try {
        $r = db_one('SELECT prompt_text FROM prompts WHERE prompt_type = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$type]);
        return (string) ($r['prompt_text'] ?? '');
    } catch (\Throwable $e) {
        return '';
    }
}

/** حالة كل نوع: v1 (مستورد زي ما هو) · edited (v1 واتعدّل) · old (البرومبت القديم) */
function prompt_pack_status(): array
{
    $out = [];
    foreach (prompt_pack() as $type => $p) {
        if ($type === 'content_alt') continue;
        $cur = trim(prompt_db_active($type));
        $out[$type] = $cur === trim($p[4]) ? 'v1' : (prompt_is_full($cur) ? 'edited' : 'old');
    }
    return $out;
}

/**
 * استيراد: كل نوع بياخد نسخة جديدة مفعّلة بنص v1 — النسخ القديمة بتفضل في السجل وتترجع بزرار
 * «البرومبت الأساسي» البديل بيتحفظ نسخة غير مفعّلة جنب «كتابة المنشور»
 */
function prompt_pack_import(array $types): array
{
    $pack = prompt_pack();
    $done = [];
    foreach ($types as $type) {
        if ($type === 'content_alt' || !isset($pack[$type])) continue;
        if (trim(prompt_db_active($type)) === trim($pack[$type][4])) continue;   // مستورد خلاص
        $v = (int) (db_one('SELECT MAX(version) v FROM prompts WHERE prompt_type = ?', [$type])['v'] ?? 0);
        if ($type === 'content' && isset($pack['content_alt'])) {
            db_insert('INSERT INTO prompts (prompt_type, title, prompt_text, version, is_active) VALUES (?,?,?,?,0)',
                [$type, 'v1 — ' . $pack['content_alt'][1] . ' (بديل)', $pack['content_alt'][4], ++$v]);
        }
        db_run('UPDATE prompts SET is_active = 0 WHERE prompt_type = ?', [$type]);
        db_insert('INSERT INTO prompts (prompt_type, title, prompt_text, version, is_active) VALUES (?,?,?,?,1)',
            [$type, 'مكتبة v1 — ' . $pack[$type][1], $pack[$type][4], ++$v]);
        $done[] = $type;
    }
    return $done;
}

/** معايير التقييم بتاعة v1 (Spread Content Score) — بالعربي */
function prompt_pack_eval_criteria(): array
{
    return ['قوة الـ Hook', 'مناسبة الجمهور', 'الاحتفاظ بالانتباه', 'القيمة', 'الارتباط العاطفي', 'قابلية المشاركة',
            'الثقة والمصداقية', 'إمكانية التصميم والإنتاج', 'الـ CTA وملاءمة البيزنس', 'مناسبة للعميل المثالي'];
}

/** النص الحالي لأي نوع (مكتبة الكود أو الأنواع القديمة) */
function prompt_text_for(string $type): string
{
    if (isset(prompt_library()[$type])) return prompt_get($type);
    return function_exists('get_active_prompt') ? get_active_prompt($type) : prompt_db_active($type);
}

/** النوع ده برومبت كامل (v1)؟ — الكود بيحط البيانات في المتغيرات بدل ما يكررها تحت */
function prompt_full(string $type): bool
{
    return prompt_is_full(prompt_text_for($type));
}

/**
 * البرومبت بعد ملء المتغيرات (الكود + «معاينة» الأدمن)
 * opt: uid · brand · agent · strip_output · addon · dna · brief · overrides
 */
function prompt_render(string $type, array $vars = [], array $opt = []): string
{
    $base = !empty($opt['uid']) ? prompt_brand_vars((int) $opt['uid'], $opt['brand'] ?? null, $opt['agent'] ?? 'copy', $opt + ['identity' => !isset($vars['brand_identity'])]) : [];
    return prompt_apply(prompt_text_for($type), $vars + $base, $opt);
}
