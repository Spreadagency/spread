import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, View } from 'react-native';

import { AiErrorView, AiProgress } from '@/components/ai-states';
import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, ChipGroup, ErrorState, Input, SkeletonList } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { ratioToAspect } from '@/lib/format';
import { saveImageToGallery, shareImage } from '@/lib/media';
import { qk, useDashboard } from '@/lib/queries';
import type { StudioDesignResult } from '@/lib/types';
import { useAiAction } from '@/lib/use-ai-action';
import { colors, radius } from '@/theme/tokens';

type Design = {
  id: number;
  url: string;
  mode_label: string;
  ratio: string;
  ago: string;
  post_id: number | null;
  post_status: string;
  versions: { id: number; n: number; url: string; note: string; current: boolean }[];
};

export default function DesignScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const did = Number(id);
  const qc = useQueryClient();
  const dash = useDashboard();
  const showNumbers = dash.data?.usage.show_numbers ?? false;
  const q = useQuery({ queryKey: qk.studioDesign(did), queryFn: () => api.call<{ design: Design }>('studio', { query: { action: 'design', id: did } }) });
  const d = q.data?.design;
  const [msg, setMsg] = useState<{ tone: string; text: string } | null>(null);
  const [edit, setEdit] = useState('');
  const [post, setPost] = useState<{ open: boolean; caption: string; hashtags: string; platform: string }>({ open: false, caption: '', hashtags: '', platform: 'both' });
  const [posting, setPosting] = useState(false);

  const editGen = useAiAction(async (text: string, key) =>
    api.call<StudioDesignResult>('studio_design', {
      method: 'POST',
      form: { action: 'generate', mode: 'free', design_edit: '1', edit_of: did, free_prompt: text, ratio: d?.ratio },
      b64Fields: ['free_prompt'],
      idempotencyKey: key,
      timeoutMs: 300_000,
    }),
  );

  const runEdit = async () => {
    if (edit.trim().length < 3) return setMsg({ tone: 'danger', text: 'اكتب التعديل اللي عايزه' });
    const r = await editGen.execute(edit.trim());
    if (r) {
      setEdit('');
      void qc.invalidateQueries({ queryKey: qk.studio });
      router.replace({ pathname: '/design/[id]', params: { id: String(r.design_id) } });
    }
  };

  const toPost = async () => {
    setPosting(true);
    try {
      const r = await api.call<{ content_id: number }>('studio', {
        method: 'POST',
        json: { action: 'to_post', id: did, caption: post.caption, hashtags: post.hashtags, platform: post.platform },
      });
      void qc.invalidateQueries({ queryKey: ['library'] });
      router.push({ pathname: '/content/[id]', params: { id: String(r.content_id) } });
    } catch (e) {
      setMsg({ tone: 'danger', text: (e as ApiError).message });
    } finally {
      setPosting(false);
    }
  };

  return (
    <Screen header={<TopBar title={d?.mode_label ?? 'التصميم'} />} refreshing={q.isRefetching} onRefresh={() => q.refetch()}>
      {q.isLoading ? <SkeletonList rows={2} /> : null}
      {q.isError ? <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} /> : null}
      {d ? (
        <>
          {msg ? <Banner tone={msg.tone} text={msg.text} /> : null}
          <Image
            source={{ uri: d.url }}
            style={{ width: '100%', aspectRatio: ratioToAspect(d.ratio), borderRadius: radius.lg, backgroundColor: colors.line }}
            contentFit="contain"
            transition={200}
            accessibilityLabel="التصميم"
          />
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Button
              title="حفظ في الصور"
              icon="download"
              kind="secondary"
              size="sm"
              style={{ flex: 1 }}
              onPress={async () => {
                const r = await saveImageToGallery(d.url);
                setMsg({ tone: r.ok ? 'ok' : 'danger', text: r.message });
              }}
            />
            <Button title="مشاركة" icon="share-2" kind="secondary" size="sm" style={{ flex: 1 }} onPress={() => shareImage(d.url)} />
          </View>

          {d.versions.length > 1 ? (
            <View style={{ gap: 8 }}>
              <AppText variant="label">النسخ</AppText>
              <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
                {d.versions.map((v) => (
                  <Pressable
                    key={v.id}
                    accessibilityRole="imagebutton"
                    accessibilityLabel={`نسخة ${v.n}`}
                    onPress={() => !v.current && router.replace({ pathname: '/design/[id]', params: { id: String(v.id) } })}>
                    <Image source={{ uri: v.url }} style={{ width: 72, height: 72, borderRadius: 12, borderWidth: v.current ? 2 : 0, borderColor: colors.blue }} contentFit="cover" />
                  </Pressable>
                ))}
              </ScrollView>
            </View>
          ) : null}

          {editGen.busy ? (
            <AiProgress title="بنعدّل التصميم" steps={['بنقرا التعديل…', 'بنرسم النسخة الجديدة…']} />
          ) : (
            <Card style={{ gap: 10 }}>
              <AppText variant="title">✨ عدّل التصميم</AppText>
              {editGen.error ? <AiErrorView error={editGen.error} onRetry={runEdit} /> : null}
              <Input value={edit} onChangeText={(t) => setEdit(t.slice(0, 1200))} placeholder="مثال: غيّر الخلفية للون أفتح وكبّر العنوان" multiline style={{ minHeight: 70 }} />
              <Button title={showNumbers ? 'نسخة معدّلة (بيتخصم تصميم)' : 'نسخة معدّلة'} icon="edit-2" size="sm" onPress={runEdit} />
            </Card>
          )}

          {d.post_id ? (
            <Button title="افتح المنشور المرتبط" icon="file-text" kind="dark" onPress={() => router.push({ pathname: '/content/[id]', params: { id: String(d.post_id) } })} />
          ) : post.open ? (
            <Card style={{ gap: 10 }}>
              <AppText variant="title">حوّله لمنشور</AppText>
              <Input label="الكابشن" value={post.caption} onChangeText={(t) => setPost((p) => ({ ...p, caption: t }))} multiline />
              <Input label="الهاشتاجات" value={post.hashtags} onChangeText={(t) => setPost((p) => ({ ...p, hashtags: t }))} />
              <ChipGroup
                label="المنصة"
                options={[
                  { key: 'facebook', label: 'فيسبوك' },
                  { key: 'instagram', label: 'إنستجرام' },
                  { key: 'both', label: 'الاتنين' },
                ]}
                value={post.platform}
                onChange={(v) => setPost((p) => ({ ...p, platform: v }))}
              />
              <Button title="احفظ كمنشور" icon="check" loading={posting} onPress={toPost} />
            </Card>
          ) : (
            <Button title="حوّله لمنشور" icon="file-plus" kind="dark" onPress={() => setPost((p) => ({ ...p, open: true }))} />
          )}
        </>
      ) : null}
    </Screen>
  );
}
