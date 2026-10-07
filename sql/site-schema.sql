-- ═══════════════════════════════════════════════════════════
-- Spread AI — قاعدة بيانات الموقع التعريفي (منفصلة عن المنصة)
-- ═══════════════════════════════════════════════════════════
-- مهم: الترميز لازم utf8mb4 عشان العربي يتخزن صح
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `site_admins` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(150) NOT NULL,
    `email`      VARCHAR(190) NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إعدادات عامة (هيدر/فوتر/ألوان/روابط)
CREATE TABLE IF NOT EXISTS `site_settings` (
    `setting_key`   VARCHAR(80) PRIMARY KEY,
    `setting_value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إظهار/إخفاء الأقسام + ترتيبها
CREATE TABLE IF NOT EXISTS `site_sections` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `section_key` VARCHAR(60) NOT NULL UNIQUE,
    `label_ar`   VARCHAR(150) NOT NULL,
    `title`      VARCHAR(255) NULL,
    `subtitle`   TEXT NULL,
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سلايدر الخدمات (صورة + كلام)
CREATE TABLE IF NOT EXISTS `site_slides` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(200) NOT NULL,
    `subtitle`   VARCHAR(400) NULL,
    `image_path` VARCHAR(500) NULL,
    `image_url`  VARCHAR(700) NULL,
    `cta_text`   VARCHAR(100) NULL,
    `cta_url`    VARCHAR(500) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- العلامات التجارية
CREATE TABLE IF NOT EXISTS `site_brands` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(200) NOT NULL,
    `logo_path`  VARCHAR(500) NULL,
    `logo_url`   VARCHAR(700) NULL,
    `link_url`   VARCHAR(500) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uniq_seed` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- المشاكل اللي بتقابل الناس
CREATE TABLE IF NOT EXISTS `site_problems` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(250) NOT NULL,
    `body`       TEXT NULL,
    `icon`       VARCHAR(20) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uniq_seed` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- معلومات عن المنصة + الحلول
CREATE TABLE IF NOT EXISTS `site_solutions` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(250) NOT NULL,
    `body`       TEXT NULL,
    `image_path` VARCHAR(500) NULL,
    `image_url`  VARCHAR(700) NULL,
    `icon`       VARCHAR(20) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uniq_seed` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- خطوات العمل
CREATE TABLE IF NOT EXISTS `site_steps` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(200) NOT NULL,
    `body`       TEXT NULL,
    `icon`       VARCHAR(20) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uniq_seed` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الخدمات
CREATE TABLE IF NOT EXISTS `site_services` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(200) NOT NULL,
    `body`       TEXT NULL,
    `icon`       VARCHAR(20) NULL,
    `image_path` VARCHAR(500) NULL,
    `image_url`  VARCHAR(700) NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uniq_seed` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- معرض التصميمات (يدوي أو مستورد من المنصة)
CREATE TABLE IF NOT EXISTS `site_gallery` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `title`         VARCHAR(200) NULL,
    `image_path`    VARCHAR(500) NULL,
    `image_url`     VARCHAR(700) NULL,
    `source`        ENUM('upload','platform','link') NOT NULL DEFAULT 'upload',
    `platform_ref`  VARCHAR(80) NULL,
    `category`      VARCHAR(80) NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`    INT NOT NULL DEFAULT 0,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الباقات والعروض
CREATE TABLE IF NOT EXISTS `site_packages` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(150) NOT NULL,
    `price`       VARCHAR(80) NULL,
    `period`      VARCHAR(80) NULL,
    `description` VARCHAR(500) NULL,
    `features`    TEXT NULL,
    `badge`       VARCHAR(80) NULL,
    `cta_text`    VARCHAR(100) NULL,
    `cta_url`     VARCHAR(500) NULL,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uniq_seed` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إعلانات وعروض (بانرات)
CREATE TABLE IF NOT EXISTS `site_promos` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(250) NOT NULL,
    `body`       VARCHAR(600) NULL,
    `image_path` VARCHAR(500) NULL,
    `image_url`  VARCHAR(700) NULL,
    `link_url`   VARCHAR(500) NULL,
    `starts_at`  DATE NULL,
    `ends_at`    DATE NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فيديوهات الشرح
CREATE TABLE IF NOT EXISTS `site_videos` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `title`       VARCHAR(250) NOT NULL,
    `description` TEXT NULL,
    `video_url`   VARCHAR(700) NOT NULL,
    `thumb_path`  VARCHAR(500) NULL,
    `thumb_url`   VARCHAR(700) NULL,
    `category`    VARCHAR(80) NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- صفحات داخلية (ثابتة + HTML مخصص)
CREATE TABLE IF NOT EXISTS `site_pages` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `slug`         VARCHAR(120) NOT NULL UNIQUE,
    `title`        VARCHAR(250) NOT NULL,
    `subtitle`     VARCHAR(500) NULL,
    `content_html` MEDIUMTEXT NULL,
    `is_builtin`   TINYINT(1) NOT NULL DEFAULT 0,
    `show_in_menu` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`   INT NOT NULL DEFAULT 0,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════ البيانات الافتراضية ═══════════

INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('site_name','Spread AI'),
('logo_path',''),
('tagline','منصة المحتوى بالذكاء الاصطناعي'),
('hero_title','محتوى وتصميمات
بالذكاء الاصطناعي'),
('hero_subtitle','من الهوية للخطة للتصميم للنشر — كله من مكان واحد، وبأسلوب براندك انت.'),
('ribbon_text','SPREAD AI • محتوى بالذكاء الاصطناعي • تصميمات • خطط محتوى • نشر تلقائي •'),
('platform_login_url','/login.php'),
('platform_register_url','/register.php'),
('cta_title','جاهز نبدأ؟'),
('cta_body','اعمل حساب دلوقتي وجرّب أول منشور وتصميم مجانًا.'),
('cta_btn','ابدأ مجانًا'),
('footer_about','Spread AI — منصة مصرية لصناعة محتوى وتصميمات السوشيال ميديا بالذكاء الاصطناعي.'),
('footer_copyright','© Spread Agency — جميع الحقوق محفوظة'),
('contact_phone',''),
('contact_whatsapp',''),
('contact_email',''),
('contact_address',''),
('social_facebook',''),
('social_instagram',''),
('social_tiktok',''),
('social_linkedin',''),
('color_blue','#0F3CC9'),
('color_turquoise','#14B8A6'),
('color_sky','#8AD9F5'),
('color_ink','#0B0B0F'),
('color_beige','#F5F2EC');

INSERT IGNORE INTO `site_sections` (`section_key`,`label_ar`,`title`,`subtitle`,`sort_order`) VALUES
('hero','الهيدر والسلايدر',NULL,NULL,1),
('ribbon','الشريط المتحرك',NULL,NULL,2),
('brands','العلامات التجارية','بيثقوا فينا',NULL,3),
('promos','الإعلانات والعروض','عروض حالية',NULL,4),
('problems','المشاكل','إيه اللي بيوقفك؟','أكتر المشاكل اللي بتقابل أصحاب البيزنس في المحتوى',5),
('about','عن المنصة والحلول','إحنا بنحلها إزاي؟','منصة متكاملة بتشتغل بأسلوب براندك انت',6),
('steps','خطوات العمل','إزاي بتشتغل؟','ست خطوات من الفكرة للنشر',7),
('services','الخدمات','خدماتنا',NULL,8),
('gallery','التصميمات','شغل اتعمل بالمنصة','تصميمات حقيقية من عملائنا',9),
('pricing','الأسعار والعروض','الباقات','اختار اللي يناسب بيزنسك',10),
('cta','جاهز نبدأ',NULL,NULL,11);

INSERT IGNORE INTO `site_pages` (`slug`,`title`,`subtitle`,`is_builtin`,`sort_order`) VALUES
('about','احنا مين','قصة Spread AI ورسالتنا',1,1),
('services','الخدمات','كل اللي بنقدمه لبيزنسك',1,2),
('designs','التصميمات','شغل حقيقي اتعمل بالمنصة',1,3),
('tutorials','شرح المنصة','فيديوهات تشرح كل خطوة',1,4),
('pricing','الأسعار','باقات تناسب كل بيزنس',1,5);

INSERT IGNORE INTO `site_steps` (`title`,`body`,`icon`,`sort_order`) VALUES
('صناعة الهوية','بنعرّف الـ AI على براندك: الألوان، النبرة، الجمهور، والخدمات — عشان كل محتوى يطلع بأسلوبك.','◈',1),
('عمل خطة','خطة محتوى شهرية كاملة موزّعة على تقويم، متنوعة بين توعوي وإعلاني وتفاعلي.','🗓',2),
('إيجاد أفكار','الـ AI بيقترح أفكار منشورات مبنية على بيزنسك ومستنداتك الحقيقية.','💡',3),
('إنشاء محتوى','منشور كامل بالنص والهاشتاجات والـ CTA بلهجتك وأسلوبك.','✎',4),
('صناعة تصميم','تصميمات بألوان هويتك ولوجوك، بمقاسات السوشيال المختلفة.','🎨',5),
('النشر على السوشيال','ربط صفحاتك ونشر تلقائي في المواعيد اللي تحددها.','🚀',6);

INSERT IGNORE INTO `site_problems` (`title`,`body`,`icon`,`sort_order`) VALUES
('مفيش وقت للمحتوى','بتقضي ساعات كل أسبوع تفكر وتكتب وتصمم — ووقتك المفروض يكون في البيزنس نفسه.','⏳',1),
('المحتوى مش ثابت','كل مرة شكل وأسلوب مختلف، والعميل مش بيحس إن ده نفس البراند.','🎭',2),
('التصميم مكلّف','المصمم غالي والتعديلات بتاخد وقت، والنتيجة مش دايمًا زي ما في دماغك.','💸',3),
('مش عارف تكتب إيه','بتقعد قدام الشاشة ومش لاقي فكرة، والمنافس بينزل كل يوم.','🤔',4),
('النشر بينسى','الخطة موجودة بس محدش بينشر في معاده، والتفاعل بيقل.','📉',5),
('نتائج بدون قياس','بتنشر كتير من غير ما تعرف إيه اللي شغال وإيه اللي لأ.','📊',6);

INSERT IGNORE INTO `site_solutions` (`title`,`body`,`icon`,`sort_order`) VALUES
('هوية بصرية ثابتة','المنصة بتدرس لوجوك وصورك وتصميماتك وتستخرج ستايلك — وكل تصميم جديد بيلتزم بيه.','◈',1),
('محتوى في دقايق','بدل ساعات: تختار النوع والمنصة وتضغط زرار — والمحتوى جاهز بلهجتك.','⚡',2),
('تصميمات احترافية','بمقاسات 1:1 و4:5 و9:16 وغيرها، بألوانك ولوجوك، من غير مصمم.','🎨',3),
('نشر تلقائي','اربط صفحتك مرة واحدة، وحدد المواعيد — والباقي علينا.','🚀',4);

INSERT IGNORE INTO `site_services` (`title`,`body`,`icon`,`sort_order`) VALUES
('كتابة المحتوى','منشورات تعريفية وإعلانية وتوعوية وقصصية وتفاعلية — بلهجتك وأسلوب براندك.','✎',1),
('تصميم السوشيال','تصميمات بالذكاء الاصطناعي بألوان هويتك، وبكل المقاسات المطلوبة.','🎨',2),
('خطط المحتوى','خطة شهرية كاملة بأفكار متنوعة موزّعة على تقويم واضح.','🗓',3),
('الهوية البصرية','بناء هوية متكاملة: ألوان، نبرة، قواعد تصميم، وملخص ذكي.','◈',4),
('صناعة اللوجو','لوجو احترافي بالذكاء الاصطناعي بستايلات مختلفة.','✨',5),
('النشر والجدولة','ربط فيسبوك وانستجرام ونشر تلقائي في مواعيده.','📲',6);

INSERT IGNORE INTO `site_packages` (`name`,`price`,`period`,`description`,`features`,`badge`,`is_featured`,`sort_order`) VALUES
('البداية','٥٠٠','جنيه / شهر','مناسبة للبيزنس الصغير','٣٠ منشور بالذكاء الاصطناعي\n١٥ تصميم\nخطة محتوى شهرية\nدعم واتساب',NULL,0,1),
('الاحترافية','١٢٠٠','جنيه / شهر','الأكثر طلبًا للشركات','١٠٠ منشور\n٥٠ تصميم\nخطط غير محدودة\nنشر تلقائي على فيسبوك وانستجرام\nهوية بصرية كاملة\nدعم أولوية','الأكثر طلبًا',1,2),
('المتقدمة','٢٥٠٠','جنيه / شهر','للوكالات والبراندات الكبيرة','منشورات وتصميمات غير محدودة\nحسابات متعددة\nمساعد شخصي\nتقارير أداء\nدعم مخصص',NULL,0,3);

-- قسم «اصنع منشورك الآن» + صفحته الداخلية
INSERT IGNORE INTO `site_sections` (`section_key`,`label_ar`,`title`,`subtitle`,`sort_order`) VALUES
('trial','اصنع منشورك الآن','اصنع منشورك الآن','اكتب معلومتين عن بيزنسك، واستلم أفكار ومنشور حقيقي في أقل من دقيقة — من غير تسجيل.',3);

INSERT IGNORE INTO `site_pages` (`slug`,`title`,`subtitle`,`is_builtin`,`sort_order`) VALUES
('create-post','اصنع منشورك الآن','جرّب المنصة بنفسك — أفكار ومنشور حقيقي لبيزنسك في دقيقة',1,0);
