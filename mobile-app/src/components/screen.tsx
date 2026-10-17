import { Feather } from '@expo/vector-icons';
import { router } from 'expo-router';
import type { ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors, space } from '@/theme/tokens';

import { AppText } from './ui';

/**
 * Standard screen: safe areas (notch / home indicator / Android status bar), keyboard avoidance,
 * pull-to-refresh, 16px side gutters, max content width on large phones/tablets.
 */
export function Screen({
  children,
  scroll = true,
  refreshing,
  onRefresh,
  header,
  footer,
  padTop = true,
  contentStyle,
}: {
  children: ReactNode;
  scroll?: boolean;
  refreshing?: boolean;
  onRefresh?: () => void;
  header?: ReactNode;
  footer?: ReactNode;
  padTop?: boolean;
  contentStyle?: StyleProp<ViewStyle>;
}) {
  const insets = useSafeAreaInsets();
  const body = scroll ? (
    <ScrollView
      keyboardShouldPersistTaps="handled"
      keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'}
      contentContainerStyle={[styles.content, { paddingTop: padTop ? space.lg : 0, paddingBottom: (footer ? space.lg : insets.bottom + 28) }, contentStyle]}
      refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={colors.blue} colors={[colors.blue]} /> : undefined}>
      <View style={styles.inner}>{children}</View>
    </ScrollView>
  ) : (
    <View style={[styles.content, { flex: 1, paddingTop: padTop ? space.lg : 0 }, contentStyle]}>
      <View style={[styles.inner, { flex: 1 }]}>{children}</View>
    </View>
  );
  return (
    <KeyboardAvoidingView style={styles.root} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      {header}
      {body}
      {footer ? <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, 12) }]}>{footer}</View> : null}
    </KeyboardAvoidingView>
  );
}

/** Top bar for stack screens (custom so Arabic fonts + RTL back arrow are consistent on both platforms) */
export function TopBar({ title, right, back = true }: { title: string; right?: ReactNode; back?: boolean }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={[styles.topbar, { paddingTop: insets.top + 6 }]}>
      <View style={styles.topRow}>
        {back ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="رجوع"
            hitSlop={10}
            onPress={() => (router.canGoBack() ? router.back() : router.replace('/home'))}
            style={styles.backBtn}>
            <Feather name="arrow-right" size={22} color={colors.ink} />
          </Pressable>
        ) : (
          <View style={{ width: 8 }} />
        )}
        <AppText variant="h3" numberOfLines={1} style={{ flex: 1 }} accessibilityRole="header">
          {title}
        </AppText>
        {right}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.bg },
  content: { paddingHorizontal: space.lg },
  inner: { width: '100%', maxWidth: 640, alignSelf: 'center', gap: space.lg },
  footer: { paddingHorizontal: space.lg, paddingTop: 12, backgroundColor: colors.card, borderTopWidth: 1, borderTopColor: colors.line },
  topbar: { backgroundColor: colors.bg, paddingHorizontal: space.md, paddingBottom: 6 },
  topRow: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 48, maxWidth: 672, width: '100%', alignSelf: 'center' },
  backBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
});
