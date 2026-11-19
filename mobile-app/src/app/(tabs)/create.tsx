import { Feather } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AiErrorView, AiProgress } from '@/components/ai-states';
import { Screen } from '@/components/screen';
import { AppText, Button, Card, ChipGroup, Input, SkeletonList, SwitchRow } from '@/components/ui';
import { api } from '@/lib/api';
import { useBootstrap, useDashboard } from '@/lib/queries';
import type { GenerateContentResult } from '@/lib/types';
import { useAiAction } from '@/lib/use-ai-action';
import { colors, space } from '@/theme/tokens';

type Form = {
  content_type: string;
  platform: string;
  length: string;
  tone: string;
  dialect: string;
  template_id: string;
  extra_notes: string;
  use_logo: boolean;
  use_personal_image: boolean;
};

const DEFAULTS: Form = {
  content_type: 'marketing',
  platform: 'both',
  length: 'medium',
  tone: 'simple',
  dialect: 'egyptian',
  template_id: '',
  extra_notes: '',
  use_logo: false,
  use_personal_image: false,
};

/** Quick create — same fields and endpoint as the website's «منشور جديد» (ajax/generate-content.php) */
export default function Create() {
  const insets = useSafeAreaInsets();
  const boot = useBootstrap();
  const dash = useDashboard();
  const [f, setF] = useState<Form>(DEFAULTS);
  const set = <K extends keyof Form>(k: K) => (v: Form[K]) => setF((p) => ({ ...p, [k]: v }));

  const gen = useAiAction(async (form: Form, key) =>
    api.call<GenerateContentResult>('generate_content', {
      method: 'POST',
      form: { ...form, template_id: form.template_id || undefined },
      b64Fields: ['extra_notes'],
      idempotencyKey: key,
      timeoutMs: 300_000,
    }),
  );

  const submit = async () => {
    const r = await gen.execute(f);
    if (r?.content_id) {
      setF(DEFAULTS);
      router.push({ pathname: '/content/[id]', params: { id: String(r.content_id), fresh: '1' } });
    }
  };

  const o = boot.data?.options;
  const showNumbers = dash.data?.usage.show_numbers ?? false;
  const cost = boot.data?.costs.content_generation_cost ?? 1;

  return (
    <Screen
      header={
        <View style={{ paddingTop: insets.top + 10, paddingHorizontal: space.lg, maxWidth: 672, width: '100%', alignSelf: 'center' }}>
          <AppText variant="h2">اصنع محتوى</AppText>
          <AppText variant="caption">منشور جاهز بنص وهاشتاجات ودعوة للإجراء — بهوية براندك</AppText>
        </View>
      }
      footer={
        gen.busy ? null : (
          <Button
            title={showNumbers ? `اكتب المنشور (${cost} كريدت)` : 'اكتب المنشور'}
            icon="zap"
            size="lg"
            onPress={submit}
            disabled={!o}
            accessibilityHint="بيتخصم من رصيد باقتك بعد ما المنشور يتولد"
          />
        )
      }>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <ModeCard icon="edit-3" title="منشور سريع" active />
        <ModeCard icon="zap" title="من الأفكار" onPress={() => router.push('/ideas')} />
        <ModeCard icon="image" title="تصميم" onPress={() => router.push('/studio')} />
      </View>

      {gen.busy ? <AiProgress /> : null}
      {gen.error && !gen.busy ? <AiErrorView error={gen.error} onRetry={submit} /> : null}
      {gen.error?.message.includes('الهوية') ? <Button title="كمّل هوية البراند" kind="secondary" icon="briefcase" onPress={() => router.push('/brand')} /> : null}

      {!o ? (
        <SkeletonList rows={3} />
      ) : (
        <View style={{ gap: 18, opacity: gen.busy ? 0.5 : 1 }} pointerEvents={gen.busy ? 'none' : 'auto'}>
          <ChipGroup label="نوع المنشور" options={o.content_types} value={f.content_type} onChange={set('content_type')} />
          <ChipGroup label="المنصة" options={o.platforms} value={f.platform} onChange={set('platform')} />
          <ChipGroup label="الطول" options={o.lengths} value={f.length} onChange={set('length')} />
          <ChipGroup label="الأسلوب" options={o.tones} value={f.tone} onChange={set('tone')} />
          <ChipGroup label="اللهجة" options={o.dialects} value={f.dialect} onChange={set('dialect')} />
          {o.templates.length ? (
            <ChipGroup
              label="قالب (اختياري)"
              options={[{ key: '', label: 'بدون قالب' }, ...o.templates.map((t) => ({ key: String(t.id), label: t.name }))]}
              value={f.template_id}
              onChange={set('template_id')}
            />
          ) : null}
          <Input
            label="عايز تقول إيه؟ (اختياري)"
            multiline
            value={f.extra_notes}
            onChangeText={(t) => set('extra_notes')(t.slice(0, 500))}
            placeholder="مثال: عرض 20% على كل المنيو لحد آخر الأسبوع"
            hint={`${f.extra_notes.length}/500`}
          />
          <Card style={{ paddingVertical: 4 }}>
            <SwitchRow label="استخدم اللوجو" hint="الذكاء الاصطناعي بيشوف لوجو البراند" value={f.use_logo} onChange={set('use_logo')} />
            <SwitchRow label="استخدم صورتك الشخصية" hint="لو رافعها في هوية البراند" value={f.use_personal_image} onChange={set('use_personal_image')} />
          </Card>
        </View>
      )}
    </Screen>
  );
}

function ModeCard({ icon, title, active, onPress }: { icon: React.ComponentProps<typeof Feather>['name']; title: string; active?: boolean; onPress?: () => void }) {
  return (
    <Pressable
      accessibilityRole="tab"
      accessibilityState={{ selected: !!active }}
      onPress={onPress}
      style={{
        flex: 1,
        alignItems: 'center',
        gap: 6,
        paddingVertical: 12,
        borderRadius: 16,
        backgroundColor: active ? colors.ink : colors.card,
        borderWidth: 1,
        borderColor: active ? colors.ink : colors.line,
      }}>
      <Feather name={icon} size={20} color={active ? colors.teal : colors.blue} />
      <AppText variant="label" style={{ color: active ? colors.white : colors.ink2 }}>
        {title}
      </AppText>
    </Pressable>
  );
}
