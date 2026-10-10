import { useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';
import { Pressable, View } from 'react-native';

import { AiErrorView, AiProgress } from '@/components/ai-states';
import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, ChipGroup, EmptyState, Input, Pill, SkeletonList } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { uuid } from '@/lib/encoding';
import { invalidateAfterCharge, qk, useDashboard } from '@/lib/queries';
import { useAiAction } from '@/lib/use-ai-action';
import { colors } from '@/theme/tokens';

/**
 * Ideas → choose → post, on the website's campaign flow (api/campaigns + api/campaign-flow):
 * ideas are charged once per generation (batch 0); each post is charged when written.
 */
type Idea = {
  id: number;
  n: string;
  angle: string;
  title: string;
  desc: string;
  audience: string;
  hook?: string;
  selected?: boolean;
  format_label: string;
  content: { id: number; status: { label: string } } | null;
};
type Board = { campaign: { id: number; title: string; topic: string | null }; ideas: Idea[]; health: { pct: number; gate: number; unlocked: boolean }; costs: { content: number; ideas: Record<string, number> } };
type CampaignRow = { id: number; title: string; ideas_count: number; updated_at: string; status: string };

const ANGLES: Record<string, string> = {
  storytelling: 'قصصي',
  promotional: 'إعلاني',
  awareness: 'توعوي',
  educational: 'تعليمي',
  engaging: 'تفاعلي',
  offer: 'عرض',
  trend: 'ترند',
};

const GOALS = [
  { key: 'offer', label: 'عرض أو خصم', emoji: '🏷' },
  { key: 'bookings', label: 'زيادة الحجوزات', emoji: '📅' },
  { key: 'awareness', label: 'التوعية', emoji: '💡' },
  { key: 'trust', label: 'بناء الثقة', emoji: '🤝' },
  { key: 'launch', label: 'إطلاق خدمة', emoji: '🚀' },
  { key: 'engagement', label: 'تفاعل الجمهور', emoji: '💬' },
];

export default function Ideas() {
  const params = useLocalSearchParams<{ campaign?: string }>();
  const [campaignId, setCampaignId] = useState<number | null>(params.campaign ? Number(params.campaign) : null);
  return campaignId ? <IdeasBoard id={campaignId} onNew={() => setCampaignId(null)} /> : <NewIdeas onCreated={setCampaignId} />;
}

function NewIdeas({ onCreated }: { onCreated: (id: number) => void }) {
  const dash = useDashboard();
  const showNumbers = dash.data?.usage.show_numbers ?? false;
  const [goal, setGoal] = useState('offer');
  const [topic, setTopic] = useState('');
  const [count, setCount] = useState('5');
  const [err, setErr] = useState<string | null>(null);
  const qc = useQueryClient();
  const recent = useQuery({ queryKey: qk.campaigns, queryFn: () => api.call<{ campaigns: CampaignRow[] }>('campaigns', { query: { action: 'list' } }) });
  // survives a network drop: the retry continues the same campaign/batch with the same keys (no double charge)
  const pending = useRef<{ campaignId: number; total: number; keys: string[]; next: number } | null>(null);

  const gen = useAiAction(async (_: null) => {
    const total = Number(count);
    if (!pending.current || pending.current.total !== total) {
      // 1) campaign container (same as the website)
      const c = await api.call<{ campaign: { id: number } }>('campaigns', {
        method: 'POST',
        json: { action: 'create', title: topic.trim().slice(0, 60) || undefined, goal, topic: topic.trim() },
      });
      pending.current = { campaignId: c.campaign.id, total, keys: Array.from({ length: Math.ceil(total / 5) }, () => uuid()), next: 0 };
    }
    // 2) idea batches of 5 — only batch 0 is charged
    const p = pending.current;
    try {
      for (let b = p.next; b < p.keys.length; b++) {
        await api.call('campaign_flow', {
          method: 'POST',
          json: { action: 'ideas_generate', id: p.campaignId, batch: b, total },
          idempotencyKey: p.keys[b],
          timeoutMs: 300_000,
        });
        p.next = b + 1;
      }
    } catch (e) {
      // business error (refunded / rejected): start clean next time · network drop: resume same batch & key
      if (!(e instanceof ApiError && e.isNetwork)) pending.current = null;
      throw e;
    }
    pending.current = null;
    return p.campaignId;
  });

  const run = async () => {
    setErr(null);
    if (topic.trim().length < 3) return setErr('اكتب هدف المحتوى أو الموضوع (مثلًا: عرض الصيف على الخدمات)');
    const id = await gen.execute(null);
    if (id) {
      void qc.invalidateQueries({ queryKey: qk.campaigns });
      onCreated(id);
    }
  };

  return (
    <Screen header={<TopBar title="أفكار لمنشورات" />}>
      {gen.busy ? (
        <AiProgress title="بنفكر في أفكار لبراندك" steps={['بنقرا هوية البراند…', 'بندوّر على زوايا مختلفة…', 'بنكتب الأفكار…', 'بنرتبها…']} />
      ) : (
        <>
          <AppText variant="body">قول هدفك، والذكاء الاصطناعي يقترح أفكار منشورات بزوايا مختلفة (قصصي · إعلاني · تفاعلي…). تختار الفكرة وهو يكتب المنشور.</AppText>
          {err ? <Banner tone="danger" text={err} /> : null}
          {gen.error ? <AiErrorView error={gen.error} onRetry={run} /> : null}
          {gen.error?.code === 'no_brand' ? <Button title="كمّل هوية البراند" kind="secondary" onPress={() => router.push('/brand')} /> : null}
          <ChipGroup label="هدف المحتوى" options={GOALS} value={goal} onChange={setGoal} />
          <Input label="الموضوع أو الرسالة" value={topic} onChangeText={(t) => setTopic(t.slice(0, 300))} multiline style={{ minHeight: 80 }} placeholder="مثال: خصم 20% على جلسات التنظيف لحد آخر الشهر" />
          <ChipGroup label="عدد الأفكار" options={[{ key: '5', label: '5 أفكار' }, { key: '10', label: '10 أفكار' }]} value={count} onChange={setCount} />
          <Button title={showNumbers ? 'اقترح أفكار (بيتخصم من رصيدك)' : 'اقترح أفكار'} icon="zap" size="lg" onPress={run} />
        </>
      )}
      {recent.data?.campaigns?.length ? (
        <View style={{ gap: 8 }}>
          <AppText variant="h3">أفكار سابقة</AppText>
          {recent.data.campaigns.slice(0, 6).map((c) => (
            <Card key={c.id} onPress={() => onCreated(c.id)} style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }} accessibilityLabel={c.title}>
              <AppText variant="bodyStrong" style={{ flex: 1 }} numberOfLines={1}>
                {c.title}
              </AppText>
              <Pill label={`${c.ideas_count} فكرة`} tone="mute" />
            </Card>
          ))}
        </View>
      ) : null}
    </Screen>
  );
}

