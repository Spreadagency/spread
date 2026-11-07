import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { View } from 'react-native';

import type { ApiError } from '@/lib/api';
import type { Paywall as PaywallT } from '@/lib/types';
import { colors } from '@/theme/tokens';

import { AppText, Banner, Button, Card, ProgressBar } from './ui';

const STEPS = ['بنقرا هوية براندك…', 'بنكتب المسودة…', 'بنظبط الأسلوب واللهجة…', 'بنراجع الهاشتاجات والـ CTA…', 'لمسات أخيرة…'];

/** Long synchronous AI request: honest indeterminate progress (no fake percentages from the server) */
export function AiProgress({ title = 'الذكاء الاصطناعي شغال', steps = STEPS, hint }: { title?: string; steps?: string[]; hint?: string }) {
  const [sec, setSec] = useState(0);
  const i = Math.min(steps.length - 1, Math.floor(sec / 6));
  useEffect(() => {
    const t = setInterval(() => setSec((s) => s + 1), 1000);
    return () => clearInterval(t);
  }, []);
  return (
    <Card style={{ alignItems: 'center', gap: 12 }} accessibilityLabel={title}>
      <Image source={require('@/assets/brand/bot-think.png')} style={{ width: 90, height: 140 }} contentFit="contain" />
      <AppText variant="title">{title}</AppText>
      <AppText variant="caption">{steps[i]}</AppText>
      <View style={{ width: '100%' }}>
        <ProgressBar pct={Math.min(92, 8 + sec * 1.6)} />
      </View>
      <AppText variant="small">{hint ?? 'خليك على الشاشة — ممكن ياخد لحد دقيقة'} · {sec} ث</AppText>
    </Card>
  );
}

/** Subscription gate (server code "subscription"): no design / publishing was generated or charged */
export function PaywallCard({ paywall, onBack }: { paywall?: PaywallT | null; onBack?: () => void }) {
  const p = paywall ?? {
    title: 'اشترك لصناعة التصميم والنشر',
    body: 'منشورك جاهز ومحفوظ. تحويله لتصميم ونشره متاحين ضمن الاشتراك.',
    benefits: [],
    cta: 'شوف الباقات',
    url: 'packages.php',
  };
  return (
    <Card style={{ gap: 10, borderColor: colors.blue }}>
      <AppText variant="h3">{p.title}</AppText>
      <AppText variant="body">{p.body}</AppText>
      {p.benefits.map((b, i) => (
        <AppText key={i} variant="caption">
          ✓ {b}
        </AppText>
      ))}
      <Button title="شوف الباقات" icon="star" onPress={() => router.push('/packages')} />
      {onBack ? <Button title="العودة للمنشور" kind="ghost" size="sm" onPress={onBack} /> : null}
    </Card>
  );
}

/** Maps a failed AI call to the right UI: paywall, quota/credits, retry-able network error, or message */
export function AiErrorView({ error, onRetry, onBack }: { error: ApiError; onRetry?: () => void; onBack?: () => void }) {
  if (error.isPaywall) return <PaywallCard paywall={(error.payload?.paywall as PaywallT | undefined) ?? null} onBack={onBack} />;
  if (error.isQuotaOrCredits) {
    return (
      <Card style={{ gap: 10 }}>
        <Banner tone="warn" icon="alert-triangle" text={error.message} />
        <Button title="رصيدي وباقتي" kind="secondary" icon="pie-chart" onPress={() => router.push('/credits')} />
      </Card>
    );
  }
  if (error.isNetwork) {
    return (
      <Card style={{ gap: 10 }}>
        <Banner tone="warn" icon="wifi-off" text={`${error.message}. إعادة المحاولة آمنة — مش هيتخصم منك مرتين.`} />
        {onRetry ? <Button title="حاول تاني" icon="refresh-cw" onPress={onRetry} /> : null}
      </Card>
    );
  }
  return (
    <Card style={{ gap: 10 }}>
      <Banner tone="danger" icon="alert-circle" text={error.message} />
      {error.code === 'in_progress' && onRetry ? <Button title="اتأكد من النتيجة" kind="secondary" onPress={onRetry} /> : null}
    </Card>
  );
}
