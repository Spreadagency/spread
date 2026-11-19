import { Feather } from '@expo/vector-icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import * as Clipboard from 'expo-clipboard';
import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Alert, Pressable, Share, StyleSheet, View } from 'react-native';

import { AiErrorView, AiProgress } from '@/components/ai-states';
import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, ChipGroup, Divider, ErrorState, IconButton, Input, Pill, SkeletonList, SwitchRow } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { ratioToAspect } from '@/lib/format';
import { pickImage, saveImageToGallery, shareImage } from '@/lib/media';
import { qk, useBootstrap, useDashboard } from '@/lib/queries';
import type { ContentDetail, GenerateDesignResult } from '@/lib/types';
import { useAiAction } from '@/lib/use-ai-action';
import { colors, radius } from '@/theme/tokens';

type Tab = 'post' | 'design' | 'history';

export default function ContentScreen() {
  const { id, fresh } = useLocalSearchParams<{ id: string; fresh?: string }>();
  const cid = Number(id);
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: qk.content(cid),
    queryFn: () => api.call<{ content: ContentDetail }>('contents', { query: { action: 'get', id: cid } }),
    enabled: cid > 0,
  });
  const c = q.data?.content;
  const [tab, setTab] = useState<Tab>('post');
  const refresh = () => qc.invalidateQueries({ queryKey: qk.content(cid) });

  return (
    <Screen
      header={
        <TopBar
          title={c ? `${c.format_emoji} ${c.type_label || c.format_label}` : 'المنشور'}
          right={c && !c.published ? <IconButton icon="send" label="نشر" onPress={() => router.push({ pathname: '/publish/[id]', params: { id: String(cid) } })} /> : null}
        />
      }
      refreshing={q.isRefetching}
      onRefresh={() => q.refetch()}>
      {q.isLoading ? <SkeletonList rows={3} /> : null}
      {q.isError ? <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} /> : null}
      {c ? (
        <>
          {fresh === '1' ? <Banner tone="ok" icon="check-circle" text="المنشور اتكتب واتحفظ في مشاريعك ✓" /> : null}
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
            <Pill label={c.status.label} tone={c.status.key === 'publish_failed' ? 'danger' : c.status.key === 'published' ? 'ok' : 'info'} />
            {c.platform ? <Pill label={c.platform} tone="mute" icon="globe" /> : null}
            {c.campaign ? <Pill label={c.campaign.title} tone="violet" icon="flag" /> : null}
          </View>
          {c.fail ? <Banner tone="danger" icon="alert-triangle" text={`فشل النشر: ${c.fail}`} /> : null}
          {c.scheduled ? <Banner tone="info" icon="clock" text={`مجدول: ${c.scheduled}`} /> : null}

          <View style={styles.tabs} accessibilityRole="tablist">
            {(
              [
                ['post', 'المنشور'],
                ['design', `التصميمات${c.designs.length ? ` (${c.designs.length})` : ''}`],
                ['history', 'النسخ'],
              ] as [Tab, string][]
            ).map(([k, l]) => (
              <Pressable key={k} accessibilityRole="tab" accessibilityState={{ selected: tab === k }} onPress={() => setTab(k)} style={[styles.tab, tab === k && styles.tabOn]}>
                <AppText variant="label" style={{ color: tab === k ? colors.white : colors.ink2 }}>
                  {l}
                </AppText>
              </Pressable>
            ))}
          </View>

          {tab === 'post' ? <PostTab c={c} onChanged={refresh} /> : null}
          {tab === 'design' ? <DesignTab c={c} onChanged={refresh} onBack={() => setTab('post')} /> : null}
          {tab === 'history' ? <HistoryTab c={c} onChanged={refresh} /> : null}
        </>
      ) : null}
    </Screen>
  );
}

/* ───────── Post text: view · copy · share · edit · AI edit · regenerate ───────── */

