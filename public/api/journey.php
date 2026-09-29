<?php
/**
 * Spread AI v2 — API «رحلتك الأولى» (المرحلة ⑦)
 *   GET  action=state                      الخطوات من الداتا الفعلية
 *   POST action=start_campaign {basis}      أول حملة (الاسم من الشهر)
 *   POST action=mark_export                 خطوة النشر لمين مالوش نشر تلقائي (صدّر المحتوى)
 * باقي الخطوات بتستخدم نفس الـ APIs بتاعة صفحاتها (brand · campaign-flow · generate-design)
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/journey.php';
require_once __DIR__ . '/../../includes/campaign-flow.php';

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'state';

switch ($action) {
    case 'state':
        api_ok(['journey' => journey_state($uid), 'balance' => credits_balance($uid)]);

    case 'start_campaign':
        if (get_setting('campaigns_enabled', '1') !== '1') api_fail('الحملات مش متاحة حاليًا', 'disabled', 403);
        $basis = api_str('basis', 20);
        if (!array_key_exists($basis, campaign_flow_bases())) api_fail('اختار هدف الحملة', 'invalid', 422);
        // مانعملش حملة تانية لو فيه حملة رحلة فاضية لسه
        $c = journey_campaign($uid);
        if ($c && $c['status'] === 'active' && (campaign_flow_counts((int) $c['id'])['ideas'] ?? 0) === 0) {
            db_run('UPDATE campaigns SET basis = ?, revision = revision + 1 WHERE id = ?', [$basis, $c['id']]);
        } else {
            $open = (int) (db_one('SELECT COUNT(*) n FROM campaigns WHERE user_id = ? AND status = "active"', [$uid])['n'] ?? 0);
            if ($open >= 30) api_fail('عندك 30 حملة نشطة — خلّص أو أرشف واحدة الأول', 'limit', 422);
            $id = db_insert('INSERT INTO campaigns (user_id, title, basis, ideas_count, period_month) VALUES (?,?,?,?,?)',
                [$uid, 'حملة ' . arabic_month_name((int) date('n')), $basis, 5, date('Y-m')]);
            $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
        }
        journey_flag($c);
        api_ok(['journey' => journey_state($uid)]);

    case 'mark_export':
        $c = journey_campaign($uid);
        if (!$c) api_fail('ابدأ حملة الأول', 'no_campaign', 422);
        $prefs = campaign_prefs($c);
        $prefs['journey_exported'] = 1;
        db_run('UPDATE campaigns SET prefs_json = ? WHERE id = ?', [json_encode($prefs, JSON_UNESCAPED_UNICODE), $c['id']]);
        api_ok(['journey' => journey_state($uid), 'export' => url('campaign-export.php?id=' . (int) $c['id'])]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
