<?php
/**
 * Spread AI — Brand Kit: الهوية المنظّمة اللي بتأثر على كل المحتوى
 *
 *   brand_kits (JSON + نسخ) · خدمات · مواضيع · شخصيات ثابتة · أشكال المحتوى · التعلّم من التعديلات
 *   الأدمن: قوالب القطاعات · مكتبة الـ Presets · مكتبة التعلّم (طرق كتابة · Hooks · قواعد · مراجع ← مواضيع)
 *
 * ممنوع أي Agent يقرا الـ Brand Kit مباشرة — كله عن طريق BrandContext (تحت).
 * بيانات brand_profiles القديمة بتفضل زي ما هي (الاسم · المجال · العنوان · الكلمات · اللهجة · الألوان)
 * وبتتزامن مع الأقسام اللي تخصها، علشان المسارات القديمة تفضل شغالة.
 */

require_once __DIR__ . '/db.php';

const BK_SCHEMA_V = '1-p1';
const BK_SECTIONS = ['business', 'audience', 'personality', 'logo', 'colors', 'typography', 'design_style', 'visuals', 'voice', 'pillars', 'content', 'offers', 'forbidden_topics', 'focus', 'cta'];
const BK_AGENTS = ['brand', 'ideas', 'copy', 'design', 'video', 'schedule'];
const BK_FORMATS = ['carousel', 'reel', 'single_image', 'story', 'text'];

/* ═══════════════ الجداول ═══════════════ */

