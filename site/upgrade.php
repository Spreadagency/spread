<?php
/**
 * Spread AI — ترقية قاعدة الموقع لـ «Website OS» (لوحة الإدارة الجديدة)
 *   كله إضافات بس (أعمدة وجداول جديدة) — مفيش حذف ولا تغيير أسماء.
 *   بيتشغّل تلقائيًا مرة واحدة (site_os_ready) وآمن للتكرار — ونسخة SQL منه في sql/site-os-upgrade.sql
 *
 *   ① site_admins: role · last_login_at          (الأدوار والصلاحيات — الأدمن الحاليين = Super Admin)
 *   ② site_pages: status · SEO · OG · canonical · noindex · صورة · blocks_json (Page Builder) · CTA
 *   ③ جداول جديدة: site_testimonials · site_faqs · site_media · site_activity_log · site_redirects · site_pageviews
 *   ④ أقسام الرئيسية الجديدة: testimonials · faq
 */
require_once __DIR__ . '/db.php';

const SITE_OS_VERSION = '3';

function s_col_exists(string $table, string $col): bool
{
    static $memo = [];
    if (isset($memo[$table . '.' . $col])) return true;
    $ok = (bool) s_one('SELECT 1 AS ok FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $col]);
    if ($ok) $memo[$table . '.' . $col] = true;
    return $ok;
}

function s_table_exists(string $table): bool
{
    return (bool) s_one('SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
}

/** إضافة عمود لو مش موجود */
function s_add_col(string $table, string $col, string $def): void
{
    if (s_table_exists($table) && !s_col_exists($table, $col)) {
        try { sdb()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}"); }
        catch (\Throwable $e) { error_log('[site-os] ' . $table . '.' . $col . ': ' . $e->getMessage()); }
    }
}

function s_site_os_tables(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS `site_testimonials` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(150) NOT NULL,
            `company`     VARCHAR(150) NULL,
            `role_title`  VARCHAR(150) NULL,
            `avatar_path` VARCHAR(500) NULL,
            `avatar_url`  VARCHAR(700) NULL,
            `content`     TEXT NOT NULL,
            `rating`      TINYINT NOT NULL DEFAULT 0,
            `video_url`   VARCHAR(700) NULL,
            `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order`  INT NOT NULL DEFAULT 0,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_t_active` (`is_active`, `sort_order`)
        ) {$t}",
        "CREATE TABLE IF NOT EXISTS `site_faqs` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `question`   VARCHAR(300) NOT NULL,
            `answer`     TEXT NOT NULL,
            `category`   VARCHAR(80) NULL,
            `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            KEY `idx_f_active` (`is_active`, `sort_order`)
        ) {$t}",
        "CREATE TABLE IF NOT EXISTS `site_media` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `path`          VARCHAR(500) NOT NULL,
            `original_name` VARCHAR(255) NULL,
            `mime`          VARCHAR(80) NULL,
            `size`          INT NOT NULL DEFAULT 0,
            `width`         INT NULL,
            `height`        INT NULL,
            `alt`           VARCHAR(255) NULL,
            `admin_id`      INT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_media_path` (`path`(190))
        ) {$t}",
        "CREATE TABLE IF NOT EXISTS `site_activity_log` (
            `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
            `admin_id`    INT NULL,
            `admin_name`  VARCHAR(150) NULL,
            `action`      VARCHAR(40) NOT NULL,
            `section`     VARCHAR(60) NOT NULL,
            `target_id`   INT NULL,
            `summary`     VARCHAR(500) NOT NULL,
            `ip`          VARCHAR(45) NULL,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_al_date` (`created_at`),
            KEY `idx_al_section` (`section`, `created_at`)
        ) {$t}",
        "CREATE TABLE IF NOT EXISTS `site_redirects` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `from_slug`  VARCHAR(120) NOT NULL UNIQUE,
            `to_slug`    VARCHAR(120) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) {$t}",
        "CREATE TABLE IF NOT EXISTS `site_pageviews` (
            `day`   DATE NOT NULL,
            `path`  VARCHAR(190) NOT NULL,
            `views` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`day`, `path`)
        ) {$t}",
    ];
}

function s_site_os_upgrade(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (s_setting('site_os_ready') === SITE_OS_VERSION) return;
    if (!s_table_exists('site_settings')) return; // الموقع لسه متركّبش

    foreach (s_site_os_tables() as $sql) {
        try { sdb()->exec($sql); } catch (\Throwable $e) { error_log('[site-os] ' . $e->getMessage()); }
    }

    // ① الأدوار — الأدمن الموجودين قبل كده بيفضلوا Super Admin (مفيش صلاحية بتتشال من حد)
    s_add_col('site_admins', 'role', "VARCHAR(30) NOT NULL DEFAULT 'super_admin'");
    s_add_col('site_admins', 'last_login_at', 'DATETIME NULL');

    // ② الصفحات
    $pageCols = [
        'status'          => "VARCHAR(12) NOT NULL DEFAULT 'published'",
        'seo_title'       => 'VARCHAR(255) NULL',
        'seo_description' => 'VARCHAR(500) NULL',
        'og_title'        => 'VARCHAR(255) NULL',
        'og_description'  => 'VARCHAR(500) NULL',
        'og_image'        => 'VARCHAR(700) NULL',
        'canonical_url'   => 'VARCHAR(700) NULL',
        'noindex'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'featured_image'  => 'VARCHAR(700) NULL',
        'blocks_json'     => 'MEDIUMTEXT NULL',
        'show_cta'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'published_at'    => 'DATETIME NULL',
        'created_at'      => 'DATETIME NULL',
    ];
    foreach ($pageCols as $c => $d) s_add_col('site_pages', $c, $d);
    // الحالة من is_active القديمة (الصفحات الموقوفة = مسودة)
    s_run("UPDATE site_pages SET status = 'draft' WHERE is_active = 0 AND status = 'published' AND published_at IS NULL AND created_at IS NULL");
    s_run('UPDATE site_pages SET created_at = updated_at WHERE created_at IS NULL');
    s_run("UPDATE site_pages SET published_at = updated_at WHERE published_at IS NULL AND status = 'published'");

    // ④ أقسام الرئيسية الجديدة — قبل «جاهز نبدأ»
    $ctaOrd = (int) (s_one("SELECT sort_order FROM site_sections WHERE section_key = 'cta'")['sort_order'] ?? 12);
    if (!s_one("SELECT id FROM site_sections WHERE section_key = 'testimonials'")) {
        s_run('UPDATE site_sections SET sort_order = sort_order + 2 WHERE sort_order >= ?', [$ctaOrd]);
        s_run("INSERT IGNORE INTO site_sections (section_key, label_ar, title, subtitle, is_visible, sort_order) VALUES
               ('testimonials', 'آراء العملاء', 'عملاء بيتكلموا عن Spread AI', 'تجارب حقيقية من أصحاب مشاريع بيعملوا محتواهم معانا.', 1, ?),
               ('faq', 'الأسئلة الشائعة', 'أسئلة بتتكرر', 'كل اللي محتاج تعرفه قبل ما تبدأ.', 1, ?)", [$ctaOrd, $ctaOrd + 1]);
    }

    s_set('site_os_ready', SITE_OS_VERSION);
}
