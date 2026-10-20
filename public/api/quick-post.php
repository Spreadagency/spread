<?php
/**
 * Spread AI — المنشور السريع
 *
 *   GET  action=options                                  الخدمات · العملاء المثاليين · الأشكال · التكلفة
 *   POST action=create {service_id, format, persona_id, note}
 *        ← منشور واحد جاهز: الـ Hook والنص والـ CTA والهاشتاجات + فكرة التصميم
 *          (الكاروسيل بشرائحه · الفيديو بسكريبته) — بيتحفظ في المكتبة وبيفتح صفحة المنشور
 *
 * نفس حصة البوستات وتكلفة كتابة المنشور · البرومبت من الأدمن (quick_post + design_idea)
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/brand-brain.php';
require_once __DIR__ . '/../../includes/brand-kit.php';
require_once __DIR__ . '/../../includes/campaign-flow.php';
require_once __DIR__ . '/../../includes/credits.php';

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'options';

function qp_formats(): array
{
    return [
        'post' => ['🖼', 'بوست'],
        'carousel' => ['🎠', 'كاروسيل'],
        'video' => ['🎬', 'فيديو (سكريبت)'],
        'story' => ['📱', 'ستوري'],
    ];
}

switch ($action) {

    case 'options':
        $kitOn = bk_enabled();
        api_ok([
            'services' => $kitOn ? array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name']], bk_services($uid, true)) : [],
            'personas' => $kitOn ? array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'emoji' => $p['emoji'] ?: '🙂', 'service_ids' => $p['service_ids']], bk_personas($uid)) : [],
            'formats' => qp_formats(),
            'slides' => carousel_limits()[2],
            'cta' => $kitOn ? (string) (bk_get($uid)['data']['cta']['text'] ?? '') : '',
            'cost' => cost_for('content_generation_cost'),
            'balance' => credits_balance($uid),
        ]);

    case 'create':
        $brand = brand_for_user($uid);
        if (!$brand || trim((string) $brand['business_name']) === '') api_fail('كمّل اسم نشاطك في هوية البراند الأول', 'no_brand', 422);
        $fmt = api_str('format', 12);
        if (!isset(qp_formats()[$fmt])) $fmt = 'post';
        $slides = $fmt === 'carousel' ? carousel_limits()[2] : 0;
        $svc = null;
        if ($sid = api_int('service_id')) {
            $svc = db_one('SELECT * FROM brand_services WHERE id = ? AND user_id = ?', [$sid, $uid]);
            if (!$svc) api_fail('الخدمة دي مش موجودة', 'not_found', 404);
        }
        $persona = ($pid = api_int('persona_id')) ? bk_persona($uid, $pid) : null;
        $note = api_str('note', 300);
        if (!rate_limit('quick_post', 'u' . $uid, 20, 600)) api_fail('كتبت منشورات كتير بسرعة — استنى شوية', 'rate_limit', 429);
        if ($g = plan_gate($uid, $fmt === 'video' ? 'videos' : 'posts')) api_fail($g, 'quota', 402, ['upgrade' => plan_upgrade_link()]);
        $cost = cost_for('content_generation_cost');
        if ($cost > 0 && (credits_balance($uid) < $cost || !credits_consume($uid, $cost, 'منشور سريع', 'content', null))) api_fail(credits_short_msg($cost), 'credits', 402);

        // بريف البوست من الهوية (الخدمة · الشكل · الموضوع) — الباقي من الـ Brand Kit
        $o = ['format' => bk_platform_to_format($fmt)];
        if ($svc) $o['service_id'] = (int) $svc['id'];
        if ($note !== '') $o['topic'] = $note;
        $brief = bk_enabled() ? BrandContext::brief($uid, $o) : null;
        $fmtLabel = content_format_label($fmt) . ($fmt === 'carousel' ? " من {$slides} شرائح" : '');
        $data = campaign_brand_block($brand, 'copy', $brief)
            . ($fmt === 'video' ? bk_prompt_block($uid, 'video') : '') . "\n\n"
            . ($svc ? 'الخدمة اللي البوست عنها: ' . $svc['name'] . ($svc['description'] ? ' — ' . mb_substr((string) $svc['description'], 0, 300) : '') . "\n" : '')
            . ($persona ? "اكتب للعميل المثالي ده بالذات:\n" . bk_persona_text($persona, $uid) . "\n" : '')
            . ($note !== '' ? 'طلب العميل: ' . $note . "\n" : '');
        $prompt = prompt_fill('quick_post', ['service' => $svc['name'] ?? 'النشاط كله', 'format' => $fmtLabel, 'persona' => $persona['name'] ?? 'الجمهور المستهدف',
                                             'dialect' => campaign_dialect($brand), 'data' => $data])
            . ($fmt !== 'video' ? "\n\n" . prompt_fill('design_idea', ['format' => $fmtLabel]) : '')
            . "\n\nأعد الناتج بالتنسيق ده بالظبط بدون أي تعليق:\n\n" . campaign_content_format($fmt, $slides);
        $ai = campaign_ai($prompt, $fmt === 'carousel' ? 2600 : 1800, 'campaign_content', ['user_id' => $uid, 'reference_type' => 'quick_post']);
        $p = $ai['ok'] ? campaign_parse_content(BrandContext::stripWhy((string) $ai['response'])) : null;
        if ($p) foreach (['hook', 'body', 'cta', 'script', 'tags'] as $k) $p[$k] = BrandContext::enforce((string) $p[$k], $uid);
        if ($p && $fmt === 'video' && $p['script'] === '') $p = null;
        if (!$p || ($p['body'] === '' && $p['hook'] === '')) {
            if ($cost > 0) credits_add($uid, $cost, 'استرداد: منشور سريع', null, 'refund', null);
            api_fail($ai['ok'] ? 'الرد مكانش مظبوط — الكريدت رجعلك، جرّب تاني' : ($ai['error'] ?? 'تعذّر التوليد'), 'ai_failed', 502);
        }
        $text = campaign_compose_text($p['hook'], $p['body']);
        $ff = ['format' => $fmt, 'slides_count' => $fmt === 'carousel' ? $slides : null,
               'slides_json' => $fmt === 'carousel' ? json_encode(campaign_parse_slides($p['slides_raw'], $slides), JSON_UNESCAPED_UNICODE) : null];
        if ($fmt === 'video') {
            $vb = video_brief([]);
            $vb['script'] = ['hook' => $p['hook'], 'body' => $p['script'], 'cta' => $p['cta'], 'shoot' => $p['shoot']];
            $ff += ['video_brief_json' => json_encode($vb, JSON_UNESCAPED_UNICODE), 'video_status' => 'script', 'video_status_at' => date('Y-m-d H:i:s')];
        }
        $cid = db_insert_row('contents', [
            'user_id' => $uid, 'brand_profile_id' => (int) $brand['id'], 'content_type' => $svc ? 'promotional' : 'marketing',
            'platform' => 'both', 'length' => 'medium', 'tone' => $brand['tone'] ?? null,
            'extra_notes' => mb_substr('منشور سريع' . ($svc ? ' — ' . $svc['name'] : '') . ($persona ? ' — لـ ' . $persona['name'] : '') . ($note !== '' ? ' — ' . $note : ''), 0, 500),
            'generated_text' => $text, 'hashtags' => $p['tags'] ?: null, 'cta' => $p['cta'] ?: null,
            'design_direction' => $p['design'] ?: null, 'image_prompt' => $p['image_prompt'] ?: null,
            'status' => 'generated', 'credits_used' => $cost,
        ] + $ff);
        save_content_version($cid, $text, $p['tags'] ?: null, $p['cta'] ?: null, 'ai', 'منشور سريع');
        if ($brief) BrandContext::attach($cid, $uid, $brief, BrandContext::parseWhy((string) $ai['response']), $o);
        if ($persona) {
            $bj = json_decode((string) (db_one('SELECT brief_json FROM contents WHERE id = ?', [$cid])['brief_json'] ?? ''), true) ?: [];
            $bj['persona'] = $persona['name'];
            db_run('UPDATE contents SET brief_json = ? WHERE id = ?', [json_encode($bj, JSON_UNESCAPED_UNICODE), $cid]);
        }
        if (function_exists('ai_log_usage')) ai_log_usage($uid, 'generate', $ai['model'] ?? 'router', $ai['usage'] ?? [], $cid);
        $row = db_one('SELECT * FROM contents WHERE id = ?', [$cid]);
        api_ok([
            'content_id' => $cid, 'url' => url('content-view.php?id=' . $cid), 'design_url' => url('content-view.php?id=' . $cid . '#design'),
            'format' => $fmt, 'hook' => $p['hook'], 'body' => $p['body'], 'cta' => $p['cta'], 'tags' => $p['tags'], 'design_idea' => $p['design'],
            'slides' => $fmt === 'carousel' ? content_slides($row) : [], 'script' => $fmt === 'video' ? $p['script'] : '',
            'balance' => credits_balance($uid),
        ]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
