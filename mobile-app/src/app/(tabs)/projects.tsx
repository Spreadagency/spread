import { Feather } from '@expo/vector-icons';
import { useInfiniteQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AppText, EmptyState, ErrorState, Pill, SkeletonList } from '@/components/ui';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth-store';
import { qk } from '@/lib/queries';
import type { LibraryItem, LibraryResp } from '@/lib/types';
import { colors, fonts, radius, shadow, space } from '@/theme/tokens';

const FILTERS: { key: string; label: string }[] = [
  { key: '', label: 'الكل' },
  { key: 'content_ready', label: 'جاهز' },
  { key: 'needs_design', label: 'محتاج تصميم' },
  { key: 'design_ready', label: 'التصميم جاهز' },
  { key: 'scheduled', label: 'مجدول' },
  { key: 'published', label: 'منشور' },
  { key: 'publish_failed', label: 'فشل النشر' },
];

export default function Projects() {
  const insets = useSafeAreaInsets();
  const signedIn = useAuth((s) => s.status === 'signedIn');
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [q, setQ] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setQ(search.trim()), 400);
    return () => clearTimeout(t);
  }, [search]);

  const list = useInfiniteQuery({
    queryKey: qk.library({ status, q }),
    queryFn: ({ pageParam }) => api.call<LibraryResp>('contents', { query: { action: 'library', status, q, page: pageParam } }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.more ? last.page + 1 : undefined),
    enabled: signedIn,
  });
  const items = list.data?.pages.flatMap((p) => p.items) ?? [];
  const total = list.data?.pages[0]?.total ?? 0;

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <View style={[styles.head, { paddingTop: insets.top + 10 }]}>
        <View style={styles.headInner}>
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
            <AppText variant="h2">مشاريعي</AppText>
            {total ? <AppText variant="caption">{total} منشور</AppText> : null}
          </View>
          <View style={styles.search}>
            <Feather name="search" size={18} color={colors.mute3} />
            <TextInput
              value={search}
              onChangeText={setSearch}
              placeholder="دوّر في المنشورات والهاشتاجات"
              placeholderTextColor={colors.mute3}
              style={styles.searchInput}
              returnKeyType="search"
              accessibilityLabel="بحث في المنشورات"
            />
            {search ? (
              <Pressable onPress={() => setSearch('')} accessibilityLabel="مسح البحث" hitSlop={10}>
                <Feather name="x" size={18} color={colors.mute2} />
              </Pressable>
            ) : null}
          </View>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
            {FILTERS.map((f) => (
              <Pressable
                key={f.key}
                accessibilityRole="button"
                accessibilityState={{ selected: f.key === status }}
                onPress={() => setStatus(f.key)}
                style={[styles.filter, f.key === status && styles.filterOn]}>
                <AppText variant="label" style={{ color: f.key === status ? colors.white : colors.ink2 }}>
                  {f.label}
                </AppText>
              </Pressable>
            ))}
          </ScrollView>
        </View>
      </View>

      {list.isLoading ? (
        <View style={{ padding: space.lg }}>
          <SkeletonList rows={5} />
        </View>
      ) : list.isError && !items.length ? (
        <ErrorState message={(list.error as Error).message} onRetry={() => list.refetch()} />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(i) => String(i.id)}
          contentContainerStyle={{ padding: space.lg, gap: 12, paddingBottom: 40, maxWidth: 672, width: '100%', alignSelf: 'center' }}
          renderItem={({ item }) => <Row item={item} />}
          onEndReachedThreshold={0.4}
          onEndReached={() => list.hasNextPage && !list.isFetchingNextPage && list.fetchNextPage()}
          refreshControl={<RefreshControl refreshing={list.isRefetching && !list.isFetchingNextPage} onRefresh={() => list.refetch()} tintColor={colors.blue} />}
          ListFooterComponent={list.isFetchingNextPage ? <ActivityIndicator color={colors.blue} style={{ margin: 16 }} /> : null}
          ListEmptyComponent={
            q || status ? (
              <EmptyState icon="search" title="مفيش نتايج" body="جرّب كلمة تانية أو فلتر تاني" />
            ) : (
              <EmptyState icon="layers" title="لسه مفيش مشاريع" body="كل منشور بتعمله بيتحفظ هنا تلقائيًا" action="اعمل أول منشور" onAction={() => router.push('/create')} />
            )
          }
        />
      )}
    </View>
  );
}

function Row({ item }: { item: LibraryItem }) {
  const tone = item.status.key === 'publish_failed' ? 'danger' : item.status.key === 'published' ? 'ok' : item.status.key === 'scheduled' ? 'violet' : 'info';
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${item.hook}، ${item.status.label}`}
      onPress={() => router.push({ pathname: '/content/[id]', params: { id: String(item.id) } })}
      style={({ pressed }) => [styles.row, pressed && { opacity: 0.9 }]}>
      {item.cover ? (
        <Image source={{ uri: item.cover }} style={styles.cover} contentFit="cover" transition={150} />
      ) : (
        <View style={[styles.cover, { backgroundColor: colors.infoBg, alignItems: 'center', justifyContent: 'center' }]}>
          <AppText style={{ fontSize: 24 }}>{item.format_emoji}</AppText>
        </View>
      )}
      <View style={{ flex: 1, gap: 6 }}>
        <AppText variant="bodyStrong" numberOfLines={2}>
          {item.hook || '—'}
        </AppText>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
          <Pill label={item.status.label} tone={tone} />
          <AppText variant="small">
            {item.type_label} · {item.ago}
          </AppText>
        </View>
        {item.fail ? (
          <AppText variant="small" style={{ color: colors.danger }} numberOfLines={1}>
            {item.fail}
          </AppText>
        ) : null}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  head: { backgroundColor: colors.bg, paddingHorizontal: space.lg, paddingBottom: 8 },
  headInner: { gap: 12, maxWidth: 640, width: '100%', alignSelf: 'center' },
  search: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: colors.card, borderRadius: radius.md, paddingHorizontal: 12, borderWidth: 1, borderColor: colors.line2 },
  searchInput: { flex: 1, height: 46, fontFamily: fonts.body, fontSize: 15, color: colors.ink, writingDirection: 'rtl' },
  filter: { paddingHorizontal: 14, paddingVertical: 8, borderRadius: radius.pill, backgroundColor: colors.card, borderWidth: 1, borderColor: colors.line2 },
  filterOn: { backgroundColor: colors.ink, borderColor: colors.ink },
  row: { flexDirection: 'row', gap: 12, backgroundColor: colors.card, borderRadius: radius.lg, padding: 10, borderWidth: 1, borderColor: colors.line, ...shadow.card },
  cover: { width: 76, height: 76, borderRadius: 12 },
});
