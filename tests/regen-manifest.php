<?php
/**
 * Spread AI — إعادة توليد بصمة الحزمة (includes/build-manifest.php) اللي بيستخدمها «فحص التحديث»
 *
 *   php tests/regen-manifest.php [ملف جديد …]
 *
 * - كل ملف في البصمة القديمة بيتحسب تاني (md5 + الحجم)، والملفات اللي اتمسحت بتتشال.
 * - الملفات اللي بتتكتب بعد الأمر بتتضاف (لازم تكون موجودة).
 * - «update» = القايمة القديمة + أي ملف بصمته اتغيرت أو اتضاف (عشان «فحص التحديث» يعرف إيه اللي في الحزمة).
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$path = $root . '/includes/build-manifest.php';
$old = is_file($path) ? (include $path) : [];
if (!is_array($old)) $old = [];
$oldFiles = (array) ($old['files'] ?? []);
$names = array_keys($oldFiles);
foreach (array_slice($argv, 1) as $f) {
    $f = ltrim(str_replace('\\', '/', $f), './');
    if (!is_file($root . '/' . $f)) { fwrite(STDERR, "مش موجود: $f\n"); exit(1); }
    $names[] = $f;
}
$names = array_values(array_unique($names));
sort($names);
$files = [];
$changed = [];
$missing = [];
foreach ($names as $f) {
    if ($f === 'includes/build-manifest.php') continue;
    if (!is_file($root . '/' . $f)) { $missing[] = $f; continue; }
    $h = md5_file($root . '/' . $f);
    $files[$f] = ['h' => $h, 's' => filesize($root . '/' . $f)];
    $prev = $oldFiles[$f] ?? null;
    if (!$prev || (is_array($prev) ? ($prev['h'] ?? '') : (string) $prev) !== $h) $changed[] = $f;
}
$update = array_values(array_unique(array_merge(array_filter((array) ($old['update'] ?? []), fn($f) => isset($files[$f])), $changed)));
sort($update);
$out = [
    'build' => (string) ($old['build'] ?? '1.0'),
    'date' => date('Y-m-d'),
    'generated' => date('Y-m-d H:i'),
    'update' => $update,
    'files' => $files,
];
file_put_contents($path, "<?php\n// ملف مولّد تلقائيًا مع الحزمة — ماتعدّلوش. بيستخدمه «فحص التحديث» (admin/ui-diagnose.php)\nreturn " . var_export($out, true) . ";\n");
echo 'ملفات: ' . count($files) . ' · اتغيّر/اتضاف: ' . count($changed) . ($changed ? "\n  " . implode("\n  ", $changed) : '') . ($missing ? "\nاتشال (مش موجود): " . implode(', ', $missing) : '') . "\n";
