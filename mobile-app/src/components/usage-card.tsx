import { router } from 'expo-router';
import { View } from 'react-native';

import { fmtNumber } from '@/lib/format';
import type { Usage } from '@/lib/types';
import { colors } from '@/theme/tokens';

import { AppText, GradientCard, ProgressBar } from './ui';

/**
 * Credits / plan summary. Follows the platform rule `credits_display`:
 * when the admin hides numbers (default "percent"), customers only see the % of the plan used.
 */
export function UsageCard({ usage, onPress }: { usage: Usage; onPress?: () => void }) {
  const left = 100 - usage.pct;
  return (
    <GradientCard style={{ gap: 12 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <View style={{ gap: 2, flex: 1 }}>
          <AppText variant="caption" style={{ color: '#9FB3D1' }}>
            {usage.plan ? `باقتك: ${usage.plan}` : 'رصيدك'}
          </AppText>
          <AppText variant="h1" style={{ color: colors.white }} accessibilityLabel={usage.show_numbers ? `الرصيد ${usage.balance} كريدت` : `متبقي ${left} بالمئة`}>
            {usage.show_numbers ? `${fmtNumber(usage.balance)} كريدت` : `${left}% متبقي`}
          </AppText>
        </View>
        <AppText variant="label" style={{ color: colors.teal }} onPress={onPress ?? (() => router.push('/credits'))} accessibilityRole="link">
          التفاصيل
        </AppText>
      </View>
      <ProgressBar pct={usage.pct} color={colors.teal} />
      <AppText variant="small" style={{ color: '#9FB3D1' }}>
        {usage.show_numbers ? `استخدمت ${fmtNumber(usage.used)} من ${fmtNumber(usage.total)}` : `استخدمت ${usage.pct}% من باقة الشهر`}
        {usage.days_left !== null ? ` · بتتجدد بعد ${usage.days_left} يوم` : ''}
      </AppText>
    </GradientCard>
  );
}
