<?php
/**
 * Spread AI v2 — تتبّع ترحيلات قاعدة البيانات
 *
 * بدل «شغّل 9 ملفات بالترتيب ومتنساش واحد»:
 * القائمة هنا مرتبة، والمنفّذ بيشغّل اللي ماتشغلش بس ويسجّله.
 * ملف جديد؟ ضيفه في آخر القائمة.
 */

if (!function_exists('db')) {
    require_once __DIR__ . '/db.php';
}

/** الترتيب مهم — ملفات المنصة بس (الموقع التعريفي ليه قاعدة بيانات منفصلة) */
function migrations_manifest(): array
{
    return [
        'schema.sql'                  => 'البنية الأساسية',
        'features-upgrade.sql'        => 'الميزات الإضافية',
        'security-upgrade.sql'        => 'تحسينات الأمان',
        'performance-upgrade.sql'     => 'تحسينات الأداء',
        'upgrade-all-phases.sql'      => 'مراحل التطوير 1–6',
        '2026_offers_system.sql'      => 'العروض والأفلييت',
        '2026_google_auth.sql'        => 'الدخول بجوجل',
        '2026_ai_request_logs.sql'    => 'سجل استدعاءات AI',
        '2026_ui_v2.sql'              => 'الواجهة الجديدة (المرحلة 0)',
        '2026_phase1_foundation.sql'  => 'الأساس التقني (المرحلة 1)',
        '2026_phase2_brand_brain.sql' => 'Brand Brain (المرحلة 2)',
        '2026_phase5_studio.sql'      => 'Design Studio (المرحلة ⑤)',
        '2026_phase5b_studio_admin.sql' => 'طرق الاستوديو والمقاسات من الأدمن (⑤-ب)',
        '2026_phase5c_trends.sql'       => 'Trend Studio + إدارة الترندات (⑤-ب)',
        '2026_phase5d_studio_edit.sql'  => 'نسخ التصميم في الاستوديو (⑤-ب)',
        '2026_phase6a_settings.sql'     => 'مركز الإعدادات: الجلسات · التحقق بخطوتين · حذف الحساب (⑥-أ)',
        '2026_phase6b_campaign_flow.sql' => 'الحملة كاملة: أفكار · محتوى · تقييم · تصميم · جدولة · نشر جماعي (⑥-ب)',
        '2026_phase7b_research.sql'      => 'البحث العميق: أبحاث بمصادر حقيقية · تقارير · Brand Brain (⑦-ب)',
        '2026_phase7c_formats.sql'       => 'أشكال المحتوى: منشور · كاروسيل (تصميم لكل شريحة) · فيديو (تنفيذ يدوي) · ستوري (⑦-ج)',
        '2026_phase8a_ai_gateway.sql'    => 'بوابة الـ AI بالبدائل · الاستهلاك والتكلفة · أسعار الموديلات وسعر الدولار · محاسبة الكريدت حسب المصدر (8-أ)',
        '2026_phase8b_quotas.sql'        => 'الحصص الشهرية للباقات · الاستهلاك بالنسبة % للعميل · ملف العميل 360 (8-ب)',
        '2026_phase8c_settings.sql'      => 'مركز الإعدادات · سجل تغييرات الإعدادات (مين غيّر إيه وإمتى) · التصدير والاستيراد (8-ج)',
        '2026_phase8c2_ai_fixes.sql'     => 'إصلاح: المحاولات اللي المزود رفضها (4xx) تكلفتها صفر + إعادة حساب العمليات المتأثرة (8-ج)',
        '2026_phase9_drafts_inspirations_slider.sql' => 'مشاريع الاستوديو اللي لسه مكملتش · تصميمات بتعجبك في Brand Brain · سلايدر إعلانات الرئيسية (9)',
    ];
}


/**
 * تقسيم ملف SQL لجمل منفصلة — بيحترم النصوص ('…' "…" `…`) والتعليقات
 * (-- و # والتعليقات المتعددة الأسطر). لازم عشان الاتصال مضبوط يمنع الجمل المتعددة (أمان).
 */
