import { Feather } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Screen } from '@/components/screen';
import { AppText, Banner, Button, Card, ErrorState, IconButton, Pill, ProgressBar, SectionTitle, Skeleton } from '@/components/ui';
import { UsageCard } from '@/components/usage-card';
import { useAuth } from '@/lib/auth-store';
import { timeAgo } from '@/lib/format';
import { openRoute } from '@/lib/navigation';
import { useDashboard } from '@/lib/queries';
import { colors, radius, space } from '@/theme/tokens';

export default function Home() {
  const insets = useSafeAreaInsets();
  const user = useAuth((s) => s.user);
  const q = useDashboard();
  const d = q.data;

  const header = (
    <View style={[styles.header, { paddingTop: insets.top + 10 }]}>
      <View style={{ flex: 1 }}>
        <AppText variant="caption">{d?.greeting ?? 'أهلًا'}</AppText>
        <AppText variant="h2" numberOfLines={1}>
          {d?.first_name ?? user?.name?.split(' ')[0] ?? ''} 👋
        </AppText>
      </View>
      <IconButton icon="bell" label="الإشعارات" onPress={() => router.push('/notifications')} badge={d?.badge} />
    </View>
  );

  if (q.isError && !d) {
    return (
      <Screen header={header}>
        <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} />
      </Screen>
    );
  }

  return (
    <Screen header={header} refreshing={q.isRefetching} onRefresh={() => q.refetch()}>
      {!d ? (
        <View style={{ gap: 14 }}>
          <Skeleton height={140} radiusSize={22} />
          <Skeleton height={90} radiusSize={22} />
          <Skeleton height={180} radiusSize={22} />
        </View>
      ) : (
        <>
          {d.business ? <Pill label={d.business} icon="briefcase" tone="mute" /> : null}
          <UsageCard usage={d.usage} />

          {/* quick create */}
          <View style={styles.quickRow}>
            <QuickAction icon="edit-3" title="منشور جديد" sub="نص + هاشتاجات" onPress={() => router.push('/create')} />
            <QuickAction icon="zap" title="أفكار لمنشورات" sub="اختار فكرة" onPress={() => router.push('/ideas')} />
            <QuickAction icon="image" title="تصميم" sub="استوديو التصميم" onPress={() => router.push('/studio')} />
          </View>

          {!d.subscribed ? (
            <Card style={{ gap: 10 }}>
              <AppText variant="title">التصميم والنشر للمشتركين</AppText>
              <AppText variant="caption">اكتب منشوراتك دلوقتي — وحوّلها لتصميم وانشرها مع الاشتراك.</AppText>
              <Button title="شوف الباقات" kind="secondary" size="sm" icon="star" onPress={() => router.push('/packages')} />
            </Card>
          ) : null}

          {d.actions.length ? (
            <View style={{ gap: 8 }}>
              <SectionTitle title="محتاج منك" />
              {d.actions.map((a, i) => (
                <Card key={i} onPress={() => openRoute(a.route)} style={styles.rowCard} accessibilityLabel={`${a.n} ${a.label}`}>
                  <Pill label={String(a.n)} tone={a.tone} />
                  <AppText variant="bodyStrong" style={{ flex: 1 }}>
                    {a.label}
                  </AppText>
                  <Feather name="chevron-left" size={20} color={colors.mute3} />
                </Card>
              ))}
            </View>
          ) : null}

          {d.brand_health.pct < 90 ? (
            <Card onPress={() => router.push('/brand')} style={{ gap: 8 }} accessibilityLabel="هوية البراند">
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <AppText variant="title">هوية البراند</AppText>
                <AppText variant="label" style={{ color: colors.blue }}>{d.brand_health.pct}%</AppText>
              </View>
              <ProgressBar pct={d.brand_health.pct} />
              <AppText variant="caption">كل ما الهوية تكمل، المحتوى بيطلع أدق ومناسب لبراندك أكتر.</AppText>
            </Card>
          ) : null}

          <View>
            <SectionTitle title="آخر المنشورات" action="الكل" onAction={() => router.push('/projects')} />
            {d.recent.length === 0 ? (
              <Card style={{ alignItems: 'center', gap: 10 }}>
                <AppText variant="body">لسه معملتش منشورات — ابدأ بأول منشور.</AppText>
                <Button title="اعمل أول منشور" size="sm" icon="plus" onPress={() => router.push('/create')} />
              </Card>
            ) : (
              <View style={{ gap: 10 }}>
                {d.recent.map((r) => (
                  <Card
                    key={r.id}
                    style={styles.rowCard}
                    onPress={() => router.push({ pathname: '/content/[id]', params: { id: String(r.id) } })}
                    accessibilityLabel={r.excerpt}>
                    {r.cover ? (
                      <Image source={{ uri: r.cover }} style={styles.thumb} contentFit="cover" />
                    ) : (
                      <View style={[styles.thumb, styles.thumbEmpty]}>
                        <Feather name="file-text" size={20} color={colors.blue} />
                      </View>
                    )}
                    <View style={{ flex: 1, gap: 4 }}>
                      <AppText variant="bodyStrong" numberOfLines={2}>
                        {r.excerpt || '—'}
                      </AppText>
                      <View style={{ flexDirection: 'row', gap: 6, alignItems: 'center' }}>
                        {r.status_label ? <Pill label={r.status_label} /> : null}
                        <AppText variant="small">{timeAgo(r.created_at)}</AppText>
                      </View>
                    </View>
                  </Card>
                ))}
              </View>
            )}
          </View>

          {d.recent_designs.length ? (
            <View>
              <SectionTitle title="آخر التصميمات" action="الاستوديو" onAction={() => router.push('/studio')} />
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 10 }}>
                {d.recent_designs.map((s) => (
                  <Pressable
                    key={s.id}
                    accessibilityRole="imagebutton"
                    accessibilityLabel="فتح التصميم"
                    onPress={() => router.push({ pathname: '/design/[id]', params: { id: String(s.id) } })}>
                    {s.url ? <Image source={{ uri: s.url }} style={styles.designThumb} contentFit="cover" transition={150} /> : null}
                  </Pressable>
                ))}
              </ScrollView>
            </View>
          ) : null}

          {d.slides.map((s) => (
            <Card key={s.id} style={{ gap: 8 }} onPress={() => openRoute(s.route)} accessibilityLabel={s.title}>
              {s.image ? <Image source={{ uri: s.image }} style={{ width: '100%', aspectRatio: 2.4, borderRadius: radius.md }} contentFit="cover" /> : null}
              <AppText variant="title">{s.title}</AppText>
              {s.body ? <AppText variant="caption">{s.body}</AppText> : null}
              {s.button ? <AppText variant="label" style={{ color: colors.blue }}>{s.button}</AppText> : null}
            </Card>
          ))}
          {q.isError ? <Banner tone="warn" icon="wifi-off" text="عرض آخر بيانات محفوظة — مفيش اتصال دلوقتي" /> : null}
        </>
      )}
    </Screen>
  );
}

