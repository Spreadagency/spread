<?php
/**
 * مزودين الـ AI (المرحلة 8-أ) — المفاتيح · القدرات · الموديلات · الأولوية · سياسة البدائل · اختبار الاتصال
 * المفاتيح بتتحفظ مشفّرة ومابتظهرش تاني بعد الحفظ.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/admin-ui.php';

require_admin();
require_admin_can('ai_settings');
$ready = usage_ready();

/** رابط API آمن: https إجباري (المفتاح بيتبعت في الهيدر) — http مسموح بس لـ localhost للتجارب */
$urlOk = function (string $u): bool {
    if (!preg_match('#^https?://[^\s]+$#i', $u)) return false;
    $p = parse_url($u);
    if (!$p || empty($p['host'])) return false;
    if (strtolower($p['scheme']) === 'https') return true;
    return in_array(strtolower($p['host']), ['127.0.0.1', 'localhost'], true);
};

$cleanList = function (string $v): array {
    $out = [];
    foreach (preg_split('/[\s,،]+/u', $v) as $m) {
        $m = trim($m);
        if ($m !== '' && preg_match('#^[A-Za-z0-9._:/@+-]{1,120}$#', $m)) $out[] = $m;
    }
    return array_values(array_unique($out));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    require_csrf();
    decode_b64_fields();
    $action = (string) ($_POST['action'] ?? '');
    $name = (string) ($_POST['provider'] ?? '');
    $row = $name !== '' ? db_one('SELECT * FROM ai_providers WHERE provider_name = ?', [$name]) : null;

    if ($action === 'save' && $row) {
        $base = trim((string) ($_POST['api_base_url'] ?? ''));
        if ($base !== '' && !$urlOk($base)) {
            flash_set('danger', 'رابط الـ API لازم يبدأ بـ https://');
            redirect('admin/ai-providers.php#p-' . $name);
        }
        $kind = in_array($_POST['kind'] ?? '', array_keys(ai_gw_kinds()), true) ? $_POST['kind'] : $row['kind'];
        $models = [
            'text' => $cleanList((string) ($_POST['models_text'] ?? '')),
            'image' => $cleanList((string) ($_POST['models_image'] ?? '')),
            'web' => $cleanList((string) ($_POST['models_web'] ?? '')),
        ];
        $status = !empty($_POST['status']) ? 'active' : 'disabled';
        $key = ai_gw_clean_key((string) ($_POST['api_key'] ?? ''));
        $willHaveKey = $key !== '' || !empty($row['api_key_encrypted']);
        if ($status === 'active' && !$willHaveKey) {
            $status = 'disabled';
            flash_set('warning', 'اتحفظ بس فضل متوقف — لازم API Key الأول');
        }
        db_run(
            'UPDATE ai_providers SET label = ?, kind = ?, api_base_url = ?, status = ?, priority = ?, supports_text = ?, supports_image = ?, supports_image_edit = ?,
                supports_web = ?, supports_vision = ?, allow_fallback = ?, allow_customer_data = ?, timeout_s = ?, models_json = ?, updated_at = NOW()
             WHERE provider_name = ?',
            [
                mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 80) ?: null, $kind,
                $base !== '' ? rtrim($base, '/') : (ai_gw_kinds()[$kind][1] ?? ''), $status,
                max(0, min(999, (int) ($_POST['priority'] ?? 100))),
                !empty($_POST['cap_text']) ? 1 : 0, !empty($_POST['cap_image']) ? 1 : 0, !empty($_POST['cap_image_edit']) ? 1 : 0,
                !empty($_POST['cap_web']) ? 1 : 0, !empty($_POST['cap_vision']) ? 1 : 0,
                !empty($_POST['allow_fallback']) ? 1 : 0, !empty($_POST['allow_customer_data']) ? 1 : 0,
                max(10, min(300, (int) ($_POST['timeout_s'] ?? 60))),
                json_encode(array_filter($models), JSON_UNESCAPED_SLASHES), $name,
            ]
        );
        if ($key !== '') {
            db_run('UPDATE ai_providers SET api_key_encrypted = ? WHERE provider_name = ?', [Crypto::encrypt($key), $name]);
        }
        // المفتاح اتغيّر → نفتح الـ breaker من جديد
        if ($key !== '') db_run('DELETE FROM ai_provider_state WHERE provider = ?', [$name]);
        admin_log('ai_provider_save', 'ai_providers', (int) $row['id'], json_encode(['provider' => $name, 'status' => $status, 'key_changed' => $key !== ''], JSON_UNESCAPED_UNICODE));
        ai_gw_cache_bust();
        if (empty($_SESSION['flash'])) flash_set('success', 'اتحفظ ✓' . ($key !== '' ? ' — المفتاح اتحدّث (اضغط «اختبار الاتصال»)' : ''));
        redirect('admin/ai-providers.php#p-' . $name);
    }

    if ($action === 'clear_key' && $row) {
        db_run('UPDATE ai_providers SET api_key_encrypted = NULL, status = "disabled" WHERE provider_name = ?', [$name]);
        admin_log('ai_provider_clear_key', 'ai_providers', (int) $row['id'], $name);
        flash_set('success', 'المفتاح اتمسح والمزود اتوقف');
        redirect('admin/ai-providers.php#p-' . $name);
    }

    if ($action === 'test' && $row) {
        $cap = in_array($_POST['cap'] ?? 'text', ['text', 'web'], true) ? $_POST['cap'] : 'text';
        [$ok, $msg] = ai_gw_test_provider($name, trim((string) ($_POST['model'] ?? '')) ?: null, $cap);
        admin_log('ai_provider_test', 'ai_providers', (int) $row['id'], $name . ' ' . ($ok ? 'ok' : 'fail'));
        flash_set($ok ? 'success' : 'danger', $row['provider_name'] . ': ' . $msg);
        redirect('admin/ai-providers.php#p-' . $name);
    }

    if ($action === 'reset_breaker' && $row) {
        db_run('DELETE FROM ai_provider_state WHERE provider = ?', [$name]);
        admin_log('ai_breaker_reset', 'ai_providers', (int) $row['id'], $name);
        flash_set('success', 'رجّعنا ' . $name . ' للسلسلة');
        redirect('admin/ai-providers.php#p-' . $name);
    }

    if ($action === 'add') {
        $slug = strtolower(trim((string) ($_POST['new_name'] ?? '')));
        $kind = in_array($_POST['new_kind'] ?? '', array_keys(ai_gw_kinds()), true) ? $_POST['new_kind'] : 'custom';
        $base = trim((string) ($_POST['new_base'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $slug)) {
            flash_set('danger', 'الاسم المختصر: حروف إنجليزي صغيرة وأرقام و - بس (2–30)');
        } elseif (db_one('SELECT id FROM ai_providers WHERE provider_name = ?', [$slug])) {
            flash_set('danger', 'فيه مزود بنفس الاسم');
        } elseif ($base === '' && $kind === 'custom') {
            flash_set('danger', 'المزود المتوافق مع OpenAI محتاج رابط API');
        } elseif ($base !== '' && !$urlOk($base)) {
            flash_set('danger', 'رابط الـ API لازم يبدأ بـ https://');
        } else {
            $d = ai_gw_kinds()[$kind];
            $id = db_insert(
                'INSERT INTO ai_providers (provider_name, kind, label, api_base_url, status, priority, supports_text, supports_web, supports_image, supports_image_edit, supports_vision, models_json)
                 VALUES (?,?,?,?, "disabled", ?, 1, ?, ?, ?, ?, ?)',
                [$slug, $kind, mb_substr(trim((string) ($_POST['new_label'] ?? '')), 0, 80) ?: $d[0], rtrim($base ?: $d[1], '/'),
                 (int) (db_one('SELECT COALESCE(MAX(priority),0) + 1 m FROM ai_providers')['m'] ?? 10),
                 in_array($kind, ['openrouter', 'openai', 'perplexity'], true) ? 1 : 0, in_array($kind, ['openrouter', 'openai'], true) ? 1 : 0,
                 in_array($kind, ['openrouter', 'openai'], true) ? 1 : 0, in_array($kind, ['openrouter', 'openai', 'gemini'], true) ? 1 : 0,
                 json_encode(array_filter(['text' => array_filter([$d[2]['text'] ?? '']), 'image' => array_filter([$d[2]['image'] ?? '']), 'web' => array_filter([$d[2]['web'] ?? ''])]), JSON_UNESCAPED_SLASHES)]
            );
            admin_log('ai_provider_add', 'ai_providers', $id, $slug);
            flash_set('success', 'اتضاف المزود «' . $slug . '» — حط المفتاح وفعّله');
            redirect('admin/ai-providers.php#p-' . $slug);
        }
        redirect('admin/ai-providers.php#add');
    }

    if ($action === 'delete' && $row) {
        $inUse = db_one('SELECT task FROM ai_task_routes WHERE chain_json LIKE ? LIMIT 1', ['%"p":"' . $name . '"%']);
        if (in_array($name, ['openrouter', 'openai', 'gemini', 'perplexity'], true)) {
            flash_set('danger', 'المزودين الأساسيين مابيتمسحوش — ممكن توقفهم');
        } elseif ($inUse) {
            flash_set('danger', 'المزود مستخدم في مسار «' . $inUse['task'] . '» — شيله من الـ Router الأول');
        } else {
            db_run('DELETE FROM ai_providers WHERE provider_name = ?', [$name]);
            db_run('DELETE FROM ai_provider_state WHERE provider = ?', [$name]);
            admin_log('ai_provider_delete', 'ai_providers', (int) $row['id'], $name);
            flash_set('success', 'اتمسح المزود');
        }
        redirect('admin/ai-providers.php');
    }
    redirect('admin/ai-providers.php');
}

