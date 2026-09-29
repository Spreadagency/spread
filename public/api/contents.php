<?php
/**
 * API المحتوى
 *   GET  ?action=list[&campaign_id=][&status=][&limit=]
 *   GET  ?action=versions&id=
 *   POST action=flag      {id, flag: none|needs_review|needs_design|ready_to_publish, note?}
 *   POST action=attach    {id, campaign_id}      ربط منشور موجود بحملة
 *   POST action=restore   {id, version_id}       استرجاع نسخة
 *
 *   ── مكتبة المحتوى (الشكل الجديد) ──
 *   GET  ?action=library[&platform=][&type=][&campaign=][&status=][&needs=1][&q=][&page=]
 *   GET  ?action=get&id=                            تفاصيل منشور للوحة الجانبية
 *   POST action=save        {id, rev, text?, hashtags?, cta?}   حفظ + نسخة جديدة
 *   POST action=use_design  {id, design_id}         اختيار التصميم كغلاف
 *   POST action=ai_edit     {id, instruction}       تعديل بالكلام — بيرجّع اقتراح بس (مابيحفظش)
 *                                                   الاعتماد = save بـ source=ai_edit
 *
 *   ── أشكال المحتوى (⑦-ج) ──
 *   POST action=slides_save   {id, slides[]}             نصوص شرائح الكاروسيل
 *   POST action=video_save    {id, brief{type,duration,platform,ratio,notes,script{hook,body,cta,shoot}}}
 *   POST action=video_approve {id}                       العميل اعتمد السكريبت
 *   POST action=video_request {id, notes}                «تواصل معنا لتنفيذ الفيديو» ← رابط واتساب جاهز + الحالة «اتبعت للتنفيذ»
 *   POST action=video_received {id}                      العميل استلم الفيديو
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/content-formats.php';   // ⑦-ج

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'list';

/* ═══════════ مكتبة المحتوى ═══════════ */

function lib_platform_label(?string $p): string
{
    return ['facebook' => 'فيسبوك', 'instagram' => 'إنستجرام', 'both' => 'فيسبوك وإنستجرام'][$p ?? ''] ?? 'بدون منصة';
}

/** الخطوة الجاية لكل حالة: [نص الزرار, نوعها, رابط] */
function lib_next_action(string $st, int $id, ?string $postId = null): array
{
    $view = url('content-view.php?id=' . $id);
    switch ($st) {
        case 'draft':            return ['اكتب المحتوى', 'link', $view];
        case 'content_ready':
        case 'needs_design':     return ['صمّم له', 'link', $view . '#design'];
        case 'needs_review':     return ['راجع المنشور', 'panel', ''];
        case 'design_ready':     return ['جدوله', 'link', $view . '#schedule'];
        case 'scheduled':        return ['غيّر الموعد', 'link', $view . '#schedule'];
        case 'ready_to_publish': return ['انشره', 'link', $view . '#schedule'];
        case 'publish_failed':   return ['أعد المحاولة', 'link', $view . '#schedule'];
        case 'published':        return $postId ? ['شوفه على فيسبوك', 'external', 'https://www.facebook.com/' . rawurlencode($postId)] : ['تم النشر ✓', 'none', ''];
    }
    return ['فتح', 'link', $view];
}

/** «ناقص إيه» بكلام بسيط */
function lib_missing(string $st, ?string $note): string
{
    return [
        'draft'          => 'ناقص: المحتوى',
        'content_ready'  => 'ناقص: التصميم',
        'needs_design'   => 'ناقص: التصميم',
        'needs_review'   => $note ? ('ملاحظة: ' . $note) : 'محتاج مراجعة قبل التصميم',
        'design_ready'   => 'ناقص: الجدولة',
    ][$st] ?? '';
}

/** أول سطر = الـ hook */
function lib_hook(string $text): string
{
    $t = trim(preg_replace('/[ \t]+/u', ' ', $text));
    $first = trim(strtok($t, "\n") ?: '');
    if (mb_strlen($first) < 12 && mb_strlen($t) > mb_strlen($first)) {
        $first = mb_substr(preg_replace('/\s+/u', ' ', $t), 0, 90);
    }
    return mb_strlen($first) > 90 ? mb_substr($first, 0, 88) . '…' : $first;
}