function QuickAction({ icon, title, sub, onPress }: { icon: React.ComponentProps<typeof Feather>['name']; title: string; sub: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={title} onPress={onPress} style={({ pressed }) => [styles.quick, pressed && { opacity: 0.85 }]}>
      <View style={styles.quickIcon}>
        <Feather name={icon} size={20} color={colors.blue} />
      </View>
      <AppText variant="label" numberOfLines={1}>
        {title}
      </AppText>
      <AppText variant="small" numberOfLines={1}>
        {sub}
      </AppText>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.lg, paddingBottom: 4, maxWidth: 672, width: '100%', alignSelf: 'center' },
  quickRow: { flexDirection: 'row', gap: 10 },
  quick: { flex: 1, backgroundColor: colors.card, borderRadius: radius.lg, padding: 12, gap: 4, borderWidth: 1, borderColor: colors.line, minHeight: 104 },
  quickIcon: { width: 38, height: 38, borderRadius: 12, backgroundColor: colors.infoBg, alignItems: 'center', justifyContent: 'center', marginBottom: 4 },
  rowCard: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 12 },
  thumb: { width: 58, height: 58, borderRadius: 12 },
  thumbEmpty: { backgroundColor: colors.infoBg, alignItems: 'center', justifyContent: 'center' },
  designThumb: { width: 130, height: 130, borderRadius: radius.lg, backgroundColor: colors.line },
});