$providers = $ready ? ai_gw_providers(true) : [];
$states = [];
foreach ($ready ? a2_rows('SELECT * FROM ai_provider_state') : [] as $s) $states[$s['provider']][$s['capability']] = $s;
$stats = [];
foreach ($ready ? a2_rows('SELECT provider, COUNT(*) n, SUM(status = "ok") ok, AVG(duration_ms) ms, SUM(cost_usd) c FROM ai_attempts WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY provider') : [] as $s) $stats[$s['provider']] = $s;
$gwOn = $ready && (string) get_setting('ai_gateway_enabled', '0') === '1';
$legacy = ai_effective_credentials();

function a2_rows(string $sql, array $p = []): array
{
    try { return db_all($sql, $p); } catch (\Throwable $e) { return []; }
}

$active = 'ai-providers';
$page_title = 'مزودين الـ AI';
include __DIR__ . '/../templates/admin-header.php';
$capLbl = ['text' => 'نص', 'image' => 'صور', 'web' => 'بحث ويب'];
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>

        <div class="page-head with-actions">
            <div>
                <h1>مزودين الـ AI</h1>
                <div class="sub">AI Providers · المفاتيح والقدرات والموديلات — البوابة بتحوّل بينهم تلقائيًا لو واحد وقع</div>
            </div>
            <a class="btn soft" href="<?= url('admin/ai-router.php') ?>"><?= admin_icon('cpu', 16) ?> الموديلات والـ Router</a>
        </div>

        <?php if (!$ready): ?>
            <div class="alert warning">لازم تشغّل ترحيل «8-أ» الأول من <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.</div>
        <?php else: ?>

        <div class="a2-note" style="margin-bottom:18px">
            <?php if ($gwOn): ?>
                ✅ <b>البوابة الموحدة شغالة</b> — كل طلبات المنصة بتعدّي على المزودين دول بالترتيب اللي في «الموديلات والـ Router»، ولو مزود وقع الطلب بيتحوّل للي بعده.
            <?php else: ?>
                ⏸ <b>البوابة لسه متقفلة</b> — المنصة شغالة بالمسار القديم (مفتاح واحد: <b><?= e($legacy['provider'] ?: '—') ?></b> من <?= e(['config.php' => 'config.php', 'admin_settings' => 'الإعداد القديم', 'ai_providers' => 'أول مزود نشط هنا', 'none' => 'مفيش'][$legacy['source']] ?? $legacy['source']) ?>) ومن غير بدائل.
                اضبط المزودين هنا واختبرهم، وبعدين فعّل البوابة من <a href="<?= url('admin/ai-router.php') ?>">الموديلات والـ Router</a>.
            <?php endif; ?>
            <br>🔒 المفاتيح بتتحفظ مشفّرة ومابتظهرش تاني. «بيانات العملاء» = مسموح يستقبل محتوى العملاء (البراند والبرومبتات) — لو قفلته المزود ده بيستخدم في اختبارات الأدمن بس.
        </div>

        <div class="a2-grid a2-g3">
        <?php foreach ($providers as $p):
            $pn = $p['name'];
            $st = $states[$pn] ?? [];
            $down = array_filter($st, fn($s) => in_array($s['state'], ['open', 'half'], true) && (empty($s['opened_until']) || strtotime($s['opened_until']) > time()));
            if ($p['status'] !== 'active') { $chip = ['chip-line', 'متوقف']; }
            elseif (!$p['has_key']) { $chip = ['chip-amber', 'مفيش مفتاح']; }
            elseif ($down) { $chip = ['chip-coral', 'متوقف مؤقتًا']; }
            elseif ((int) ($p['row']['last_test_ok'] ?? -1) === 0) { $chip = ['chip-amber', 'آخر اختبار فشل']; }
            else { $chip = ['chip-mint', 'Connected']; }
            $s7 = $stats[$pn] ?? null;
            $m = $p['models'];
        ?>
            <form method="post" class="card a2-prov" id="p-<?= e($pn) ?>" data-safe-post autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="provider" value="<?= e($pn) ?>">
                <div class="a2-prov-h">
                    <h3><?= e($p['label']) ?> <small class="a2-muted" style="font-size:12px;font-weight:500">#<?= (int) $p['priority'] ?> · <?= e($pn) ?></small></h3>
                    <span class="chip <?= $chip[0] ?>">● <?= e($chip[1]) ?></span>
                </div>
                <div class="a2-row" style="justify-content:space-between">
                    <span class="a2-lbl" style="margin:0">مفعّل</span>
                    <label class="a2-sw"><input type="checkbox" name="status" value="1" <?= $p['status'] === 'active' ? 'checked' : '' ?> aria-label="تفعيل <?= e($pn) ?>"><span></span></label>
                </div>
                <div>
                    <label class="a2-lbl" for="k-<?= e($pn) ?>">API Key</label>
                    <input class="input" id="k-<?= e($pn) ?>" type="password" name="api_key" autocomplete="new-password" placeholder="<?= $p['has_key'] ? 'محفوظ على السيرفر — اكتب مفتاح جديد لو هتغيّره' : 'حط المفتاح هنا' ?>" style="width:100%">
                    <?php if (!empty($p['key_broken'])): ?>
                        <div class="a2-note" style="margin-top:6px;color:#C2362E">⚠️ المفتاح المحفوظ هنا مابيتفكش (غالبًا ENCRYPTION_KEY في config.php اتغير) — اكتب المفتاح تاني واحفظ.</div>
                    <?php elseif (in_array($p['key_src'] ?? '', ['config.php', 'admin_settings'], true)): ?>
                        <div class="a2-muted" style="margin-top:6px;font-size:12px">🔑 المفتاح شغال من <?= $p['key_src'] === 'config.php' ? 'ملف config.php' : 'الإعداد القديم («ربط الذكاء الاصطناعي»)' ?> — احفظه هنا علشان تتحكم فيه من مكان واحد.</div>
                    <?php endif; ?>
                </div>
                <div class="a2-grid a2-g2" style="gap:10px">
                    <div><label class="a2-lbl">النوع</label>
                        <select class="input" name="kind" style="width:100%">
                            <?php foreach (ai_gw_kinds() as $kk => $kd): ?><option value="<?= $kk ?>" <?= $p['kind'] === $kk ? 'selected' : '' ?>><?= e($kd[0]) ?></option><?php endforeach; ?>
                        </select></div>
                    <div><label class="a2-lbl">الاسم الظاهر</label><input class="input" type="text" name="label" value="<?= e($p['label']) ?>" style="width:100%"></div>
                </div>
                <div><label class="a2-lbl">رابط الـ API (Base URL)</label><input class="input" type="url" name="api_base_url" value="<?= e($p['base']) ?>" dir="ltr" style="width:100%" data-no-encode="1"></div>
                <div>
                    <label class="a2-lbl">الموديلات (الأول = الافتراضي)</label>
                    <input class="input" type="text" name="models_text" value="<?= e(implode(', ', $m['text'] ?? [])) ?>" placeholder="نص: gpt-4o-mini, …" dir="ltr" style="width:100%;margin-bottom:6px" data-no-encode="1">
                    <input class="input" type="text" name="models_image" value="<?= e(implode(', ', $m['image'] ?? [])) ?>" placeholder="صور: gpt-image-1, …" dir="ltr" style="width:100%;margin-bottom:6px" data-no-encode="1">
                    <input class="input" type="text" name="models_web" value="<?= e(implode(', ', $m['web'] ?? [])) ?>" placeholder="بحث ويب: sonar, …" dir="ltr" style="width:100%" data-no-encode="1">
                </div>
                <div>
                    <span class="a2-lbl">القدرات</span>
                    <div class="a2-row" style="gap:12px;font-size:13px">
                        <?php foreach (['cap_text' => ['text', 'نص'], 'cap_vision' => ['vision', 'يشوف صور'], 'cap_image' => ['image', 'يولّد صور'], 'cap_image_edit' => ['image_edit', 'صور مرجعية'], 'cap_web' => ['web', 'بحث ويب']] as $cn => [$ck, $cl]): ?>
                            <label style="display:inline-flex;gap:5px;align-items:center"><input type="checkbox" name="<?= $cn ?>" value="1" <?= $p['caps'][$ck] ? 'checked' : '' ?>> <?= $cl ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="a2-grid a2-g2" style="gap:10px">
                    <div><label class="a2-lbl">Priority</label><input class="input" type="number" name="priority" min="0" max="999" value="<?= (int) $p['priority'] ?>" style="width:100%"></div>
                    <div><label class="a2-lbl">مهلة الطلب (ث)</label><input class="input" type="number" name="timeout_s" min="10" max="300" value="<?= (int) $p['timeout_s'] ?>" style="width:100%"></div>
                </div>
                <div class="a2-row" style="justify-content:space-between">
                    <span style="font-size:13px"><b>Fallback</b> — يستخدم كبديل</span>
                    <label class="a2-sw"><input type="checkbox" name="allow_fallback" value="1" <?= $p['allow_fallback'] ? 'checked' : '' ?>><span></span></label>
                </div>
                <div class="a2-row" style="justify-content:space-between">
                    <span style="font-size:13px"><b>بيانات العملاء</b> — مسموح يستقبلها</span>
                    <label class="a2-sw"><input type="checkbox" name="allow_customer_data" value="1" <?= $p['allow_customer_data'] ? 'checked' : '' ?>><span></span></label>
                </div>
                <?php if ($st): ?>
                <div class="a2-note" style="padding:8px 12px">
                    <?php foreach ($st as $cap => $s):
                        $isDown = in_array($s['state'], ['open', 'half'], true) && (empty($s['opened_until']) || strtotime($s['opened_until']) > time()); ?>
                        <div class="a2-kv" style="padding:4px 0"><span><?= e($capLbl[$cap] ?? $cap) ?></span>
                            <b class="a2-st <?= $isDown ? 'bad' : ((int) $s['fail_count'] > 0 ? 'warn' : '') ?>"><?= $isDown ? 'متوقف لحد ' . date('H:i', strtotime($s['opened_until'])) . ' (' . e($s['reason']) . ')' : ((int) $s['fail_count'] > 0 ? (int) $s['fail_count'] . ' أعطال مؤخرًا' : 'شغال') ?></b></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="a2-muted">
                    آخر اختبار: <?= $p['row']['last_test_at'] ? e(time_ago($p['row']['last_test_at'])) . ' — ' . e((string) $p['row']['last_test_msg']) : '—' ?>
                    <?php if ($s7): ?><br>آخر 7 أيام: <?= (int) $s7['n'] ?> محاولة · نجاح <?= $s7['n'] ? round($s7['ok'] / $s7['n'] * 100) : 0 ?>% · <?= a2n($s7['ms'] / 1000, 1) ?>ث · <?= a2usd($s7['c'], 3) ?><?php endif; ?>
                </div>
                <div class="a2-row">
                    <button class="btn sm" type="submit" name="action" value="save">حفظ</button>
                    <button class="btn sm soft" type="submit" name="action" value="test" <?= $p['has_key'] ? '' : 'disabled' ?>>Test Connection ▷</button>
                    <?php if ($p['caps']['web'] && in_array($p['kind'], ['openrouter', 'openai', 'perplexity'], true)): ?>
                        <button class="btn sm ghost" type="submit" name="action" value="test" onclick="this.form.cap.value='web'" <?= $p['has_key'] ? '' : 'disabled' ?>>اختبار البحث</button>
                    <?php endif; ?>
                    <input type="hidden" name="cap" value="text">
                    <?php if ($down): ?><button class="btn sm ghost" type="submit" name="action" value="reset_breaker">رجّعه للسلسلة</button><?php endif; ?>
                    <span class="a2-grow"></span>
                    <?php if ($p['has_key']): ?><button class="btn sm danger" type="submit" name="action" value="clear_key" onclick="return confirm('تمسح مفتاح <?= e($pn) ?>؟ المزود هيتوقف.')">مسح المفتاح</button><?php endif; ?>
                    <?php if (!in_array($pn, ['openrouter', 'openai', 'gemini', 'perplexity'], true)): ?><button class="btn sm danger" type="submit" name="action" value="delete" onclick="return confirm('تمسح المزود <?= e($pn) ?> نهائيًا؟')">حذف</button><?php endif; ?>
                </div>
            </form>
        <?php endforeach; ?>

            <form method="post" class="card a2-prov" id="add" data-safe-post style="border-style:dashed">
                <?= csrf_field() ?><input type="hidden" name="action" value="add">
                <div class="a2-prov-h"><h3>+ إضافة مزود</h3></div>
                <p class="a2-muted" style="margin:0">أي مزود متوافق مع OpenAI (DeepSeek · Groq · Together · Mistral · Azure OpenAI …) أو نسخة تانية من مزود معروف بمفتاح مختلف.</p>
                <div><label class="a2-lbl">الاسم المختصر (إنجليزي)</label><input class="input" type="text" name="new_name" placeholder="deepseek" dir="ltr" style="width:100%" data-no-encode="1" required pattern="[a-z0-9_-]{2,30}"></div>
                <div><label class="a2-lbl">الاسم الظاهر</label><input class="input" type="text" name="new_label" placeholder="DeepSeek" style="width:100%"></div>
                <div><label class="a2-lbl">النوع</label>
                    <select class="input" name="new_kind" style="width:100%">
                        <?php foreach (ai_gw_kinds() as $kk => $kd): ?><option value="<?= $kk ?>" <?= $kk === 'custom' ? 'selected' : '' ?>><?= e($kd[0]) ?></option><?php endforeach; ?>
                    </select></div>
                <div><label class="a2-lbl">رابط الـ API</label><input class="input" type="url" name="new_base" placeholder="https://api.deepseek.com/v1" dir="ltr" style="width:100%" data-no-encode="1"></div>
                <div><button class="btn" type="submit">إضافة</button></div>
            </form>
        </div>

        <p class="a2-muted" style="margin-top:14px">الإعداد القديم (مفتاح واحد) لسه موجود كاحتياطي لما البوابة تكون متقفلة: <a href="<?= url('admin/ai-connection.php') ?>">ربط الـ AI (قديم)</a> · <a href="<?= url('admin/ai-management.php') ?>">Smart Router (قديم)</a></p>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
