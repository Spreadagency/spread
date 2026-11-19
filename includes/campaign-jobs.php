<?php
/**
 * Spread AI — خطة الحملات · المرحلة 5: الكتابة والتصميم الجماعي للحملات على طابور الشغل (ce_jobs)
 *
 *   camp_write  ← كل خطوة = فكرة واحدة بـ campaign_write_idea (نفس مسار الصفحة بالظبط)
 *   camp_design ← كل خطوة = تصميم واحد (منشور أو شريحة) بـ design_generate (نفس مسار الصفحة)
 *
 * قفل التاب مابيوقفش الشغل: الـ cron (cron/ce-worker.php) بيكمّل، ولو مش شغال الصفحة بتطلب الخطوات بنفسها (job_step).
 * الطابور بنية مشتركة — مش مربوط بمفتاح المحرك (content_engine) ولا بكود ce_campaigns.
 * الخصم والعدّ والرجوع جوه الدالتين نفسهم (الكريدت + حدود الحملة في الباقة) — فشل خطوة بيرجّع اللي اتخصم عليها.
 */
require_once __DIR__ . '/ce-jobs.php';
require_once __DIR__ . '/content-formats.php';
require_once __DIR__ . '/brand-brain.php';
require_once __DIR__ . '/campaign-allowance.php';

const CJ_KINDS = ['camp_write', 'camp_design'];

ce_job_register('camp_write', 'cj_write_job', 'كتابة منشورات الحملة');
ce_job_register('camp_design', 'cj_design_job', 'تصميمات الحملة');

/** الأخطاء اللي بتوقف باقي الشغلانة (مش هتتحل لوحدها في الخطوة الجاية) */
const CJ_STOP_CODES = ['credits', 'campaign_limit', 'not_approved', 'closed', 'quota', 'subscription', 'brand_gate'];

/** المستخدم بالشكل اللي الدوال محتاجاه (الـ worker مالوش جلسة) */
function cj_user(int $uid): ?array
{
    return db_one('SELECT * FROM users WHERE id = ? AND status = "active"', [$uid]) ?: null;
}

/**
 * بدء كتابة جماعية: الأفكار المختارة اللي لسه ملهاش محتوى (أو ids محددة)
 * @return array{ok: bool, job?: array, error?: string, code?: string, count?: int}
 */
function cj_start_write(int $uid, array $c, array $ideaIds = []): array
{
    $sql = 'SELECT id FROM campaign_ideas WHERE campaign_id = ? AND user_id = ? AND selected = 1 AND content_id IS NULL'
         . (ca_ready() ? ' AND rejected = 0' : '');
    $ids = array_map('intval', array_column(db_all($sql . ' ORDER BY sort_order, id', [(int) $c['id'], $uid]), 'id'));
    if ($ideaIds) $ids = array_values(array_intersect($ids, array_map('intval', $ideaIds)));
    if (!$ids) return ['ok' => false, 'code' => 'empty', 'error' => 'مفيش أفكار محتاجة كتابة'];
    if ($a = ce_job_active_for($uid, 'camp_write', 'campaign', (int) $c['id'])) return ['ok' => true, 'job' => $a, 'existing' => true, 'count' => count($ids)];
    $r = ce_job_create($uid, 'camp_write', ['cid' => (int) $c['id'], 'ideas' => $ids, 'n' => 0, 'ok' => [], 'err' => []],
        ['ref_type' => 'campaign', 'ref_id' => (int) $c['id'], 'max_attempts' => 3]);
    return $r + ['count' => count($ids)];
}

/** بدء تصميم جماعي: كل منشور (وكل شريحة) لسه ماتصممش */
function cj_start_design(int $uid, array $c, array $contentIds = [], string $ratio = ''): array
{
    $items = [];
    $only = array_map('intval', $contentIds);
    $ratio = preg_match('/^\d{1,2}:\d{1,2}$/', $ratio) ? $ratio : '';
    foreach (db_all('SELECT c.* FROM campaign_ideas i JOIN contents c ON c.id = i.content_id WHERE i.campaign_id = ? AND i.user_id = ? AND i.selected = 1'
                    . (ca_ready() ? ' AND i.rejected = 0' : '') . ' ORDER BY i.sort_order, i.id', [(int) $c['id'], $uid]) as $co) {
        if (content_format_key($co['format'] ?? 'post') === 'video') continue;
        if ($only && !in_array((int) $co['id'], $only, true)) continue;
        foreach (content_design_progress($co)['missing'] as $slide) {
            $items[] = ['content_id' => (int) $co['id'], 'slide' => content_format_key($co['format'] ?? 'post') === 'carousel' ? (int) $slide : 0];
        }
    }
    if (!$items) return ['ok' => false, 'code' => 'empty', 'error' => 'كل المنشورات متصممة'];
    if ($a = ce_job_active_for($uid, 'camp_design', 'campaign', (int) $c['id'])) return ['ok' => true, 'job' => $a, 'existing' => true, 'count' => count($items)];
    $r = ce_job_create($uid, 'camp_design', ['cid' => (int) $c['id'], 'items' => $items, 'n' => 0, 'ok' => [], 'err' => [], 'ratio' => $ratio],
        ['ref_type' => 'campaign', 'ref_id' => (int) $c['id'], 'max_attempts' => 3]);
    return $r + ['count' => count($items)];
}