function IdeasBoard({ id, onNew }: { id: number; onNew: () => void }) {
  const qc = useQueryClient();
  const dash = useDashboard();
  const showNumbers = dash.data?.usage.show_numbers ?? false;
  const board = useQuery({ queryKey: qk.board(id), queryFn: () => api.call<Board>('campaign_flow', { query: { action: 'board', id } }) });
  const [working, setWorking] = useState<number | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const keys = useRef<Record<number, string>>({});

  const write = async (idea: Idea) => {
    if (working) return;
    setWorking(idea.id);
    setError(null);
    keys.current[idea.id] ??= uuid();
    try {
      const r = await api.call<{ idea: Idea }>('campaign_flow', {
        method: 'POST',
        json: { action: 'content_generate', id, idea_id: idea.id },
        idempotencyKey: keys.current[idea.id],
        timeoutMs: 300_000,
      });
      delete keys.current[idea.id];
      invalidateAfterCharge();
      void qc.invalidateQueries({ queryKey: qk.board(id) });
      const cid = r.idea.content?.id;
      if (cid) router.push({ pathname: '/content/[id]', params: { id: String(cid), fresh: '1' } });
    } catch (e) {
      const err = e as ApiError;
      if (!err.isNetwork) delete keys.current[idea.id];
      setError(err);
    } finally {
      setWorking(null);
    }
  };

  const toggle = async (idea: Idea) => {
    await api.call('campaign_flow', { method: 'POST', json: { action: 'idea_toggle', id, idea_id: idea.id, selected: idea.selected ? 0 : 1 } }).catch(() => undefined);
    void qc.invalidateQueries({ queryKey: qk.board(id) });
  };

  const b = board.data;
  return (
    <Screen header={<TopBar title={b?.campaign.title ?? 'الأفكار'} />} refreshing={board.isRefetching} onRefresh={() => board.refetch()}>
      {board.isLoading ? <SkeletonList rows={4} /> : null}
      {board.isError ? <Banner tone="danger" text={(board.error as Error).message} /> : null}
      {working ? <AiProgress title="بنكتب المنشور من الفكرة" /> : null}
      {error ? <AiErrorView error={error} /> : null}
      {error?.code === 'brand_gate' ? <Button title="كمّل هوية البراند" icon="briefcase" kind="secondary" onPress={() => router.push('/brand')} /> : null}
      {b && !b.health.unlocked ? (
        <Banner tone="warn" icon="alert-triangle" text={`كتابة المنشورات من الأفكار محتاجة هوية البراند ${b.health.gate}% على الأقل — هويتك دلوقتي ${b.health.pct}%.`} />
      ) : null}
      {b && !b.ideas.length ? <EmptyState icon="zap" title="مفيش أفكار لسه" action="اقترح أفكار جديدة" onAction={onNew} /> : null}
      {b?.ideas.map((i) => (
        <Card key={i.id} style={{ gap: 8, borderColor: i.selected ? colors.blue : colors.line }}>
          <View style={{ flexDirection: 'row', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
            <Pill label={i.n} tone="mute" />
            {i.angle ? <Pill label={ANGLES[i.angle] ?? i.angle} tone="violet" /> : null}
            <Pill label={i.format_label} tone="info" />
          </View>
          <AppText variant="title">{i.title}</AppText>
          {i.desc ? <AppText variant="caption">{i.desc}</AppText> : null}
          {i.content ? (
            <Button title={`افتح المنشور · ${i.content.status.label}`} kind="secondary" size="sm" icon="file-text" onPress={() => router.push({ pathname: '/content/[id]', params: { id: String(i.content!.id) } })} />
          ) : (
            <View style={{ flexDirection: 'row', gap: 8 }}>
              <Button
                title={showNumbers ? `اكتب المنشور (${b.costs.content})` : 'اكتب المنشور'}
                icon="edit-3"
                size="sm"
                style={{ flex: 1 }}
                loading={working === i.id}
                disabled={!!working || !b.health.unlocked}
                onPress={() => write(i)}
              />
              <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: !!i.selected }} onPress={() => toggle(i)} style={{ justifyContent: 'center', paddingHorizontal: 8 }}>
                <AppText variant="label" style={{ color: i.selected ? colors.blue : colors.mute3 }}>
                  {i.selected ? '★ مختارة' : '☆ اختار'}
                </AppText>
              </Pressable>
            </View>
          )}
        </Card>
      ))}
      {b ? <Button title="أفكار جديدة" kind="ghost" icon="plus" onPress={onNew} /> : null}
    </Screen>
  );
}
