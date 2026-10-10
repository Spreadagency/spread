import { Feather } from '@expo/vector-icons';
import * as Haptics from 'expo-haptics';
import { LinearGradient } from 'expo-linear-gradient';
import { forwardRef, useEffect, useState, type ComponentProps, type ReactNode } from 'react';
import {
  ActivityIndicator,
  Animated,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
  type StyleProp,
  type TextInputProps,
  type TextProps,
  type TextStyle,
  type ViewStyle,
} from 'react-native';

import { colors, fonts, gradients, radius, shadow, space, toneColors } from '@/theme/tokens';

export type IconName = ComponentProps<typeof Feather>['name'];

/* ───────── Text ───────── */

type Variant = 'h1' | 'h2' | 'h3' | 'title' | 'body' | 'bodyStrong' | 'caption' | 'label' | 'small';

const variantStyle: Record<Variant, TextStyle> = {
  h1: { fontFamily: fonts.displayBold, fontSize: 26, lineHeight: 38, color: colors.ink },
  h2: { fontFamily: fonts.displayBold, fontSize: 21, lineHeight: 32, color: colors.ink },
  h3: { fontFamily: fonts.display, fontSize: 17, lineHeight: 27, color: colors.ink },
  title: { fontFamily: fonts.bodyBold, fontSize: 16, lineHeight: 25, color: colors.ink },
  body: { fontFamily: fonts.body, fontSize: 15, lineHeight: 25, color: colors.ink2 },
  bodyStrong: { fontFamily: fonts.bodyMedium, fontSize: 15, lineHeight: 25, color: colors.ink },
  caption: { fontFamily: fonts.body, fontSize: 13, lineHeight: 21, color: colors.mute2 },
  label: { fontFamily: fonts.bodyMedium, fontSize: 13, lineHeight: 20, color: colors.ink2 },
  small: { fontFamily: fonts.body, fontSize: 11.5, lineHeight: 18, color: colors.mute3 },
};

export function AppText({ variant = 'body', style, ...rest }: TextProps & { variant?: Variant }) {
  return <Text {...rest} style={[styles.textBase, variantStyle[variant], style]} />;
}

/* ───────── Buttons ───────── */

type ButtonKind = 'primary' | 'secondary' | 'ghost' | 'danger' | 'dark' | 'outlineLight';

export function Button({
  title,
  onPress,
  kind = 'primary',
  icon,
  loading,
  disabled,
  style,
  size = 'md',
  accessibilityHint,
}: {
  title: string;
  onPress?: () => void;
  kind?: ButtonKind;
  icon?: IconName;
  loading?: boolean;
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
  size?: 'md' | 'sm' | 'lg';
  accessibilityHint?: string;
}) {
  const isDisabled = disabled || loading;
  const h = size === 'lg' ? 56 : size === 'sm' ? 38 : 50;
  const fg = kind === 'primary' || kind === 'danger' || kind === 'dark' || kind === 'outlineLight' ? colors.white : kind === 'secondary' ? colors.blue : colors.ink2;
  const inner = (
    <View style={[styles.btnInner, { height: h, paddingHorizontal: size === 'sm' ? 14 : 20 }]}>
      {loading ? <ActivityIndicator color={fg} /> : icon ? <Feather name={icon} size={size === 'sm' ? 16 : 19} color={fg} /> : null}
      <Text style={[styles.btnText, { color: fg, fontSize: size === 'sm' ? 14 : 16 }]} numberOfLines={1}>
        {title}
      </Text>
    </View>
  );
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={title}
      accessibilityHint={accessibilityHint}
      accessibilityState={{ disabled: !!isDisabled, busy: !!loading }}
      disabled={isDisabled}
      onPress={() => {
        if (Platform.OS !== 'web') void Haptics.selectionAsync();
        onPress?.();
      }}
      style={({ pressed }) => [
        styles.btn,
        kind === 'primary' && shadow.raised,
        kind === 'secondary' && styles.btnSecondary,
        kind === 'ghost' && styles.btnGhost,
        kind === 'danger' && { backgroundColor: colors.danger },
        kind === 'dark' && { backgroundColor: colors.ink },
        kind === 'outlineLight' && { backgroundColor: 'rgba(255,255,255,0.08)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.45)' },
        { opacity: isDisabled ? 0.55 : pressed ? 0.88 : 1, transform: [{ scale: pressed ? 0.985 : 1 }] },
        style,
      ]}>
      {kind === 'primary' ? (
        <LinearGradient colors={gradients.brand} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={{ borderRadius: radius.md }}>
          {inner}
        </LinearGradient>
      ) : (
        inner
      )}
    </Pressable>
  );
}