function lib_cover_url(?string $path): ?string
{
    if (!$path) return null;
    return function_exists('upload_url') ? upload_url($path) : APP_URL . '/storage/' . ltrim($path, '/');
}

function lib_row(array $r): array
{
    $meta = content_status_meta($r['st']);
    [$lbl, $kind, $href] = lib_next_action($r['st'], (int) $r['id'], $r['publish_post_id'] ?? null);
    if (($r['format'] ?? '') === 'video' && $r['st'] === 'content_ready') [$lbl, $kind, $href] = ['تابع الفيديو 🎬', 'panel', ''];
    $fmt = content_format_key($r['format'] ?? 'post');
    return [
        'id'        => (int) $r['id'],
        'format'    => $fmt, 'format_label' => content_format_label($fmt), 'format_emoji' => content_formats()[$fmt]['emoji'],
        'slides_count' => $fmt === 'carousel' ? (int) ($r['slides_count'] ?? 0) : null,
        'video_status' => $fmt === 'video' ? video_status_meta($r['video_status'] ?? null) : null,
        'type'      => $r['content_type'],
        'type_label'=> function_exists('content_type_label') ? content_type_label((string) $r['content_type']) : (string) $r['content_type'],
        'platform'  => lib_platform_label($r['platform'] ?? null),
        'hook'      => lib_hook((string) $r['generated_text']),
        'status'    => $meta,
        'pct'       => (int) $meta['pct'],
        'missing'   => $fmt === 'video' && $r['st'] === 'content_ready' ? '🎬 ' . video_status_meta($r['video_status'] ?? null)['label'] : lib_missing($r['st'], $r['workflow_note'] ?? null),
        'next'      => ['label' => $lbl, 'kind' => $kind, 'url' => $href],
        'scheduled' => $r['scheduled_at'] && in_array($r['st'], ['scheduled'], true) ? date('Y/m/d H:i', strtotime($r['scheduled_at'])) : null,
        'fail'      => $r['st'] === 'publish_failed' ? mb_substr((string) ($r['publish_error'] ?: 'فيسبوك رفض النشر'), 0, 160) : null,
        'campaign'  => $r['campaign_id'] ? ['id' => (int) $r['campaign_id'], 'title' => (string) $r['campaign_title']] : null,
        'cover'     => lib_cover_url($r['cover']),
        'ago'       => function_exists('ui_time_ago') ? ui_time_ago($r['created_at']) : $r['created_at'],
    ];
}