function PostTab({ c, onChanged }: { c: ContentDetail; onChanged: () => void }) {
  const dash = useDashboard();
  const boot = useBootstrap();
  const showNumbers = dash.data?.usage.show_numbers ?? false;
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState({ text: c.text, hashtags: c.hashtags, cta: c.cta });
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<{ tone: string; text: string } | null>(null);
  const [instruction, setInstruction] = useState('');
  const [proposal, setProposal] = useState<{ text: string; hashtags: string; cta: string } | null>(null);
  const locked = c.published;

  const full = [c.text, c.hashtags, c.cta].filter(Boolean).join('\n\n');

  const save = async (data: { text: string; hashtags: string; cta: string }, source?: string) => {
    setSaving(true);
    setMsg(null);
    try {
      const r = await api.call<{ saved?: boolean; unchanged?: boolean }>('contents', {
        method: 'POST',
        json: { action: 'save', id: c.id, rev: c.rev, ...data, ...(source ? { source, note: instruction } : {}) },
      });
      setMsg({ tone: 'ok', text: r.unchanged ? 'مفيش تغيير' : 'اتحفظ ✓ (النسخة القديمة محفوظة في «النسخ»)' });
      setEditing(false);
      setProposal(null);
      onChanged();
    } catch (e) {
      const err = e as ApiError;
      setMsg({ tone: 'danger', text: err.code === 'conflict' ? 'المنشور اتعدّل من مكان تاني — حدّث الصفحة وجرّب تاني' : err.message });
      if (err.code === 'conflict') onChanged();
    } finally {
      setSaving(false);
    }
  };

  const aiEdit = useAiAction(async (instr: string, key) =>
    api.call<{ proposal: { text: string; hashtags: string; cta: string } }>('contents', {
      method: 'POST',
      json: { action: 'ai_edit', id: c.id, instruction: instr },
      idempotencyKey: key,
      timeoutMs: 300_000,
    }),
  );
  const regen = useAiAction(async (_: null, key) =>
    api.call('regenerate_content', { method: 'POST', form: { content_id: c.id }, idempotencyKey: key, timeoutMs: 300_000 }),
  );

  const runAiEdit = async () => {
    if (instruction.trim().length < 3) return setMsg({ tone: 'danger', text: 'اكتب التعديل اللي عايزه (3 حروف على الأقل)' });
    const r = await aiEdit.execute(instruction.trim());
    if (r) setProposal(r.proposal);
  };

  const confirmRegen = () =>
    Alert.alert('إعادة توليد المنشور؟', 'هيتكتب نص جديد بنفس الإعدادات، والنص الحالي هيتحفظ في «النسخ».' + (showNumbers ? ` التكلفة ${boot.data?.costs.content_regeneration_cost ?? 1} كريدت.` : ''), [
      { text: 'إلغاء', style: 'cancel' },
      {
        text: 'أعد التوليد',
        onPress: async () => {
          const r = await regen.execute(null);
          if (r) {
            setMsg({ tone: 'ok', text: 'اتكتب نص جديد ✓' });
            onChanged();
          }
        },
      },
    ]);

  if (aiEdit.busy || regen.busy) return <AiProgress title={regen.busy ? 'بنعيد كتابة المنشور' : 'بنعدّل المنشور'} />;

  return (
    <View style={{ gap: 14 }}>
      {msg ? <Banner tone={msg.tone} text={msg.text} /> : null}
      {aiEdit.error ? <AiErrorView error={aiEdit.error} onRetry={runAiEdit} /> : null}
      {regen.error ? <AiErrorView error={regen.error} /> : null}

      {proposal ? (
        <Card style={{ gap: 10, borderColor: colors.blue }}>
          <AppText variant="title">اقتراح التعديل</AppText>
          <AppText variant="body" selectable>
            {proposal.text}
          </AppText>
          {proposal.hashtags ? <AppText variant="caption">{proposal.hashtags}</AppText> : null}
          {proposal.cta ? <AppText variant="bodyStrong">{proposal.cta}</AppText> : null}
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <Button title="اعتمد التعديل" icon="check" size="sm" loading={saving} onPress={() => save(proposal, 'ai_edit')} style={{ flex: 1 }} />
            <Button title="تجاهل" kind="ghost" size="sm" onPress={() => setProposal(null)} style={{ flex: 1 }} />
          </View>
        </Card>
      ) : null}

      {editing ? (
        <Card style={{ gap: 12 }}>
          <Input label="نص المنشور" multiline value={draft.text} onChangeText={(t) => setDraft((d) => ({ ...d, text: t }))} style={{ minHeight: 200 }} />
          <Input label="الهاشتاجات" value={draft.hashtags} onChangeText={(t) => setDraft((d) => ({ ...d, hashtags: t }))} />
          <Input label="الدعوة للإجراء (CTA)" value={draft.cta} onChangeText={(t) => setDraft((d) => ({ ...d, cta: t }))} />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <Button title="حفظ" icon="save" loading={saving} onPress={() => save(draft)} style={{ flex: 1 }} disabled={!draft.text.trim()} />
            <Button title="إلغاء" kind="ghost" onPress={() => setEditing(false)} style={{ flex: 1 }} />
          </View>
        </Card>
      ) : (
        <Card style={{ gap: 12 }}>
          <AppText variant="body" selectable style={{ fontSize: 16, lineHeight: 28 }}>
            {c.text}
          </AppText>
          {c.hashtags ? (
            <AppText variant="caption" selectable style={{ color: colors.blue }}>
              {c.hashtags}
            </AppText>
          ) : null}
          {c.cta ? (
            <AppText variant="bodyStrong" selectable>
              👉 {c.cta}
            </AppText>
          ) : null}
          <Divider />
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            <Button
              title="نسخ"
              icon="copy"
              kind="secondary"
              size="sm"
              onPress={async () => {
                await Clipboard.setStringAsync(full);
                setMsg({ tone: 'ok', text: 'اتنسخ النص ✓' });
              }}
            />
            <Button title="مشاركة" icon="share-2" kind="secondary" size="sm" onPress={() => Share.share({ message: full })} />
            {!locked ? (
              <Button
                title="تعديل"
                icon="edit-2"
                kind="secondary"
                size="sm"
                onPress={() => {
                  setDraft({ text: c.text, hashtags: c.hashtags, cta: c.cta });
                  setEditing(true);
                }}
              />
            ) : null}
            {!locked ? <Button title="إعادة توليد" icon="refresh-cw" kind="ghost" size="sm" onPress={confirmRegen} /> : null}
          </View>
        </Card>
      )}

      {!locked && !editing && !proposal ? (
        <Card style={{ gap: 10 }}>
          <AppText variant="title">✨ عدّل بالكلام</AppText>
          <AppText variant="caption">قول للذكاء الاصطناعي عايز تغيّر إيه — هيقترح نسخة وانت تعتمدها.</AppText>
          <Input value={instruction} onChangeText={(t) => setInstruction(t.slice(0, 500))} placeholder="مثال: خليه أقصر وأضف إيموجي" multiline style={{ minHeight: 70 }} />
          <Button
            title={showNumbers && c.costs.ai_edit ? `اقترح تعديل (${c.costs.ai_edit} كريدت)` : 'اقترح تعديل'}
            icon="wind"
            size="sm"
            onPress={runAiEdit}
            disabled={instruction.trim().length < 3}
          />
        </Card>
      ) : null}

      {!c.published ? (
        <Button title="جهّز للنشر" icon="send" kind="dark" onPress={() => router.push({ pathname: '/publish/[id]', params: { id: String(c.id) } })} />
      ) : (
        <Banner tone="ok" icon="check-circle" text="المنشور ده اتنشر" />
      )}
    </View>
  );
}

