<?php
/**
 * Spread AI — API محرك المحتوى v3 (المرحلة D)
 *
 *   GET  action=home                         حالة المحرك: الـ DNA + آخر شغلانة + الحملات + الرصيد
 *   GET  action=job      {id}                حالة شغلانة (للمتابعة كل ثانيتين)
 *   GET  action=wizard                       خيارات الـ Wizard (من الـ Brand DNA)
 *   GET  action=campaign {id}                بريف + تقدير حملة
 *   POST action=dna_build {force?}           بناء/تحديث الـ Brand DNA (شغلانة في الخلفية)
 *   POST action=dna_save  {dna}              تعديل العميل (الحقول اللي اتغيرت بتتعلّم «يدوي»)
 *   POST action=dna_undo                     الرجوع للنسخة اللي قبلها
 *   POST action=job_step  {id}               تشغيل خطوة لو الـ cron مش شغال (الصفحة مفتوحة)
 *   POST action=job_cancel {id}              إلغاء — الكريدت المحجوز بيرجع
 *   POST action=estimate {brief}             تقدير من غير حفظ
 *   POST action=campaign_save {id?, brief, partial?}  حفظ البريف + التقدير
 *   GET  action=plan {id}                    خطة الحملة: الخانات + المخالفات + الدرجة + آخر تقييم + الشغلانة (المرحلة E)
 *   POST action=campaign_start {id}          ابدأ الحملة: حجز كريدت الخطة + شغلانة الخطة والبوابة
 *   POST action=plan_rebuild {id}            إعادة بناء الخطة (الخانات المقفولة بتفضل)
 *   POST action=plan_rescore {id}            تقييم تاني بعد تعديلات العميل (الناقد بس)
 *   POST action=plan_save {id, slots[]}      ترتيب جديد + قفل + تعديل خانات (بالتحقق من القواعد في الكود)
 *   POST action=plan_approve {id}            اعتماد الخطة (الدرجة ≥ الحد ومفيش قواعد متكسّرة)
 *   GET  action=write {id}                   الكتابة: الخانات + الأفكار + المحتوى + الجودة + التقرير النهائي (المرحلة F)
 *   POST action=write_start {id}             ابدأ كتابة الحملة بعد اعتماد الخطة (حجز + شغلانة)
 *   POST action=idea_swap {id, slot_id, idea_id}  اكتب الخانة بفكرة تانية (أو نفس الفكرة من جديد)
 *   POST action=content_accept {id, slot_id}  اعتمد خانة «محتاجة مراجعة يدوية» زي ما هي
 *   GET  action=design {id}                  التصميمات لكل خانة + القوالب + الشغلانة + الحجز المتوقع (المرحلة G)
 *   POST action=design_start {id}            صمّم الحملة (اللي اتصمم قبل كده بيفضل)
 *   POST action=design_redo {id, slot_id, frame}  صورة جديدة لتصميم واحد
 *   POST action=design_text {id, slot_id, frame, headline, subline, template_id}  تعديل النص/القالب ← رسم تاني من غير كريدت
 *   POST action=design_accept {id, slot_id, frame}  اعتمد تصميم «محتاج مراجعة» زي ما هو
 *
 * المحرك متاح بس للعملاء اللي الأدمن فتحه لهم (الميزات ← content_engine).
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/ce-design.php';

$action = api_action() ?: 'home';
$user = api_boot();
$uid = (int) $user['id'];

if (!ce_ready()) api_fail('المحرك الجديد لسه مش متركّب — محتاج ترحيل 2026_ce_engine', 'not_ready', 503);
if (!ce_enabled_for($uid)) api_fail('المحرك الجديد لسه مش متاح لحسابك', 'feature', 403);

$jobOut = function (?array $j): ?array {
    return $j ? ce_job_public($j) : null;
};
// الخطة + حجز الكتابة المتوقع (لما تتعتمد)
$planOut = function (array $view, int $cid) use ($uid): array {
    $c = ce_campaign_get($cid, $uid);
    $view['write_credits'] = $c && $c['status'] === 'plan_ok' && ce_write_ready() ? ce_write_hold_credits($c) : null;
    $view['write_rules'] = ce_qa_rules();
    return $view;
};

switch ($action) {
    case 'home':
        $dnaJob = ce_job_active_for($uid, 'brand_dna');
        $camps = db_all('SELECT * FROM ce_campaigns WHERE user_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 20', [$uid]);
        api_ok([
            'dna' => ce_dna_get($uid), 'dna_state' => ce_dna_state($uid), 'dna_job' => $jobOut($dnaJob),
            'options' => ce_dna_options($uid), 'campaigns' => array_map('ce_campaign_public', $camps),
            'balance' => credits_balance($uid), 'worker' => ce_worker_alive(),
        ]);

    case 'job':
        $j = ce_job_get(api_int('id'), $uid);
        if (!$j) api_fail('مش موجودة', 'not_found', 404);
        api_ok(['job' => ce_job_public($j), 'worker' => ce_worker_alive()]);

    case 'wizard':
        if (!ce_dna_row($uid)) api_fail('ابني الـ Brand DNA الأول — منه بنجيب الخدمات والعملاء', 'no_dna', 409);
        api_ok(['options' => ce_wizard_options($uid), 'balance' => credits_balance($uid)]);

    case 'campaign':
        $c = ce_campaign_get(api_int('id'), $uid);
        if (!$c) api_fail('الحملة مش موجودة', 'not_found', 404);
        api_ok(['campaign' => ce_campaign_public($c), 'balance' => credits_balance($uid)]);

    case 'dna_build':
        $r = ce_dna_build($uid, !empty(api_input()['force']));
        if (!$r['ok']) api_fail($r['error'] ?? 'مقدرناش نبدأ', $r['code'] ?? 'error', ($r['code'] ?? '') === 'credits' ? 402 : 400);
        api_ok(['job' => ce_job_public($r['job']), 'existing' => !empty($r['existing'])]);

    case 'dna_save':
        $dna = api_input()['dna'] ?? null;
        if (!is_array($dna)) api_fail('مفيش بيانات', 'input');
        $r = ce_dna_user_save($uid, array_intersect_key($dna, array_flip(CE_DNA_KEYS)));
        api_ok(['dna' => $r['dna'], 'dna_state' => ce_dna_state($uid)]);

    case 'dna_undo':
        if (!ce_dna_undo($uid)) api_fail('مفيش نسخة قبلها', 'none', 409);
        api_ok(['dna' => ce_dna_get($uid), 'dna_state' => ce_dna_state($uid)]);

    case 'job_step':
        $id = api_int('id');
        $j = ce_job_get($id, $uid);
        if (!$j) api_fail('مش موجودة', 'not_found', 404);
        if (in_array($j['status'], ['queued', 'running'], true)) {
            $after = ce_job_step($id); // null = حد تاني (الـ cron أو تاب تاني) شغال عليها دلوقتي
            if ($after) $j = $after;
        }
        api_ok(['job' => ce_job_public($j), 'worker' => ce_worker_alive()]);

    case 'job_cancel':
        if (!ce_job_cancel(api_int('id'), $uid)) api_fail('مينفعش تتلغي دلوقتي', 'state', 409);
        api_ok(['job' => ce_job_public(ce_job_get(api_int('id'), $uid)), 'balance' => credits_balance($uid)]);

    case 'estimate':
        [$brief, $err] = ce_brief_clean($uid, (array) (api_input()['brief'] ?? []));
        if ($err) api_ok(['errors' => $err, 'estimate' => null]);
        api_ok(['errors' => [], 'estimate' => ce_estimate_public(ce_estimate($brief)), 'balance' => credits_balance($uid)]);

    case 'plan':
        $c = ce_campaign_get(api_int('id'), $uid);
        if (!$c) api_fail('الحملة مش موجودة', 'not_found', 404);
        api_ok(['plan' => $planOut(ce_plan_view($uid, $c), (int) $c['id']), 'balance' => credits_balance($uid), 'worker' => ce_worker_alive()]);

    case 'campaign_start':
    case 'plan_rebuild':
    case 'plan_rescore':
        $r = ce_plan_start($uid, api_int('id'), $action === 'plan_rescore' ? 'gate_only' : 'full');
        if (!$r['ok']) api_fail($r['error'] ?? 'مقدرناش نبدأ', $r['code'] ?? 'error', ($r['code'] ?? '') === 'credits' ? 402 : (($r['code'] ?? '') === 'not_found' ? 404 : 409));
        api_ok(['job' => ce_job_public($r['job']), 'existing' => !empty($r['existing']), 'balance' => credits_balance($uid)]);

    case 'plan_save':
        $slots = api_input()['slots'] ?? null;
        if (!is_array($slots)) api_fail('مفيش بيانات', 'input');
        $r = ce_plan_save($uid, api_int('id'), array_values($slots));
        if (!$r['ok']) api_fail($r['error'], $r['code'], $r['code'] === 'not_found' ? 404 : 409);
        api_ok(['plan' => $planOut($r['view'], api_int('id'))]);

    case 'plan_approve':
        $r = ce_plan_approve($uid, api_int('id'));
        if (!$r['ok']) api_fail($r['error'], $r['code'], $r['code'] === 'not_found' ? 404 : 409);
        api_ok(['plan' => $planOut($r['view'], api_int('id')), 'balance' => credits_balance($uid)]);

    case 'write':
        $c = ce_campaign_get(api_int('id'), $uid);
        if (!$c) api_fail('الحملة مش موجودة', 'not_found', 404);
        $wv = ce_write_view($uid, $c);
        $c = ce_campaign_get((int) $c['id'], $uid);
        api_ok(['write' => $wv, 'design' => ce_design_view($uid, $c), 'campaign' => ce_campaign_public($c), 'balance' => credits_balance($uid), 'worker' => ce_worker_alive()]);

    case 'write_start':
    case 'idea_swap':
        $r = $action === 'write_start' ? ce_write_start($uid, api_int('id'))
            : ce_write_slot($uid, api_int('id'), api_int('slot_id'), api_int('idea_id'));
        if (!$r['ok']) api_fail($r['error'] ?? 'مقدرناش نبدأ', $r['code'] ?? 'error', ($r['code'] ?? '') === 'credits' ? 402 : (($r['code'] ?? '') === 'not_found' ? 404 : 409));
        api_ok(['job' => ce_job_public($r['job']), 'existing' => !empty($r['existing']), 'balance' => credits_balance($uid)]);

    case 'content_accept':
        $c = ce_campaign_get(api_int('id'), $uid);
        if (!$c) api_fail('الحملة مش موجودة', 'not_found', 404);
        if (!ce_write_ready() || !ce_content_accept($uid, (int) $c['id'], api_int('slot_id'))) api_fail('الخانة دي مش محتاجة مراجعة', 'state', 409);
        api_ok(['write' => ce_write_view($uid, $c)]);

    case 'design':
        $c = ce_campaign_get(api_int('id'), $uid);
        if (!$c) api_fail('الحملة مش موجودة', 'not_found', 404);
        api_ok(['design' => ce_design_view($uid, $c), 'balance' => credits_balance($uid), 'worker' => ce_worker_alive()]);

    case 'design_start':
    case 'design_redo':
        $r = $action === 'design_start' ? ce_design_start($uid, api_int('id'))
            : ce_design_redo($uid, api_int('id'), api_int('slot_id'), api_int('frame'));
        if (!$r['ok']) api_fail($r['error'] ?? 'مقدرناش نبدأ', $r['code'] ?? 'error', ($r['code'] ?? '') === 'credits' ? 402 : (($r['code'] ?? '') === 'not_found' ? 404 : 409));
        api_ok(['job' => ce_job_public($r['job']), 'existing' => !empty($r['existing']), 'balance' => credits_balance($uid)]);

    case 'design_text':
    case 'design_accept':
        $c = ce_campaign_get(api_int('id'), $uid);
        if (!$c) api_fail('الحملة مش موجودة', 'not_found', 404);
        if ($action === 'design_accept') {
            if (!ce_design_ready() || !ce_design_accept($uid, (int) $c['id'], api_int('slot_id'), api_int('frame'))) api_fail('التصميم ده مش محتاج مراجعة', 'state', 409);
        } else {
            $in = api_input();
            $r = ce_design_text($uid, (int) $c['id'], api_int('slot_id'), api_int('frame'), array_intersect_key($in, array_flip(['headline', 'subline', 'template_id'])));
            if (!$r['ok']) api_fail($r['error'], $r['code'], $r['code'] === 'not_found' ? 404 : 422);
        }
        api_ok(['design' => ce_design_view($uid, $c)]);

    case 'campaign_save':
        $in = (array) (api_input()['brief'] ?? []);
        if (!empty(api_input()['partial'])) $in['partial'] = 1;
        $r = ce_campaign_save($uid, $in, api_int('id'));
        if (!$r['ok']) api_fail(reset($r['errors']) ?: 'راجع الاختيارات', 'invalid', 422, ['errors' => $r['errors']]);
        api_ok(['campaign' => ce_campaign_public($r['campaign']), 'errors' => $r['errors'], 'balance' => credits_balance($uid)]);
}

api_fail('عملية مش معروفة', 'action', 404);
