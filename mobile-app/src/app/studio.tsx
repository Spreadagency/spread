import { Feather } from '@expo/vector-icons';
import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { AiErrorView, AiProgress } from '@/components/ai-states';
import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, ChipGroup, Input, SkeletonList, SwitchRow } from '@/components/ui';
import { api, type FileField } from '@/lib/api';
import { ratioToAspect } from '@/lib/format';
import { pickImage } from '@/lib/media';
import { qk, useDashboard } from '@/lib/queries';
import type { StudioDesignResult } from '@/lib/types';
import { useAiAction } from '@/lib/use-ai-action';
import { colors, radius } from '@/theme/tokens';

type StudioHome = {
  brand: { name: string; logo: string | null };
  costs: { design: number; brief: number; balance: number };
  ratios: { key: string; label: string; hint: string }[];
  recent: { id: number; url: string; ratio: string; mode_label?: string; versions_n?: number }[];
};

type Mode = 'free' | 'from_image';

/** Free design studio — same endpoint as the website studio (ajax/studio-design.php) */
export default function Studio() {
  const dash = useDashboard();
  const showNumbers = dash.data?.usage.show_numbers ?? false;
  const home = useQuery({ queryKey: qk.studio, queryFn: () => api.call<StudioHome>('studio', { query: { action: 'home' } }) });
  const [mode, setMode] = useState<Mode>('free');
  const [prompt, setPrompt] = useState('');
  const [ratio, setRatio] = useState('1:1');
  const [logo, setLogo] = useState(true);
  const [image, setImage] = useState<FileField | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  const gen = useAiAction(async (_: null, key) =>
    api.call<StudioDesignResult>('studio_design', {
      method: 'POST',
      form: {
        action: 'generate',
        mode,
        free_prompt: prompt.trim(),
        design_idea: prompt.trim(),
        ratio,
        include_logo: logo,
        source_image: image ?? undefined,
      },
      b64Fields: ['free_prompt', 'design_idea'],
      idempotencyKey: key,
      timeoutMs: 300_000,
    }),
  );

  const run = async () => {
    setMsg(null);
    if (prompt.trim().length < 5) return setMsg('اوصف التصميم اللي عايزه (5 حروف على الأقل)');
    if (mode === 'from_image' && !image) return setMsg('اختار الصورة اللي هنصمّم منها');
    const r = await gen.execute(null);
    if (r) {
      void home.refetch();
      router.push({ pathname: '/design/[id]', params: { id: String(r.design_id) } });
    }
  };

  const ratios = home.data?.ratios ?? [];
  return (
    <Screen header={<TopBar title="استوديو التصميم" />} refreshing={home.isRefetching} onRefresh={() => home.refetch()}>
      {gen.busy ? (
        <AiProgress title="بنصمّم" steps={['بنقرا الوصف والهوية…', 'بنختار التكوين…', 'بنرسم…', 'لمسات أخيرة…']} hint="التصميم ممكن ياخد لحد دقيقتين" />
      ) : (
        <>
          {msg ? <Banner tone="danger" text={msg} /> : null}
          {gen.error ? <AiErrorView error={gen.error} onRetry={run} /> : null}
          <ChipGroup<Mode>
            label="نوع التصميم"
            options={[
              { key: 'free', label: 'من وصف', emoji: '✍️' },
              { key: 'from_image', label: 'من صورة', emoji: '🖼' },
            ]}
            value={mode}
            onChange={setMode}
          />
          <Input label="اوصف التصميم" value={prompt} onChangeText={(t) => setPrompt(t.slice(0, 1200))} multiline placeholder="مثال: بوستر لعرض 20% على البيتزا، ألوان دافية وخط عربي واضح" />
          {mode === 'from_image' ? (
            <Card style={{ gap: 10, alignItems: 'center' }}>
              {image ? <Image source={{ uri: image.uri }} style={{ width: 160, height: 160, borderRadius: radius.md }} contentFit="cover" /> : null}
              <View style={{ flexDirection: 'row', gap: 8 }}>
                <Button
                  title={image ? 'غيّر الصورة' : 'اختار صورة'}
                  icon="image"
                  kind="secondary"
                  size="sm"
                  onPress={async () => {
                    const r = await pickImage('library');
                    if (r?.ok) setImage(r.file);
                    else if (r && !r.ok) setMsg(r.error);
                  }}
                />
                <Button
                  title="الكاميرا"
                  icon="camera"
                  kind="ghost"
                  size="sm"
                  onPress={async () => {
                    const r = await pickImage('camera');
                    if (r?.ok) setImage(r.file);
                    else if (r && !r.ok) setMsg(r.error);
                  }}
                />
              </View>
              <AppText variant="small">JPG / PNG / WebP · حتى 5 ميجا</AppText>
            </Card>
          ) : null}
          {ratios.length ? <ChipGroup label="المقاس" options={ratios} value={ratio} onChange={setRatio} /> : null}
          <SwitchRow label="حط لوجو البراند" value={logo} onChange={setLogo} />
          <Button title={showNumbers && home.data ? `صمّم (${home.data.costs.design} كريدت)` : 'صمّم'} icon="image" size="lg" onPress={run} />
        </>
      )}

      <View style={{ gap: 10 }}>
        <AppText variant="h3">تصميماتك</AppText>
        {home.isLoading ? <SkeletonList rows={2} /> : null}
        <View style={styles.grid}>
          {home.data?.recent.map((d) => (
            <Pressable
              key={d.id}
              accessibilityRole="imagebutton"
              accessibilityLabel="فتح التصميم"
              onPress={() => router.push({ pathname: '/design/[id]', params: { id: String(d.id) } })}
              style={styles.cell}>
              <Image source={{ uri: d.url }} style={{ width: '100%', aspectRatio: ratioToAspect(d.ratio), borderRadius: radius.md, backgroundColor: colors.line }} contentFit="cover" transition={150} />
              {d.versions_n && d.versions_n > 1 ? (
                <View style={styles.badge}>
                  <Feather name="layers" size={11} color={colors.white} />
                  <AppText variant="small" style={{ color: colors.white }}>
                    {d.versions_n}
                  </AppText>
                </View>
              ) : null}
            </Pressable>
          ))}
        </View>
        {home.data && !home.data.recent.length ? <AppText variant="caption">لسه معملتش تصميمات من الاستوديو.</AppText> : null}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  cell: { width: '48%' },
  badge: { position: 'absolute', top: 6, left: 6, flexDirection: 'row', gap: 3, alignItems: 'center', backgroundColor: 'rgba(11,21,38,0.7)', borderRadius: 99, paddingHorizontal: 7, paddingVertical: 2 },
});