function bk_schema_sql(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS brand_kits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sector_template_id INT NULL,
            data LONGTEXT NOT NULL,
            completeness TINYINT NOT NULL DEFAULT 0,
            version INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bk_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_kit_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand_kit_id INT NOT NULL,
            version INT NOT NULL,
            data LONGTEXT NOT NULL,
            changed_by ENUM('user','ai','admin') NOT NULL DEFAULT 'user',
            note VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bkv_kit (brand_kit_id, version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
            is_focus TINYINT(1) NOT NULL DEFAULT 0,
            seasons TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bs_user (user_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_topics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            service_id INT NULL,
            title VARCHAR(255) NOT NULL,
            source ENUM('user','ai','template','library') NOT NULL DEFAULT 'user',
            times_used INT NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bt_user (user_id, is_active),
            KEY idx_bt_service (service_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_characters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            type ENUM('real_person','ai_character','mascot') NOT NULL,
            role VARCHAR(150) NULL,
            description TEXT NULL,
            personality TEXT NULL,
            use_in VARCHAR(255) NULL,
            share_percent TINYINT NOT NULL DEFAULT 0,
            voice_id VARCHAR(100) NULL,
            consent_confirmed TINYINT(1) NOT NULL DEFAULT 0,
            consent_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bc_user (user_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_character_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            character_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bci_char (character_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_formats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            format ENUM('carousel','reel','single_image','story','text') NOT NULL,
            `rank` TINYINT NOT NULL DEFAULT 1,
            per_week TINYINT NOT NULL DEFAULT 0,
            settings TEXT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_bf (user_id, format)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            content_id INT NULL,
            field VARCHAR(100) NULL,
            action ENUM('like','dislike','edit','regenerate','chip') NOT NULL,
            before_value TEXT NULL,
            after_value TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bfb_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_suggestions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            field VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            proposed_value TEXT NULL,
            status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            KEY idx_bsg_user (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS sector_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            keywords VARCHAR(500) NULL,
            brief_questions TEXT NULL,
            default_pillars TEXT NULL,
            default_topics TEXT NULL,
            forbidden TEXT NULL,
            notes TEXT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS style_presets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind ENUM('design_style','palette','tone','visual','character_style') NOT NULL,
            name VARCHAR(100) NOT NULL,
            preview_path VARCHAR(255) NULL,
            data TEXT NULL,
            sector_template_id INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_sp_kind (kind, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // مكتبة التعلّم (الأدمن): طرق كتابة · قوالب Hooks · قواعد · مراجع ← بيطلع منها مواضيع وزوايا
        "CREATE TABLE IF NOT EXISTS brand_learning (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind ENUM('method','hooks','rules','topics','reference') NOT NULL DEFAULT 'reference',
            title VARCHAR(200) NOT NULL,
            summary VARCHAR(500) NULL,
            body MEDIUMTEXT NULL,
            source_url VARCHAR(500) NULL,
            sector_template_id INT NULL,
            agents VARCHAR(100) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            extracted_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bl_kind (kind, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS brand_personas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            emoji VARCHAR(8) NULL,
            age VARCHAR(40) NULL,
            gender VARCHAR(30) NULL,
            job VARCHAR(150) NULL,
            location VARCHAR(150) NULL,
            pains TEXT NULL,
            desires TEXT NULL,
            objections TEXT NULL,
            triggers TEXT NULL,
            channels VARCHAR(255) NULL,
            service_ids VARCHAR(255) NULL,
            voice VARCHAR(255) NULL,
            source ENUM('ai','user') NOT NULL DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bp_user (user_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS learning_topics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            learning_id INT NULL,
            sector_template_id INT NULL,
            title VARCHAR(255) NOT NULL,
            angle VARCHAR(100) NULL,
            pillar VARCHAR(100) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            times_used INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_lt_sector (sector_template_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** أعمدة جديدة على جداول موجودة (مفيش حذف ولا تعديل لبيانات) */
function bk_schema_columns(): array
{
    return [
        'contents' => [
            'brand_overrides' => 'TEXT NULL', 'brand_snapshot' => 'MEDIUMTEXT NULL', 'ai_reasoning' => 'TEXT NULL',
            'brief_json' => 'TEXT NULL', 'bk_service_id' => 'INT NULL', 'bk_topic_id' => 'INT NULL', 'bk_character_id' => 'INT NULL',
            'feedback' => 'TINYINT NULL',
        ],
        'campaigns' => ['brief_json' => 'TEXT NULL'],
        'campaign_ideas' => ['brief_json' => 'TEXT NULL'],
    ];
}

function bk_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (get_setting('bk_schema_v', '0') === BK_SCHEMA_V) return;
        foreach (bk_schema_sql() as $sql) db_run($sql);
        foreach (bk_schema_columns() as $table => $cols) {
            $have = array_column(db_all("SHOW COLUMNS FROM `$table`"), 'Field');
            foreach ($cols as $col => $def) {
                if (!in_array($col, $have, true)) db_run("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            }
        }
        bk_seed_defaults();
        set_setting('bk_schema_v', BK_SCHEMA_V);
    } catch (\Throwable $e) {
        error_log('[brand-kit] schema: ' . $e->getMessage());
    }
}

/** بيانات بداية للأدمن (قطاعات · Presets · طرق كتابة) — مرة واحدة ولو الجداول فاضية */
function bk_seed_defaults(): void
{
    if (!(int) (db_one('SELECT COUNT(*) n FROM sector_templates')['n'] ?? 0)) {
        $sectors = [
            ['طبي وعيادات', 'عيادة,طبي,دكتور,أسنان,تجميل,مستشفى,مركز طبي,جلدية,علاج طبيعي',
             [['name' => 'توعية وطمأنة', 'percent' => 35], ['name' => 'نتائج وحالات (بموافقة)', 'percent' => 20], ['name' => 'الفريق والمكان', 'percent' => 15], ['name' => 'عروض وحجز', 'percent' => 20], ['name' => 'أسئلة شائعة', 'percent' => 10]],
             ['إمتى لازم تزور الدكتور', 'خرافات شائعة وتصحيحها', 'إيه اللي بيحصل في أول زيارة', 'نصايح بعد العلاج', 'أسئلة المرضى المتكررة'],
             ['مضمون 100%', 'بدون ألم نهائيًا', 'أفضل دكتور في مصر', 'علاج نهائي', 'نتائج مضمونة']],
            ['مطاعم وكافيهات', 'مطعم,كافيه,أكل,حلويات,مخبز,مشويات,بيتزا',
             [['name' => 'الأكل والمنيو', 'percent' => 35], ['name' => 'عروض', 'percent' => 25], ['name' => 'كواليس المطبخ', 'percent' => 15], ['name' => 'تفاعل وآراء العملاء', 'percent' => 25]],
             ['طبق اليوم', 'إزاي بنحضّر الصنف الأشهر', 'عرض العيلة', 'رأي عميل', 'سؤال: تختار إيه؟'],
             ['أنضف مطعم في البلد']],
            ['تعليم وكورسات', 'أكاديمية,كورس,تدريب,تعليم,مدرسة,حضانة,دروس',
             [['name' => 'معلومة مفيدة', 'percent' => 35], ['name' => 'قصص نجاح', 'percent' => 20], ['name' => 'عن الكورسات والحجز', 'percent' => 25], ['name' => 'تفاعل', 'percent' => 20]],
             ['غلطة شائعة بيقع فيها المبتدئين', 'خطة مذاكرة في 3 خطوات', 'قصة نجاح طالب', 'مواعيد الدفعة الجديدة'],
             ['مضمون تشتغل بعد الكورس']],
            ['عقارات', 'عقار,شقق,كمبوند,تطوير عقاري,وحدات,فيلا',
             [['name' => 'المشروعات والوحدات', 'percent' => 35], ['name' => 'نصايح شراء واستثمار', 'percent' => 25], ['name' => 'أنظمة سداد وعروض', 'percent' => 25], ['name' => 'ثقة ومصداقية', 'percent' => 15]],
             ['إزاي تختار وحدة مناسبة', 'أنظمة السداد ببساطة', 'جولة في المشروع', 'أسئلة قبل التعاقد'],
             ['عائد مضمون']],
            ['متاجر وملابس', 'ملابس,متجر,أزياء,شنط,أحذية,اكسسوارات,بيوتي,مستحضرات',
             [['name' => 'المنتجات', 'percent' => 40], ['name' => 'تنسيقات واستايل', 'percent' => 20], ['name' => 'عروض', 'percent' => 25], ['name' => 'آراء العملاء', 'percent' => 15]],
             ['تنسيق الأسبوع', 'وصل حديثًا', 'إزاي تختار مقاسك', 'عرض نهاية الأسبوع'],
             []],
        ];
        foreach ($sectors as $i => [$name, $kw, $pillars, $topics, $forbidden]) {
            db_insert('INSERT INTO sector_templates (name, keywords, brief_questions, default_pillars, default_topics, forbidden, is_published, sort_order) VALUES (?,?,?,?,?,?,1,?)',
                [$name, $kw, json_encode([], JSON_UNESCAPED_UNICODE), json_encode($pillars, JSON_UNESCAPED_UNICODE),
                 json_encode($topics, JSON_UNESCAPED_UNICODE), json_encode($forbidden, JSON_UNESCAPED_UNICODE), $i + 1]);
        }
    }
    if (!(int) (db_one('SELECT COUNT(*) n FROM style_presets')['n'] ?? 0)) {
        $presets = [
            ['design_style', 'بسيط ونضيف', ['desc' => 'مساحات بيضا، عناصر قليلة، خط واضح', 'en' => 'minimal, clean, lots of white space, one focal element']],
            ['design_style', 'فخم وهادي', ['desc' => 'ألوان غامقة، ذهبي خفيف، تفاصيل راقية', 'en' => 'premium, elegant, dark tones with subtle gold accents']],
            ['design_style', 'مرح وملوّن', ['desc' => 'ألوان مبهجة، أشكال دايرية، إحساس شبابي', 'en' => 'playful, colorful, rounded shapes, youthful']],
            ['design_style', 'طبي موثوق', ['desc' => 'أزرق وأبيض، أيقونات خط رفيع، صور حقيقية', 'en' => 'trustworthy medical look, blue and white, thin line icons, real photos']],
            ['palette', 'أزرق وتركواز', ['colors' => ['#0C87EF', '#2EE3CC', '#0B1526', '#F7FAFD']]],
            ['palette', 'أخضر طبيعي', ['colors' => ['#2E7D32', '#A5D6A7', '#FFB300', '#FAFAF5']]],
            ['palette', 'أسود وذهبي', ['colors' => ['#111111', '#C9A227', '#3A3A3A', '#F5F1E6']]],
            ['tone', 'قريب وودود', ['voice' => ['formal_friendly' => 80, 'serious_fun' => 45, 'scientific_simple' => 80]]],
            ['tone', 'رسمي وخبير', ['voice' => ['formal_friendly' => 25, 'serious_fun' => 15, 'scientific_simple' => 45]]],
            ['tone', 'خفيف ومرح', ['voice' => ['formal_friendly' => 90, 'serious_fun' => 80, 'scientific_simple' => 90]]],
            ['visual', 'صور حقيقية مشرقة', ['visuals' => ['kind' => 'photo', 'light' => 'bright', 'people' => 'egyptian']]],
            ['visual', '3D حديث', ['visuals' => ['kind' => '3d', 'light' => 'bright', 'people' => 'characters_only']]],
            ['visual', 'رسوم فلات', ['visuals' => ['kind' => 'flat', 'light' => 'bright', 'people' => 'none']]],
            ['character_style', 'ماسكوت كرتوني', ['desc' => 'شخصية كرتونية بسيطة بألوان البراند', 'en' => 'friendly cartoon mascot in brand colors, simple shapes']],
        ];
        foreach ($presets as $i => [$kind, $name, $data]) {
            db_insert('INSERT INTO style_presets (kind, name, data, sort_order) VALUES (?,?,?,?)', [$kind, $name, json_encode($data, JSON_UNESCAPED_UNICODE), $i]);
        }
    }
    if (!(int) (db_one('SELECT COUNT(*) n FROM brand_learning')['n'] ?? 0)) {
        $lib = [
            ['method', 'AIDA', 'انتباه ← اهتمام ← رغبة ← فعل', "1) Attention: جملة أولى تشد (سؤال أو رقم أو مشكلة).\n2) Interest: معلومة أو تفصيلة تخلّيه يكمّل.\n3) Desire: الفايدة اللي هتفرق معاه هو.\n4) Action: خطوة واحدة واضحة (احجز، ابعت، اطلب)."],
            ['method', 'PAS', 'مشكلة ← تضخيم ← حل', "1) Problem: سمّي المشكلة اللي العميل حاسس بيها بكلامه هو.\n2) Agitate: وضّح تأثيرها لو فضلت.\n3) Solution: الخدمة كحل طبيعي مش إعلان مباشر."],
            ['method', 'قصة قبل وبعد', 'قبل ← نقطة التحول ← بعد', "احكي الوضع قبل، بعدين اللحظة اللي اتغير فيها كل حاجة (الخدمة)، وبعدين النتيجة. من غير مبالغة ومن غير وعود."],
            ['method', 'سؤال وجواب', 'سؤال شائع ← إجابة بسيطة ← دعوة', "ابدأ بسؤال بيتسأل كتير بنفس كلام الناس، جاوب في 3 نقط بسيطة، واختم بدعوة للسؤال في الكومنت أو الحجز."],
            ['hooks', 'قوالب Hooks مجرّبة', 'أول جملة بتشد', "- «غلطة بيقع فيها 9 من كل 10…»\n- «لو بتعاني من … الكلام ده ليك»\n- «محدش هيقولك الحقيقة دي عن …»\n- «3 حاجات لازم تعرفها قبل …»\n- «سؤال بيتسأل كل يوم: …؟»\n- «إيه الفرق بين … و…؟»"],
            ['rules', 'قواعد عامة للكتابة', 'بتتطبق على كل المحتوى', "- جملة أولى قصيرة ومفهومة من غير ما يكمّل.\n- فكرة واحدة بس في البوست.\n- CTA واحد واضح.\n- ماتخترعش أرقام أو أسعار أو نتائج.\n- اكتب بكلام الجمهور مش بكلام المتخصصين."],
        ];
        foreach ($lib as [$kind, $title, $summary, $body]) {
            db_insert('INSERT INTO brand_learning (kind, title, summary, body, agents) VALUES (?,?,?,?,?)',
                [$kind, $title, $summary, $body, $kind === 'rules' ? 'ideas,copy' : ($kind === 'hooks' ? 'copy' : 'copy')]);
        }
    }
}

/* ═══════════════ الأساسيات ═══════════════ */

function bk_json($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bk_arr($v): array
{
    if (is_array($v)) return $v;
    $j = json_decode((string) $v, true);
    return is_array($j) ? $j : [];
}

function bk_defaults(): array
{
    return [
        'business'     => ['name' => '', 'sector' => '', 'location' => '', 'usp' => '', 'price_range' => ''],
        'audience'     => ['description' => '', 'age' => '', 'gender' => '', 'pains' => [], 'objections' => []],
        'personality'  => [],
        'logo'         => ['position' => 'bottom-right', 'variants' => ['light' => '', 'dark' => '', 'icon' => '']],
        'colors'       => ['primary' => '', 'secondary' => '', 'accent' => '', 'neutral' => '', 'ratio' => '60-30-10', 'preset_id' => null],
        'typography'   => ['ar' => 'Cairo', 'en' => 'Inter'],
        'design_style' => ['preset_id' => null, 'icons' => 'thin_line', 'notes' => '', 'avoid' => [], 'use_inspirations' => true],
        'visuals'      => ['preset_id' => null, 'kind' => '', 'light' => '', 'people' => '', 'places' => [], 'brand_color_in_photos' => 'subtle', 'avoid' => []],
        'voice'        => ['preset_id' => null, 'formal_friendly' => 60, 'serious_fun' => 40, 'scientific_simple' => 70, 'dialect' => 'egyptian', 'emoji' => true,
                           'words_use' => [], 'words_avoid' => [], 'sample_lines' => []],
        'pillars'      => [],
        'content'      => ['goal' => 'trust', 'hooks' => ['question', 'story'], 'caption_length' => 'medium', 'platforms' => ['facebook', 'instagram'], 'posts_per_month' => 12],
        'offers'       => [],
        'forbidden_topics' => [],
        'focus'        => ['mode' => 'balanced', 'service_id' => null, 'month' => ''],
        'cta'          => ['type' => '', 'text' => '', 'link' => ''],
        'confidence'   => [],
    ];
}

/** دمج عميق للبيانات على القيم الافتراضية (القوائم بتتستبدل كاملة) */
function bk_merge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && $base[$k] !== [] && array_keys($base[$k]) !== range(0, count($base[$k]) - 1)) {
            $base[$k] = bk_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function bk_brand_profile(int $uid): ?array
{
    try {
        return db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$uid]) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function bk_lines(?string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\n،,]+/u', (string) $s) ?: []), fn($x) => $x !== ''));
}

/** الـ Kit بتاع العميل (بيتعمل لو مش موجود) + الحقول اللي جاية من brand_profiles */
function bk_get(int $uid): array
{
    bk_ensure_schema();
    $row = db_one('SELECT * FROM brand_kits WHERE user_id = ?', [$uid]);
    if (!$row) {
        $sector = bk_detect_sector($uid);
        db_run('INSERT IGNORE INTO brand_kits (user_id, sector_template_id, data, completeness, version) VALUES (?,?,?,0,1)',
            [$uid, $sector, bk_json(new \stdClass())]);
        $row = db_one('SELECT * FROM brand_kits WHERE user_id = ?', [$uid]);
    }
    $data = bk_merge(bk_defaults(), bk_arr($row['data'] ?? '{}'));

    // brand_profiles هو المصدر للحقول المشتركة (أي مكان يعدّلها — Brand Brain · المساعد · التجربة · الأدمن — بيظهر هنا)
    if ($bp = bk_brand_profile($uid)) {
        bk_reconcile_services($uid, $bp);
        bk_migrate_personal($uid, $bp);
        $data['business']['name'] = trim((string) $bp['business_name']) ?: $data['business']['name'];
        $data['business']['sector'] = trim((string) $bp['industry']) ?: $data['business']['sector'];
        $data['business']['location'] = trim((string) ($bp['address'] ?? '')) ?: $data['business']['location'];
        $data['business']['description'] = (string) ($bp['description'] ?? '');
        $data['audience']['description'] = trim((string) $bp['audience']) ?: $data['audience']['description'];
        $data['voice']['words_use'] = !empty($bp['keywords_use']) ? bk_lines($bp['keywords_use']) : $data['voice']['words_use'];
        $data['voice']['words_avoid'] = !empty($bp['keywords_avoid']) ? bk_lines($bp['keywords_avoid']) : $data['voice']['words_avoid'];
        if (!empty($bp['dialect']) && !($data['voice']['dialect'] === 'mixed' && bk_dialect_key((string) $bp['dialect']) === 'egyptian')) {
            $data['voice']['dialect'] = bk_dialect_key((string) $bp['dialect']);
        }
        if (!$data['colors']['primary'] && !empty($bp['colors'])) {
            preg_match_all('/#[0-9a-f]{6}\b/i', (string) $bp['colors'], $m);
            foreach (['primary', 'secondary', 'accent', 'neutral'] as $i => $k) if (!empty($m[0][$i])) $data['colors'][$k] = strtoupper($m[0][$i]);
        }
        $data['logo']['path'] = (string) ($bp['logo_path'] ?? '');
    }
    return [
        'id' => (int) $row['id'], 'user_id' => $uid, 'sector_template_id' => $row['sector_template_id'] !== null ? (int) $row['sector_template_id'] : null,
        'data' => $data, 'completeness' => (int) $row['completeness'], 'version' => (int) $row['version'], 'updated_at' => $row['updated_at'],
    ];
}

function bk_dialect_key(string $d): string
{
    return match ($d) {
        'gulf', 'khaleeji' => 'gulf',
        'msa' => 'msa',
        'levantine' => 'levantine',
        'mixed' => 'mixed',
        default => 'egyptian',
    };
}

/** اللهجة زي ما بتتخزن في brand_profiles (Brand Brain بيستخدم khaleeji) */
function bk_dialect_store(string $d): string
{
    return ['gulf' => 'khaleeji', 'mixed' => 'egyptian'][bk_dialect_key($d)] ?? bk_dialect_key($d);
}

/** كل مفاتيح اللهجة الموجودة في المنصة (فورم المنشور · Brand Brain · الـ Kit) ← وصف واحد للبرومبت */
function bk_dialect_label(string $d): string
{
    return match (bk_dialect_key($d)) {
        'gulf' => 'اللهجة الخليجية البيضاء',
        'msa' => 'العربية الفصحى المبسطة',
        'levantine' => 'اللهجة الشامية',
        'mixed' => 'مصري خفيف مع كلمات فصحى بسيطة',
        default => 'اللهجة المصرية الخفيفة',
    };
}

/** تنضيف قسم قبل الحفظ (أنواع + أطوال) */
function bk_clean_section(string $section, $value)
{
    $str = fn($v, $n = 300) => mb_substr(trim((string) $v), 0, $n);
    $list = function ($v, $n = 20, $len = 120) {
        $v = is_array($v) ? $v : bk_lines((string) $v);
        return array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, $len), $v), fn($x) => $x !== ''), 0, $n));
    };
    $hex = fn($v) => preg_match('/^#[0-9a-f]{6}$/i', trim((string) $v)) ? strtoupper(trim((string) $v)) : '';
    $pct = fn($v) => max(0, min(100, (int) $v));
    $v = is_array($value) ? $value : [];
    $d = bk_defaults()[$section] ?? null;

    switch ($section) {
        case 'business':
            return ['name' => $str($v['name'] ?? '', 200), 'sector' => $str($v['sector'] ?? '', 150), 'location' => $str($v['location'] ?? '', 300),
                    'usp' => $str($v['usp'] ?? '', 500), 'price_range' => $str($v['price_range'] ?? '', 100)];
        case 'audience':
            return ['description' => $str($v['description'] ?? '', 600), 'age' => $str($v['age'] ?? '', 60), 'gender' => $str($v['gender'] ?? '', 40),
                    'pains' => $list($v['pains'] ?? []), 'objections' => $list($v['objections'] ?? [])];
        case 'personality':
            return $list($value, 6, 40);
        case 'logo':
            return ['position' => in_array($v['position'] ?? '', ['top-right', 'top-left', 'bottom-right', 'bottom-left', 'center'], true) ? $v['position'] : 'bottom-right',
                    'variants' => $d['variants']];
        case 'colors':
            return ['primary' => $hex($v['primary'] ?? ''), 'secondary' => $hex($v['secondary'] ?? ''), 'accent' => $hex($v['accent'] ?? ''), 'neutral' => $hex($v['neutral'] ?? ''),
                    'ratio' => in_array($v['ratio'] ?? '', ['60-30-10', '70-20-10', '50-30-20'], true) ? $v['ratio'] : '60-30-10',
                    'preset_id' => !empty($v['preset_id']) ? (int) $v['preset_id'] : null];
        case 'typography':
            return ['ar' => $str($v['ar'] ?? 'Cairo', 40) ?: 'Cairo', 'en' => $str($v['en'] ?? 'Inter', 40) ?: 'Inter'];
        case 'design_style':
            return ['preset_id' => !empty($v['preset_id']) ? (int) $v['preset_id'] : null,
                    'icons' => in_array($v['icons'] ?? '', ['thin_line', 'filled', 'hand_drawn', 'none'], true) ? $v['icons'] : 'thin_line',
                    'notes' => $str($v['notes'] ?? '', 600), 'avoid' => $list($v['avoid'] ?? []), 'use_inspirations' => !array_key_exists('use_inspirations', $v) || !empty($v['use_inspirations'])];
        case 'visuals':
            return ['preset_id' => !empty($v['preset_id']) ? (int) $v['preset_id'] : null,
                    'kind' => in_array($v['kind'] ?? '', ['photo', '3d', 'flat', 'mixed'], true) ? $v['kind'] : '',
                    'light' => in_array($v['light'] ?? '', ['bright', 'warm', 'dramatic'], true) ? $v['light'] : '',
                    'people' => in_array($v['people'] ?? '', ['egyptian', 'arab', 'characters_only', 'none'], true) ? $v['people'] : '',
                    'places' => $list($v['places'] ?? [], 10, 60),
                    'brand_color_in_photos' => in_array($v['brand_color_in_photos'] ?? '', ['none', 'subtle', 'strong'], true) ? $v['brand_color_in_photos'] : 'subtle',
                    'avoid' => $list($v['avoid'] ?? [])];
        case 'voice':
            return ['preset_id' => !empty($v['preset_id']) ? (int) $v['preset_id'] : null,
                    'formal_friendly' => $pct($v['formal_friendly'] ?? 60), 'serious_fun' => $pct($v['serious_fun'] ?? 40), 'scientific_simple' => $pct($v['scientific_simple'] ?? 70),
                    'dialect' => bk_dialect_key((string) ($v['dialect'] ?? 'egyptian')), 'emoji' => !empty($v['emoji']),
                    'words_use' => $list($v['words_use'] ?? [], 30, 60), 'words_avoid' => $list($v['words_avoid'] ?? [], 40, 60),
                    'sample_lines' => $list($v['sample_lines'] ?? [], 5, 300)];
        case 'pillars':
            $out = [];
            foreach (array_slice(is_array($value) ? $value : [], 0, 8) as $p) {
                $n = $str(is_array($p) ? ($p['name'] ?? '') : $p, 80);
                if ($n !== '') $out[] = ['name' => $n, 'percent' => $pct(is_array($p) ? ($p['percent'] ?? 0) : 0)];
            }
            return $out;
        case 'content':
            $hooks = array_values(array_intersect((array) ($v['hooks'] ?? []), array_keys(bk_hook_types())));
            $plats = array_values(array_intersect((array) ($v['platforms'] ?? []), ['facebook', 'instagram', 'tiktok', 'linkedin']));
            return ['goal' => in_array($v['goal'] ?? '', ['bookings', 'sales', 'reach', 'trust', 'engagement'], true) ? $v['goal'] : 'trust',
                    'hooks' => $hooks ?: ['question'], 'caption_length' => in_array($v['caption_length'] ?? '', ['short', 'medium', 'long'], true) ? $v['caption_length'] : 'medium',
                    'platforms' => $plats ?: ['facebook', 'instagram'], 'posts_per_month' => max(1, min(90, (int) ($v['posts_per_month'] ?? 12)))];
        case 'offers':
            $out = [];
            foreach (array_slice(is_array($value) ? $value : [], 0, 10) as $o) {
                $t = $str(is_array($o) ? ($o['title'] ?? '') : $o, 200);
                if ($t === '') continue;
                $until = is_array($o) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($o['until'] ?? '')) ? $o['until'] : '';
                $out[] = ['title' => $t, 'details' => $str(is_array($o) ? ($o['details'] ?? '') : '', 400), 'until' => $until];
            }
            return $out;
        case 'forbidden_topics':
            return $list($value, 30, 120);
        case 'focus':
            return ['mode' => ($v['mode'] ?? '') === 'service' ? 'service' : 'balanced',
                    'service_id' => !empty($v['service_id']) ? (int) $v['service_id'] : null, 'month' => date('Y-m')];
        case 'cta':
            return ['type' => isset(bk_cta_types()[$v['type'] ?? '']) ? $v['type'] : '', 'text' => $str($v['text'] ?? '', 200), 'link' => $str($v['link'] ?? '', 300)];
    }
    return null;
}

/** حفظ قسم = نسخة جديدة + مزامنة brand_profiles + تحديث الاكتمال */
function bk_save_section(int $uid, string $section, $value, string $by = 'user', string $note = ''): array
{
    if (!in_array($section, BK_SECTIONS, true)) return ['ok' => false, 'error' => 'قسم غير معروف'];
    $kit = bk_get($uid);
    $clean = bk_clean_section($section, $value);
    $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
    $stored = bk_arr($row['data'] ?? '{}');
    $stored[$section] = $clean;
    if (isset($stored['confidence'][$section])) unset($stored['confidence'][$section]);   // العميل أكّد
    bk_write($kit['id'], $uid, $stored, $by, $note ?: ('تعديل: ' . bk_section_label($section)));
    bk_sync_profile($uid, $section, $clean);
    return ['ok' => true, 'kit' => bk_get($uid)];
}

/** كتابة البيانات كلها + نسخة */
function bk_write(int $kitId, int $uid, array $stored, string $by, string $note): void
{
    $by = in_array($by, ['user', 'ai', 'admin'], true) ? $by : 'user';
    unset($stored['_profile']);
    $json = bk_json($stored);
    db_run('UPDATE brand_kits SET data = ?, version = version + 1 WHERE id = ?', [$json, $kitId]);
    $v = (int) (db_one('SELECT version FROM brand_kits WHERE id = ?', [$kitId])['version'] ?? 1);
    // النسخة = الـ Kit + الحقول الأساسية من brand_profiles (الرجوع لنسخة بيرجّع الاتنين)
    $snap = $stored + ['_profile' => bk_profile_snapshot($uid)];
    db_insert('INSERT INTO brand_kit_versions (brand_kit_id, version, data, changed_by, note) VALUES (?,?,?,?,?)', [$kitId, $v, bk_json($snap), $by, mb_substr($note, 0, 255)]);
    bk_update_completeness($uid);
    if (class_exists('BrandContext', false)) BrandContext::flush($uid);
}

/** الحقول الأساسية من brand_profiles اللي بتتحفظ مع كل نسخة */
function bk_profile_fields(): array
{
    return ['business_name', 'industry', 'description', 'audience', 'services', 'tone', 'dialect', 'keywords_use', 'keywords_avoid', 'colors',
            'address', 'phones', 'whatsapp', 'working_hours', 'website', 'social_facebook', 'social_instagram', 'social_tiktok', 'social_linkedin', 'design_rules'];
}

function bk_profile_snapshot(int $uid): array
{
    $bp = bk_brand_profile($uid) ?: [];
    $out = [];
    foreach (bk_profile_fields() as $f) if (array_key_exists($f, $bp)) $out[$f] = $bp[$f];
    return $out;
}

/** رجوع لنسخة: الـ Kit + الحقول الأساسية */
function bk_restore_version(int $uid, int $version, string $by, string $note): bool
{
    $kit = bk_get($uid);
    $v = db_one('SELECT data FROM brand_kit_versions WHERE brand_kit_id = ? AND version = ?', [$kit['id'], $version]);
    if (!$v) return false;
    $data = bk_arr($v['data']);
    $profile = (array) ($data['_profile'] ?? []);
    unset($data['_profile']);
    if ($profile && ($bp = bk_brand_profile($uid))) {
        $set = [];
        $vals = [];
        foreach (bk_profile_fields() as $f) {
            if (array_key_exists($f, $profile) && array_key_exists($f, $bp)) { $set[] = "`$f` = ?"; $vals[] = $profile[$f]; }
        }
        if ($set) { $vals[] = $bp['id']; db_run('UPDATE brand_profiles SET ' . implode(', ', $set) . ' WHERE id = ?', $vals); }
        // الخدمات: نص النسخة ← الجدول
        if (array_key_exists('services', $profile)) bk_services_from_text($uid, (string) $profile['services'], true);
    }
    bk_write($kit['id'], $uid, $data, $by, $note);
    return true;
}

/**
 * أي تعديل في brand_profiles من بره مركز الهوية (Brand Brain · المساعد · الاقتراحات · التجربة · الأدمن · الموبايل)
 * ← ينعكس على الـ Kit (النبرة · الخدمات · الألوان) + نسخة جديدة باسم اللي عدّل
 */
function bk_profile_changed(int $uid, array $fields, string $by = 'user', string $note = ''): void
{
    if ($uid <= 0 || !$fields) return;
    try {
        bk_ensure_schema();
        $bp = bk_brand_profile($uid);
        if (!$bp) return;
        $kit = bk_get($uid);
        $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
        $stored = bk_arr($row['data'] ?? '{}');
        $d = $kit['data'];
        if (in_array('tone', $fields, true) && in_array($bp['tone'] ?? '', ['simple', 'formal', 'fun', 'professional'], true) && bk_tone_from_voice($d['voice']) !== $bp['tone']) {
            $map = ['simple' => 'قريب وودود', 'formal' => 'رسمي وخبير', 'fun' => 'خفيف ومرح', 'professional' => 'رسمي وخبير'];
            $p = db_one('SELECT id, data FROM style_presets WHERE kind = "tone" AND name = ? AND is_active = 1', [$map[$bp['tone']] ?? '']);
            $voice = $d['voice'];
            if ($p) $voice = array_merge($voice, bk_arr($p['data'])['voice'] ?? [], ['preset_id' => (int) $p['id']]);
            if ($bp['tone'] === 'professional') { $voice['scientific_simple'] = 45; $voice['serious_fun'] = 20; }
            $stored['voice'] = bk_clean_section('voice', $voice);
        }
        if (in_array('colors', $fields, true) && !empty($bp['colors'])) {
            preg_match_all('/#[0-9a-f]{6}\b/i', (string) $bp['colors'], $m);
            if ($m[0]) {
                $c = $d['colors'];
                foreach (['primary', 'secondary', 'accent', 'neutral'] as $i => $k) $c[$k] = isset($m[0][$i]) ? strtoupper($m[0][$i]) : $c[$k];
                $stored['colors'] = bk_clean_section('colors', $c);
            }
        }
        if (in_array('services', $fields, true)) bk_services_from_text($uid, (string) $bp['services'], false);
        if (in_array('industry', $fields, true) && empty($kit['sector_template_id'])) {
            $sid = bk_detect_sector($uid);
            if ($sid) db_run('UPDATE brand_kits SET sector_template_id = ? WHERE id = ?', [$sid, $kit['id']]);
        }
        $labels = function_exists('brand_fields') ? array_map(fn($f) => brand_fields()[$f][0] ?? $f, $fields) : $fields;
        bk_write($kit['id'], $uid, $stored, $by, $note ?: ('تعديل: ' . implode('، ', array_slice($labels, 0, 4))));
    } catch (\Throwable $e) {
        error_log('[brand-kit] profile_changed: ' . $e->getMessage());
    }
}

/** النبرة القديمة (4 قيم) محسوبة من طريقة الكلام — علشان المسارات القديمة والموبايل */
function bk_tone_from_voice(array $v): string
{
    if ((int) $v['serious_fun'] >= 66) return 'fun';
    if ((int) $v['formal_friendly'] <= 33) return (int) $v['scientific_simple'] <= 50 ? 'professional' : 'formal';
    if ((int) $v['scientific_simple'] <= 40) return 'professional';
    return 'simple';
}

/* ─── الخدمات: الجدول هو الأصل · brand_profiles.services نسخة نصية منه ─── */

function bk_service_lines(string $text): array
{
    $out = [];
    foreach (preg_split('/\R|[•·]|(?<=\S)\s*[،,]\s*(?=\S)/u', $text) as $l) {
        $l = trim(preg_replace('/^[\-\*\d\.\)\s]+/u', '', $l));
        if ($l !== '' && mb_strlen($l) <= 150) $out[mb_strtolower($l)] = $l;
    }
    return array_values($out);
}

/** النص (من أي مصدر) ← خدمات جديدة في الجدول · $exact: يطابق النص بالظبط (رجوع لنسخة) */
function bk_services_from_text(int $uid, string $text, bool $exact): void
{
    $lines = bk_service_lines($text);
    $have = [];
    foreach (db_all('SELECT id, name FROM brand_services WHERE user_id = ?', [$uid]) as $r) $have[mb_strtolower(trim($r['name']))] = (int) $r['id'];
    $order = count($have);
    foreach ($lines as $l) {
        if (isset($have[mb_strtolower($l)])) continue;
        db_insert('INSERT INTO brand_services (user_id, name, priority, sort_order) VALUES (?,?,"medium",?)', [$uid, mb_substr($l, 0, 150), ++$order]);
    }
    if ($exact) {
        $keep = array_map('mb_strtolower', $lines);
        foreach ($have as $name => $id) {
            if (!in_array($name, $keep, true)) db_run('UPDATE brand_services SET is_active = 0 WHERE id = ?', [$id]);
            else db_run('UPDATE brand_services SET is_active = 1 WHERE id = ?', [$id]);
        }
    }
    bk_services_to_text($uid);
}

/** الجدول ← brand_profiles.services (سطر لكل خدمة شغالة) */
function bk_services_to_text(int $uid): void
{
    $rows = db_all('SELECT name, description FROM brand_services WHERE user_id = ? AND is_active = 1 ORDER BY is_focus DESC, FIELD(priority, "high","medium","low"), sort_order, id', [$uid]);
    $text = implode("\n", array_map(fn($r) => $r['name'], $rows));
    db_run('UPDATE brand_profiles SET services = ? WHERE user_id = ?', [$text !== '' ? $text : null, $uid]);
}

/** مرة لكل طلب: سطور في النص القديم مش في الجدول (اتكتبت من بره) ← تتضاف */
function bk_reconcile_services(int $uid, array $bp): void
{
    static $done = [];
    if (isset($done[$uid])) return;
    $done[$uid] = true;
    $text = trim((string) ($bp['services'] ?? ''));
    if ($text === '') {
        // الجدول فيه خدمات والنص القديم فاضي ← نكتبه (للمسارات القديمة والموبايل)
        if ((int) (db_one('SELECT COUNT(*) n FROM brand_services WHERE user_id = ? AND is_active = 1', [$uid])['n'] ?? 0) > 0) bk_services_to_text($uid);
        return;
    }
    $names = array_map(fn($n) => mb_strtolower(trim($n)), array_column(db_all('SELECT name FROM brand_services WHERE user_id = ?', [$uid]), 'name'));
    foreach (bk_service_lines($text) as $l) {
        if (!in_array(mb_strtolower($l), $names, true)) { bk_services_from_text($uid, $text, false); return; }
    }
}

/** الحقول القديمة اللي باقي المنصة بتقراها */
function bk_sync_profile(int $uid, string $section, $clean): void
{
    $bp = bk_brand_profile($uid);
    if (!$bp) return;
    $set = [];
    if ($section === 'business') {
        if ($clean['name'] !== '') $set['business_name'] = $clean['name'];
        if ($clean['sector'] !== '') $set['industry'] = $clean['sector'];
        if ($clean['location'] !== '') $set['address'] = $clean['location'];
    } elseif ($section === 'audience' && $clean['description'] !== '') {
        $set['audience'] = $clean['description'];
    } elseif ($section === 'voice') {
        $set['keywords_use'] = implode('، ', $clean['words_use']) ?: null;
        $set['keywords_avoid'] = implode('، ', $clean['words_avoid']) ?: null;
        $set['dialect'] = bk_dialect_store($clean['dialect']);
        $set['tone'] = bk_tone_from_voice($clean);
    } elseif ($section === 'colors') {
        $c = array_filter([$clean['primary'], $clean['secondary'], $clean['accent'], $clean['neutral']]);
        if ($c) $set['colors'] = mb_substr(implode(', ', $c), 0, 100);
    }
    if (!$set) return;
    $sql = [];
    foreach ($set as $k => $v) $sql[] = "`$k` = ?";
    try {
        db_run('UPDATE brand_profiles SET ' . implode(', ', $sql) . ' WHERE id = ?', array_merge(array_values($set), [$bp['id']]));
    } catch (\Throwable $e) {
        error_log('[brand-kit] sync: ' . $e->getMessage());
    }
}

function bk_section_label(string $s): string
{
    return [
        'business' => 'النشاط', 'audience' => 'الجمهور', 'personality' => 'الشخصية', 'logo' => 'اللوجو', 'colors' => 'الألوان',
        'typography' => 'الخطوط', 'design_style' => 'استايل التصميم', 'visuals' => 'طبيعة الصور', 'voice' => 'طريقة الكلام',
        'pillars' => 'اتجاه المحتوى', 'content' => 'أشكال المحتوى', 'offers' => 'العروض', 'forbidden_topics' => 'مواضيع ممنوعة', 'focus' => 'تركيز الشهر',
        'services' => 'الخدمات', 'topics' => 'المواضيع', 'characters' => 'الشخصيات', 'formats' => 'أشكال المحتوى',
        'cta' => 'الـ CTA الأساسي', 'personas' => 'العملاء المثاليين',
    ][$s] ?? $s;
}

function bk_hook_types(): array
{
    return ['question' => 'سؤال', 'story' => 'قصة', 'number' => 'رقم أو إحصائية', 'problem' => 'مشكلة', 'myth' => 'خرافة شائعة', 'offer' => 'عرض مباشر', 'tip' => 'نصيحة سريعة'];
}

function bk_format_labels(): array
{
    return ['carousel' => 'كاروسيل', 'reel' => 'ريلز', 'single_image' => 'صورة واحدة', 'story' => 'ستوري', 'text' => 'نص بس'];
}

/** شكل الـ Brand Kit ← شكل المنصة (contents.format) */
function bk_format_to_platform(string $f): string
{
    return ['carousel' => 'carousel', 'reel' => 'video', 'story' => 'story', 'single_image' => 'post', 'text' => 'post'][$f] ?? 'post';
}

function bk_platform_to_format(string $f): string
{
    return ['carousel' => 'carousel', 'video' => 'reel', 'story' => 'story', 'post' => 'single_image'][$f] ?? 'single_image';
}

/* ═══════════════ الاكتمال ═══════════════ */

/**
 * اكتمال الهوية الموحّد (مركز الهوية + Brand Brain + البوابة اللي بتقفل المحتوى) — رقم واحد في كل المنصة
 * @return array{pct:int, missing:array<int,array{key:string,label:string,weight:int,section:string}>, filled:array}
 */
function bk_completeness(int $uid, ?array $kit = null): array
{
    static $memo = [];
    if ($kit === null && isset($memo[$uid])) return $memo[$uid];
    $kit = $kit ?: bk_get($uid);
    $d = $kit['data'];
    $bp = bk_brand_profile($uid) ?: [];
    $f = fn($k) => trim((string) ($bp[$k] ?? '')) !== '';
    $count = fn(string $sql) => (int) (db_one($sql, [$uid])['n'] ?? 0);
    $stored = bk_arr(db_one('SELECT data FROM brand_kits WHERE user_id = ?', [$uid])['data'] ?? '{}');
    $sector = bk_sector($kit['sector_template_id']);
    // [مفتاح, اسم, وزن, متكامل؟, القسم اللي يتفتح]
    $checks = [
        ['business', 'اسم النشاط والمجال', 10, $f('business_name') && $f('industry'), 'basics'],
        ['description', 'وصف النشاط أو اللي بيميزك', 6, $f('description') || $d['business']['usp'] !== '', 'basics'],
        ['audience', 'مين جمهورك', 6, $f('audience') || $d['audience']['pains'], 'business'],
        ['personas', 'العملاء المثاليين', 4, $count('SELECT COUNT(*) n FROM brand_personas WHERE user_id = ? AND is_active = 1') > 0, 'personas'],
        ['cta', 'الـ CTA الأساسي', 4, ($d['cta']['text'] ?? '') !== '', 'cta'],
        ['services', 'الخدمات', 12, $count('SELECT COUNT(*) n FROM brand_services WHERE user_id = ? AND is_active = 1') > 0, 'services'],
        ['topics', 'المواضيع (3 على الأقل)', 6, $count('SELECT COUNT(*) n FROM brand_topics WHERE user_id = ? AND is_active = 1') >= 3, 'services'],
        ['voice', 'طريقة الكلام', 10, isset($stored['voice']) || $f('tone'), 'voice'],
        ['colors', 'الألوان', 8, $d['colors']['primary'] !== '' || $f('colors'), 'colors'],
        ['logo', 'اللوجو', 8, $f('logo_path'), 'logo'],
        ['contact', 'بيانات التواصل', 6, $f('phones') || $f('whatsapp') || $f('address'), 'basics'],
        ['links', 'الموقع أو السوشيال', 2, $f('website') || $f('social_facebook') || $f('social_instagram') || $f('social_tiktok') || $f('social_linkedin'), 'basics'],
        ['pillars', 'اتجاه المحتوى', 6, count($d['pillars']) >= 2 || count($sector['default_pillars'] ?? []) >= 2, 'pillars'],
        ['formats', 'أشكال المحتوى', 2, $count('SELECT COUNT(*) n FROM brand_formats WHERE user_id = ? AND is_enabled = 1') > 0 || !$count('SELECT COUNT(*) n FROM brand_formats WHERE user_id = ?'), 'formats'],
        ['visuals', 'طبيعة الصور', 6, $d['visuals']['kind'] !== '', 'visuals'],
        ['design_style', 'استايل التصميم', 2, !empty($d['design_style']['preset_id']) || $d['design_style']['notes'] !== '' || $f('design_rules') || $f('visual_identity'), 'colors'],
        ['personality', 'شخصية البراند', 2, count($d['personality']) > 0, 'business'],
    ];
    $pct = 0;
    $missing = [];
    $filled = [];
    foreach ($checks as [$key, $label, $w, $ok, $sec]) {
        if ($ok) { $pct += $w; $filled[] = $key; }
        else $missing[] = ['key' => $key, 'label' => $label, 'weight' => $w, 'section' => $sec];
    }
    usort($missing, fn($a, $b) => $b['weight'] <=> $a['weight']);
    return $memo[$uid] = ['pct' => min(100, $pct), 'missing' => $missing, 'filled' => $filled];
}

function bk_update_completeness(int $uid): int
{
    try {
        $c = bk_completeness($uid, bk_get($uid));
        db_run('UPDATE brand_kits SET completeness = ? WHERE user_id = ?', [$c['pct'], $uid]);
        return $c['pct'];
    } catch (\Throwable $e) {
        return 0;
    }
}

/* ═══════════════ القطاع ═══════════════ */

function bk_sectors(bool $publishedOnly = true): array
{
    bk_ensure_schema();
    return db_all('SELECT * FROM sector_templates' . ($publishedOnly ? ' WHERE is_published = 1' : '') . ' ORDER BY sort_order, id');
}

function bk_sector(?int $id): ?array
{
    if (!$id) return null;
    $s = db_one('SELECT * FROM sector_templates WHERE id = ?', [$id]);
    if (!$s) return null;
    foreach (['brief_questions', 'default_pillars', 'default_topics', 'forbidden'] as $k) $s[$k] = bk_arr($s[$k]);
    return $s;
}

/** القطاع الأقرب من مجال العميل (كلمات مفتاحية) */
function bk_detect_sector(int $uid): ?int
{
    $bp = bk_brand_profile($uid);
    $hay = mb_strtolower(trim(($bp['industry'] ?? '') . ' ' . ($bp['business_name'] ?? '') . ' ' . ($bp['description'] ?? '')));
    if ($hay === '') return null;
    foreach (db_all('SELECT id, name, keywords FROM sector_templates WHERE is_published = 1 ORDER BY sort_order') as $s) {
        foreach (array_merge([$s['name']], bk_lines($s['keywords'])) as $kw) {
            if ($kw !== '' && mb_strpos($hay, mb_strtolower($kw)) !== false) return (int) $s['id'];
        }
    }
    return null;
}

/* ═══════════════ الخدمات · المواضيع · الشخصيات · الأشكال ═══════════════ */

function bk_services(int $uid, bool $activeOnly = false): array
{
    bk_ensure_schema();
    $rows = db_all('SELECT * FROM brand_services WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY is_focus DESC, FIELD(priority, "high","medium","low"), sort_order, id', [$uid]);
    foreach ($rows as &$r) $r['seasons'] = bk_arr($r['seasons']);
    return $rows;
}

function bk_topics(int $uid, ?int $serviceId = null, bool $activeOnly = true): array
{
    bk_ensure_schema();
    $sql = 'SELECT * FROM brand_topics WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '');
    $p = [$uid];
    if ($serviceId !== null) { $sql .= ' AND service_id <=> ?'; $p[] = $serviceId ?: null; }
    return db_all($sql . ' ORDER BY times_used ASC, id DESC', $p);
}

function bk_characters(int $uid, bool $activeOnly = false): array
{
    bk_ensure_schema();
    $rows = db_all('SELECT * FROM brand_characters WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY id', [$uid]);
    foreach ($rows as &$r) {
        $r['use_in'] = array_values(array_filter(explode(',', (string) $r['use_in'])));
        $r['images'] = db_all('SELECT id, image_path, is_primary FROM brand_character_images WHERE character_id = ? ORDER BY is_primary DESC, id', [$r['id']]);
        $r['ready'] = bk_character_ready($r);
    }
    return $rows;
}

/** الشخصية تتستخدم في الصور؟ (صور مرجعية + موافقة للشخص الحقيقي) */
function bk_character_ready(array $c): bool
{
    if ((int) $c['is_active'] !== 1) return false;
    if ($c['type'] === 'real_person' && (int) $c['consent_confirmed'] !== 1) return false;
    return !empty($c['images']);
}

function bk_formats(int $uid): array
{
    bk_ensure_schema();
    $rows = db_all('SELECT * FROM brand_formats WHERE user_id = ? ORDER BY `rank`, id', [$uid]);
    if (!$rows) {
        // افتراضي: صورة · كاروسيل · ريلز · ستوري
        foreach ([['single_image', 1, 2], ['carousel', 2, 1], ['reel', 3, 1], ['story', 4, 0], ['text', 5, 0]] as [$f, $r, $w]) {
            db_run('INSERT IGNORE INTO brand_formats (user_id, format, `rank`, per_week, settings, is_enabled) VALUES (?,?,?,?,?,?)',
                [$uid, $f, $r, $w, bk_json($f === 'carousel' ? ['slides' => '5-7'] : ($f === 'reel' ? ['duration' => '15-30'] : new \stdClass())), $f === 'text' ? 0 : 1]);
        }
        $rows = db_all('SELECT * FROM brand_formats WHERE user_id = ? ORDER BY `rank`, id', [$uid]);
    }
    foreach ($rows as &$r) $r['settings'] = bk_arr($r['settings']);
    return $rows;
}

/* ═══════════════ مكتبة التعلّم (الأدمن) ═══════════════ */

function bk_learning_kinds(): array
{
    return [
        'method'    => ['طريقة كتابة', 'إطار بيتبني عليه البوست (AIDA · PAS · قصة…) — بيتختار واحد لكل بوست'],
        'hooks'     => ['قوالب Hooks', 'جمل افتتاحية مجرّبة — الكاتب بيختار منها'],
        'rules'     => ['قواعد', 'بتتطبق دايمًا على الوكلاء اللي تختارهم'],
        'topics'    => ['بنك مواضيع', 'مواضيع جاهزة للقطاع — بتظهر للعملاء كاقتراحات'],
        'reference' => ['مرجع للتعلّم', 'مقال · كتاب · ملاحظات — الـ AI بيطلع منه مواضيع وزوايا'],
    ];
}

/** عناصر المكتبة اللي تخص العميل ده (عام + قطاعه) */
function bk_learning_for(?int $sectorId, ?string $kind = null, ?string $agent = null): array
{
    bk_ensure_schema();
    $sql = 'SELECT * FROM brand_learning WHERE is_active = 1 AND (sector_template_id IS NULL' . ($sectorId ? ' OR sector_template_id = ?' : '') . ')';
    $p = $sectorId ? [$sectorId] : [];
    if ($kind) { $sql .= ' AND kind = ?'; $p[] = $kind; }
    $rows = db_all($sql . ' ORDER BY sector_template_id IS NULL, id', $p);
    if ($agent) $rows = array_values(array_filter($rows, fn($r) => trim((string) $r['agents']) === '' || in_array($agent, explode(',', (string) $r['agents']), true)));
    return $rows;
}

/** مواضيع من المكتبة (قطاع العميل + العام) ماتكونش عند العميل */
function bk_library_topics(int $uid, ?int $sectorId, int $limit = 10): array
{
    bk_ensure_schema();
    $have = array_map('mb_strtolower', array_column(db_all('SELECT title FROM brand_topics WHERE user_id = ?', [$uid]), 'title'));
    $rows = db_all('SELECT * FROM learning_topics WHERE is_active = 1 AND (sector_template_id IS NULL' . ($sectorId ? ' OR sector_template_id = ?' : '') . ')
                    ORDER BY sector_template_id IS NULL, times_used ASC, RAND() LIMIT 60', $sectorId ? [$sectorId] : []);
    $out = [];
    foreach ($rows as $r) {
        if (in_array(mb_strtolower($r['title']), $have, true)) continue;
        $out[] = $r;
        if (count($out) >= $limit) break;
    }
    // مواضيع قالب القطاع الافتراضية
    if (count($out) < $limit && ($s = bk_sector($sectorId))) {
        foreach ($s['default_topics'] as $t) {
            if (!is_string($t) || in_array(mb_strtolower($t), $have, true)) continue;
            $out[] = ['id' => null, 'title' => $t, 'angle' => '', 'pillar' => '', 'sector_template_id' => $sectorId];
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

/**
 * الأدمن: استخراج مواضيع وزوايا من عنصر في المكتبة بالـ AI (مابيخصمش من عميل)
 * @return array{ok:bool, added:int, error:?string}
 */
function bk_learning_extract(int $learningId): array
{
    $l = db_one('SELECT * FROM brand_learning WHERE id = ?', [$learningId]);
    if (!$l) return ['ok' => false, 'added' => 0, 'error' => 'العنصر مش موجود'];
    $text = trim($l['title'] . "\n" . $l['summary'] . "\n" . $l['body']);
    if (mb_strlen($text) < 40) return ['ok' => false, 'added' => 0, 'error' => 'المحتوى قليل جدًا — زوّد الشرح'];
    $sector = bk_sector($l['sector_template_id'] ? (int) $l['sector_template_id'] : null);
    require_once __DIR__ . '/ai.php';
    $prompt = "انت استراتيجي محتوى. اقرا المرجع ده وطلّع منه مواضيع بوستات سوشيال ميديا عملية ممكن أي نشاط"
        . ($sector ? " في قطاع «{$sector['name']}»" : '') . " يستخدمها.\n"
        . "- 8 لـ 15 موضوع، كل موضوع عنوان قصير محدد (مش عام) بالعامية المصرية.\n"
        . "- angle: نوع الزاوية (توعوي · قصة · سؤال شائع · خرافة · نصيحة · مقارنة · كواليس · عرض).\n"
        . "- pillar: اتجاه المحتوى اللي الموضوع تحته (كلمتين).\n"
        . "- ماتخترعش معلومات مش موجودة في المرجع.\n"
        . "رجّع JSON بس: {\"topics\":[{\"title\":\"\",\"angle\":\"\",\"pillar\":\"\"}]}\n\n---\n" . mb_substr($text, 0, 9000);
    if (function_exists('ai_log_set_context')) ai_log_set_context(['kind' => 'brand', 'user_id' => null, 'reference_type' => 'brand_learning', 'reference_id' => $learningId]);
    $r = ai_generate($prompt, [], ['max_tokens' => 2500, 'temperature' => 0.5, 'json' => true]);
    if (empty($r['ok'])) return ['ok' => false, 'added' => 0, 'error' => $r['error'] ?? 'تعذّر الاستخراج'];
    $j = bk_json_from_ai((string) $r['response']);
    $added = 0;
    $have = array_map('mb_strtolower', array_column(db_all('SELECT title FROM learning_topics WHERE learning_id = ?', [$learningId]), 'title'));
    foreach ((array) ($j['topics'] ?? []) as $t) {
        $title = mb_substr(trim((string) ($t['title'] ?? '')), 0, 255);
        if ($title === '' || in_array(mb_strtolower($title), $have, true)) continue;
        db_insert('INSERT INTO learning_topics (learning_id, sector_template_id, title, angle, pillar) VALUES (?,?,?,?,?)',
            [$learningId, $l['sector_template_id'], $title, mb_substr((string) ($t['angle'] ?? ''), 0, 100), mb_substr((string) ($t['pillar'] ?? ''), 0, 100)]);
        $have[] = mb_strtolower($title);
        $added++;
    }
    db_run('UPDATE brand_learning SET extracted_at = NOW() WHERE id = ?', [$learningId]);
    return ['ok' => $added > 0, 'added' => $added, 'error' => $added ? null : 'الرد مطلعش مواضيع — جرّب تاني'];
}

function bk_json_from_ai(string $raw): array
{
    $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));
    $a = strpos($raw, '{');
    $b = strrpos($raw, '}');
    if ($a === false || $b === false || $b <= $a) return [];
    $j = json_decode(substr($raw, $a, $b - $a + 1), true);
    return is_array($j) ? $j : [];
}

/* ═══════════════ المواسم ═══════════════ */

/** المواسم الحالية: الصيف/الشتا/… + رمضان والعيد (بالتقويم الهجري لو متاح) */
function bk_current_seasons(?int $ts = null): array
{
    $ts = $ts ?: time();
    $m = (int) date('n', $ts);
    $out = [];
    $out[] = in_array($m, [6, 7, 8], true) ? 'الصيف' : (in_array($m, [12, 1, 2], true) ? 'الشتا' : (in_array($m, [3, 4, 5], true) ? 'الربيع' : 'الخريف'));
    if (in_array($m, [9, 10], true)) $out[] = 'الدراسة';
    if (in_array($m, [6, 7], true)) $out[] = 'الإجازة';
    if ($m === 11) $out[] = 'الجمعة البيضا';
    if (class_exists('IntlDateFormatter')) {
        try {
            $f = new \IntlDateFormatter('en_US@calendar=islamic-civil', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Africa/Cairo', \IntlDateFormatter::TRADITIONAL, 'M');
            $hm = (int) $f->format($ts);
            if ($hm === 9) $out[] = 'رمضان';
            if ($hm === 10) $out[] = 'العيد';
            if ($hm === 12) $out[] = 'العيد';
        } catch (\Throwable $e) {}
    }
    return $out;
}

/* ═══════════════ التعلّم من تعديلات العميل ═══════════════ */

function bk_feedback(int $uid, ?int $contentId, string $action, string $field = '', ?string $before = null, ?string $after = null): void
{
    if (!in_array($action, ['like', 'dislike', 'edit', 'regenerate', 'chip'], true)) return;
    try {
        bk_ensure_schema();
        db_insert('INSERT INTO brand_feedback (user_id, content_id, field, action, before_value, after_value) VALUES (?,?,?,?,?,?)',
            [$uid, $contentId, mb_substr($field, 0, 100) ?: null, $action, $before !== null ? mb_substr($before, 0, 5000) : null, $after !== null ? mb_substr($after, 0, 5000) : null]);
    } catch (\Throwable $e) {}
}

/** تعديل كابشن: بنسجّل الفرق (الطول · الإيموجي · الهاشتاجات) — مش النص كله بس */
function bk_feedback_text_edit(int $uid, int $contentId, string $before, string $after): void
{
    $before = trim($before);
    $after = trim($after);
    if ($before === $after || $before === '') return;
    bk_feedback($uid, $contentId, 'edit', 'caption', $before, $after);
}

function bk_words(string $s): int
{
    return count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY));
}

function bk_emoji_count(string $s): int
{
    return (int) preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $s);
}

/**
 * يوميًا: نفس نوع التعديل اتكرر 3 مرات أو أكتر في آخر 10 بوستات ← اقتراح بالمصري (مفيش تعديل من غير موافقة)
 * @return int عدد الاقتراحات الجديدة
 */
function bk_learn_for_user(int $uid): int
{
    $rows = db_all('SELECT * FROM brand_feedback WHERE user_id = ? AND created_at >= NOW() - INTERVAL 45 DAY ORDER BY id DESC LIMIT 60', [$uid]);
    if (!$rows) return 0;
    $kit = bk_get($uid);
    $d = $kit['data'];
    $edits = array_slice(array_values(array_filter($rows, fn($r) => $r['action'] === 'edit' && $r['field'] === 'caption')), 0, 10);
    $signals = ['shorter' => 0, 'longer' => 0, 'less_emoji' => 0, 'more_emoji' => 0];
    foreach ($edits as $e) {
        $b = bk_words((string) $e['before_value']);
        $a = bk_words((string) $e['after_value']);
        if ($b > 0 && $a < $b * 0.7) $signals['shorter']++;
        if ($b > 0 && $a > $b * 1.3) $signals['longer']++;
        $eb = bk_emoji_count((string) $e['before_value']);
        $ea = bk_emoji_count((string) $e['after_value']);
        if ($eb >= 2 && $ea === 0) $signals['less_emoji']++;
        if ($eb === 0 && $ea >= 2) $signals['more_emoji']++;
    }
    $chips = array_values(array_filter($rows, fn($r) => $r['action'] === 'chip'));
    $chipCount = [];
    foreach (array_slice($chips, 0, 10) as $c) {
        $k = $c['field'] . '=' . $c['after_value'];
        $chipCount[$k] = ($chipCount[$k] ?? 0) + 1;
    }

    $sugs = [];
    $len = $d['content']['caption_length'];
    if ($signals['shorter'] >= 3 && $len !== 'short') {
        $sugs[] = ['caption_length', 'بتقصّر الكابشن كل مرة تقريبًا — نخلّي الطول الأساسي ' . ($len === 'long' ? 'متوسط' : 'قصير') . '؟',
                   ['section' => 'content', 'key' => 'caption_length', 'value' => $len === 'long' ? 'medium' : 'short']];
    }
    if ($signals['longer'] >= 3 && $len !== 'long') {
        $sugs[] = ['caption_length', 'بتطوّل الكابشن بعد ما يطلع — نخلّي الطول الأساسي ' . ($len === 'short' ? 'متوسط' : 'طويل') . '؟',
                   ['section' => 'content', 'key' => 'caption_length', 'value' => $len === 'short' ? 'medium' : 'long']];
    }
    if ($signals['less_emoji'] >= 3 && $d['voice']['emoji']) {
        $sugs[] = ['emoji', 'لاحظنا إنك بتشيل الإيموجي من البوستات — نوقفها خالص؟', ['section' => 'voice', 'key' => 'emoji', 'value' => false]];
    }
    if ($signals['more_emoji'] >= 3 && !$d['voice']['emoji']) {
        $sugs[] = ['emoji', 'بتزوّد إيموجي في البوستات — نخليها جزء من الأسلوب؟', ['section' => 'voice', 'key' => 'emoji', 'value' => true]];
    }
    foreach ($chipCount as $k => $n) {
        if ($n < 3) continue;
        [$field, $val] = explode('=', $k, 2);
        if ($field === 'tone' && $val !== '') {
            $sugs[] = ['tone', 'بتغيّر النبرة لـ «' . $val . '» في أغلب البوستات — نخليها الأساسية؟', ['section' => 'voice', 'key' => 'tone_label', 'value' => $val]];
        }
    }

    $added = 0;
    foreach ($sugs as [$field, $msg, $val]) {
        // نفس الاقتراح مايتكررش لو مستني أو اترفض من قريب
        $dup = db_one('SELECT id FROM brand_suggestions WHERE user_id = ? AND field = ? AND (status = "pending" OR (status = "rejected" AND decided_at > NOW() - INTERVAL 30 DAY))', [$uid, $field]);
        if ($dup) continue;
        db_insert('INSERT INTO brand_suggestions (user_id, field, message, proposed_value) VALUES (?,?,?,?)', [$uid, $field, $msg, bk_json($val)]);
        $added++;
    }
    return $added;
}

/** قبول اقتراح ← تحديث الـ Kit بنسخة changed_by=ai */
function bk_suggestion_decide(int $uid, int $id, bool $accept): array
{
    $s = db_one('SELECT * FROM brand_suggestions WHERE id = ? AND user_id = ? AND status = "pending"', [$id, $uid]);
    if (!$s) return ['ok' => false, 'error' => 'الاقتراح مش موجود'];
    db_run('UPDATE brand_suggestions SET status = ?, decided_at = NOW() WHERE id = ?', [$accept ? 'accepted' : 'rejected', $id]);
    if (!$accept) return ['ok' => true];
    $v = bk_arr($s['proposed_value']);
    // اقتراح مواضيع (من البحث العميق / الترندات) ← تتضاف للمواضيع
    if (($v['type'] ?? '') === 'topics') {
        $have = array_map('mb_strtolower', array_column(db_all('SELECT title FROM brand_topics WHERE user_id = ?', [$uid]), 'title'));
        foreach ((array) ($v['titles'] ?? []) as $t) {
            $t = mb_substr(trim((string) $t), 0, 255);
            if ($t === '' || in_array(mb_strtolower($t), $have, true)) continue;
            db_insert('INSERT INTO brand_topics (user_id, title, source) VALUES (?,?,"ai")', [$uid, $t]);
            $have[] = mb_strtolower($t);
        }
        bk_update_completeness($uid);
        return ['ok' => true];
    }
    $sec = (string) ($v['section'] ?? '');
    if (!in_array($sec, BK_SECTIONS, true)) return ['ok' => true];
    $kit = bk_get($uid);
    $cur = $kit['data'][$sec];
    if ($sec === 'voice' && ($v['key'] ?? '') === 'tone_label') {
        $cur['sample_lines'] = $cur['sample_lines'];
        $p = db_one('SELECT * FROM style_presets WHERE kind = "tone" AND name = ? AND is_active = 1', [(string) $v['value']]);
        if ($p) $cur = array_merge($cur, bk_arr($p['data'])['voice'] ?? [], ['preset_id' => (int) $p['id']]);
    } elseif (isset($v['merge']) && is_array($v['merge'])) {
        $cur = array_merge($cur, $v['merge']);
    } else {
        $cur[(string) $v['key']] = $v['value'];
    }
    $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
    $stored = bk_arr($row['data'] ?? '{}');
    $stored[$sec] = bk_clean_section($sec, $cur);
    bk_write($kit['id'], $uid, $stored, 'ai', 'اقتراح مقبول: ' . mb_substr((string) $s['message'], 0, 200));
    bk_sync_profile($uid, $sec, $stored[$sec]);
    return ['ok' => true];
}

/* ═══════════════ BrandContext ═══════════════ */

/**
 * بيبني سياق الهوية لكل Agent — الجزء اللي يخصه بس
 *
 * ترتيب الأولوية لو فيه تعارض: طلب العميل في البوست ← brand_overrides ← Brand Kit ← قالب القطاع
 * الممنوعات (words_avoid · visuals.avoid · ممنوعات القطاع · المواضيع الممنوعة) بتتطبق دايمًا.
 */
final class BrandContext
{
    /** @var array<int,array> */
    private static array $cache = [];

    private static function base(int $uid): array
    {
        if (isset(self::$cache[$uid])) return self::$cache[$uid];
        $kit = bk_get($uid);
        $sector = bk_sector($kit['sector_template_id']);
        return self::$cache[$uid] = [
            'kit' => $kit, 'sector' => $sector,
            'services' => bk_services($uid, true),
            'characters' => bk_characters($uid, true),
            'formats' => array_values(array_filter(bk_formats($uid), fn($f) => (int) $f['is_enabled'] === 1)),
        ];
    }

    public static function flush(?int $uid = null): void
    {
        if ($uid === null) self::$cache = [];
        else unset(self::$cache[$uid]);
    }

    /** كل الممنوعات (كلمات + مواضيع + صور) */
    public static function forbidden(int $uid): array
    {
        $b = self::base($uid);
        $d = $b['kit']['data'];
        $words = array_merge($d['voice']['words_avoid'], $b['sector']['forbidden'] ?? []);
        return [
            'words' => array_values(array_unique(array_filter(array_map('trim', $words)))),
            'topics' => $d['forbidden_topics'],
            'visuals' => array_values(array_unique(array_merge($d['visuals']['avoid'], $d['design_style']['avoid']))),
        ];
    }

    /**
     * الجزء اللي يخص الـ Agent بس، بعد تطبيق الـ overrides
     * $overrides: service_id · topic_id · topic · character_id · format · tone (preset name) · caption_length · goal · pillar · method_id
     */
    public static function build(int $uid, string $agent, array $overrides = []): array
    {
        $b = self::base($uid);
        $d = $b['kit']['data'];
        $sector = $b['sector'];
        $pillars = $d['pillars'] ?: ($sector['default_pillars'] ?? []);
        $voice = $d['voice'];
        if (!empty($overrides['tone'])) {
            $p = db_one('SELECT data FROM style_presets WHERE kind = "tone" AND name = ? AND is_active = 1', [(string) $overrides['tone']]);
            if ($p) $voice = array_merge($voice, bk_arr($p['data'])['voice'] ?? []);
            $voice['tone_label'] = (string) $overrides['tone'];
        }
        $content = $d['content'];
        if (!empty($overrides['caption_length']) && in_array($overrides['caption_length'], ['short', 'medium', 'long'], true)) $content['caption_length'] = $overrides['caption_length'];
        if (!empty($overrides['goal'])) $content['goal'] = (string) $overrides['goal'];

        $ctx = [
            'agent' => $agent, 'user_id' => $uid, 'sector' => $sector['name'] ?? '',
            'forbidden' => self::forbidden($uid),
            'brief' => $overrides['__brief'] ?? null,
            'admin_prompt' => self::agentPrompt($agent, $uid),
        ];
        $ctx['rules'] = array_map(fn($r) => ['title' => $r['title'], 'body' => (string) $r['body']], bk_learning_for($b['kit']['sector_template_id'], 'rules', $agent));

        switch ($agent) {
            case 'ideas':
                $ctx += [
                    'business' => $d['business'], 'services' => self::servicesBrief($b['services']), 'focus' => self::focusService($uid),
                    'seasons' => bk_current_seasons(), 'pillars' => $pillars, 'offers' => self::liveOffers($d['offers']),
                    'characters' => array_map(fn($c) => ['name' => $c['name'], 'role' => $c['role'], 'type' => $c['type']], $b['characters']),
                    'formats' => array_map(fn($f) => bk_format_labels()[$f['format']] ?? $f['format'], $b['formats']),
                    'angle_voice' => self::voiceText($voice, true),
                    'topics' => array_map(fn($t) => $t['title'], array_slice(bk_topics($uid), 0, 25)),
                    'library_topics' => array_map(fn($t) => $t['title'], bk_library_topics($uid, $b['kit']['sector_template_id'], 10)),
                    'goal' => $content['goal'],
                ];
                break;
            case 'copy':
                $ctx += [
                    'business' => $d['business'], 'audience' => $d['audience'], 'personality' => $d['personality'],
                    'voice' => $voice, 'content' => $content, 'offers' => self::liveOffers($d['offers']),
                    'hooks_library' => array_map(fn($r) => (string) $r['body'], bk_learning_for($b['kit']['sector_template_id'], 'hooks', 'copy')),
                ];
                break;
            case 'design':
                $ctx += [
                    'business' => ['name' => $d['business']['name'], 'sector' => $d['business']['sector']],
                    'colors' => $d['colors'], 'typography' => $d['typography'], 'design_style' => self::presetMerge($d['design_style']),
                    'logo' => $d['logo'], 'visuals' => self::presetMerge($d['visuals']),
                ];
                break;
            case 'video':
                $ctx += [
                    'business' => $d['business'], 'visuals' => self::presetMerge($d['visuals']), 'colors' => $d['colors'], 'voice' => $voice,
                    'duration' => self::formatSetting($b['formats'], 'reel', 'duration', '15-30'),
                ];
                break;
            case 'schedule':
                $ctx += [
                    'formats' => array_map(fn($f) => ['format' => $f['format'], 'per_week' => (int) $f['per_week']], $b['formats']),
                    'pillars' => $pillars, 'focus' => self::focusService($uid), 'seasons' => bk_current_seasons(),
                    'characters' => array_map(fn($c) => ['name' => $c['name'], 'share' => (int) $c['share_percent']], $b['characters']),
                    'posts_per_month' => $content['posts_per_month'],
                ];
                break;
            default:   // brand
                $ctx += ['kit' => $d];
        }
        return $ctx;
    }

    /** برومبت الأدمن للـ Agent (من مكتبة البرومبتات — النسخة المفعّلة) */
    /**
     * برومبت الأدمن للـ Agent (من مكتبة البرومبتات — النسخة المفعّلة)
     * برومبت كامل (v1) بيتملى بالمتغيرات: $addon = فوق برومبت مهمة تانية (دوره ومهمته وقواعده بس) · غير كده البرومبت كله من غير شكل الرد
     */
    public static function agentPrompt(string $agent, int $uid = 0, array $vars = [], bool $addon = true): string
    {
        if (!function_exists('get_active_prompt')) return '';
        try { $t = trim(get_active_prompt('agent_' . $agent)); } catch (\Throwable $e) { return ''; }
        if ($t === '' || !function_exists('prompt_is_full') || !prompt_is_full($t)) return $t;
        $base = $uid > 0 ? prompt_brand_vars($uid, null, $agent, ['identity' => !isset($vars['brand_identity']) && !$addon]) : [];
        return trim(prompt_apply($t, $vars + $base, $addon ? ['addon' => true] : ['strip_output' => true]));
    }

    private static function presetMerge(array $sec): array
    {
        if (!empty($sec['preset_id'])) {
            $p = db_one('SELECT name, data FROM style_presets WHERE id = ? AND is_active = 1', [(int) $sec['preset_id']]);
            if ($p) {
                $pd = bk_arr($p['data']);
                $sec['preset_name'] = $p['name'];
                $sec['preset_desc'] = (string) ($pd['en'] ?? ($pd['desc'] ?? ''));
                foreach ((array) ($pd['visuals'] ?? []) as $k => $v) if (empty($sec[$k])) $sec[$k] = $v;
            }
        }
        return $sec;
    }

    private static function formatSetting(array $formats, string $f, string $key, string $def): string
    {
        foreach ($formats as $r) if ($r['format'] === $f) return (string) ($r['settings'][$key] ?? $def);
        return $def;
    }

    private static function servicesBrief(array $services): array
    {
        return array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'priority' => $s['priority'], 'focus' => (int) $s['is_focus'] === 1,
                                    'seasons' => $s['seasons'], 'desc' => mb_substr((string) $s['description'], 0, 200)], $services);
    }

    public static function focusService(int $uid): ?array
    {
        $b = self::base($uid);
        foreach ($b['services'] as $s) if ((int) $s['is_focus'] === 1) return ['id' => (int) $s['id'], 'name' => $s['name']];
        return null;
    }

    private static function liveOffers(array $offers): array
    {
        $today = date('Y-m-d');
        return array_values(array_filter($offers, fn($o) => ($o['until'] ?? '') === '' || $o['until'] >= $today));
    }

    /** وصف النبرة بالكلام (من الـ sliders) */
    public static function voiceText(array $v, bool $short = false): string
    {
        $ff = (int) $v['formal_friendly'];
        $sf = (int) $v['serious_fun'];
        $ss = (int) $v['scientific_simple'];
        $parts = [];
        $parts[] = $ff >= 66 ? 'قريب وودود' : ($ff <= 33 ? 'رسمي' : 'متوازن بين الرسمي والودود');
        $parts[] = $sf >= 66 ? 'خفيف وفيه هزار' : ($sf <= 33 ? 'جاد' : 'جاد مع لمسة خفة');
        $parts[] = $ss >= 66 ? 'بسيط من غير مصطلحات' : ($ss <= 33 ? 'علمي ودقيق' : 'بسيط مع معلومة دقيقة');
        $t = implode('، ', $parts);
        if (!empty($v['tone_label'])) $t = $v['tone_label'] . ' (' . $t . ')';
        if ($short) return $t;
        return $t . ' — ' . bk_dialect_label((string) $v['dialect']) . ($v['emoji'] ? ' — إيموجي معتدل' : ' — من غير إيموجي');
    }

    /* ─── اختيار البوست ─── */

    /**
     * بيختار خدمة/موضوع/شخصية/شكل/اتجاه/طريقة كتابة للبوست الجديد (لو مش محددين في الـ overrides)
     * @return array brief: service · topic · character · format · pillar · method · hook · goal · reasons
     */
    public static function pickForPost(int $uid, ?array $overrides = []): array
    {
        $o = $overrides ?: [];
        $b = self::base($uid);
        $d = $b['kit']['data'];
        $why = [];

        // ① الخدمة: خدمة الشهر بوزن أعلى ← الأولوية ← الموسم الحالي
        $service = null;
        if (!empty($o['service_id'])) {
            foreach ($b['services'] as $s) if ((int) $s['id'] === (int) $o['service_id']) $service = $s;
        }
        if (!$service && $b['services']) {
            $seasons = bk_current_seasons();
            $weights = [];
            foreach ($b['services'] as $s) {
                $w = ['high' => 3, 'medium' => 2, 'low' => 1][$s['priority']] ?? 2;
                if ((int) $s['is_focus'] === 1) $w *= 4;
                if (array_intersect($s['seasons'], $seasons)) $w *= 2;
                $weights[(int) $s['id']] = $w;
            }
            $sid = self::weighted($weights);
            foreach ($b['services'] as $s) if ((int) $s['id'] === $sid) $service = $s;
            if ($service) {
                if ((int) $service['is_focus'] === 1) $why[] = 'ركّزنا على «' . $service['name'] . '» لأنها خدمة الشهر';
                elseif (array_intersect($service['seasons'], $seasons)) $why[] = '«' . $service['name'] . '» مناسبة لموسم ' . implode('/', array_intersect($service['seasons'], $seasons));
                else $why[] = 'اخترنا «' . $service['name'] . '» حسب أولوية خدماتك';
            }
        }

        // ② الموضوع: الأقل استخدامًا · مفيش تكرار في نفس الشهر
        $topic = null;
        if (!empty($o['topic'])) {
            $topic = ['id' => null, 'title' => mb_substr((string) $o['topic'], 0, 255), 'source' => 'request'];
        } elseif (!empty($o['topic_id'])) {
            $topic = db_one('SELECT * FROM brand_topics WHERE id = ? AND user_id = ?', [(int) $o['topic_id'], $uid]) ?: null;
        }
        if (!$topic) {
            $month = date('Y-m-01 00:00:00');
            $pool = db_all('SELECT * FROM brand_topics WHERE user_id = ? AND is_active = 1 AND (last_used_at IS NULL OR last_used_at < ?)'
                . ($service ? ' AND (service_id = ? OR service_id IS NULL)' : '') . ' ORDER BY ' . ($service ? 'service_id <=> ? DESC, ' : '') . 'times_used ASC, last_used_at ASC, RAND() LIMIT 1',
                $service ? [$uid, $month, (int) $service['id'], (int) $service['id']] : [$uid, $month]);
            if ($pool) {
                $topic = $pool[0];
                $why[] = 'موضوع «' . $topic['title'] . '» ' . ((int) $topic['times_used'] === 0 ? 'لسه ما اتكلمناش عنه' : 'من أقل المواضيع استخدامًا');
            } elseif ($lib = bk_library_topics($uid, $b['kit']['sector_template_id'], 3)) {
                $t = $lib[array_rand($lib)];
                $topic = ['id' => null, 'title' => $t['title'], 'source' => 'library', 'library_id' => $t['id'] ?? null, 'angle' => $t['angle'] ?? ''];
                $why[] = 'موضوع من مكتبة ' . ($b['sector']['name'] ?? 'Spread') . ' لأن مواضيعك خلصت الشهر ده';
            }
        }

        // ③ الشكل: حسب per_week والمتبقي من الأسبوع
        $format = null;
        if (!empty($o['format']) && in_array($o['format'], BK_FORMATS, true)) {
            $format = $o['format'];
        } elseif ($b['formats']) {
            $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
            $done = [];
            foreach (db_all('SELECT format, COUNT(*) n FROM contents WHERE user_id = ? AND created_at >= ? GROUP BY format', [$uid, $weekStart]) as $r) {
                $done[bk_platform_to_format((string) $r['format'])] = (int) $r['n'];
            }
            $best = null;
            $bestGap = -999;
            foreach ($b['formats'] as $f) {
                $gap = (int) $f['per_week'] - ($done[$f['format']] ?? 0);
                if ($gap > $bestGap) { $bestGap = $gap; $best = $f['format']; }
            }
            $format = $best;
            if ($format && $bestGap > 0) $why[] = (bk_format_labels()[$format] ?? $format) . ' لأن فاضل ' . $bestGap . ' من خطة الأسبوع';
        }

        // ④ الشخصية: حسب share_percent · الشخص الحقيقي لازم موافقة + صور
        $character = null;
        if (array_key_exists('character_id', $o)) {
            foreach ($b['characters'] as $c) if ((int) $c['id'] === (int) $o['character_id'] && ($c['type'] !== 'real_person' || (int) $c['consent_confirmed'] === 1)) $character = $c;
        } else {
            $eligible = array_values(array_filter($b['characters'], fn($c) => (int) $c['share_percent'] > 0 && $c['ready']));
            $weights = [];
            foreach ($eligible as $c) $weights[(int) $c['id']] = (int) $c['share_percent'];
            $total = array_sum($weights);
            if ($total > 0 && mt_rand(1, 100) <= min(100, $total)) {
                $cid = self::weighted($weights);
                foreach ($eligible as $c) if ((int) $c['id'] === $cid) $character = $c;
                if ($character) $why[] = 'ظهور «' . $character['name'] . '» حسب نسبة ظهوره (' . (int) $character['share_percent'] . '%)';
            }
        }

        // ⑤ اتجاه المحتوى: الأقل تمثيلًا الشهر ده مقارنة بنسبته
        $pillar = (string) ($o['pillar'] ?? '');
        $pillars = $d['pillars'] ?: ($b['sector']['default_pillars'] ?? []);
        if ($pillar === '' && $pillars) {
            $used = [];
            foreach (db_all('SELECT brief_json FROM contents WHERE user_id = ? AND created_at >= ? AND brief_json IS NOT NULL', [$uid, date('Y-m-01')]) as $r) {
                $p = (string) (bk_arr($r['brief_json'])['pillar'] ?? '');
                if ($p !== '') $used[$p] = ($used[$p] ?? 0) + 1;
            }
            $tot = max(1, array_sum($used));
            $bestGap = -999;
            foreach ($pillars as $p) {
                $gap = (int) $p['percent'] - (int) round(100 * ($used[$p['name']] ?? 0) / $tot);
                if ($gap > $bestGap) { $bestGap = $gap; $pillar = (string) $p['name']; }
            }
        }

        // ⑥ طريقة الكتابة (من مكتبة التعلّم) — بالتناوب
        $method = null;
        $methods = bk_learning_for($b['kit']['sector_template_id'], 'method', 'copy');
        if (!empty($o['method_id'])) {
            foreach ($methods as $m) if ((int) $m['id'] === (int) $o['method_id']) $method = $m;
        }
        if (!$method && $methods) {
            $last = array_column(array_map(fn($r) => bk_arr($r['brief_json']), db_all('SELECT brief_json FROM contents WHERE user_id = ? AND brief_json IS NOT NULL ORDER BY id DESC LIMIT 5', [$uid])), 'method_id');
            $fresh = array_values(array_filter($methods, fn($m) => !in_array((int) $m['id'], array_map('intval', $last), true)));
            $method = ($fresh ?: $methods)[array_rand($fresh ?: $methods)];
        }

        $hookTypes = $d['content']['hooks'] ?: ['question'];
        $hook = (string) ($o['hook'] ?? $hookTypes[array_rand($hookTypes)]);

        return [
            'service_id' => $service ? (int) $service['id'] : null, 'service' => $service['name'] ?? '', 'service_desc' => mb_substr((string) ($service['description'] ?? ''), 0, 400),
            'topic_id' => isset($topic['id']) && $topic['id'] && ($topic['source'] ?? '') !== 'library' ? (int) $topic['id'] : null,
            'topic' => (string) ($topic['title'] ?? ''), 'topic_source' => (string) ($topic['source'] ?? ''),
            'character_id' => $character ? (int) $character['id'] : null, 'character' => $character['name'] ?? '',
            'format' => $format ?: 'single_image', 'pillar' => $pillar,
            'method_id' => $method ? (int) $method['id'] : null, 'method' => $method['title'] ?? '', 'method_body' => (string) ($method['body'] ?? ''),
            'hook' => $hook, 'goal' => (string) ($o['goal'] ?? $d['content']['goal']),
            'tone' => (string) ($o['tone'] ?? ''), 'caption_length' => (string) ($o['caption_length'] ?? $d['content']['caption_length']),
            'request' => mb_substr(trim((string) ($o['request'] ?? '')), 0, 1000),
            'reasons' => array_slice($why, 0, 3),
        ];
    }

    private static function weighted(array $weights): ?int
    {
        $sum = array_sum($weights);
        if ($sum <= 0) return $weights ? (int) array_key_first($weights) : null;
        $r = mt_rand(1, $sum);
        foreach ($weights as $id => $w) {
            $r -= $w;
            if ($r <= 0) return (int) $id;
        }
        return (int) array_key_first($weights);
    }

    /** بريف الحملة: الهدف · تركيز الشهر · الاتجاهات · المواضيع · طرق الكتابة · الأشكال · الممنوعات */
    public static function campaignBrief(int $uid, array $campaign): array
    {
        $b = self::base($uid);
        $d = $b['kit']['data'];
        $pillars = $d['pillars'] ?: ($b['sector']['default_pillars'] ?? []);
        $topics = array_slice(bk_topics($uid), 0, 12);
        $lib = bk_library_topics($uid, $b['kit']['sector_template_id'], 8);
        return [
            'title' => (string) ($campaign['title'] ?? ''), 'goal' => (string) ($campaign['basis'] ?? $d['content']['goal']),
            'focus' => self::focusService($uid)['name'] ?? '', 'seasons' => bk_current_seasons(),
            'services' => array_map(fn($s) => $s['name'], array_slice($b['services'], 0, 8)),
            'pillars' => $pillars,
            'topics' => array_values(array_unique(array_merge(array_map(fn($t) => $t['title'], $topics), array_map(fn($t) => $t['title'], $lib)))),
            'methods' => array_map(fn($m) => $m['title'], bk_learning_for($b['kit']['sector_template_id'], 'method', 'copy')),
            'formats' => array_map(fn($f) => ['format' => bk_format_labels()[$f['format']] ?? $f['format'], 'per_week' => (int) $f['per_week']], $b['formats']),
            'voice' => self::voiceText($d['voice']), 'offers' => self::liveOffers($d['offers']),
            'forbidden' => self::forbidden($uid), 'built_at' => date('Y-m-d H:i'),
        ];
    }

    /* ─── للبرومبت ─── */

    /** بيحوّل السياق لنص منظم يتحط في البرومبت */
    public static function toPrompt(array $ctx, string $agent): string
    {
        $L = [];
        $en = $agent === 'design';
        if ($ctx['admin_prompt'] !== '') $L[] = ($en ? "AGENT INSTRUCTIONS:\n" : "تعليمات الوكيل:\n") . $ctx['admin_prompt'];

        $br = $ctx['brief'] ?? null;
        switch ($agent) {
            case 'ideas':
                $L[] = 'هوية البراند (Brand Kit):';
                if ($ctx['services']) {
                    $L[] = '- الخدمات (بالأولوية):';
                    foreach ($ctx['services'] as $s) $L[] = '  • ' . $s['name'] . ($s['focus'] ? ' ⭐ خدمة الشهر — أعطيها نصيب أكبر من الأفكار' : '') . ' [' . $s['priority'] . ']' . ($s['seasons'] ? ' (مواسم: ' . implode('، ', $s['seasons']) . ')' : '');
                }
                $L[] = '- المواسم الحالية: ' . implode('، ', $ctx['seasons']);
                if ($ctx['pillars']) $L[] = '- اتجاهات المحتوى ونِسبها (وزّع الأفكار عليها): ' . implode('، ', array_map(fn($p) => $p['name'] . ' ' . $p['percent'] . '%', $ctx['pillars']));
                if ($ctx['topics']) $L[] = '- مواضيع العميل (استخدم منها وطوّرها): ' . implode('، ', array_slice($ctx['topics'], 0, 20));
                if ($ctx['library_topics']) $L[] = '- مواضيع مقترحة من مكتبة القطاع: ' . implode('، ', $ctx['library_topics']);
                if ($ctx['offers']) $L[] = '- عروض شغالة: ' . implode('، ', array_map(fn($o) => $o['title'] . ($o['until'] ? ' (لحد ' . $o['until'] . ')' : ''), $ctx['offers']));
                if ($ctx['characters']) $L[] = '- شخصيات ثابتة ممكن تظهر في القصص: ' . implode('، ', array_map(fn($c) => $c['name'] . ($c['role'] ? ' (' . $c['role'] . ')' : ''), $ctx['characters']));
                if ($ctx['formats']) $L[] = '- أشكال المحتوى المفضلة بالترتيب: ' . implode('، ', $ctx['formats']);
                $L[] = '- زاوية الكلام: ' . $ctx['angle_voice'];
                $L[] = '- هدف المحتوى: ' . bk_goal_label($ctx['goal']);
                break;

            case 'copy':
                $v = $ctx['voice'];
                $L[] = 'هوية البراند (Brand Kit):';
                if ($ctx['business']['price_range'] !== '') $L[] = '- مستوى الأسعار: ' . $ctx['business']['price_range'];
                $a = $ctx['audience'];
                if ($a['age'] !== '' || $a['gender'] !== '') $L[] = '- الجمهور: ' . trim($a['age'] . ' ' . $a['gender']);
                if ($a['pains']) $L[] = '- مشاكل الجمهور (اتكلم بلغتها): ' . implode('، ', $a['pains']);
                if ($a['objections']) $L[] = '- اعتراضاتهم (رد عليها بهدوء لو مناسب): ' . implode('، ', $a['objections']);
                if ($ctx['personality']) $L[] = '- شخصية البراند: ' . implode('، ', $ctx['personality']);
                $L[] = '- طريقة الكلام: ' . self::voiceText($v);
                if ($v['words_use']) $L[] = '- كلمات بنحب نستخدمها: ' . implode('، ', $v['words_use']);
                if ($v['sample_lines']) $L[] = "- جمل بأسلوب البراند (قلّد الروح مش الكلام):\n  " . implode("\n  ", $v['sample_lines']);
                $len = ['short' => 'قصير (40-80 كلمة)', 'medium' => 'متوسط (90-160 كلمة)', 'long' => 'طويل (180-280 كلمة)'][$ctx['content']['caption_length']] ?? 'متوسط';
                $L[] = '- طول الكابشن الأساسي: ' . $len;
                $L[] = '- الهدف ← الـ CTA: ' . bk_goal_cta($ctx['content']['goal']);
                if ($ctx['offers']) $L[] = '- عروض شغالة (اذكرها بس لو مناسبة للبوست): ' . implode('، ', array_map(fn($o) => $o['title'] . ($o['details'] ? ' — ' . $o['details'] : '') . ($o['until'] ? ' (لحد ' . $o['until'] . ')' : ''), $ctx['offers']));
                if ($ctx['hooks_library']) $L[] = "- قوالب Hooks ممكن تستلهم منها:\n" . mb_substr(implode("\n", $ctx['hooks_library']), 0, 900);
                break;

            case 'design':
                $c = $ctx['colors'];
                $L[] = 'BRAND KIT (follow strictly):';
                $pal = array_filter(['primary' => $c['primary'], 'secondary' => $c['secondary'], 'accent' => $c['accent'], 'neutral' => $c['neutral']]);
                if ($pal) $L[] = '- Palette: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($pal), $pal)) . ' — ratio ' . $c['ratio'];
                $L[] = '- Typography: Arabic "' . $ctx['typography']['ar'] . '", Latin "' . $ctx['typography']['en'] . '"';
                $ds = $ctx['design_style'];
                if (!empty($ds['preset_desc'])) $L[] = '- Design style: ' . $ds['preset_desc'];
                if ($ds['notes'] !== '') $L[] = '- Design notes from the client: ' . $ds['notes'];
                $L[] = '- Icons: ' . str_replace('_', ' ', $ds['icons']);
                if (!empty($ctx['logo']['path'])) $L[] = '- Logo position: ' . str_replace('-', ' ', $ctx['logo']['position']);
                $vi = $ctx['visuals'];
                $kind = ['photo' => 'realistic photography', '3d' => 'modern 3D renders', 'flat' => 'flat vector illustration', 'mixed' => 'mix of photos and graphics'][$vi['kind']] ?? '';
                if ($kind) $L[] = '- Imagery: ' . $kind;
                if ($vi['light'] !== '') $L[] = '- Light & mood: ' . ['bright' => 'bright and airy', 'warm' => 'warm and cozy', 'dramatic' => 'dramatic contrast'][$vi['light']];
                if ($vi['people'] !== '') $L[] = '- People: ' . ['egyptian' => 'Egyptian people with natural, modest look', 'arab' => 'Arab people', 'characters_only' => 'only the brand characters', 'none' => 'no people'][$vi['people']];
                if ($vi['places']) $L[] = '- Typical places: ' . implode(', ', $vi['places']);
                $L[] = '- Brand colors inside photos: ' . $vi['brand_color_in_photos'];
                break;

            case 'video':
                $L[] = 'هوية البراند للفيديو:';
                $L[] = '- طريقة الكلام في السكريبت: ' . self::voiceText($ctx['voice']);
                $L[] = '- مدة الريل المفضلة: ' . $ctx['duration'] . ' ثانية';
                $vi = $ctx['visuals'];
                if ($vi['kind'] !== '') $L[] = '- نوع الصورة: ' . $vi['kind'] . ($vi['light'] !== '' ? ' · إضاءة ' . $vi['light'] : '');
                if ($vi['places']) $L[] = '- أماكن التصوير المعتادة: ' . implode('، ', $vi['places']);
                break;

            case 'schedule':
                $L[] = 'خطة الهوية للجدولة:';
                $L[] = '- عدد البوستات في الشهر: ' . $ctx['posts_per_month'];
                $L[] = '- الأشكال في الأسبوع: ' . implode('، ', array_map(fn($f) => (bk_format_labels()[$f['format']] ?? $f['format']) . ' ×' . $f['per_week'], $ctx['formats']));
                if ($ctx['pillars']) $L[] = '- الاتجاهات: ' . implode('، ', array_map(fn($p) => $p['name'] . ' ' . $p['percent'] . '%', $ctx['pillars']));
                if ($ctx['focus']) $L[] = '- خدمة الشهر: ' . $ctx['focus']['name'];
                break;
        }

        // قواعد مكتبة التعلّم
        foreach ($ctx['rules'] ?? [] as $r) {
            $L[] = ($en ? 'RULES — ' : 'قواعد «') . $r['title'] . ($en ? ":\n" : "» (التزم بيها):\n") . mb_substr($r['body'], 0, 1200);
        }

        // البريف (بوست واحد)
        if ($br) $L[] = self::briefText($br, $agent);

        // الممنوعات — دايمًا
        $f = $ctx['forbidden'];
        if ($en) {
            if ($f['visuals']) $L[] = 'NEVER SHOW: ' . implode(', ', $f['visuals']);
        } else {
            if ($f['words']) $L[] = '⛔ كلمات وعبارات ممنوعة نهائيًا (ماتكتبهاش ولا بصيغة تانية): ' . implode('، ', $f['words']);
            if ($f['topics']) $L[] = '⛔ مواضيع ممنوعة: ' . implode('، ', $f['topics']);
        }
        if (in_array($agent, ['copy', 'ideas'], true) && ($ctx['sector'] ?? '') !== '') {
            $L[] = 'القطاع: ' . $ctx['sector'] . ' — التزم بأخلاقيات القطاع ده ومتديش وعود مضمونة.';
        }
        return implode("\n", $L);
    }

    /** نص البريف للبرومبت */
    public static function briefText(array $br, string $agent = 'copy'): string
    {
        if ($agent === 'design') {
            $L = ['POST BRIEF:'];
            if ($br['service'] !== '') $L[] = '- Service featured: ' . $br['service'];
            if ($br['topic'] !== '') $L[] = '- Topic: ' . $br['topic'];
            if (!empty($br['character_desc'])) $L[] = '- Recurring brand character: ' . $br['character_desc'] . ' — keep the SAME face, features and outfit as the reference image.';
            return implode("\n", $L);
        }
        $L = ['بريف البوست ده (التزم بيه):'];
        if ($br['request'] !== '') $L[] = '- طلب العميل (ليه الأولوية على أي حاجة تانية ما عدا الممنوعات): ' . $br['request'];
        if ($br['service'] !== '') $L[] = '- الخدمة: ' . $br['service'] . ($br['service_desc'] !== '' ? ' — ' . $br['service_desc'] : '');
        if ($br['topic'] !== '') $L[] = '- الموضوع: ' . $br['topic'];
        if ($br['pillar'] !== '') $L[] = '- الاتجاه: ' . $br['pillar'];
        $L[] = '- الشكل: ' . (bk_format_labels()[$br['format']] ?? $br['format']);
        if ($br['method'] !== '') $L[] = '- طريقة الكتابة: ' . $br['method'] . ($br['method_body'] !== '' ? "\n  " . str_replace("\n", "\n  ", mb_substr($br['method_body'], 0, 700)) : '');
        $L[] = '- نوع الـ Hook: ' . (bk_hook_types()[$br['hook']] ?? $br['hook']);
        if ($br['character'] !== '') $L[] = '- الشخصية اللي بتظهر/بتتكلم: ' . $br['character'] . (!empty($br['character_voice']) ? ' — طريقة كلامها: ' . $br['character_voice'] : '');
        $L[] = '- الهدف: ' . bk_goal_label($br['goal']) . ' ← ' . bk_goal_cta($br['goal']);
        if ($br['tone'] !== '') $L[] = '- النبرة للبوست ده: ' . $br['tone'];
        if (in_array($agent, ['copy'], true)) {
            $L[] = "في آخر الرد ضيف 3 أسباب قصيرة جدًا (كل سبب أقل من 12 كلمة) لاختياراتك بالتنسيق:\n[WHY]\nسبب 1\nسبب 2\nسبب 3\n[/WHY]";
        }
        return implode("\n", $L);
    }

    /** البريف كامل لبوست (pickForPost + تفاصيل الشخصية) */
    public static function brief(int $uid, array $overrides = []): array
    {
        $br = self::pickForPost($uid, $overrides);
        if ($br['character_id']) {
            $c = db_one('SELECT * FROM brand_characters WHERE id = ? AND user_id = ?', [$br['character_id'], $uid]);
            if ($c) {
                $br['character_desc'] = trim($c['name'] . ' — ' . ($c['role'] ? $c['role'] . '. ' : '') . (string) $c['description']);
                $br['character_voice'] = (string) $c['personality'];
            }
        }
        return $br;
    }

    /** نسخة مختصرة من الهوية اللي اتعمل بيها البوست (brand_snapshot) */
    public static function snapshot(int $uid): array
    {
        $b = self::base($uid);
        $d = $b['kit']['data'];
        return [
            'kit_version' => $b['kit']['version'], 'sector' => $b['sector']['name'] ?? '',
            'voice' => self::voiceText($d['voice']), 'colors' => array_values(array_filter([$d['colors']['primary'], $d['colors']['secondary'], $d['colors']['accent']])),
            'goal' => $d['content']['goal'], 'caption_length' => $d['content']['caption_length'],
            'forbidden_words' => self::forbidden($uid)['words'], 'at' => date('Y-m-d H:i:s'),
        ];
    }

    /** الممنوعات دايمًا: أي جملة فيها كلمة ممنوعة بتتشال من الكابشن */
    public static function enforce(string $text, int $uid): string
    {
        $words = self::forbidden($uid)['words'];
        if (!$words || trim($text) === '') return $text;
        $hit = fn(string $s) => (bool) array_filter($words, fn($w) => $w !== '' && mb_stripos($s, $w) !== false);
        if (!$hit($text)) return $text;
        $lines = preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($lines as $line) {
            if (preg_match('/^\R$/u', $line)) { $out .= $line; continue; }
            if (!$hit($line)) { $out .= $line; continue; }
            // الجملة اللي فيها الكلمة بس — مش السطر كله
            $parts = preg_split('/(?<=[.!؟?…])\s+/u', $line);
            $keep = array_filter($parts, fn($p) => !$hit($p));
            $out .= implode(' ', $keep);
        }
        $out = trim(preg_replace("/\n{3,}/u", "\n\n", $out));
        // آخر حل: لو فاضل أي كلمة (جوه نص من غير علامات)
        foreach ($words as $w) if ($w !== '') $out = str_ireplace($w, '', $out);
        return trim(preg_replace('/[ \t]{2,}/u', ' ', $out));
    }

    /** فيه كلمة ممنوعة في طلب العميل؟ (للتنبيه) */
    public static function violations(string $text, int $uid): array
    {
        return array_values(array_filter(self::forbidden($uid)['words'], fn($w) => $w !== '' && mb_stripos($text, $w) !== false));
    }

    /** أسباب «ليه الـ AI عمل كده» من [WHY] */
    public static function parseWhy(string $response): array
    {
        if (!preg_match('/\[WHY\](.*?)\[\/WHY\]/su', $response, $m)) return [];
        $lines = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/^[\-\*•\d\.\)\s]+/u', '', $l)), preg_split('/\R/u', $m[1]))));
        return array_slice(array_map(fn($l) => mb_substr($l, 0, 140), $lines), 0, 3);
    }

    public static function stripWhy(string $text): string
    {
        return trim(preg_replace('/\[WHY\].*?(\[\/WHY\]|$)/su', '', $text));
    }

    /**
     * بعد حفظ البوست: البريف + snapshot + الأسباب + استخدام الموضوع
     * $reasons من الـ AI؛ لو فاضية بنستخدم أسباب الاختيار
     */
    public static function attach(int $contentId, int $uid, array $brief, array $reasons = [], array $overrides = []): void
    {
        try {
            bk_ensure_schema();
            $reasons = $reasons ?: ($brief['reasons'] ?? []);
            $store = $brief;
            unset($store['method_body']);
            db_run('UPDATE contents SET brief_json = ?, brand_snapshot = ?, ai_reasoning = ?, brand_overrides = COALESCE(?, brand_overrides),
                           bk_service_id = ?, bk_topic_id = ?, bk_character_id = ? WHERE id = ? AND user_id = ?',
                [bk_json($store), bk_json(self::snapshot($uid)), bk_json(array_slice($reasons, 0, 3)), $overrides ? bk_json($overrides) : null,
                 $brief['service_id'] ?? null, $brief['topic_id'] ?? null, $brief['character_id'] ?? null, $contentId, $uid]);
            if (!empty($brief['topic_id'])) {
                db_run('UPDATE brand_topics SET times_used = times_used + 1, last_used_at = NOW() WHERE id = ? AND user_id = ?', [$brief['topic_id'], $uid]);
            } elseif (($brief['topic_source'] ?? '') === 'library' && $brief['topic'] !== '') {
                db_run('UPDATE learning_topics SET times_used = times_used + 1 WHERE title = ? LIMIT 1', [$brief['topic']]);
            }
        } catch (\Throwable $e) {
            error_log('[brand-kit] attach: ' . $e->getMessage());
        }
    }

    /**
     * الحقائق (brand_profiles + الخدمات + التواصل + المستندات) — الجزء الثابت من بلوك الهوية الموحّد
     * ideas: من غير التواصل · copy: كامل · design: الاسم والمجال وقواعد العميل بس
     */
    public static function factsBlock(int $uid, string $agent): string
    {
        $bp = bk_brand_profile($uid) ?: [];
        $kit = self::base($uid)['kit'];
        $d = $kit['data'];
        $v = fn($k) => trim((string) ($bp[$k] ?? ''));
        if ($agent === 'design') {
            $L = ['Brand: ' . $v('business_name')];
            if ($v('industry') !== '') $L[] = 'Industry: ' . $v('industry');
            if ($d['colors']['primary'] === '' && $v('colors') !== '') $L[] = 'BRAND COLORS: ' . $v('colors');
            if ($v('design_rules') !== '') $L[] = 'DESIGN RULES written by the client (must follow strictly): ' . mb_substr($v('design_rules'), 0, 800);
            // تحليل الهوية البصرية القديم: بس لو العميل لسه ماحددش استايل في مركز الهوية
            $ds = $d['design_style'];
            if ($v('visual_identity') !== '' && empty($ds['preset_id']) && $ds['notes'] === '') {
                $L[] = 'Visual identity notes (from analysis of the client\'s past designs): ' . mb_substr($v('visual_identity'), 0, 700);
            }
            return implode("\n", $L);
        }
        $L = ['بيانات البراند:'];
        foreach (['business_name' => 'اسم النشاط', 'industry' => 'المجال', 'description' => 'الوصف'] as $k => $l) {
            if ($v($k) !== '') $L[] = '- ' . $l . ': ' . mb_substr($v($k), 0, 800);
        }
        if ($d['business']['usp'] !== '') $L[] = '- اللي بيميزنا: ' . $d['business']['usp'];
        if ($v('audience') !== '') $L[] = '- الجمهور المستهدف: ' . mb_substr($v('audience'), 0, 600);
        if ($agent === 'copy') {
            $svc = self::base($uid)['services'];
            if ($svc) {
                $L[] = '- الخدمات/المنتجات:';
                foreach (array_slice($svc, 0, 15) as $x) $L[] = '  • ' . $x['name'] . ($x['description'] ? ' — ' . mb_substr((string) $x['description'], 0, 160) : '');
            }
            $c = [];
            foreach (['address' => 'العنوان', 'phones' => 'أرقام التواصل', 'whatsapp' => 'واتساب', 'working_hours' => 'مواعيد العمل', 'website' => 'الموقع'] as $k => $l) {
                if ($v($k) !== '') $c[] = $l . ': ' . $v($k);
            }
            $soc = [];
            foreach (['social_facebook' => 'فيسبوك', 'social_instagram' => 'انستجرام', 'social_tiktok' => 'تيك توك', 'social_linkedin' => 'لينكدإن'] as $k => $l) {
                if ($v($k) !== '') $soc[] = $l . ': ' . $v($k);
            }
            if ($soc) $c[] = 'السوشيال: ' . implode(' · ', $soc);
            if ($c) {
                $L[] = '- بيانات التواصل (للـ CTA لما تكون مناسبة): ' . implode(' | ', $c);
                $L[] = 'ماتخترعش أرقام أو عناوين أو أسعار غير الموجودة هنا.';
            }
            if ($cta = bk_cta_text($uid, $d)) $L[] = '- الـ CTA الأساسي (استخدمه في آخر البوست إلا لو الحملة أو العميل طلب غيره): ' . $cta;
        }
        if ($v('notes') !== '') $L[] = '- ملاحظات العميل: ' . mb_substr($v('notes'), 0, 500);
        // ملخص الهوية القديم: بس لو الـ Kit لسه فاضي (وإلا بيبقى قديم ومتعارض)
        if ($v('ai_summary') !== '' && $kit['completeness'] < 40) $L[] = '- ملخص الهوية: ' . mb_substr($v('ai_summary'), 0, 600);
        if (!empty($bp['id']) && function_exists('get_brand_knowledge')) {
            $k = get_brand_knowledge((int) $bp['id']);
            if ($k !== '') $L[] = "\nمعلومات موثّقة عن البيزنس (من مستندات العميل — مصدر حقائق، ماتخترعش حاجة تخالفها):\n" . mb_substr($k, 0, $agent === 'copy' ? 4000 : 2500);
        }
        return implode("\n", $L);
    }

    /** أداة واحدة للبرومبتات: السياق + البريف كنص (بأمان — أي خطأ = نص فاضي) */
    public static function promptFor(int $uid, string $agent, ?array $brief = null, array $overrides = []): string
    {
        if ($uid <= 0) return '';
        try {
            $o = $overrides;
            if ($brief) $o['__brief'] = $brief;
            return self::toPrompt(self::build($uid, $agent, $o), $agent);
        } catch (\Throwable $e) {
            error_log('[brand-kit] context: ' . $e->getMessage());
            return '';
        }
    }
}

function bk_goal_label(string $g): string
{
    return ['bookings' => 'حجوزات', 'sales' => 'مبيعات', 'reach' => 'انتشار', 'trust' => 'ثقة', 'engagement' => 'تفاعل'][$g] ?? $g;
}

function bk_goal_cta(string $g): string
{
    return [
        'bookings' => 'CTA للحجز (رسالة/رقم/لينك الحجز)',
        'sales' => 'CTA للطلب أو الشراء',
        'reach' => 'CTA للمشاركة أو المنشن',
        'trust' => 'CTA هادي (اسأل/تابعنا) من غير ضغط بيع',
        'engagement' => 'CTA سؤال يشجع على الكومنت',
    ][$g] ?? 'CTA واحد واضح';
}

/** Brand Kit مفعّل؟ (الأدمن يقدر يقفله — المسارات القديمة بتشتغل زي ما هي) */
function bk_enabled(): bool
{
    try { return get_setting('brand_kit_enabled', '1') === '1'; } catch (\Throwable $e) { return true; }
}

/** المدخل الموحد للبرومبتات القديمة (prompt-builder / campaign-flow / plan-functions / design) */
function bk_prompt_block(int $uid, string $agent, ?array $brief = null, array $overrides = []): string
{
    if (!bk_enabled() || $uid <= 0) return '';
    $t = BrandContext::promptFor($uid, $agent, $brief, $overrides);
    return $t !== '' ? "\n\n" . $t : '';
}

/** الـ overrides من طلب العميل (chips البوست) — bk_service_id · bk_topic_id · bk_topic · bk_character_id · bk_format · bk_tone · bk_goal · bk_length */
function bk_overrides_from_input(array $in, string $request = ''): array
{
    $o = [];
    foreach (['service_id', 'topic_id', 'method_id'] as $k) if (!empty($in['bk_' . $k])) $o[$k] = (int) $in['bk_' . $k];
    if (isset($in['bk_character_id']) && $in['bk_character_id'] !== '') $o['character_id'] = (int) $in['bk_character_id'];   // 0 = من غير شخصية
    if (!empty($in['bk_topic'])) $o['topic'] = mb_substr(trim((string) $in['bk_topic']), 0, 255);
    if (!empty($in['bk_format']) && in_array($in['bk_format'], BK_FORMATS, true)) $o['format'] = (string) $in['bk_format'];
    if (!empty($in['bk_tone'])) $o['tone'] = mb_substr(trim((string) $in['bk_tone']), 0, 60);
    if (!empty($in['bk_goal']) && in_array($in['bk_goal'], ['bookings', 'sales', 'reach', 'trust', 'engagement'], true)) $o['goal'] = (string) $in['bk_goal'];
    if (!empty($in['bk_length']) && in_array($in['bk_length'], ['short', 'medium', 'long'], true)) $o['caption_length'] = (string) $in['bk_length'];
    if (trim($request) !== '') $o['request'] = mb_substr(trim($request), 0, 1000);
    return $o;
}

/** بريف إعادة التوليد: نفس اختيارات البوست اللي فات + أي chip اتغير (المتغير بس) */
function bk_brief_for_content(array $content, int $uid, array $newOverrides = []): array
{
    $prev = bk_arr($content['brief_json'] ?? '');
    $saved = bk_arr($content['brand_overrides'] ?? '');
    $o = array_merge($saved, $newOverrides);
    foreach (['service_id', 'topic_id', 'method_id', 'format', 'pillar', 'hook'] as $k) {
        if (!array_key_exists($k, $o) && !empty($prev[$k])) $o[$k] = $prev[$k];
    }
    if (!array_key_exists('character_id', $o) && array_key_exists('character_id', $prev)) $o['character_id'] = (int) $prev['character_id'];
    if (empty($o['topic_id']) && empty($o['topic']) && !empty($prev['topic'])) $o['topic'] = $prev['topic'];
    if (!empty($content['extra_notes']) && empty($o['request'])) $o['request'] = (string) $content['extra_notes'];
    return BrandContext::brief($uid, $o);
}


/**
 * بلوك الهوية الموحّد لأي برومبت: الحقائق + الـ Kit للـ Agent ده + البريف — مكان واحد بدل بلوكين
 * null = مركز الهوية مقفول (المسار القديم يشتغل زي ما هو)
 */
function bk_brand_block(array $brand, string $agent, ?array $brief = null, array $overrides = []): ?string
{
    $uid = (int) ($brand['user_id'] ?? 0);
    if (!bk_enabled() || $uid <= 0) return null;
    try {
        if (!function_exists('get_brand_knowledge') && is_file(__DIR__ . '/source-extract.php')) require_once __DIR__ . '/source-extract.php';
        $facts = BrandContext::factsBlock($uid, $agent);
        $kit = BrandContext::promptFor($uid, $agent, $brief, $overrides);
        return trim($facts . ($kit !== '' ? "\n\n" . $kit : ''));
    } catch (\Throwable $e) {
        error_log('[brand-kit] block: ' . $e->getMessage());
        return null;
    }
}


/** اقتراح للهوية (صندوق الاقتراحات الموحّد) — مابيتكررش لو نفس الحقل مستني رد */
function bk_suggest(int $uid, string $field, string $message, array $proposed): ?int
{
    try {
        bk_ensure_schema();
        if (db_one('SELECT id FROM brand_suggestions WHERE user_id = ? AND field = ? AND status = "pending"', [$uid, $field])) return null;
        return (int) db_insert('INSERT INTO brand_suggestions (user_id, field, message, proposed_value) VALUES (?,?,?,?)',
            [$uid, mb_substr($field, 0, 100), mb_substr($message, 0, 2000), bk_json($proposed)]);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * تحليل الهوية البصرية (Brand Brain) ← اقتراحات لقسم الشكل بدل نص طويل في البرومبت:
 * الألوان (الأكواد اللي اتذكرت) + ملاحظات التصميم
 */
function bk_suggest_from_visual_identity(int $uid, string $text): int
{
    if (!bk_enabled() || trim($text) === '') return 0;
    $n = 0;
    preg_match_all('/#[0-9a-f]{6}\b/i', $text, $m);
    $hex = array_values(array_unique(array_map('strtoupper', $m[0])));
    if ($hex) {
        $merge = [];
        foreach (['primary', 'secondary', 'accent', 'neutral'] as $i => $k) if (isset($hex[$i])) $merge[$k] = $hex[$i];
        if (bk_suggest($uid, 'vi_colors', 'تحليل تصميماتك طلّع الألوان دي: ' . implode(' · ', array_slice($hex, 0, 4)) . ' — نخليها ألوان البراند؟', ['section' => 'colors', 'merge' => $merge])) $n++;
    }
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn($l) => mb_strlen($l) > 12));
    $notes = mb_substr(implode(' ', array_slice($lines, 0, 6)), 0, 600);
    if ($notes !== '' && bk_suggest($uid, 'vi_notes', 'من تحليل تصميماتك: «' . mb_substr($notes, 0, 160) . '…» — نحطها في ملاحظات التصميم؟', ['section' => 'design_style', 'key' => 'notes', 'value' => $notes])) $n++;
    return $n;
}

/**
 * الصورة الشخصية القديمة (Brand Brain) ← شخصية «صاحب البراند» في الـ Kit — مرة واحدة
 * العميل رفعها بنفسه علشان تظهر في التصميمات، فالموافقة متسجّلة إنها «من الصورة الشخصية»
 */
function bk_migrate_personal(int $uid, array $bp): void
{
    static $done = [];
    if (isset($done[$uid])) return;
    $done[$uid] = true;
    try {
        $paths = [];
        if (!empty($bp['personal_image_path'])) $paths[] = (string) $bp['personal_image_path'];
        foreach (db_all('SELECT image_path FROM brand_images WHERE brand_profile_id = ? AND image_type = "personal" AND image_path IS NOT NULL ORDER BY id LIMIT 7', [(int) $bp['id']]) as $r) $paths[] = (string) $r['image_path'];
        $paths = array_values(array_unique(array_filter($paths)));
        if (!$paths) return;
        if (db_one('SELECT c.id FROM brand_characters c JOIN brand_character_images i ON i.character_id = c.id WHERE c.user_id = ? AND i.image_path = ? LIMIT 1', [$uid, $paths[0]])) return;
        $cid = db_insert('INSERT INTO brand_characters (user_id, name, type, role, description, use_in, share_percent, consent_confirmed, consent_at, is_active)
                          VALUES (?, "الصورة الشخصية", "real_person", "من Brand Brain (الصورة الشخصية / المنتج)", NULL, "image,story", 0, 1, NOW(), 1)', [$uid]);
        foreach ($paths as $i => $path) db_insert('INSERT INTO brand_character_images (character_id, image_path, is_primary) VALUES (?,?,?)', [$cid, $path, $i === 0 ? 1 : 0]);
    } catch (\Throwable $e) {
        error_log('[brand-kit] migrate personal: ' . $e->getMessage());
    }
}


/**
 * تعديلات «مساعد الهوية» على مركز الهوية (من [KIT] في المحادثة) — نسخة باسم الـ AI
 * @return string[] اللي اتغيّر (للرد على العميل)
 */
function bk_agent_apply(int $uid, array $j): array
{
    $done = [];
    $kit = bk_get($uid);
    $row = db_one('SELECT data FROM brand_kits WHERE id = ?', [$kit['id']]);
    $stored = bk_arr($row['data'] ?? '{}');
    $d = $kit['data'];
    $changed = false;
    $have = array_map(fn($n) => mb_strtolower(trim($n)), array_column(db_all('SELECT name FROM brand_services WHERE user_id = ?', [$uid]), 'name'));
    $nS = 0;
    foreach (array_slice((array) ($j['add_services'] ?? []), 0, 10) as $sv) {
        $name = mb_substr(trim((string) (is_array($sv) ? ($sv['name'] ?? '') : $sv)), 0, 150);
        if ($name === '' || in_array(mb_strtolower($name), $have, true)) continue;
        $prio = is_array($sv) && in_array($sv['priority'] ?? '', ['high', 'medium', 'low'], true) ? $sv['priority'] : 'medium';
        db_insert('INSERT INTO brand_services (user_id, name, description, priority, sort_order) VALUES (?,?,?,?,?)',
            [$uid, $name, is_array($sv) ? (mb_substr(trim((string) ($sv['description'] ?? '')), 0, 1000) ?: null) : null, $prio, count($have) + 1]);
        $have[] = mb_strtolower($name);
        $nS++;
    }
    if ($nS) { bk_services_to_text($uid); $done[] = $nS . ' خدمة'; }
    $topics = array_values(array_filter(array_map(fn($t) => mb_substr(trim((string) $t), 0, 255), (array) ($j['add_topics'] ?? []))));
    if ($topics) {
        $haveT = array_map('mb_strtolower', array_column(db_all('SELECT title FROM brand_topics WHERE user_id = ?', [$uid]), 'title'));
        $nT = 0;
        foreach (array_slice($topics, 0, 15) as $t) {
            if (in_array(mb_strtolower($t), $haveT, true)) continue;
            db_insert('INSERT INTO brand_topics (user_id, title, source) VALUES (?,?,"ai")', [$uid, $t]);
            $nT++;
        }
        if ($nT) $done[] = $nT . ' موضوع';
    }
    if (!empty($j['add_offers']) && is_array($j['add_offers'])) {
        $stored['offers'] = bk_clean_section('offers', array_merge($d['offers'], $j['add_offers']));
        $done[] = 'العروض';
        $changed = true;
    }
    if (!empty($j['pillars']) && is_array($j['pillars'])) {
        $stored['pillars'] = bk_clean_section('pillars', $j['pillars']);
        $done[] = 'اتجاهات المحتوى';
        $changed = true;
    }
    if (!empty($j['voice_preset'])) {
        $p = db_one('SELECT id, data FROM style_presets WHERE kind = "tone" AND name = ? AND is_active = 1', [(string) $j['voice_preset']]);
        if ($p) {
            $stored['voice'] = bk_clean_section('voice', array_merge($d['voice'], bk_arr($p['data'])['voice'] ?? [], ['preset_id' => (int) $p['id']]));
            $done[] = 'طريقة الكلام';
            $changed = true;
        }
    }
    if (!empty($j['forbidden_words']) && is_array($j['forbidden_words'])) {
        $v = $stored['voice'] ?? $d['voice'];
        $v['words_avoid'] = array_values(array_unique(array_merge($d['voice']['words_avoid'], $j['forbidden_words'])));
        $stored['voice'] = bk_clean_section('voice', $v);
        $done[] = 'الكلمات الممنوعة';
        $changed = true;
    }
    if (!empty($j['forbidden_topics']) && is_array($j['forbidden_topics'])) {
        $stored['forbidden_topics'] = bk_clean_section('forbidden_topics', array_merge($d['forbidden_topics'], $j['forbidden_topics']));
        $done[] = 'المواضيع الممنوعة';
        $changed = true;
    }
    if (!empty($j['focus_service']) && is_string($j['focus_service'])) {
        $f = db_one('SELECT id FROM brand_services WHERE user_id = ? AND name = ?', [$uid, trim($j['focus_service'])]);
        if ($f) {
            db_run('UPDATE brand_services SET is_focus = (id = ?) WHERE user_id = ?', [$f['id'], $uid]);
            $stored['focus'] = bk_clean_section('focus', ['mode' => 'service', 'service_id' => (int) $f['id']]);
            $done[] = 'خدمة الشهر';
            $changed = true;
        }
    }
    if ($changed || $done) {
        bk_write($kit['id'], $uid, $stored, 'ai', 'من مساعد الهوية: ' . implode('، ', $done));
        if (isset($stored['voice'])) bk_sync_profile($uid, 'voice', $stored['voice']);
    }
    return $done;
}

/* ═══════════════ الـ CTA الأساسي ═══════════════ */

function bk_cta_types(): array
{
    return ['whatsapp' => 'ابعت واتساب', 'call' => 'اتصل بينا', 'booking' => 'احجز', 'visit' => 'زورنا', 'order' => 'اطلب أونلاين', 'website' => 'شوف الموقع', 'message' => 'ابعتلنا رسالة'];
}

/** «الجملة — النوع (اللينك/الرقم)» · اللينك بيتاخد من بيانات التواصل لو فاضي */
function bk_cta_text(int $uid, ?array $d = null): string
{
    $d = $d ?? bk_get($uid)['data'];
    $c = $d['cta'] ?? [];
    if (trim((string) ($c['text'] ?? '')) === '') return '';
    $link = trim((string) ($c['link'] ?? ''));
    if ($link === '') {
        $bp = bk_brand_profile($uid) ?: [];
        $link = match ($c['type'] ?? '') {
            'whatsapp' => (string) ($bp['whatsapp'] ?? ''), 'call' => (string) ($bp['phones'] ?? ''),
            'website', 'order', 'booking' => (string) ($bp['website'] ?? ''), 'visit' => (string) ($bp['address'] ?? ''),
            default => '',
        };
    }
    return $c['text'] . (!empty($c['type']) ? ' — ' . (bk_cta_types()[$c['type']] ?? '') : '') . ($link !== '' ? ' (' . mb_substr($link, 0, 160) . ')' : '');
}

/* ═══════════════ العملاء المثاليين (Buyer Personas) ═══════════════ */

function bk_personas(int $uid, bool $activeOnly = true): array
{
    try {
        $rows = db_all('SELECT * FROM brand_personas WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id', [$uid]);
    } catch (\Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        foreach (['pains', 'desires', 'objections', 'triggers'] as $k) $r[$k] = bk_arr($r[$k]);
        $r['service_ids'] = array_values(array_filter(array_map('intval', explode(',', (string) $r['service_ids']))));
        $r['channels'] = array_values(array_filter(array_map('trim', explode(',', (string) $r['channels']))));
    }
    unset($r);
    return $rows;
}

function bk_persona(int $uid, int $id): ?array
{
    foreach (bk_personas($uid, false) as $p) if ((int) $p['id'] === $id) return $p;
    return null;
}

/** وصف الشخص للبرومبت */
function bk_persona_text(array $p, int $uid = 0): string
{
    $svc = [];
    if ($p['service_ids'] && $uid) {
        foreach (bk_services($uid) as $s) if (in_array((int) $s['id'], $p['service_ids'], true)) $svc[] = $s['name'];
    }
    $L = ['العميل المثالي: ' . $p['name'] . (trim(implode('، ', array_filter([$p['age'], $p['gender'], $p['job'], $p['location']]))) !== '' ? ' (' . implode('، ', array_filter([$p['age'], $p['gender'], $p['job'], $p['location']])) . ')' : '')];
    if ($svc) $L[] = '  مهتم بـ: ' . implode('، ', $svc);
    if ($p['pains']) $L[] = '  وجعه: ' . implode('، ', array_slice($p['pains'], 0, 4));
    if ($p['desires']) $L[] = '  نفسه في: ' . implode('، ', array_slice($p['desires'], 0, 4));
    if ($p['objections']) $L[] = '  اعتراضاته: ' . implode('، ', array_slice($p['objections'], 0, 4));
    if ($p['triggers']) $L[] = '  اللي يخليه يشتري: ' . implode('، ', array_slice($p['triggers'], 0, 4));
    if (!empty($p['voice'])) $L[] = '  الكلام اللي يناسبه: ' . $p['voice'];
    return implode("\n", $L);
}

/** حفظ شخص (من الواجهة أو من الـ AI) — الخدمات لازم تكون من خدمات العميل */
function bk_persona_save(int $uid, array $in, int $id = 0, string $source = 'user'): int
{
    $str = fn($v, $n) => mb_substr(trim((string) $v), 0, $n);
    $list = fn($v) => json_encode(array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 160), is_array($v) ? $v : bk_lines((string) $v))), 0, 8)), JSON_UNESCAPED_UNICODE);
    $own = array_map('intval', array_column(bk_services($uid, false), 'id'));
    $svc = array_values(array_intersect(array_map('intval', (array) ($in['service_ids'] ?? [])), $own));
    $row = [$str($in['name'] ?? '', 100) ?: 'عميل', $str($in['emoji'] ?? '', 8) ?: null, $str($in['age'] ?? '', 40) ?: null, $str($in['gender'] ?? '', 30) ?: null,
            $str($in['job'] ?? '', 150) ?: null, $str($in['location'] ?? '', 150) ?: null, $list($in['pains'] ?? []), $list($in['desires'] ?? []), $list($in['objections'] ?? []),
            $list($in['triggers'] ?? []), $str(implode(',', array_map('trim', is_array($in['channels'] ?? null) ? $in['channels'] : explode(',', (string) ($in['channels'] ?? '')))), 255) ?: null,
            $svc ? implode(',', $svc) : null, $str($in['voice'] ?? '', 255) ?: null, array_key_exists('is_active', $in) ? (int) (bool) $in['is_active'] : 1];
    if ($id) {
        db_run('UPDATE brand_personas SET name=?, emoji=?, age=?, gender=?, job=?, location=?, pains=?, desires=?, objections=?, triggers=?, channels=?, service_ids=?, voice=?, is_active=? WHERE id=? AND user_id=?',
            array_merge($row, [$id, $uid]));
        return $id;
    }
    return (int) db_insert('INSERT INTO brand_personas (name, emoji, age, gender, job, location, pains, desires, objections, triggers, channels, service_ids, voice, is_active, user_id, source, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array_merge($row, [$uid, $source === 'ai' ? 'ai' : 'user', (int) (db_one('SELECT COUNT(*) n FROM brand_personas WHERE user_id = ?', [$uid])['n'] ?? 0) + 1]));
}

/** عدد البيرسونات الافتراضي والأقصى (من الإعدادات) */
function bk_personas_default(): int
{
    return max(1, min(6, (int) get_setting('brand_personas_default', '3')));
}

function bk_personas_max(): int
{
    return 6;
}

