import { Feather } from '@expo/vector-icons';
import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Screen } from '@/components/screen';
import { AppText, Banner, Button, Card, EmptyState, Pill, SectionTitle, SkeletonList } from '@/components/ui';
import { api } from '@/lib/api';
import { openOnWebsite } from '@/lib/navigation';
import { useSettingsHome } from '@/lib/queries';
import type { LibraryResp } from '@/lib/types';
import { colors, space } from '@/theme/tokens';

/** Connected Facebook pages (+ linked Instagram), scheduled posts and failures — real backend state only */
export default function Publishing() {
  const insets = useSafeAreaInsets();
  const settings = useSettingsHome();
  const scheduled = useQuery({ queryKey: ['library', { status: 'scheduled' }], queryFn: () => api.call<LibraryResp>('contents', { query: { action: 'library', status: 'scheduled' } }) });
  const failed = useQuery({ queryKey: ['library', { status: 'publish_failed' }], queryFn: () => api.call<LibraryResp>('contents', { query: { action: 'library', status: 'publish_failed' } }) });
  const ready = useQuery({ queryKey: ['library', { status: 'design_ready' }], queryFn: () => api.call<LibraryResp>('contents', { query: { action: 'library', status: 'design_ready' } }) });
  const [msg, setMsg] = useState<string | null>(null);
  const acc = settings.data?.accounts;

  const refresh = () => {
    void settings.refetch();
    void scheduled.refetch();
    void failed.refetch();
    void ready.refetch();
  };

  const connect = async () => {
    setMsg(null);
    try {
      // Facebook's permission screen must run in a browser (OAuth); the site opens already signed in
      await openOnWebsite('social-accounts.php');
      refresh();
    } catch (e) {
      setMsg((e as Error).message);
    }
  };

  return (
    <Screen
      header={
        <View style={{ paddingTop: insets.top + 10, paddingHorizontal: space.lg, maxWidth: 672, width: '100%', alignSelf: 'center' }}>
          <AppText variant="h2">النشر</AppText>
          <AppText variant="caption">صفحاتك المربوطة والمنشورات المجدولة</AppText>
        </View>
      }
      refreshing={settings.isRefetching}
      onRefresh={refresh}>
      {msg ? <Banner tone="danger" text={msg} /> : null}
      <View>
        <SectionTitle title="الصفحات المربوطة" />
        {settings.isLoading ? <SkeletonList rows={1} /> : null}
        {acc && !acc.allowed ? (
          <Card style={{ gap: 8 }}>
            <AppText variant="title">النشر التلقائي مش مفعّل لحسابك</AppText>
            <AppText variant="caption">تقدر تنسخ المنشور وتحفظ التصميم وتنشرهم بنفسك — أو كلم الدعم لتفعيل النشر التلقائي.</AppText>
          </Card>
        ) : null}
        {acc?.allowed ? (
          <View style={{ gap: 10 }}>
            {acc.pages.map((p) => (
              <Card key={p.id} style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                {p.avatar ? <Image source={{ uri: p.avatar }} style={{ width: 44, height: 44, borderRadius: 22 }} /> : <Feather name="facebook" size={28} color={colors.blue} />}
                <View style={{ flex: 1, gap: 4 }}>
                  <AppText variant="bodyStrong" numberOfLines={1}>
                    {p.name}
                  </AppText>
                  <View style={{ flexDirection: 'row', gap: 6 }}>
                    <Pill label="فيسبوك" tone="info" />
                    {p.has_ig ? <Pill label={p.ig ? `@${p.ig}` : 'إنستجرام'} tone="violet" /> : null}
                    {p.status !== 'active' ? <Pill label="محتاج إعادة ربط" tone="danger" /> : null}
                  </View>
                </View>
              </Card>
            ))}
            {!acc.pages.length ? <EmptyState icon="link" title="مفيش صفحات مربوطة" body="اربط صفحة فيسبوك (والإنستجرام المرتبط بيها) علشان تنشر وتجدول من التطبيق." /> : null}
            <Button title={acc.pages.length ? 'إدارة الصفحات' : 'اربط صفحة'} icon="link" kind="secondary" onPress={connect} />
            <AppText variant="small">الربط بيفتح صفحة فيسبوك الرسمية في المتصفح للموافقة على الصلاحيات.</AppText>
          </View>
        ) : null}
      </View>

      <ListBlock title="جاهز للنشر" data={ready.data} empty="مفيش منشورات تصميمها جاهز ومستنية النشر" />
      <ListBlock title="مجدول" data={scheduled.data} empty="مفيش منشورات مجدولة" />
      <ListBlock title="فشل النشر" data={failed.data} empty="مفيش أخطاء نشر 👌" danger />
    </Screen>
  );
}

function ListBlock({ title, data, empty, danger }: { title: string; data?: LibraryResp; empty: string; danger?: boolean }) {
  return (
    <View>
      <SectionTitle title={`${title}${data?.total ? ` (${data.total})` : ''}`} />
      {!data ? <SkeletonList rows={1} /> : null}
      {data && !data.items.length ? <AppText variant="caption">{empty}</AppText> : null}
      <View style={{ gap: 8 }}>
        {data?.items.slice(0, 8).map((i) => (
          <Card key={i.id} onPress={() => router.push({ pathname: '/content/[id]', params: { id: String(i.id) } })} style={{ gap: 6 }} accessibilityLabel={i.hook}>
            <AppText variant="bodyStrong" numberOfLines={2}>
              {i.hook}
            </AppText>
            {i.scheduled ? <AppText variant="small">🕒 {i.scheduled}</AppText> : null}
            {danger && i.fail ? (
              <AppText variant="small" style={{ color: colors.danger }} numberOfLines={2}>
                {i.fail}
              </AppText>
            ) : null}
          </Card>
        ))}
      </View>
    </View>
  );
}