export function IconButton({
  icon,
  onPress,
  label,
  color = colors.ink,
  bg = colors.card,
  badge,
}: {
  icon: IconName;
  onPress: () => void;
  label: string;
  color?: string;
  bg?: string;
  badge?: number;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      hitSlop={8}
      onPress={onPress}
      style={({ pressed }) => [styles.iconBtn, { backgroundColor: bg, opacity: pressed ? 0.7 : 1 }]}>
      <Feather name={icon} size={20} color={color} />
      {badge ? (
        <View style={styles.badgeDot}>
          <Text style={styles.badgeText}>{badge > 9 ? '9+' : badge}</Text>
        </View>
      ) : null}
    </Pressable>
  );
}

/* ───────── Surfaces ───────── */

export function Card({ children, style, onPress, accessibilityLabel }: { children: ReactNode; style?: StyleProp<ViewStyle>; onPress?: () => void; accessibilityLabel?: string }) {
  if (onPress) {
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        onPress={onPress}
        style={({ pressed }) => [styles.card, style, pressed && { opacity: 0.92, transform: [{ scale: 0.995 }] }]}>
        {children}
      </Pressable>
    );
  }
  return <View style={[styles.card, style]}>{children}</View>;
}

export function GradientCard({ children, style, kind = 'navy' }: { children: ReactNode; style?: StyleProp<ViewStyle>; kind?: 'navy' | 'brand' }) {
  return (
    <LinearGradient colors={gradients[kind]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[styles.gradCard, style]}>
      {children}
    </LinearGradient>
  );
}

export function SectionTitle({ title, action, onAction }: { title: string; action?: string; onAction?: () => void }) {
  return (
    <View style={styles.sectionRow}>
      <AppText variant="h3">{title}</AppText>
      {action ? (
        <Pressable accessibilityRole="button" onPress={onAction} hitSlop={10}>
          <AppText variant="label" style={{ color: colors.blue }}>
            {action}
          </AppText>
        </Pressable>
      ) : null}
    </View>
  );
}

export function Pill({ label, tone, icon }: { label: string; tone?: string; icon?: IconName }) {
  const c = toneColors(tone);
  return (
    <View style={[styles.pill, { backgroundColor: c.bg }]}>
      {icon ? <Feather name={icon} size={12} color={c.fg} /> : null}
      <Text style={[styles.pillText, { color: c.fg }]} numberOfLines={1}>
        {label}
      </Text>
    </View>
  );
}

export function ProgressBar({ pct, color = colors.blue, height = 8 }: { pct: number; color?: string; height?: number }) {
  const w = Math.max(0, Math.min(100, pct));
  return (
    <View style={[styles.track, { height }]} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: w }}>
      <View style={{ width: `${w}%`, height, borderRadius: height, backgroundColor: color }} />
    </View>
  );
}

/* ───────── Form controls ───────── */

