import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AppText, Button } from '@/components/ui';
import { colors, gradients, space } from '@/theme/tokens';

export default function Welcome() {
  const insets = useSafeAreaInsets();
  return (
    <LinearGradient colors={gradients.navy} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[styles.root, { paddingTop: insets.top + 24, paddingBottom: insets.bottom + 24 }]}>
      <View style={styles.inner}>
        <View style={styles.brandRow}>
          <Image source={require('@/assets/brand/spread-mark.png')} style={{ width: 40, height: 33 }} contentFit="contain" accessibilityLabel="Spread AI" />
          <AppText variant="h2" style={{ color: colors.white }}>
            Spread AI
          </AppText>
        </View>
        <View style={styles.hero}>
          <Image source={require('@/assets/brand/bot-wave.png')} style={styles.bot} contentFit="contain" accessibilityIgnoresInvertColors />
        </View>
        <View style={{ gap: 10 }}>
          <AppText variant="h1" style={{ color: colors.white }}>
            فريق تسويق كامل بالذكاء الاصطناعي
          </AppText>
          <AppText variant="body" style={{ color: '#C9D6EA' }}>
            من الفكرة للمنشور للتصميم للنشر — كل محتوى براندك في مكان واحد، وبنفس حسابك على المنصة.
          </AppText>
        </View>
        <View style={{ gap: 12, marginTop: space.xl }}>
          <Button title="تسجيل الدخول" icon="log-in" size="lg" onPress={() => router.push('/login')} />
          <Button title="إنشاء حساب جديد" kind="outlineLight" size="lg" onPress={() => router.push('/register')} />
        </View>
      </View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, paddingHorizontal: space.xl },
  inner: { flex: 1, width: '100%', maxWidth: 520, alignSelf: 'center', justifyContent: 'space-between' },
  brandRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  hero: { flex: 1, alignItems: 'center', justifyContent: 'center', minHeight: 180 },
  bot: { width: '62%', aspectRatio: 380 / 591, maxHeight: 340 },
});
