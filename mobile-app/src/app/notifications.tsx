import { Feather } from '@expo/vector-icons';
import { useQueryClient } from '@tanstack/react-query';
import { Pressable, View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Card, EmptyState, ErrorState, SectionTitle, SkeletonList, type IconName } from '@/components/ui';
import { api } from '@/lib/api';
import { timeAgo } from '@/lib/format';
import { openRoute } from '@/lib/navigation';
import { qk, useNotifications } from '@/lib/queries';
import { colors, toneColors } from '@/theme/tokens';

const ICONS: Record<string, IconName> = { alert: 'alert-triangle', check: 'check-circle', bell: 'bell', edit: 'edit-3', image: 'image', camera: 'video', coin: 'pie-chart', megaphone: 'volume-2' };

export default function Notifications() {
  const q = useNotifications();
  const qc = useQueryClient();
  const d = q.data;

  const markAll = async () => {
    await api.app('notif_read', { method: 'POST', json: {} }).catch(() => undefined);
    void qc.invalidateQueries({ queryKey: qk.notifications });
    void qc.invalidateQueries({ queryKey: qk.dashboard });
  };

  return (
    <Screen
      header={
        <TopBar
          title="الإشعارات"
          right={
            d?.history.some((h) => !h.read) ? (
              <Pressable accessibilityRole="button" onPress={markAll} hitSlop={10}>
                <AppText variant="label" style={{ color: colors.blue }}>
                  قراءة الكل
                </AppText>
              </Pressable>
            ) : null
          }
        />
      }
      refreshing={q.isRefetching}
      onRefresh={() => q.refetch()}>
      {q.isLoading ? <SkeletonList rows={4} /> : null}
      {q.isError ? <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} /> : null}
      {d ? (
        <>
          <View style={{ gap: 8 }}>
            <SectionTitle title="دلوقتي" />
            {d.items.map((n, i) => {
              const c = toneColors(n.tone);
              return (
                <Card key={i} onPress={() => openRoute(n.route)} style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }} accessibilityLabel={n.title}>
                  <View style={{ width: 40, height: 40, borderRadius: 12, backgroundColor: c.bg, alignItems: 'center', justifyContent: 'center' }}>
                    <Feather name={ICONS[n.icon] ?? 'bell'} size={18} color={c.fg} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <AppText variant="bodyStrong">{n.title}</AppText>
                    {n.sub ? <AppText variant="small">{n.sub}</AppText> : null}
                  </View>
                </Card>
              );
            })}
          </View>
          <View style={{ gap: 8 }}>
            <SectionTitle title="السجل" />
            {!d.history.length ? <EmptyState icon="bell-off" title="مفيش إشعارات" body="إشعارات الدفع والباقة والنشر بتظهر هنا" /> : null}
            {d.history.map((h) => (
              <Card
                key={h.id}
                onPress={async () => {
                  if (!h.read) {
                    await api.app('notif_read', { method: 'POST', json: { id: h.id } }).catch(() => undefined);
                    void qc.invalidateQueries({ queryKey: qk.notifications });
                  }
                  if (h.route.kind !== 'notification') openRoute(h.route);
                }}
                style={{ gap: 4, borderColor: h.read ? colors.line : colors.blue }}
                accessibilityLabel={h.title}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
                  <AppText variant="bodyStrong" style={{ flex: 1 }}>
                    {!h.read ? '● ' : ''}
                    {h.title}
                  </AppText>
                  <AppText variant="small">{timeAgo(h.created_at)}</AppText>
                </View>
                {h.body ? <AppText variant="caption">{h.body}</AppText> : null}
              </Card>
            ))}
          </View>
        </>
      ) : null}
    </Screen>
  );
}
