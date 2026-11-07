import { View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, ErrorState, Pill, SkeletonList } from '@/components/ui';
import { PURCHASES_BUILD_FLAG } from '@/lib/config';
import { fmtNumber } from '@/lib/format';
import { openOnWebsite } from '@/lib/navigation';
import { useCredits, usePackages } from '@/lib/queries';
import { colors } from '@/theme/tokens';

const UNIT_LABELS: Record<string, string> = { posts: 'منشور', designs: 'تصميم', publishes: 'نشر', research: 'بحث', videos: 'سكريبت فيديو' };

/**
 * Plans. Store builds do NOT sell digital goods inside the app (App Store 3.1.1 / Google Play Payments policy):
 * no prices, no buy buttons, no links to web checkout. Selling is enabled only when BOTH the build flag
 * (EXPO_PUBLIC_PURCHASES=web, for direct/internal builds) and the server setting mobile_purchases=web allow it.
 * In-app purchases (StoreKit / Play Billing) are a pending owner decision — see docs/02-architecture.md §8.
 */
export default function Packages() {
  const q = usePackages();
  const credits = useCredits();
  const webSales = PURCHASES_BUILD_FLAG === 'web' && q.data?.purchases === 'web';
  const current = credits.data?.usage.plan;

  return (
    <Screen header={<TopBar title="الباقات" />} refreshing={q.isRefetching} onRefresh={() => q.refetch()}>
      {current ? <Banner tone="info" icon="award" text={`باقتك الحالية: ${current}${credits.data?.usage.ends_label ? ` · لحد ${credits.data.usage.ends_label}` : ''}`} /> : null}
      {credits.data && !credits.data.subscribed ? <Banner tone="warn" icon="lock" text="التصميم والنشر متاحين ضمن الاشتراك." /> : null}
      {q.isLoading ? <SkeletonList rows={3} /> : null}
      {q.isError ? <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} /> : null}
      {q.data?.packages.map((p) => (
        <Card key={p.id} style={{ gap: 10, borderColor: p.featured ? colors.blue : colors.line, borderWidth: p.featured ? 2 : 1 }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
            <AppText variant="h3">{p.name}</AppText>
            {p.badge ? <Pill label={p.badge} tone="violet" /> : current === p.name ? <Pill label="باقتك" tone="ok" /> : null}
          </View>
          {p.description ? <AppText variant="caption">{p.description}</AppText> : null}
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
            {Object.entries(p.quotas)
              .filter(([, v]) => Number(v) > 0)
              .map(([k, v]) => (
                <Pill key={k} label={`${v} ${UNIT_LABELS[k] ?? k}`} tone="info" />
              ))}
            <Pill label={`${p.validity_days} يوم`} tone="mute" icon="calendar" />
          </View>
          {p.features.map((f, i) => (
            <AppText key={i} variant="body">
              ✓ {f}
            </AppText>
          ))}
          {webSales && p.price_egp !== null ? (
            <>
              <AppText variant="h2">{fmtNumber(p.price_egp)} ج.م</AppText>
              <Button title="اشترك" icon="credit-card" onPress={() => openOnWebsite(`checkout.php?package=${p.id}`)} />
            </>
          ) : null}
        </Card>
      ))}
      {!webSales && q.data ? (
        <AppText variant="caption" style={{ textAlign: 'center' }}>
          أي باقة مفعّلة على حسابك بتشتغل هنا تلقائيًا، بنفس الرصيد والحصص.
        </AppText>
      ) : null}
    </Screen>
  );
}
