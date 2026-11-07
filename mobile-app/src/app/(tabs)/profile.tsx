import { Feather } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { router, type Href } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Alert, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Screen } from '@/components/screen';
import { AppText, Card, type IconName } from '@/components/ui';
import { UsageCard } from '@/components/usage-card';
import { useAuth } from '@/lib/auth-store';
import { APP_VERSION } from '@/lib/config';
import { queryClient, useBootstrap, useDashboard } from '@/lib/queries';
import { colors, space } from '@/theme/tokens';

export default function Profile() {
  const insets = useSafeAreaInsets();
  const user = useAuth((s) => s.user);
  const logout = useAuth((s) => s.logout);
  const dash = useDashboard();
  const boot = useBootstrap();

  const confirmLogout = () =>
    Alert.alert('تسجيل الخروج', 'متأكد إنك عايز تخرج من الحساب على الجهاز ده؟', [
      { text: 'إلغاء', style: 'cancel' },
      {
        text: 'خروج',
        style: 'destructive',
        onPress: async () => {
          await logout();
          queryClient.clear();
        },
      },
    ]);

  const items: { icon: IconName; label: string; href?: Href; onPress?: () => void }[] = [
    { icon: 'pie-chart', label: 'رصيدي وباقتي', href: '/credits' },
    { icon: 'star', label: 'الباقات', href: '/packages' },
    { icon: 'briefcase', label: 'هوية البراند', href: '/brand' },
    { icon: 'bell', label: 'الإشعارات', href: '/notifications' },
    { icon: 'settings', label: 'إعدادات الحساب والأمان', href: '/settings' },
    { icon: 'help-circle', label: 'المساعدة والدعم', href: '/help' },
    { icon: 'shield', label: 'سياسة الخصوصية', onPress: () => boot.data && WebBrowser.openBrowserAsync(boot.data.links.privacy) },
    { icon: 'file-text', label: 'الشروط والأحكام', onPress: () => boot.data && WebBrowser.openBrowserAsync(boot.data.links.terms) },
  ];

  return (
    <Screen
      header={
        <View style={{ paddingTop: insets.top + 10, paddingHorizontal: space.lg, maxWidth: 672, width: '100%', alignSelf: 'center' }}>
          <AppText variant="h2">حسابي</AppText>
        </View>
      }>
      <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
        {user?.avatar ? (
          <Image source={{ uri: user.avatar }} style={styles.avatar} />
        ) : (
          <View style={[styles.avatar, { backgroundColor: colors.ink, alignItems: 'center', justifyContent: 'center' }]}>
            <AppText variant="h3" style={{ color: colors.teal }}>
              {(user?.name ?? '?').trim().charAt(0)}
            </AppText>
          </View>
        )}
        <View style={{ flex: 1 }}>
          <AppText variant="title" numberOfLines={1}>
            {user?.name}
          </AppText>
          <AppText variant="caption" numberOfLines={1}>
            {user?.email}
          </AppText>
        </View>
      </Card>
      {dash.data ? <UsageCard usage={dash.data.usage} /> : null}
      <Card style={{ paddingVertical: 4 }}>
        {items.map((it, i) => (
          <Pressable
            key={it.label}
            accessibilityRole="button"
            onPress={() => (it.href ? router.push(it.href) : it.onPress?.())}
            style={({ pressed }) => [styles.item, i > 0 && styles.itemBorder, pressed && { opacity: 0.6 }]}>
            <Feather name={it.icon} size={20} color={colors.blue} />
            <AppText variant="bodyStrong" style={{ flex: 1 }}>
              {it.label}
            </AppText>
            <Feather name="chevron-left" size={18} color={colors.mute3} />
          </Pressable>
        ))}
      </Card>
      <Pressable accessibilityRole="button" onPress={confirmLogout} style={({ pressed }) => [styles.logout, pressed && { opacity: 0.7 }]}>
        <Feather name="log-out" size={19} color={colors.danger} />
        <AppText variant="bodyStrong" style={{ color: colors.danger }}>
          تسجيل الخروج
        </AppText>
      </Pressable>
      <AppText variant="small" style={{ textAlign: 'center' }}>
        Spread AI · الإصدار {APP_VERSION}
      </AppText>
    </Screen>
  );
}

const styles = StyleSheet.create({
  avatar: { width: 56, height: 56, borderRadius: 28 },
  item: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 15 },
  itemBorder: { borderTopWidth: 1, borderTopColor: colors.line },
  logout: { flexDirection: 'row', gap: 10, alignItems: 'center', justifyContent: 'center', paddingVertical: 14, borderRadius: 14, backgroundColor: colors.dangerBg },
});