switch ($action) {

    case 'list':
        $w = ['c.user_id = ?'];
        $p = [$uid];
        $cid = api_int('campaign_id');
        if ($cid > 0) {
            api_own('campaigns', $cid, $uid);
            $w[] = 'c.campaign_id = ?';
            $p[] = $cid;
        }
        $status = api_str('status', 30);
        $stSql = content_status_sql('c');
        $having = '';
        if ($status !== '' && isset(content_statuses()[$status])) {
            $having = ' HAVING st = ' . db()->quote($status);
        }
        $limit = api_int('limit', 50, 1, 200);

        $rows = db_all(
            "SELECT c.id, c.campaign_id, c.content_type, c.platform, c.generated_text, c.hashtags, c.cta,
                    c.publish_status, c.scheduled_at, c.published_at, c.workflow_flag, c.workflow_note,
                    c.created_at, c.updated_at, {$stSql} AS st,
                    (SELECT image_path FROM content_designs d WHERE d.content_id = c.id ORDER BY d.id DESC LIMIT 1) AS design
             FROM contents c WHERE " . implode(' AND ', $w) . $having . "
             ORDER BY c.updated_at DESC LIMIT {$limit}", $p
        );

        $items = array_map(function ($r) {
            $txt = preg_replace('/\s+/u', ' ', (string) $r['generated_text']);
            return [
                'id'          => (int) $r['id'],
                'campaign_id' => $r['campaign_id'] ? (int) $r['campaign_id'] : null,
                'type'        => $r['content_type'],
                'platform'    => $r['platform'],
                'excerpt'     => mb_substr($txt, 0, 140),
                'status'      => content_status_meta($r['st']),
                'note'        => $r['workflow_note'],
                'design'      => $r['design'] ? url($r['design']) : null,
                'scheduled_at'=> $r['scheduled_at'],
                'updated_at'  => $r['updated_at'],
            ];
        }, $rows);
        api_ok(['items' => $items]);

    case 'versions':
        $c = api_own('contents', api_int('id'), $uid);
        $v = db_all('SELECT id, version_number, content_text, hashtags, cta, version_type, notes, created_at
                     FROM content_versions WHERE content_id = ? ORDER BY version_number DESC LIMIT 50', [$c['id']]);
        $types = ['ai' => 'من الـ AI', 'edited' => 'تعديل يدوي', 'ai_edit' => 'تعديل بالكلام', 'restore' => 'استرجاع'];
        api_ok(['versions' => array_map(fn($r) => [
            'id'      => (int) $r['id'],
            'n'       => (int) $r['version_number'],
            'text'    => $r['content_text'],
            'hashtags'=> $r['hashtags'],
            'cta'     => $r['cta'],
            'type'    => $types[$r['version_type']] ?? $r['version_type'],
            'note'    => $r['notes'],
            'at'      => $r['created_at'],
        ], $v)]);

    case 'flag':
        $c = api_own('contents', api_int('id'), $uid);
        $flag = api_str('flag', 30);
        if (!content_set_flag((int) $c['id'], $uid, $flag, api_str('note', 255) ?: null)) {
            api_fail('حالة غير صحيحة', 'bad_flag', 422);
        }
        $row = db_one('SELECT * FROM contents WHERE id = ?', [$c['id']]);
        api_ok(['status' => content_status_meta(content_status($row))]);

    case 'attach':
        $c = api_own('contents', api_int('id'), $uid);
        $cid = api_int('campaign_id');
        if ($cid > 0) {
            api_own('campaigns', $cid, $uid);
        }
        db_run('UPDATE contents SET campaign_id = ? WHERE id = ? AND user_id = ?', [$cid ?: null, $c['id'], $uid]);
        api_ok(['id' => (int) $c['id'], 'campaign_id' => $cid ?: null]);

    case 'restore':
        $c = api_own('contents', api_int('id'), $uid);
        $v = db_one('SELECT * FROM content_versions WHERE id = ? AND content_id = ?', [api_int('version_id'), $c['id']]);
        if (!$v) {
            api_fail('النسخة دي مش موجودة', 'not_found', 404);
        }
        db_run('UPDATE contents SET generated_text = ?, hashtags = ?, cta = ?, status = "edited" WHERE id = ?',
            [$v['content_text'], $v['hashtags'], $v['cta'], $c['id']]);
        save_content_version((int) $c['id'], (string) $v['content_text'], $v['hashtags'], $v['cta'],
            'restore', 'استرجاع النسخة ' . (int) $v['version_number']);
        api_ok(['restored' => (int) $v['version_number']]);

    /* ── المكتبة ── */
    case 'library':
        require_once __DIR__ . '/../../includes/uploader.php';
        if (is_file(__DIR__ . '/../../includes/ui-v2.php')) require_once __DIR__ . '/../../includes/ui-v2.php';
        $stSql = content_status_sql('c');
        $w = ['c.user_id = ?'];
        $p = [$uid];
        $plat = api_str('platform', 20);
        if (in_array($plat, ['facebook', 'instagram', 'both'], true)) { $w[] = 'c.platform = ?'; $p[] = $plat; }
        $type = api_str('type', 40);
        if ($type !== '') { $w[] = 'c.content_type = ?'; $p[] = $type; }
        $camp = api_str('campaign', 12);
        if ($camp === 'none') { $w[] = 'c.campaign_id IS NULL'; }
        elseif (ctype_digit($camp) && (int) $camp > 0) { $w[] = 'c.campaign_id = ?'; $p[] = (int) $camp; }
        $q = api_str('q', 100);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = '(c.generated_text LIKE ? OR c.hashtags LIKE ? OR c.cta LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        $baseWhere = implode(' AND ', $w);
        $baseParams = $p;

        // عدّادات الحالات بنفس الفلاتر (من غير فلتر الحالة نفسه) — عشان الشرايح تبين الأرقام الصح
        $counts = array_fill_keys(array_keys(content_statuses()), 0);
        foreach (db_all("SELECT {$stSql} st, COUNT(*) n FROM contents c WHERE {$baseWhere} GROUP BY st", $baseParams) as $r) {
            $counts[$r['st']] = (int) $r['n'];
        }
        $needSet = ['publish_failed', 'needs_review', 'needs_design', 'content_ready'];
        $actionCount = array_sum(array_intersect_key($counts, array_flip($needSet)));

        $status = api_str('status', 30);
        $having = [];
        if (api_int('needs') === 1) {
            $having[] = 'st IN ("' . implode('","', $needSet) . '")';
        } elseif ($status !== '' && isset(content_statuses()[$status])) {
            $having[] = 'st = ' . db()->quote($status);
        }
        $havingSql = $having ? ' HAVING ' . implode(' AND ', $having) : '';

        $per = 24;
        $page = api_int('page', 1, 1, 500);
        $total = (int) (db_one("SELECT COUNT(*) n FROM (SELECT {$stSql} st FROM contents c WHERE {$baseWhere}{$havingSql}) x", $baseParams)['n'] ?? 0);
        $rows = db_all(
            "SELECT c.id, c.content_type, c.platform, c.generated_text, c.workflow_note, c.scheduled_at, c.publish_error,"
                    . (formats_ready() ? ' c.format, c.slides_count, c.video_status,' : '') . "
                    c.publish_post_id, c.campaign_id, c.created_at, cp.title campaign_title, {$stSql} st,
                    COALESCE(
                        (SELECT d.image_path FROM content_designs d WHERE d.id = c.selected_image_id AND d.content_id = c.id),
                        (SELECT d.image_path FROM content_designs d WHERE d.content_id = c.id ORDER BY d.id DESC LIMIT 1)
                    ) cover
             FROM contents c LEFT JOIN campaigns cp ON cp.id = c.campaign_id AND cp.user_id = c.user_id
             WHERE {$baseWhere}{$havingSql}
             ORDER BY c.created_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $baseParams
        );

        $out = ['items' => array_map('lib_row', $rows), 'total' => $total, 'page' => $page,
                'more' => $page * $per < $total, 'counts' => $counts, 'actionCount' => $actionCount];
        if (api_int('meta') === 1) {
            $out['campaigns'] = array_map(fn($c) => ['id' => (int) $c['id'], 'title' => $c['title']],
                db_all('SELECT id, title FROM campaigns WHERE user_id = ? AND status <> "archived" ORDER BY updated_at DESC LIMIT 60', [$uid]));
            $out['types'] = array_map(fn($t) => ['key' => $t, 'label' => function_exists('content_type_label') ? content_type_label($t) : $t],
                array_column(db_all('SELECT DISTINCT content_type FROM contents WHERE user_id = ? AND content_type IS NOT NULL', [$uid]), 'content_type'));
            $out['statuses'] = array_map('content_status_meta', array_keys(content_statuses()));
        }
        api_ok($out);

    /* ── تفاصيل منشور ── */
    case 'get':
        require_once __DIR__ . '/../../includes/uploader.php';
        if (is_file(__DIR__ . '/../../includes/ui-v2.php')) require_once __DIR__ . '/../../includes/ui-v2.php';
        $c = api_own('contents', api_int('id'), $uid);
        $row = db_one('SELECT c.*, cp.title campaign_title, ' . content_status_sql('c') . ' st,
                       (SELECT d.image_path FROM content_designs d WHERE d.id = c.selected_image_id AND d.content_id = c.id) sel_cover,
                       (SELECT d.image_path FROM content_designs d WHERE d.content_id = c.id ORDER BY d.id DESC LIMIT 1) last_cover
                       FROM contents c LEFT JOIN campaigns cp ON cp.id = c.campaign_id AND cp.user_id = c.user_id
                       WHERE c.id = ?', [$c['id']]);
        $row['cover'] = $row['sel_cover'] ?: $row['last_cover'];
        $base = lib_row($row);
        $designs = db_all('SELECT id, image_path, created_at, ratio FROM content_designs WHERE content_id = ? ORDER BY id DESC LIMIT 20', [$c['id']]);
        $selected = (int) ($row['selected_image_id'] ?: ($designs[0]['id'] ?? 0));
        $versions = db_all('SELECT id, version_number, version_type, notes, created_at FROM content_versions
                            WHERE content_id = ? ORDER BY version_number DESC LIMIT 30', [$c['id']]);
        $vtypes = ['ai' => 'من الـ AI', 'edited' => 'تعديل يدوي', 'ai_edit' => 'تعديل بالكلام', 'restore' => 'استرجاع'];
        $brand = db_one('SELECT business_name, logo_path FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: [];
        api_ok(['content' => $base + [
            'text'     => (string) $row['generated_text'],
            'hashtags' => (string) ($row['hashtags'] ?? ''),
            'cta'      => (string) ($row['cta'] ?? ''),
            'rev'      => (string) $row['updated_at'],
            'published'=> $row['st'] === 'published',
            'date'     => date('Y/m/d', strtotime($row['created_at'])),
            'costs'    => ['ai_edit' => cost_for('ai_edit_cost'), 'design' => cost_for('content_design_cost'),
                           'balance' => credits_balance($uid)],
            'designs'  => array_map(fn($d) => ['id' => (int) $d['id'], 'url' => lib_cover_url($d['image_path']), 'ratio' => (string) ($d['ratio'] ?? ''),
                                               'ago' => function_exists('ui_time_ago') ? ui_time_ago($d['created_at']) : '',
                                               'current' => (int) $d['id'] === $selected], $designs),
            'versions' => array_map(fn($v) => ['id' => (int) $v['id'], 'n' => (int) $v['version_number'],
                                                'type' => $vtypes[$v['version_type']] ?? $v['version_type'],
                                                'note' => (string) ($v['notes'] ?? ''),
                                                'ago' => function_exists('ui_time_ago') ? ui_time_ago($v['created_at']) : ''], $versions),
            'brand'    => ['name' => (string) ($brand['business_name'] ?? ''), 'logo' => lib_cover_url($brand['logo_path'] ?? null)],
        ] + content_format_api($row) + ['carousel_limits' => carousel_limits(), 'video_options' => video_brief_options()]]);

    /* ── حفظ التعديل + نسخة ── */
    case 'save':
        $c = api_own('contents', api_int('id'), $uid);
        $rev = api_str('rev', 30);
        if ($rev !== '' && $rev !== (string) $c['updated_at']) {
            api_fail('المنشور اتعدّل من مكان تاني — حدّث وجرّب تاني', 'conflict', 409);
        }
        $in = api_input();
        $text = array_key_exists('text', $in) ? trim((string) $in['text']) : (string) $c['generated_text'];
        $tags = array_key_exists('hashtags', $in) ? trim(mb_substr((string) $in['hashtags'], 0, 1000)) : (string) ($c['hashtags'] ?? '');
        $cta  = array_key_exists('cta', $in) ? trim(mb_substr((string) $in['cta'], 0, 500)) : (string) ($c['cta'] ?? '');
        if ($text === '') api_fail('النص مايبقاش فاضي', 'empty', 422);
        if (mb_strlen($text) > 20000) api_fail('النص طويل جدًا', 'too_long', 422);
        if ($text === (string) $c['generated_text'] && $tags === (string) ($c['hashtags'] ?? '') && $cta === (string) ($c['cta'] ?? '')) {
            api_ok(['unchanged' => true]);
        }
        db_run('UPDATE contents SET generated_text = ?, hashtags = ?, cta = ?, status = "edited" WHERE id = ? AND user_id = ?',
            [$text, $tags ?: null, $cta ?: null, $c['id'], $uid]);
        $isAi = api_str('source', 20) === 'ai_edit';
        $note = $isAi ? ('بالكلام: ' . mb_substr(api_str('note', 200), 0, 200)) : 'تعديل من مكتبة المحتوى';
        save_content_version((int) $c['id'], $text, $tags ?: null, $cta ?: null, $isAi ? 'ai_edit' : 'edited', $note);
        $fresh = db_one('SELECT updated_at FROM contents WHERE id = ?', [$c['id']]);
        api_ok(['saved' => true, 'rev' => (string) $fresh['updated_at']]);

    /* ══════════ أشكال المحتوى (⑦-ج) ══════════ */
    case 'slides_save':
        $c = api_own('contents', api_int('id'), $uid);
        if (content_format_key($c['format'] ?? '') !== 'carousel') api_fail('ده مش كاروسيل', 'invalid', 422);
        if (in_array((string) $c['publish_status'], ['published', 'scheduled', 'processing'], true)) api_fail('الكاروسيل اتسلّم للنشر', 'locked', 409);
        $sl = content_slides_clean(api_param('slides', []), (int) $c['slides_count']);
        db_run('UPDATE contents SET slides_json = ?, status = "edited" WHERE id = ? AND user_id = ?', [json_encode($sl, JSON_UNESCAPED_UNICODE), $c['id'], $uid]);
        api_ok(['saved' => true]);

    case 'video_save':
    case 'video_approve':
    case 'video_request':
    case 'video_received':
        $c = api_own('contents', api_int('id'), $uid);
        if (content_format_key($c['format'] ?? '') !== 'video') api_fail('ده مش فيديو', 'invalid', 422);
        $st = (string) ($c['video_status'] ?: 'script');
        $sent = in_array($st, ['sent', 'in_production', 'ready', 'delivered'], true);
        if ($action === 'video_save') {
            if ($sent) api_fail('الطلب اتبعت للتنفيذ — أي تعديل ابعته لفريقنا على واتساب', 'locked', 409);
            $in = api_param('brief', []);
            $old = video_brief($c);
            $b = video_brief_clean(is_array($in) ? $in : [], $old);
            // السكريبت اتغيّر بعد الاعتماد = يتعتمد تاني
            $newSt = $st === 'approved' && $b['script'] !== $old['script'] ? 'script' : $st;
            db_run('UPDATE contents SET video_brief_json = ?, video_status = ? WHERE id = ? AND user_id = ?', [json_encode($b, JSON_UNESCAPED_UNICODE), $newSt, $c['id'], $uid]);
            if ($c['campaign_id']) {
                db_run('UPDATE campaign_ideas SET script_json = ? WHERE content_id = ? AND user_id = ?', [json_encode(['hook' => $b['script']['hook'], 'body' => $b['script']['body'],
                    'cta' => $b['script']['cta'], 'tags' => (string) $c['hashtags'], 'design' => $b['script']['shoot']], JSON_UNESCAPED_UNICODE), $c['id'], $uid]);
            }
            api_ok(['saved' => true, 'video' => content_format_api(db_one('SELECT * FROM contents WHERE id = ?', [$c['id']]))['video']]);
        }
        if ($action === 'video_approve') {
            if (trim(video_brief($c)['script']['body']) === '') api_fail('السكريبت فاضي — اكتبه الأول', 'empty', 422);
            if (!$sent) video_set_status((int) $c['id'], 'approved');
        } elseif ($action === 'video_request') {
            if (!in_array($st, ['approved', 'sent'], true)) api_fail('اعتمد السكريبت الأول', 'state', 409);
            if (video_whatsapp_number() === '') api_fail('رقم واتساب التنفيذ مش مضبوط — كلّم الإدارة', 'no_whatsapp', 503);
            if (!rate_limit('video_request', 'u' . $uid, 10, 3600)) api_fail('طلبات كتير — استنى شوية', 'rate_limit', 429);
            $notes = api_str('notes', 1000);
            if ($notes !== '') {
                $b = video_brief($c); $b['notes'] = $notes;
                db_run('UPDATE contents SET video_brief_json = ? WHERE id = ?', [json_encode($b, JSON_UNESCAPED_UNICODE), $c['id']]);
            }
            if ($st === 'approved') video_set_status((int) $c['id'], 'sent');
            $fresh = db_one('SELECT * FROM contents WHERE id = ?', [$c['id']]);
            require_once __DIR__ . '/../../includes/brand-brain.php';
            api_ok(['wa' => video_whatsapp_link($user, brand_for_user($uid), $fresh), 'video' => content_format_api($fresh)['video']]);
        } else {
            if ($st !== 'ready') api_fail('الفيديو لسه مااتسلمش من الفريق', 'state', 409);
            video_set_status((int) $c['id'], 'delivered');
        }
        api_ok(['video' => content_format_api(db_one('SELECT * FROM contents WHERE id = ?', [$c['id']]))['video']]);

    /* ── اختيار التصميم كغلاف ── */
    case 'use_design':
        $c = api_own('contents', api_int('id'), $uid);
        $d = db_one('SELECT id FROM content_designs WHERE id = ? AND content_id = ?', [api_int('design_id'), $c['id']]);
        if (!$d) api_fail('التصميم ده مش موجود', 'not_found', 404);
        db_run('UPDATE contents SET selected_image_id = ? WHERE id = ? AND user_id = ?', [$d['id'], $c['id'], $uid]);
        api_ok(['selected' => (int) $d['id']]);

    /* ── تعديل التصميم ③: رفع تصميم جاهز من جهاز العميل — بيبقى نسخة جديدة والغلاف ── */
    case 'upload_design':
        require_once __DIR__ . '/../../includes/uploader.php';
        $c = api_own('contents', api_int('id'), $uid);
        if (in_array((string) ($c['publish_status'] ?? ''), ['published', 'scheduled', 'processing'], true)) {
            api_fail('المنشور اتسلّم للنشر — التصميم مايتغيّرش', 'locked', 409);
        }
        if (!rate_limit('upload_design', 'u' . $uid, 15, 600)) api_fail('رفعت كتير بسرعة — استنى شوية', 'rate_limit', 429);
        if (empty($_FILES['design']['name'])) api_fail('اختار صورة التصميم', 'empty', 422);
        $up = upload_image($_FILES['design'], 'designs');
        if (!$up['ok']) api_fail($up['error'], 'upload', 422);
        $ratio = api_str('ratio', 10);
        $did = db_insert('INSERT INTO content_designs (content_id, user_id, image_path, prompt, model, credits_used) VALUES (?, ?, ?, ?, ?, 0)',
            [$c['id'], $uid, $up['path'], 'تصميم مرفوع من العميل', 'upload']);
        if ($ratio !== '' && preg_match('/^\d{1,2}:\d{1,2}$/', $ratio)) {
            try { db_run('UPDATE content_designs SET ratio = ? WHERE id = ?', [$ratio, $did]); } catch (\Throwable $e) { /* عمود المقاس مش موجود */ }
        }
        db_run('UPDATE contents SET selected_image_id = ? WHERE id = ? AND user_id = ?', [$did, $c['id'], $uid]);
        api_ok(['design_id' => $did, 'url' => upload_url($up['path'])]);

    /* ── تعديل بالكلام: اقتراح بس — الحفظ لما العميل يعتمد ── */
    case 'ai_edit':
        require_once __DIR__ . '/../../includes/ai.php';
        require_once __DIR__ . '/../../includes/prompt-builder.php';
        $c = api_own('contents', api_int('id'), $uid);
        $instruction = api_str('instruction', 500);
        if (mb_strlen($instruction) < 3) {
            api_fail('اكتب عايز تعدّل إيه', 'empty', 422);
        }
        if (trim((string) $c['generated_text']) === '') {
            api_fail('المنشور ده لسه مفيهوش نص — اكتبه الأول', 'no_text', 422);
        }
        if (!rate_limit('ai_edit', 'u' . $uid, 12, 600)) {
            api_fail('عدّلت كتير بسرعة — استنى شوية', 'rate_limit', 429);
        }

        $brand = db_one('SELECT * FROM brand_profiles WHERE user_id = ? ORDER BY id LIMIT 1', [$uid]) ?: [];
        $tones = ['simple' => 'بسيطة', 'formal' => 'رسمية', 'fun' => 'مرحة', 'professional' => 'احترافية'];
        $dialects = ['egyptian' => 'العامية المصرية', 'khaleeji' => 'الخليجية', 'levantine' => 'الشامية', 'msa' => 'الفصحى'];
        $ctx = [];
        if (!empty($brand['business_name'])) $ctx[] = 'البراند: ' . $brand['business_name'] . (!empty($brand['industry']) ? ' (' . $brand['industry'] . ')' : '');
        if (!empty($brand['audience']))      $ctx[] = 'الجمهور: ' . mb_substr($brand['audience'], 0, 300);
        if (!empty($brand['tone']))          $ctx[] = 'النبرة: ' . ($tones[$brand['tone']] ?? $brand['tone']);
        if (!empty($brand['dialect']))       $ctx[] = 'اللهجة: ' . ($dialects[$brand['dialect']] ?? $brand['dialect']);
        if (!empty($brand['keywords_avoid'])) $ctx[] = 'كلمات ممنوعة: ' . mb_substr($brand['keywords_avoid'], 0, 200);
        if (!empty($c['campaign_id'])) {
            $cp = db_one('SELECT title, goal, topic FROM campaigns WHERE id = ? AND user_id = ?', [$c['campaign_id'], $uid]);
            if ($cp) $ctx[] = 'الحملة: ' . $cp['title'] . ($cp['topic'] ? ' — ' . $cp['topic'] : '');
        }
        $prev = db_one('SELECT content_text FROM content_versions WHERE content_id = ? ORDER BY version_number DESC LIMIT 1 OFFSET 1', [$c['id']]);

        $prompt = "انت كاتب محتوى سوشيال ميديا محترف.\n"
            . ($ctx ? "سياق:\n- " . implode("\n- ", $ctx) . "\n\n" : '')
            . "ده منشور موجود:\n[CONTENT]\n" . $c['generated_text'] . "\n[/CONTENT]\n"
            . "[HASHTAGS]\n" . ($c['hashtags'] ?? '') . "\n[/HASHTAGS]\n"
            . "[CTA]\n" . ($c['cta'] ?? '') . "\n[/CTA]\n\n"
            . ($prev && trim((string) $prev['content_text']) !== '' ? "النسخة اللي قبله (للمرجع بس — ماترجعلهاش إلا لو اتطلب):\n" . mb_substr($prev['content_text'], 0, 1200) . "\n\n" : '')
            . "طلب العميل: «" . $instruction . "»\n\n"
            . "القواعد:\n"
            . "- عدّل اللي اتطلب **بس**. أي جزء مش مطلوب تغييره يفضل زي ما هو بالحرف.\n"
            . "- حافظ على نفس اللهجة ونبرة البراند.\n"
            . "- ممنوع تخترع أرقام أو أسعار أو مواعيد مش موجودة.\n"
            . "- رجّع المنشور كامل بنفس الصيغة بالظبط: [CONTENT]...[/CONTENT] [HASHTAGS]...[/HASHTAGS] [CTA]...[/CTA] — من غير أي شرح.";

        $cost = cost_for('ai_edit_cost');
        if ($cost > 0 && !credits_consume($uid, $cost, 'تعديل بالكلام #' . $c['id'], 'ai_edit', (int) $c['id'])) {
            api_fail(credits_short_msg($cost), 'credits', 402);
        }
        if (function_exists('ai_log_set_context')) {
            ai_log_set_context(['kind' => 'content', 'user_id' => $uid, 'reference_type' => 'contents',
                                'reference_id' => (int) $c['id'], 'options' => ['ai_edit' => $instruction]]);
        }
        $ai = (function_exists('smart_ai_enabled') && smart_ai_enabled())
            ? smart_ai_generate($prompt, [], 'content_generation', ['user_id' => $uid, 'job_type' => 'ai_edit', 'temperature' => 0.5])
            : ai_generate($prompt);
        $parsed = !empty($ai['ok']) ? parse_ai_response((string) $ai['response']) : ['content' => ''];
        if (trim((string) ($parsed['content'] ?? '')) === '') {
            if ($cost > 0) credits_add($uid, $cost, 'استرداد: تعديل بالكلام ماطلعش نتيجة #' . $c['id'], null, 'refund', (int) $c['id']);
            api_fail(!empty($ai['ok']) ? 'الـ AI رجّع رد مش مفهوم — الكريدت رجعلك، جرّب تاني' : ($ai['error'] ?? 'تعذّر التعديل — الكريدت رجعلك'), 'ai_failed', 502);
        }
        // لو الـ AI ماغيّرش الهاشتاجات/الـ CTA وسابهم فاضيين، نحتفظ بالقديم
        $new = [
            'text'     => trim($parsed['content']),
            'hashtags' => trim((string) ($parsed['hashtags'] ?? '')) ?: (string) ($c['hashtags'] ?? ''),
            'cta'      => trim((string) ($parsed['cta'] ?? '')) ?: (string) ($c['cta'] ?? ''),
        ];
        $wantsDesign = (bool) preg_match('/تصميم|الصورة|صورة|ألوان|الوان|لون|لوجو|اللوجو|خلفية|الخلفية|الخط|فونت|design|image/iu', $instruction);
        api_ok([
            'proposal'     => $new,
            'old'          => ['text' => (string) $c['generated_text'], 'hashtags' => (string) ($c['hashtags'] ?? ''), 'cta' => (string) ($c['cta'] ?? '')],
            'instruction'  => $instruction,
            'wants_design' => $wantsDesign,
            'charged'      => $cost,
            'balance'      => credits_balance($uid),
        ]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
