<?php
/**
 * Spread AI — «عملاء بنديرهم» (الأدمن): علامة ⭐ على العميل اللي الفريق بيدير حسابه
 * بيظهروا الأول في صفحة العملاء + فلتر ليهم. محفوظة في الإعدادات (managed_client_ids) — من غير ترحيل.
 */
function mc_ids(bool $fresh = false): array
{
    static $ids = null;
    if ($ids !== null && !$fresh) return $ids;
    $v = json_decode((string) get_setting('managed_client_ids', '[]'), true);
    return $ids = array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))));
}

function mc_is(int $uid): bool
{
    return in_array($uid, mc_ids(), true);
}

/** ⭐ / شيل العلامة */
function mc_set(int $uid, bool $on): void
{
    $ids = mc_ids(true);
    $ids = $on ? array_values(array_unique(array_merge($ids, [$uid]))) : array_values(array_diff($ids, [$uid]));
    set_setting('managed_client_ids', json_encode(array_map('intval', $ids)));
    mc_ids(true);
}

/** قايمة الأرقام لـ SQL (FIND_IN_SET) — «0» لو فاضية */
function mc_csv(): string
{
    return implode(',', mc_ids()) ?: '0';
}
