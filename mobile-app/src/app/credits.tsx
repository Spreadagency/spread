import { router } from 'expo-router';
import { View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Button, Card, Divider, ErrorState, Pill, ProgressBar, SectionTitle, SkeletonList } from '@/components/ui';
import { UsageCard } from '@/components/usage-card';
import { fmtDate } from '@/lib/format';
import { useCredits } from '@/lib/queries';
import { colors } from '@/theme/tokens';

const PAY_STATUS: Record<string, { label: string; tone: string }> = {
  pending: { label: 'قيد المراجعة', tone: 'warn' },
  under_review: { label: 'قيد المراجعة', tone: 'warn' },
  info_requested: { label: 'مطلوب معلومات', tone: 'warn' },
  approved: { label: 'اتفعّلت', tone: 'ok' },
  rejected: { label: 'مرفوض', tone: 'danger' },
  cancelled: { label: 'ملغي', tone: 'mute' },
};

/** Balance, plan quotas and history — read-only; the server is the only source of truth for credits */
export default function Credits() {
  const q = useCredits();
  const d = q.data;
  return (
    <Screen header={<TopBar title="رصيدي وباقتي" />} refreshing={q.isRefetching} onRefresh={() => q.refetch()}>
      {q.isLoading ? <SkeletonList rows={3} /> : null}
      {q.isError ? <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} /> : null}
      {d ? (
        <>
          <UsageCard usage={d.usage} onPress={() => router.push('/packages')} />
          <Card style={{ gap: 12 }}>
            <SectionTitle title="حصص الشهر" />
            {d.usage.units.map((u) => (
              <View key={u.key} style={{ gap: 6 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <AppText variant="bodyStrong">
                    {u.emoji} {u.label}
                  </AppText>
                  <AppText variant="caption">{u.open ? `${u.used} · مفتوح` : `${u.used} من ${u.limit}`}</AppText>
                </View>
                {!u.open ? <ProgressBar pct={u.pct ?? 0} color={(u.pct ?? 0) >= 90 ? colors.danger : colors.blue} /> : null}
              </View>
            ))}
          </Card>

          {d.usage.show_numbers ? (
            <Card style={{ gap: 8 }}>
              <SectionTitle title="تكلفة كل عملية" />
              {[
                ['منشور', d.costs.content_generation_cost],
                ['إعادة توليد', d.costs.content_regeneration_cost],
                ['تصميم', d.costs.content_design_cost],
                ['تعديل بالكلام', d.costs.ai_edit_cost],
                ['لوجو', d.costs.logo_generate_cost],
              ].map(([l, v]) => (
                <View key={String(l)} style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <AppText variant="body">{l}</AppText>
                  <AppText variant="bodyStrong">{v} كريدت</AppText>
                </View>
              ))}
            </Card>
          ) : null}

          {d.payments.length ? (
            <Card style={{ gap: 10 }}>
              <SectionTitle title="طلبات الدفع" />
              {d.payments.map((p) => (
                <View key={p.id} style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                  <View>
                    <AppText variant="bodyStrong">{p.package || `طلب #${p.id}`}</AppText>
                    <AppText variant="small">{fmtDate(p.created_at)}</AppText>
                  </View>
                  <Pill label={PAY_STATUS[p.status]?.label ?? p.status} tone={PAY_STATUS[p.status]?.tone} />
                </View>
              ))}
            </Card>
          ) : null}

          <Card style={{ gap: 4 }}>
            <SectionTitle title="سجل الحركات" />
            {d.history.length === 0 ? <AppText variant="caption">مفيش حركات لسه.</AppText> : null}
            {d.history.map((h, i) => (
              <View key={h.id}>
                {i > 0 ? <Divider /> : null}
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: 10 }}>
                  <View style={{ flex: 1 }}>
                    <AppText variant="bodyStrong" numberOfLines={1}>
                      {h.label}
                    </AppText>
                    <AppText variant="small">{fmtDate(h.created_at, true)}</AppText>
                  </View>
                  {h.amount !== null ? (
                    <AppText variant="title" style={{ color: h.type === 'add' ? colors.success : colors.ink2 }}>
                      {h.type === 'add' ? '+' : '−'}
                      {Math.abs(h.amount)}
                    </AppText>
                  ) : (
                    <Pill label={h.type === 'add' ? 'إضافة' : 'استخدام'} tone={h.type === 'add' ? 'ok' : 'mute'} />
                  )}
                </View>
              </View>
            ))}
          </Card>
          <Button title="الباقات" icon="star" kind="secondary" onPress={() => router.push('/packages')} />
        </>
      ) : null}
    </Screen>
  );
}