/* ───────── Designs: list · set cover · save/share · generate (subscription gate on the server) ───────── */

function DesignTab({ c, onChanged, onBack }: { c: ContentDetail; onChanged: () => void; onBack: () => void }) {
  const boot = useBootstrap();
  const dash = useDashboard();
  const showNumbers = dash.data?.usage.show_numbers ?? false;
  const ratios = boot.data?.options.ratios ?? [{ key: '1:1', label: 'مربع' }];
  const [ratio, setRatio] = useState(c.format === 'story' ? '9:16' : '1:1');
  const [prompt, setPrompt] = useState('');
  const [includeLogo, setIncludeLogo] = useState(true);
  const [ref, setRef] = useState<{ uri: string; name: string; type: string } | null>(null);
  const [msg, setMsg] = useState<{ tone: string; text: string } | null>(null);

  const gen = useAiAction(async (_: null, key) =>
    api.call<GenerateDesignResult>('generate_design', {
      method: 'POST',
      form: { content_id: c.id, ratio, include_logo: includeLogo, custom_prompt: prompt.trim() || undefined, source_image: ref ?? undefined },
      b64Fields: prompt.trim() ? ['custom_prompt'] : [],
      idempotencyKey: key,
      timeoutMs: 300_000,
    }),
  );

  const run = async () => {
    setMsg(null);
    const r = await gen.execute(null);
    if (r) {
      setMsg({ tone: 'ok', text: 'التصميم جاهز ✓' });
      setPrompt('');
      setRef(null);
      onChanged();
    }
  };

  const setAsCover = async (designId: number) => {
    try {
      await api.call('contents', { method: 'POST', json: { action: 'use_design', id: c.id, design_id: designId } });
      onChanged();
    } catch (e) {
      setMsg({ tone: 'danger', text: (e as Error).message });
    }
  };

  if (c.format === 'video') return <Banner tone="info" text="الفيديو بيتنفّذ يدويًا من فريقنا — تابع حالته من الموقع." />;
  if (gen.busy) return <AiProgress title="بنصمّم المنشور" steps={['بنقرا المنشور والهوية…', 'بنختار التكوين والألوان…', 'بنرسم التصميم…', 'بنضيف اللوجو واللمسات…']} hint="التصميم ممكن ياخد لحد دقيقتين" />;

  return (
    <View style={{ gap: 14 }}>
      {msg ? <Banner tone={msg.tone} text={msg.text} /> : null}
      {gen.error ? <AiErrorView error={gen.error} onRetry={run} onBack={onBack} /> : null}

      {c.designs.map((d) => (
        <Card key={d.id} style={{ gap: 10, padding: 10 }}>
          <Image source={{ uri: d.url }} style={{ width: '100%', aspectRatio: ratioToAspect(d.ratio), borderRadius: radius.md, backgroundColor: colors.line }} contentFit="cover" transition={200} accessibilityLabel="تصميم المنشور" />
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>
            {d.current ? <Pill label="الغلاف الحالي" tone="ok" icon="check" /> : <Button title="اختاره غلاف" size="sm" kind="secondary" onPress={() => setAsCover(d.id)} disabled={c.published} />}
            <Button
              title="حفظ"
              icon="download"
              size="sm"
              kind="ghost"
              onPress={async () => {
                const r = await saveImageToGallery(d.url);
                setMsg({ tone: r.ok ? 'ok' : 'danger', text: r.message });
              }}
            />
            <Button title="مشاركة" icon="share-2" size="sm" kind="ghost" onPress={() => shareImage(d.url)} />
          </View>
        </Card>
      ))}

      {!c.published && !gen.error?.isPaywall ? (
        <Card style={{ gap: 14 }}>
          <AppText variant="title">{c.designs.length ? 'تصميم جديد' : 'صمّم المنشور ده'}</AppText>
          <ChipGroup label="المقاس" options={ratios} value={ratio} onChange={setRatio} />
          <Input label="توجيه إضافي (اختياري)" value={prompt} onChangeText={(t) => setPrompt(t.slice(0, 1200))} multiline style={{ minHeight: 70 }} placeholder="مثال: خلفية فاتحة وألوان البراند" />
          <SwitchRow label="حط اللوجو" value={includeLogo} onChange={setIncludeLogo} />
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
            {ref ? <Image source={{ uri: ref.uri }} style={{ width: 54, height: 54, borderRadius: 10 }} /> : null}
            <Button
              title={ref ? 'غيّر الصورة المرجعية' : 'صورة مرجعية (اختياري)'}
              icon="image"
              kind="ghost"
              size="sm"
              onPress={async () => {
                const r = await pickImage();
                if (r?.ok) setRef(r.file);
                else if (r && !r.ok) setMsg({ tone: 'danger', text: r.error });
              }}
            />
            {ref ? (
              <Pressable accessibilityRole="button" accessibilityLabel="إزالة الصورة" onPress={() => setRef(null)} hitSlop={10}>
                <Feather name="x" size={20} color={colors.mute2} />
              </Pressable>
            ) : null}
          </View>
          <Button title={showNumbers ? `صمّم (${c.costs.design} كريدت)` : 'صمّم'} icon="image" onPress={run} />
        </Card>
      ) : null}
    </View>
  );
}

