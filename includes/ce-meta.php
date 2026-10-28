<?php
/**
 * Spread AI — محرك المحتوى v3 · ربط النتايج بـ Meta Insights
 *
 * القطعة بتتربط بالبوست اللي نزل فعلًا على صفحة فيسبوك أو حساب إنستجرام بيزنس مربوط (social_connections):
 *   - يدوي: العميل بيختار البوست من آخر بوستات الصفحة (ومعاه نسبة التشابه مع نص القطعة)
 *   - تلقائي: الـ worker بيربط لو النص شبه بعض جدًا (≥ ce_meta_match_min) ومفيش بوست تاني قريب منه
 * وبعدها الأرقام بتتسحب من Insights كل ce_meta_sync_hours لحد ce_meta_sync_days يوم بعد النشر:
 *   فيسبوك: reactions · comments · shares + insights (الوصول · المشاهدات · الضغطات)
 *   إنستجرام: like_count · comments_count + insights (reach · saved · shares · views)
 * Meta بتغيّر أسماء الـ metrics من نسخة لنسخة — بنجرّب أكتر من مجموعة بالترتيب.
 * لو الصفحة مااديتش صلاحية Insights (read_insights / instagram_manage_insights): بنسحب التفاعل بس ونقول للعميل يربط تاني.
 * «الرسايل/الحجوزات» مش في Insights — بتفضل من إدخال العميل.
 */
require_once __DIR__ . '/ce-learning.php';
require_once __DIR__ . '/social.php';

/** مجموعات الـ metrics بالترتيب (الأولى اللي تشتغل بتكسب) */
const CE_META_FB_METRICS = [
    ['post_impressions_unique', 'post_impressions', 'post_clicks'],
    ['post_total_media_view_unique', 'post_media_view', 'post_clicks'],
    ['post_impressions_unique'],
];
const CE_META_IG_METRICS = [
    ['reach', 'saved', 'shares', 'views'],
    ['reach', 'saved', 'shares'],
    ['reach', 'saved', 'impressions'],
    ['reach'],
];