/** خطوة كتابة: فكرة واحدة */
function cj_write_job(array $job, array &$state): array
{
    require_once __DIR__ . '/campaign-write.php';
    $uid = (int) $job['user_id'];
    $ids = (array) ($state['ideas'] ?? []);
    $n = (int) ($state['n'] ?? 0);
    if ($n >= count($ids)) return cj_finish($state, count($ids));
    $c = db_one('SELECT * FROM campaigns WHERE id = ? AND user_id = ?', [(int) $state['cid'], $uid]);
    if (!$c) return ['fail' => 'الحملة اتمسحت'];
    $brand = brand_for_user($uid) ?: [];
    if (function_exists('brand_health') && !brand_health($brand)['unlocked']) return ['fail' => 'هوية البراند محتاجة تكمل الأول'];
    $i = db_one('SELECT * FROM campaign_ideas WHERE id = ? AND campaign_id = ?', [(int) $ids[$n], (int) $c['id']]);
    $state['n'] = $n + 1;
    if ($i && !$i['content_id']) {
        $r = campaign_write_idea($uid, $c, $i, ['brand' => $brand, 'no_rate_limit' => true]);
        if ($r['ok']) {
            $state['ok'][] = (int) $i['id'];
        } else {
            $state['err'][(string) $i['id']] = mb_substr((string) $r['error'], 0, 200);
            // مش هتتحل لوحدها (الرصيد · حد الحملة · الحملة اتقفلت) ← نوقف الباقي
            if (in_array($r['code'], CJ_STOP_CODES, true)) {
                $state['stopped'] = (string) $r['error'];
                return cj_finish($state, count($ids));
            }
        }
    }
    if ($state['n'] >= count($ids)) return cj_finish($state, count($ids));
    return ['progress' => (int) floor(100 * $state['n'] / max(1, count($ids))), 'stage' => 'write', 'label' => 'بنكتب ' . ($state['n'] + 1) . ' من ' . count($ids) . '…'];
}

/** خطوة تصميم: منشور أو شريحة واحدة */
function cj_design_job(array $job, array &$state): array
{
    require_once __DIR__ . '/design-generate.php';
    $uid = (int) $job['user_id'];
    $items = (array) ($state['items'] ?? []);
    $n = (int) ($state['n'] ?? 0);
    if ($n >= count($items)) return cj_finish($state, count($items));
    $user = cj_user($uid);
    if (!$user) return ['fail' => 'الحساب موقوف'];
    $it = $items[$n];
    $state['n'] = $n + 1;
    // اتصمم في الوقت ده من مكان تاني؟ (تاب تاني / العميل) ← نعدّيه
    $co = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [(int) $it['content_id'], $uid]);
    $missing = $co ? content_design_progress($co)['missing'] : [];
    $slide = (int) ($it['slide'] ?? 0);
    if ($co && in_array($slide ?: 1, $missing, true)) {
        // نفس طلب الصفحة: المنشور بفكرة تصميمه · الكاروسيل بنص الشريحة (من السيرفر) · الستوري 9:16
        $in = ['content_id' => (int) $it['content_id'], 'slide_no' => $slide ?: null, 'include_logo' => '1', 'bulk' => '1', 'no_rate_limit' => true,
               'ratio' => content_format_key($co['format'] ?? 'post') === 'story' ? '9:16' : ((string) ($state['ratio'] ?? '') ?: '1:1')];
        if (!$slide) $in['custom_prompt'] = (string) ($co['design_direction'] ?? '');
        $r = design_generate($user, $in);
        $key = $it['content_id'] . ':' . $slide;
        if (!empty($r['ok'])) {
            $state['ok'][] = $key;
        } else {
            $state['err'][$key] = mb_substr((string) ($r['error'] ?? 'فشل'), 0, 200);
            if (in_array((string) ($r['code'] ?? ''), CJ_STOP_CODES, true) || str_contains((string) ($r['error'] ?? ''), 'رصيد')) {
                $state['stopped'] = (string) $r['error'];
                return cj_finish($state, count($items));
            }
        }
    }
    if ($state['n'] >= count($items)) return cj_finish($state, count($items));
    return ['progress' => (int) floor(100 * $state['n'] / max(1, count($items))), 'stage' => 'design', 'label' => 'بنصمم ' . ($state['n'] + 1) . ' من ' . count($items) . '…'];
}

function cj_finish(array $state, int $total): array
{
    return ['done' => true, 'used_credits' => 0, 'result' => [
        'total' => $total, 'ok' => count((array) ($state['ok'] ?? [])), 'failed' => count((array) ($state['err'] ?? [])),
        'errors' => array_slice((array) ($state['err'] ?? []), 0, 20, true), 'stopped' => $state['stopped'] ?? null,
    ]];
}

/** الشغل الشغال للحملة (للواجهة بعد الريفرش) */
function cj_active(int $uid, int $cid): array
{
    $out = [];
    foreach (CJ_KINDS as $k) {
        $j = ce_job_active_for($uid, $k, 'campaign', $cid)
            ?: (db_one('SELECT * FROM ce_jobs WHERE user_id = ? AND kind = ? AND ref_type = "campaign" AND ref_id = ? AND finished_at > NOW() - INTERVAL 10 MINUTE ORDER BY id DESC LIMIT 1', [$uid, $k, $cid]) ?: null);
        if ($j) $out[$k] = ce_job_public($j);
    }
    return $out;
}
