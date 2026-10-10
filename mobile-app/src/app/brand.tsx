import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { useState } from 'react';
import { View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, ErrorState, Input, Pill, ProgressBar, SectionTitle, SkeletonList } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { pickImage } from '@/lib/media';
import { openOnWebsite } from '@/lib/navigation';
import { qk } from '@/lib/queries';
import { colors, radius } from '@/theme/tokens';

type Field = { key: string; label: string; group: string; weight: number; question: string; value: string; filled: boolean };
type Brand = { id: number; name: string; health: { pct: number; gate: number; unlocked: boolean }; fields: Field[] };

const GROUPS: Record<string, string> = { basic: 'الأساسيات', audience: 'الجمهور والخدمات', style: 'الأسلوب', visual: 'الهوية البصرية', contact: 'التواصل', links: 'الروابط' };
const MULTILINE = new Set(['description', 'audience', 'services', 'keywords_use', 'address', 'working_hours']);

/** Brand identity (Brand Brain) — same fields and rules as the website (api/brand.php save_field / upload_logo) */
export default function BrandScreen() {
  const qc = useQueryClient();
  const q = useQuery({ queryKey: qk.brand, queryFn: () => api.call<{ brand: Brand }>('brand', { query: { action: 'get' } }) });
  const b = q.data?.brand;
  const [edits, setEdits] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ tone: string; text: string } | null>(null);

  const save = async (f: Field) => {
    const value = edits[f.key];
    if (value === undefined || value === f.value) return;
    setSaving(f.key);
    setMsg(null);
    try {
      const r = await api.call<{ brand: Brand }>('brand', { method: 'POST', json: { action: 'save_field', field: f.key, value } });
      qc.setQueryData(qk.brand, { ok: true, brand: r.brand });
      setEdits((e) => {
        const n = { ...e };
        delete n[f.key];
        return n;
      });
      void qc.invalidateQueries({ queryKey: qk.dashboard });
    } catch (e) {
      setMsg({ tone: 'danger', text: (e as ApiError).message });
    } finally {
      setSaving(null);
    }
  };

  const uploadLogo = async () => {
    const p = await pickImage();
    if (!p) return;
    if (!p.ok) return setMsg({ tone: 'danger', text: p.error });
    setSaving('logo_path');
    try {
      const r = await api.call<{ brand: Brand }>('brand', { method: 'POST', form: { action: 'upload_logo', logo: p.file }, timeoutMs: 90_000 });
      qc.setQueryData(qk.brand, { ok: true, brand: r.brand });
      setMsg({ tone: 'ok', text: 'اتحفظ اللوجو ✓' });
    } catch (e) {
      setMsg({ tone: 'danger', text: (e as ApiError).message });
    } finally {
      setSaving(null);
    }
  };

  const groups = b ? Object.keys(GROUPS).filter((g) => b.fields.some((f) => f.group === g)) : [];

  return (
    <Screen header={<TopBar title="هوية البراند" />} refreshing={q.isRefetching} onRefresh={() => q.refetch()}>
      {q.isLoading ? <SkeletonList rows={4} /> : null}
      {q.isError ? <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} /> : null}
      {msg ? <Banner tone={msg.tone} text={msg.text} /> : null}
      {b ? (
        <>
          <Card style={{ gap: 8 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <AppText variant="title">اكتمال الهوية</AppText>
              <AppText variant="title" style={{ color: colors.blue }}>
                {b.health.pct}%
              </AppText>
            </View>
            <ProgressBar pct={b.health.pct} />
            <AppText variant="caption">
              {b.health.unlocked ? 'هويتك جاهزة — المحتوى بيطلع بأسلوب براندك ✓' : `كمّل لحد ${b.health.gate}% علشان تفتح كتابة المنشورات من الأفكار.`}
            </AppText>
          </Card>
          {groups.map((g) => (
            <Card key={g} style={{ gap: 14 }}>
              <SectionTitle title={GROUPS[g]} />
              {b.fields
                .filter((f) => f.group === g)
                .map((f) =>
                  f.key === 'logo_path' ? (
                    <View key={f.key} style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                      {f.value ? (
                        <Image source={{ uri: f.value }} style={{ width: 64, height: 64, borderRadius: radius.md, backgroundColor: colors.bgAlt }} contentFit="contain" />
                      ) : null}
                      <Button title={f.value ? 'غيّر اللوجو' : 'ارفع اللوجو'} icon="upload" kind="secondary" size="sm" loading={saving === 'logo_path'} onPress={uploadLogo} />
                    </View>
                  ) : (
                    <View key={f.key} style={{ gap: 6 }}>
                      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                        <AppText variant="label">{f.label}</AppText>
                        {f.weight > 0 && !f.filled ? <Pill label={`+${f.weight}%`} tone="violet" /> : null}
                      </View>
                      <Input
                        value={edits[f.key] ?? f.value}
                        onChangeText={(t) => setEdits((e) => ({ ...e, [f.key]: t }))}
                        onBlur={() => void save(f)}
                        placeholder={f.question || f.label}
                        multiline={MULTILINE.has(f.key)}
                        style={MULTILINE.has(f.key) ? { minHeight: 80 } : undefined}
                        autoCapitalize={g === 'links' ? 'none' : 'sentences'}
                        keyboardType={g === 'links' ? 'url' : 'default'}
                      />
                      {edits[f.key] !== undefined && edits[f.key] !== f.value ? (
                        <Button title="حفظ" size="sm" kind="secondary" loading={saving === f.key} onPress={() => save(f)} />
                      ) : null}
                    </View>
                  ),
                )}
            </Card>
          ))}
          <Button title="مصادر البراند وتحليل الموقع (على الموقع)" kind="ghost" icon="external-link" onPress={() => void openOnWebsite('brand-brain.php')} />
        </>
      ) : null}
    </Screen>
  );
}