export const Input = forwardRef<TextInput, TextInputProps & { label?: string; error?: string | null; hint?: string; icon?: IconName }>(
  function Input({ label, error, hint, icon, style, multiline, ...rest }, ref) {
    return (
      <View style={{ gap: 6 }}>
        {label ? <AppText variant="label">{label}</AppText> : null}
        <View style={[styles.inputWrap, multiline && { alignItems: 'flex-start' }, error ? { borderColor: colors.danger } : null]}>
          {icon ? <Feather name={icon} size={18} color={colors.mute3} style={{ marginTop: multiline ? 14 : 0 }} /> : null}
          <TextInput
            ref={ref}
            placeholderTextColor={colors.mute3}
            multiline={multiline}
            accessibilityLabel={label ?? rest.placeholder}
            style={[styles.input, multiline && { minHeight: 110, textAlignVertical: 'top', paddingTop: 13 }, style]}
            {...rest}
          />
        </View>
        {error ? (
          <AppText variant="small" style={{ color: colors.danger }}>
            {error}
          </AppText>
        ) : hint ? (
          <AppText variant="small">{hint}</AppText>
        ) : null}
      </View>
    );
  },
);

export function ChipGroup<T extends string>({
  options,
  value,
  onChange,
  label,
}: {
  options: { key: T; label: string; emoji?: string }[];
  value: T | null;
  onChange: (v: T) => void;
  label?: string;
}) {
  return (
    <View style={{ gap: 8 }}>
      {label ? <AppText variant="label">{label}</AppText> : null}
      <View style={styles.chips} accessibilityRole="radiogroup">
        {options.map((o) => {
          const on = o.key === value;
          return (
            <Pressable
              key={o.key}
              accessibilityRole="radio"
              accessibilityState={{ selected: on }}
              accessibilityLabel={o.label}
              onPress={() => onChange(o.key)}
              style={[styles.chip, on && styles.chipOn]}>
              <Text style={[styles.chipText, on && { color: colors.white }]}>{o.emoji ? `${o.emoji} ${o.label}` : o.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

export function SwitchRow({ label, value, onChange, hint }: { label: string; value: boolean; onChange: (v: boolean) => void; hint?: string }) {
  return (
    <Pressable
      accessibilityRole="switch"
      accessibilityState={{ checked: value }}
      accessibilityLabel={label}
      onPress={() => onChange(!value)}
      style={styles.switchRow}>
      <View style={{ flex: 1 }}>
        <AppText variant="bodyStrong">{label}</AppText>
        {hint ? <AppText variant="small">{hint}</AppText> : null}
      </View>
      <View style={[styles.switchTrack, value && { backgroundColor: colors.blue }]}>
        <View style={[styles.switchKnob, value ? { alignSelf: 'flex-end' } : { alignSelf: 'flex-start' }]} />
      </View>
    </Pressable>
  );
}

/* ───────── States ───────── */

export function Skeleton({ height = 16, width = '100%', radiusSize = 10, style }: { height?: number; width?: number | `${number}%`; radiusSize?: number; style?: StyleProp<ViewStyle> }) {
  const [op] = useState(() => new Animated.Value(0.5));
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(op, { toValue: 1, duration: 700, useNativeDriver: true }),
        Animated.timing(op, { toValue: 0.5, duration: 700, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [op]);
  return <Animated.View style={[{ height, width, borderRadius: radiusSize, backgroundColor: colors.line2, opacity: op }, style]} />;
}

export function SkeletonList({ rows = 4 }: { rows?: number }) {
  return (
    <View style={{ gap: 12 }} accessibilityLabel="جاري التحميل">
      {Array.from({ length: rows }).map((_, i) => (
        <Card key={i} style={{ gap: 10 }}>
          <Skeleton height={14} width="45%" />
          <Skeleton height={12} />
          <Skeleton height={12} width="80%" />
        </Card>
      ))}
    </View>
  );
}

export function EmptyState({ icon = 'inbox', title, body, action, onAction }: { icon?: IconName; title: string; body?: string; action?: string; onAction?: () => void }) {
  return (
    <View style={styles.empty}>
      <View style={styles.emptyIcon}>
        <Feather name={icon} size={26} color={colors.blue} />
      </View>
      <AppText variant="h3" style={{ textAlign: 'center' }}>
        {title}
      </AppText>
      {body ? (
        <AppText variant="caption" style={{ textAlign: 'center' }}>
          {body}
        </AppText>
      ) : null}
      {action ? <Button title={action} onPress={onAction} size="sm" kind="secondary" style={{ marginTop: 6 }} /> : null}
    </View>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={styles.empty} accessibilityRole="alert">
      <View style={[styles.emptyIcon, { backgroundColor: colors.dangerBg }]}>
        <Feather name="wifi-off" size={24} color={colors.danger} />
      </View>
      <AppText variant="bodyStrong" style={{ textAlign: 'center' }}>
        {message}
      </AppText>
      {onRetry ? <Button title="حاول تاني" icon="refresh-cw" onPress={onRetry} size="sm" kind="secondary" /> : null}
    </View>
  );
}

export function Banner({ tone = 'info', text, icon }: { tone?: string; text: string; icon?: IconName }) {
  const c = toneColors(tone);
  icon = icon ?? (tone === 'ok' ? 'check-circle' : tone === 'danger' ? 'alert-circle' : tone === 'warn' ? 'alert-triangle' : 'info');
  return (
    <View style={[styles.banner, { backgroundColor: c.bg }]} accessibilityRole="alert">
      <Feather name={icon} size={17} color={c.fg} />
      <AppText variant="label" style={{ color: c.fg, flex: 1 }}>
        {text}
      </AppText>
    </View>
  );
}

export function Divider() {
  return <View style={{ height: 1, backgroundColor: colors.line }} />;
}

const styles = StyleSheet.create({
  textBase: { writingDirection: 'rtl' },
  btn: { borderRadius: radius.md, backgroundColor: colors.blue, overflow: 'hidden' },
  btnSecondary: { backgroundColor: colors.infoBg },
  btnGhost: { backgroundColor: 'transparent', borderWidth: 1, borderColor: colors.line2 },
  btnInner: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8 },
  btnText: { fontFamily: fonts.bodyBold },
  iconBtn: { width: 44, height: 44, borderRadius: 14, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: colors.line },
  badgeDot: {
    position: 'absolute',
    top: -4,
    right: -4,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    backgroundColor: colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 4,
  },
  badgeText: { color: colors.white, fontSize: 10, fontFamily: fonts.bodyBold },
  card: { backgroundColor: colors.card, borderRadius: radius.xl, padding: space.lg, borderWidth: 1, borderColor: colors.line, ...shadow.card },
  gradCard: { borderRadius: radius.xl, padding: space.xl, overflow: 'hidden' },
  sectionRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
  pill: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 10, paddingVertical: 4, borderRadius: radius.pill, alignSelf: 'flex-start' },
  pillText: { fontFamily: fonts.bodyMedium, fontSize: 12 },
  track: { width: '100%', backgroundColor: colors.line, borderRadius: 99, overflow: 'hidden' },
  inputWrap: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: colors.card,
    borderWidth: 1.5,
    borderColor: colors.line2,
    borderRadius: radius.md,
    paddingHorizontal: 14,
  },
  input: { flex: 1, minHeight: 50, fontFamily: fonts.body, fontSize: 15.5, color: colors.ink, writingDirection: 'rtl' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { paddingHorizontal: 14, paddingVertical: 9, borderRadius: radius.pill, backgroundColor: colors.card, borderWidth: 1, borderColor: colors.line2, minHeight: 40, justifyContent: 'center' },
  chipOn: { backgroundColor: colors.ink, borderColor: colors.ink },
  chipText: { fontFamily: fonts.bodyMedium, fontSize: 14, color: colors.ink2 },
  switchRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10 },
  switchTrack: { width: 50, height: 30, borderRadius: 15, backgroundColor: colors.line2, padding: 3, justifyContent: 'center' },
  switchKnob: { width: 24, height: 24, borderRadius: 12, backgroundColor: colors.white, ...shadow.card },
  empty: { alignItems: 'center', gap: 10, paddingVertical: 36, paddingHorizontal: 20 },
  emptyIcon: { width: 60, height: 60, borderRadius: 20, backgroundColor: colors.infoBg, alignItems: 'center', justifyContent: 'center' },
  banner: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderRadius: radius.md },
});