function sql_split(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $q = null;   // علامة التنصيص الحالية
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $nx = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($q !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $q !== '`') { $buf .= $nx; $i++; continue; }
            if ($ch === $q) {
                if ($nx === $q) { $buf .= $nx; $i++; continue; }   // '' جوه النص
                $q = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $q = $ch; $buf .= $ch; continue; }
        if (($ch === '-' && $nx === '-') || $ch === '#') {
            $e = strpos($sql, "\n", $i);
            $i = $e === false ? $len : $e;
            $buf .= "\n";
            continue;
        }
        if ($ch === '/' && $nx === '*') {
            $e = strpos($sql, '*/', $i + 2);
            $i = $e === false ? $len : $e + 1;
            continue;
        }
        if ($ch === ';') {
            if (trim($buf) !== '') $out[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

function migrations_dir(): string
{
    return dirname(__DIR__) . '/sql';
}

function migrations_table_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM schema_migrations LIMIT 1');
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function migrations_ensure_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `filename` VARCHAR(120) NOT NULL, `checksum` CHAR(40) NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `applied_by` VARCHAR(120) NULL,
        `duration_ms` INT NOT NULL DEFAULT 0, PRIMARY KEY (`filename`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** حالة كل ملف: مطبّق · معلّق · اتغيّر بعد التطبيق · مفقود */
function migrations_status(): array
{
    $applied = [];
    if (migrations_table_ready()) {
        foreach (db_all('SELECT * FROM schema_migrations') as $r) {
            $applied[$r['filename']] = $r;
        }
    }
    $out = [];
    foreach (migrations_manifest() as $file => $label) {
        $path = migrations_dir() . '/' . $file;
        $exists = is_file($path);
        $sum = $exists ? sha1_file($path) : null;
        $row = $applied[$file] ?? null;
        $state = !$exists ? 'missing' : (!$row ? 'pending' : ($row['checksum'] && $row['checksum'] !== $sum ? 'changed' : 'applied'));
        $out[] = [
            'file'       => $file,
            'label'      => $label,
            'state'      => $state,
            'applied_at' => $row['applied_at'] ?? null,
            'applied_by' => $row['applied_by'] ?? null,
            'ms'         => (int) ($row['duration_ms'] ?? 0),
        ];
    }
    return $out;
}

/**
 * تشغيل ملف SQL كامل (فيه SET/PREPARE/EXECUTE متعددة)
 * بيلف على كل نتيجة عشان أي خطأ في أي جملة يظهر — مش الأولى بس.
 */
function migration_run_file(string $file, string $by = 'admin'): array
{
    $path = migrations_dir() . '/' . basename($file);
    if (!is_file($path) || !array_key_exists(basename($file), migrations_manifest())) {
        return ['ok' => false, 'error' => 'الملف مش في القائمة المعتمدة'];
    }
    $statements = sql_split((string) file_get_contents($path));
    $t0 = microtime(true);
    $pdo = db();
    foreach ($statements as $n => $stmt) {
        try {
            // query مش exec: بعض الجمل (SELECT/EXECUTE) بترجّع نتيجة لازم تتقفل
            $res = $pdo->query($stmt);
            if ($res instanceof \PDOStatement) {
                try { $res->fetchAll(); } catch (\Throwable $ignore) { /* جمل مالهاش نتائج */ }
                $res->closeCursor();
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'الجملة ' . ($n + 1) . ' من ' . count($statements) . ': '
                . mb_substr($e->getMessage(), 0, 260)];
        }
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);
    migration_mark($file, $by, $ms);
    return ['ok' => true, 'ms' => $ms];
}

function migration_mark(string $file, string $by = 'admin', int $ms = 0): void
{
    migrations_ensure_table();
    $path = migrations_dir() . '/' . basename($file);
    db_run(
        'INSERT INTO schema_migrations (filename, checksum, applied_by, duration_ms) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = NOW(),
                                 applied_by = VALUES(applied_by), duration_ms = VALUES(duration_ms)',
        [basename($file), is_file($path) ? sha1_file($path) : null, mb_substr($by, 0, 120), $ms]
    );
}

/** تشغيل كل المعلّق بالترتيب — بيقف عند أول خطأ */
function migrations_run_pending(string $by = 'admin'): array
{
    // جدول التتبّع نفسه — مستقل عن أي ملف (ترتيب الملفات مهم: البنية الأساسية أولًا)
    migrations_ensure_table();
    $ran = [];
    foreach (migrations_status() as $m) {
        if ($m['state'] !== 'pending') continue;
        $r = migration_run_file($m['file'], $by);
        if (!$r['ok']) {
            return ['ok' => false, 'ran' => $ran, 'error' => $m['file'] . ': ' . $r['error']];
        }
        $ran[] = $m['file'];
    }
    return ['ok' => true, 'ran' => $ran];
}