/* ───────── Versions ───────── */

function HistoryTab({ c, onChanged }: { c: ContentDetail; onChanged: () => void }) {
  const [busy, setBusy] = useState<number | null>(null);
  const labels: Record<string, string> = { ai: 'من الذكاء الاصطناعي', edited: 'تعديل يدوي', ai_edit: 'تعديل بالكلام', restore: 'استرجاع' };
  if (!c.versions.length) return <Banner tone="info" text="لسه مفيش نسخ قديمة — أي تعديل هيحفظ النسخة السابقة هنا." />;
  return (
    <View style={{ gap: 10 }}>
      {c.versions.map((v) => (
        <Card key={v.id} style={{ gap: 8 }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
            <AppText variant="label">
              نسخة {v.n} · {labels[v.type] ?? v.type}
            </AppText>
            <AppText variant="small">{v.at}</AppText>
          </View>
          <AppText variant="caption" numberOfLines={4}>
            {v.text}
          </AppText>
          {!c.published ? (
            <Button
              title="استرجع النسخة دي"
              size="sm"
              kind="ghost"
              icon="rotate-ccw"
              loading={busy === v.id}
              onPress={async () => {
                setBusy(v.id);
                try {
                  await api.call('contents', { method: 'POST', json: { action: 'restore', id: c.id, version_id: v.id } });
                  onChanged();
                } finally {
                  setBusy(null);
                }
              }}
            />
          ) : null}
        </Card>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  tabs: { flexDirection: 'row', backgroundColor: colors.card, borderRadius: radius.md, padding: 4, borderWidth: 1, borderColor: colors.line },
  tab: { flex: 1, alignItems: 'center', paddingVertical: 9, borderRadius: 10 },
  tabOn: { backgroundColor: colors.ink },
});