function ce_meta_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!ce_learning_ready()) return $ok = false;
    try {
        db_one('SELECT provider_post_id FROM ce_performance LIMIT 1');
        db_one('SELECT id FROM social_connections LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function ce_meta_post_id_ok(string $id): bool
{
    return (bool) preg_match('/^[0-9_]{5,80}$/', $id);
}

/** صفحات العميل الشغالة بالمنصات المتاحة في كل واحدة (من غير توكنز) */
function ce_meta_connections(int $uid): array
{
    if (!ce_meta_ready()) return [];
    $out = [];
    foreach (user_connections($uid, 'active') as $c) {
        $targets = [['platform' => 'facebook', 'label' => 'فيسبوك — ' . $c['page_name']]];
        if (!empty($c['ig_user_id'])) $targets[] = ['platform' => 'instagram', 'label' => 'إنستجرام — @' . ($c['ig_username'] ?: $c['ig_user_id'])];
        $sc = (string) ($c['scopes'] ?? '');
        $out[] = ['id' => (int) $c['id'], 'name' => (string) $c['page_name'], 'targets' => $targets,
                  'insights' => str_contains($sc, 'read_insights') || str_contains($sc, 'instagram_manage_insights')];
    }
    return $out;
}

/** الحساب على المنصة (رقم الصفحة أو حساب إنستجرام) */
function ce_meta_account_id(array $conn, string $platform): ?string
{
    if ($platform === 'instagram') return !empty($conn['ig_user_id']) ? (string) $conn['ig_user_id'] : null;
    return (string) $conn['provider_page_id'];
}

/** نداء Graph بتوكن الصفحة — ولو التوكن انتهى بنعلّم الاتصال «منتهي» */
function ce_meta_call(array $conn, string $path, array $params = []): array
{
    $token = social_token_decrypt((string) $conn['access_token_enc']);
    if (!$token) return ['ok' => false, 'error' => 'تعذّر فك تشفير توكن الصفحة', 'code' => 190, 'data' => []];
    $r = graph_request('GET', $path, $params + ['access_token' => $token], ['connection_id' => (int) $conn['id'], 'action' => 'insights ' . strtok($path, '/')]);
    if (!$r['ok'] && (int) $r['code'] === 190) {
        try { connection_mark((int) $conn['id'], 'expired', 'التوكن انتهى — اربط الصفحة تاني'); } catch (\Throwable $e) {}
    }
    return $r;
}

/** آخر بوستات الصفحة/الحساب — شكل موحّد */
function ce_meta_recent_posts(array $conn, string $platform, int $limit = 25): array
{
    $acc = ce_meta_account_id($conn, $platform);
    if (!$acc) return ['ok' => false, 'error' => 'الصفحة دي مش مربوط بيها حساب إنستجرام', 'posts' => []];
    if ($platform === 'instagram') {
        $r = ce_meta_call($conn, $acc . '/media', ['fields' => 'id,caption,timestamp,permalink,media_type,thumbnail_url,media_url', 'limit' => $limit]);
        $map = fn($p) => ['id' => (string) $p['id'], 'text' => (string) ($p['caption'] ?? ''), 'created' => substr((string) ($p['timestamp'] ?? ''), 0, 10),
                          'permalink' => (string) ($p['permalink'] ?? ''), 'picture' => (string) ($p['thumbnail_url'] ?? ($p['media_url'] ?? ''))];
    } else {
        $r = ce_meta_call($conn, $acc . '/posts', ['fields' => 'id,message,created_time,permalink_url,full_picture', 'limit' => $limit]);
        $map = fn($p) => ['id' => (string) $p['id'], 'text' => (string) ($p['message'] ?? ''), 'created' => substr((string) ($p['created_time'] ?? ''), 0, 10),
                          'permalink' => (string) ($p['permalink_url'] ?? ''), 'picture' => (string) ($p['full_picture'] ?? '')];
    }
    if (!$r['ok']) return ['ok' => false, 'error' => ce_meta_error_text($r), 'posts' => []];
    $posts = array_values(array_filter(array_map($map, (array) ($r['data']['data'] ?? [])), fn($p) => ce_meta_post_id_ok($p['id'])));
    return ['ok' => true, 'posts' => $posts];
}

function ce_meta_error_text(array $r): string
{
    $code = (int) ($r['code'] ?? 0);
    if ($code === 190) return 'صلاحية الصفحة انتهت — اربط الصفحة تاني من «حسابات السوشيال»';
    if (in_array($code, [10, 200], true) || ($code >= 200 && $code < 300)) return 'الصفحة مااديتش الصلاحية دي — اربطها تاني ووافق على كل الصلاحيات';
    return 'Meta رجّعت خطأ: ' . mb_substr((string) ($r['error'] ?? ''), 0, 160);
}

/** نص القطعة للمقارنة مع البوست */
function ce_meta_content_text(array $content): string
{
    $b = json_decode((string) $content['body_json'], true) ?: [];
    return mb_substr(trim(ce_content_text($b)), 0, 500);
}

function ce_meta_similarity(string $a, string $b): float
{
    $a = mb_substr(ce_text_norm($a), 0, 400);
    $b = mb_substr(ce_text_norm($b), 0, 400);
    if ($a === '' || $b === '') return 0.0;
    return round(ce_similarity($a, $b), 3);
}

/** البوستات المرشّحة لقطعة: الأقرب في النص الأول */
function ce_meta_candidates(int $uid, int $contentId, int $connId, string $platform): array
{
    $content = db_one('SELECT * FROM ce_contents WHERE id = ? AND user_id = ?', [$contentId, $uid]);
    if (!$content) return ['ok' => false, 'code' => 'not_found', 'error' => 'القطعة مش موجودة'];
    $conn = connection_for_user($connId, $uid);
    if (!$conn || $conn['status'] !== 'active') return ['ok' => false, 'code' => 'not_found', 'error' => 'الصفحة مش مربوطة'];
    $r = ce_meta_recent_posts($conn, $platform);
    if (!$r['ok']) return ['ok' => false, 'code' => 'meta', 'error' => $r['error']];
    $text = ce_meta_content_text($content);
    $linked = array_column(db_all('SELECT provider_post_id, content_id FROM ce_performance WHERE provider_post_id IS NOT NULL AND user_id = ?', [$uid]), 'content_id', 'provider_post_id');
    $posts = [];
    foreach ($r['posts'] as $p) {
        $p['similarity'] = ce_meta_similarity($text, $p['text']);
        $p['text'] = mb_substr($p['text'], 0, 220);
        $p['taken'] = isset($linked[$p['id']]) && (int) $linked[$p['id']] !== $contentId;
        $posts[] = $p;
    }
    usort($posts, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    return ['ok' => true, 'posts' => $posts];
}

/**
 * ربط قطعة ببوست: بنتأكد إن البوست تبع الصفحة دي، وبعدين بنسحب الأرقام على طول
 * $mode = manual (العميل) · auto (الـ worker)
 */
function ce_meta_link(int $uid, int $contentId, int $connId, string $platform, string $postId, string $mode = 'manual'): array
{
    if (!ce_meta_ready()) return ['ok' => false, 'error' => 'شغّل ترحيل 2026_ce_meta_insights الأول'];
    $platform = $platform === 'instagram' ? 'instagram' : 'facebook';
    if (!ce_meta_post_id_ok($postId)) return ['ok' => false, 'code' => 'input', 'error' => 'رقم البوست غلط'];
    $content = db_one('SELECT * FROM ce_contents WHERE id = ? AND user_id = ?', [$contentId, $uid]);
    if (!$content) return ['ok' => false, 'code' => 'not_found', 'error' => 'القطعة مش موجودة'];
    if (!in_array($content['status'], ['pass', 'manual'], true)) return ['ok' => false, 'code' => 'state', 'error' => 'القطعة لسه ماخلصتش'];
    $conn = connection_for_user($connId, $uid);
    if (!$conn || $conn['status'] !== 'active') return ['ok' => false, 'code' => 'not_found', 'error' => 'الصفحة مش مربوطة'];
    $acc = ce_meta_account_id($conn, $platform);
    if (!$acc) return ['ok' => false, 'code' => 'input', 'error' => 'الصفحة دي مش مربوط بيها حساب إنستجرام'];
    $other = db_one('SELECT content_id FROM ce_performance WHERE provider_post_id = ? AND content_id <> ?', [$postId, $contentId]);
    if ($other) return ['ok' => false, 'code' => 'taken', 'error' => 'البوست ده مربوط بقطعة تانية'];
    // البوست تبع الحساب ده؟
    if ($platform === 'instagram') {
        $r = ce_meta_call($conn, $postId, ['fields' => 'id,owner,timestamp,permalink']);
        $owner = (string) ($r['data']['owner']['id'] ?? $acc);
        $published = substr((string) ($r['data']['timestamp'] ?? ''), 0, 10);
        $permalink = (string) ($r['data']['permalink'] ?? '');
    } else {
        $r = ce_meta_call($conn, $postId, ['fields' => 'id,from,created_time,permalink_url']);
        $owner = (string) ($r['data']['from']['id'] ?? '');
        $published = substr((string) ($r['data']['created_time'] ?? ''), 0, 10);
        $permalink = (string) ($r['data']['permalink_url'] ?? '');
    }
    if (!$r['ok']) return ['ok' => false, 'code' => 'meta', 'error' => ce_meta_error_text($r)];
    if ($owner !== $acc) return ['ok' => false, 'code' => 'input', 'error' => 'البوست ده مش من الصفحة دي'];
    $published = preg_match('/^\d{4}-\d{2}-\d{2}$/', $published) ? $published : null;
    db_run('INSERT INTO ce_performance (user_id, campaign_id, content_id, platform, published_at, source, connection_id, provider_post_id, permalink, link_mode, sync_status)
            VALUES (?,?,?,?,?,"meta",?,?,?,?,"pending")
            ON DUPLICATE KEY UPDATE platform = VALUES(platform), published_at = COALESCE(VALUES(published_at), published_at), source = "meta", connection_id = VALUES(connection_id),
              provider_post_id = VALUES(provider_post_id), permalink = VALUES(permalink), link_mode = VALUES(link_mode), synced_at = NULL, sync_status = "pending", sync_error = NULL',
        [$uid, (int) $content['campaign_id'], $contentId, $platform, $published, $connId, $postId, mb_substr($permalink, 0, 500) ?: null, $mode === 'auto' ? 'auto' : 'manual']);
    $sync = ce_meta_sync_row(db_one('SELECT * FROM ce_performance WHERE content_id = ?', [$contentId]));
    return ['ok' => true, 'sync' => $sync, 'perf' => ce_perf_public(db_one('SELECT * FROM ce_performance WHERE content_id = ?', [$contentId]))];
}

/** فك الربط: الأرقام اللي اتسحبت بتفضل كإدخال يدوي */
function ce_meta_unlink(int $uid, int $contentId): bool
{
    if (!ce_meta_ready()) return false;
    db_run('UPDATE ce_performance SET source = "manual", connection_id = NULL, provider_post_id = NULL, permalink = NULL, link_mode = NULL, synced_at = NULL, sync_status = NULL, sync_error = NULL
            WHERE content_id = ? AND user_id = ? AND provider_post_id IS NOT NULL', [$contentId, $uid]);
    return true;
}

/** قيمة metric من رد insights (values[0].value أو total_value.value — حسب نسخة الـ API) */
function ce_meta_metric_values(array $data): array
{
    $out = [];
    foreach ((array) ($data['data'] ?? []) as $m) {
        $v = $m['total_value']['value'] ?? ($m['values'][0]['value'] ?? null);
        if (is_array($v)) $v = array_sum(array_map('intval', $v));
        if ($v !== null && is_numeric($v)) $out[(string) $m['name']] = (int) $v;
    }
    return $out;
}

/**
 * الأرقام من Meta لبوست واحد
 * @return array{ok:bool, m?:array, partial?:bool, error?:string, code?:int}
 */
function ce_meta_fetch(array $conn, string $platform, string $postId): array
{
    $m = [];
    if ($platform === 'instagram') {
        $b = ce_meta_call($conn, $postId, ['fields' => 'id,like_count,comments_count']);
        if (!$b['ok']) return ['ok' => false, 'error' => ce_meta_error_text($b), 'code' => (int) $b['code']];
        $m['likes'] = (int) ($b['data']['like_count'] ?? 0);
        $m['comments'] = (int) ($b['data']['comments_count'] ?? 0);
        $sets = CE_META_IG_METRICS;
    } else {
        $b = ce_meta_call($conn, $postId, ['fields' => 'id,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares']);
        if (!$b['ok']) return ['ok' => false, 'error' => ce_meta_error_text($b), 'code' => (int) $b['code']];
        $m['likes'] = (int) ($b['data']['reactions']['summary']['total_count'] ?? 0);
        $m['comments'] = (int) ($b['data']['comments']['summary']['total_count'] ?? 0);
        $m['shares'] = (int) ($b['data']['shares']['count'] ?? 0);
        $sets = CE_META_FB_METRICS;
    }
    $ins = null;
    $lastErr = null;
    foreach ($sets as $set) {
        $r = ce_meta_call($conn, $postId . '/insights', ['metric' => implode(',', $set)] + ($platform === 'instagram' ? [] : ['period' => 'lifetime']));
        if ($r['ok']) { $ins = ce_meta_metric_values($r['data']); break; }
        $lastErr = $r;
        if ((int) $r['code'] === 190) return ['ok' => false, 'error' => ce_meta_error_text($r), 'code' => 190];
        if (in_array((int) $r['code'], [10, 200], true)) break;   // مفيش صلاحية — مالوش لازمة نجرّب مجموعة تانية
    }
    if ($ins !== null) {
        if ($platform === 'instagram') {
            $m['reach'] = $ins['reach'] ?? 0;
            $m['saves'] = $ins['saved'] ?? 0;
            if (isset($ins['shares'])) $m['shares'] = $ins['shares'];
            $m['impressions'] = $ins['views'] ?? ($ins['impressions'] ?? 0);
        } else {
            $m['reach'] = $ins['post_impressions_unique'] ?? ($ins['post_total_media_view_unique'] ?? 0);
            $m['impressions'] = $ins['post_impressions'] ?? ($ins['post_media_view'] ?? 0);
            $m['clicks'] = $ins['post_clicks'] ?? 0;
        }
    }
    return ['ok' => true, 'm' => $m, 'partial' => $ins === null, 'error' => $ins === null && $lastErr ? ce_meta_error_text($lastErr) : null];
}

/** مزامنة صف واحد — الرسايل (من العميل) بتفضل */
function ce_meta_sync_row(?array $row): array
{
    if (!$row || empty($row['provider_post_id'])) return ['ok' => false, 'error' => 'مش مربوط'];
    $conn = db_one('SELECT * FROM social_connections WHERE id = ? AND user_id = ?', [(int) $row['connection_id'], (int) $row['user_id']]);
    if (!$conn || $conn['status'] !== 'active') {
        db_run('UPDATE ce_performance SET synced_at = NOW(), sync_status = "expired", sync_error = ? WHERE id = ?', ['الصفحة مش مربوطة أو صلاحيتها انتهت — اربطها تاني', (int) $row['id']]);
        return ['ok' => false, 'status' => 'expired'];
    }
    $f = ce_meta_fetch($conn, (string) $row['platform'], (string) $row['provider_post_id']);
    if (!$f['ok']) {
        $st = (int) ($f['code'] ?? 0) === 190 ? 'expired' : 'error';
        db_run('UPDATE ce_performance SET synced_at = NOW(), sync_status = ?, sync_error = ? WHERE id = ?', [$st, mb_substr((string) $f['error'], 0, 300), (int) $row['id']]);
        return ['ok' => false, 'status' => $st, 'error' => $f['error']];
    }
    $v = $f['m'] + array_intersect_key($row, array_flip(CE_PERF_FIELDS));
    $v['messages'] = (int) $row['messages'];
    foreach (CE_PERF_FIELDS as $k) $v[$k] = max(0, min(CE_PERF_MAX, (int) ($v[$k] ?? 0)));
    $rate = ce_perf_rate($v);
    $st = $f['partial'] ? 'partial' : 'ok';
    db_run('UPDATE ce_performance SET reach = ?, impressions = ?, likes = ?, comments = ?, shares = ?, saves = ?, clicks = ?, eng_rate = ?, synced_at = NOW(), sync_status = ?, sync_error = ? WHERE id = ?',
        [$v['reach'], $v['impressions'], $v['likes'], $v['comments'], $v['shares'], $v['saves'], $v['clicks'], $rate, $st,
         $f['partial'] ? mb_substr('الوصول والمشاهدات محتاجين صلاحية Insights — ' . (string) $f['error'], 0, 300) : null, (int) $row['id']]);
    return ['ok' => true, 'status' => $st, 'eng_rate' => $rate];
}

/** البوستات اللي محتاجة تتحدّث (لحد ce_meta_sync_days من النشر · كل ce_meta_sync_hours) */
function ce_meta_sync_due(int $limit = 20): array
{
    if (!ce_meta_ready()) return ['synced' => 0];
    $days = max(1, ce_setting_int('ce_meta_sync_days', 30));
    $hours = max(1, ce_setting_int('ce_meta_sync_hours', 12));
    $rows = db_all('SELECT p.* FROM ce_performance p JOIN social_connections c ON c.id = p.connection_id AND c.status = "active"
                    WHERE p.provider_post_id IS NOT NULL AND (p.published_at IS NULL OR p.published_at >= ?)
                      AND (p.synced_at IS NULL OR p.synced_at < ?) ORDER BY p.synced_at IS NOT NULL, p.synced_at LIMIT ' . max(1, min(200, $limit)),
        [date('Y-m-d', strtotime("-$days days")), date('Y-m-d H:i:s', strtotime("-$hours hours"))]);
    $n = 0;
    foreach ($rows as $r) { ce_meta_sync_row($r); $n++; }
    return ['synced' => $n];
}

/**
 * الربط التلقائي: قطع المحرك (آخر 60 يوم) اللي مالهاش نتايج ← أقرب بوست في صفحات العميل لو التشابه ≥ الحد
 * ومفيش بوست تاني قريب (فرق 0.1 على الأقل) — عشان مانربطش غلط
 */
function ce_meta_auto_match(int $maxUsers = 20): array
{
    $out = ['users' => 0, 'linked' => 0];
    if (!ce_meta_ready() || ce_setting_int('ce_meta_auto_match', 1) !== 1) return $out;
    $min = max(0.5, min(0.99, ce_setting_float('ce_meta_match_min', 0.75)));
    $users = db_all('SELECT DISTINCT c.user_id FROM ce_contents c JOIN social_connections s ON s.user_id = c.user_id AND s.status = "active"
                     LEFT JOIN ce_performance p ON p.content_id = c.id
                     WHERE c.status IN ("pass", "manual") AND c.created_at >= ? AND p.id IS NULL LIMIT ' . max(1, $maxUsers), [date('Y-m-d', strtotime('-60 days'))]);
    foreach ($users as $u) {
        $uid = (int) $u['user_id'];
        $out['users']++;
        $contents = db_all('SELECT c.* FROM ce_contents c LEFT JOIN ce_performance p ON p.content_id = c.id
                            WHERE c.user_id = ? AND c.status IN ("pass", "manual") AND c.created_at >= ? AND p.id IS NULL LIMIT 60', [$uid, date('Y-m-d', strtotime('-60 days'))]);
        $taken = array_flip(array_column(db_all('SELECT provider_post_id FROM ce_performance WHERE provider_post_id IS NOT NULL AND user_id = ?', [$uid]), 'provider_post_id'));
        $pool = [];
        foreach (user_connections($uid, 'active') as $conn) {
            foreach (['facebook', 'instagram'] as $pf) {
                if (!ce_meta_account_id($conn, $pf)) continue;
                $r = ce_meta_recent_posts($conn, $pf, 50);
                if (!$r['ok']) continue;
                foreach ($r['posts'] as $p) if (!isset($taken[$p['id']]) && trim($p['text']) !== '') $pool[] = $p + ['conn' => (int) $conn['id'], 'platform' => $pf];
            }
        }
        if (!$pool) continue;
        foreach ($contents as $c) {
            $text = ce_meta_content_text($c);
            $scores = [];
            foreach ($pool as $i => $p) if (!isset($taken[$p['id']])) $scores[$i] = ce_meta_similarity($text, $p['text']);
            if (!$scores) break;
            arsort($scores);
            $best = array_key_first($scores);
            $second = array_values($scores)[1] ?? 0.0;
            if ($scores[$best] < $min || $scores[$best] - $second < 0.1) continue;
            $p = $pool[$best];
            $r = ce_meta_link($uid, (int) $c['id'], $p['conn'], $p['platform'], $p['id'], 'auto');
            if ($r['ok']) { $out['linked']++; $taken[$p['id']] = true; }
        }
    }
    return $out;
}

/** من الـ worker: مزامنة كل 10 دقايق · ربط تلقائي كل 6 ساعات */
function ce_meta_tick(): ?array
{
    if (!ce_meta_ready()) return null;
    $now = time();
    $out = null;
    if ($now - (int) strtotime((string) get_setting('ce_meta_last_match', '') ?: '2000-01-01') >= 6 * 3600) {
        set_setting('ce_meta_last_match', date('Y-m-d H:i:s'));
        $out['match'] = ce_meta_auto_match();
    }
    if ($now - (int) strtotime((string) get_setting('ce_meta_last_sync', '') ?: '2000-01-01') >= 600) {
        set_setting('ce_meta_last_sync', date('Y-m-d H:i:s'));
        $out['sync'] = ce_meta_sync_due(20);
    }
    return $out;
}

/** للأدمن: أرقام الربط */
function ce_meta_stats(): array
{
    if (!ce_meta_ready()) return [];
    return db_one('SELECT SUM(provider_post_id IS NOT NULL) linked, SUM(link_mode = "auto") auto, SUM(sync_status = "ok") ok, SUM(sync_status = "partial") partial,
                          SUM(sync_status IN ("error", "expired")) bad, SUM(synced_at >= ?) synced_24h FROM ce_performance', [date('Y-m-d H:i:s', strtotime('-24 hours'))]) ?: [];
}
