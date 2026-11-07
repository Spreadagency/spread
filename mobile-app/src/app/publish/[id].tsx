import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { useMemo, useRef, useState } from 'react';
import { Alert, View } from 'react-native';

import { AiErrorView } from '@/components/ai-states';
import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, ChipGroup, EmptyState, Input, SkeletonList } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { uuid } from '@/lib/encoding';
import { openOnWebsite } from '@/lib/navigation';
import { invalidateAfterCharge, qk, useSettingsHome } from '@/lib/queries';
import type { ContentDetail } from '@/lib/types';
import { radius } from '@/theme/tokens';

type Mode = 'now' | 'schedule';

const pad = (n: number) => String(n).padStart(2, '0');
const DAY_NAMES = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

/** Publishing goes through the website's own endpoint (ajax/publish-direct.php) — the Graph API result is shown as-is */
export default function Publish() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const cid = Number(id);
  const qc = useQueryClient();
  const content = useQuery({ queryKey: qk.content(cid), queryFn: () => api.call<{ content: ContentDetail }>('contents', { query: { action: 'get', id: cid } }) });
  const settings = useSettingsHome();
  const acc = settings.data?.accounts;
  const pages = (acc?.pages ?? []).filter((p) => p.status === 'active');
  const [pageId, setPageId] = useState<string | null>(null);
  const [platform, setPlatform] = useState('facebook');
  const [mode, setMode] = useState<Mode>('now');
  const days = useMemo(() => {
    const out: { key: string; label: string }[] = [];
    const now = new Date();
    for (let i = 0; i < 14; i++) {
      const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + i);
      const key = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      out.push({ key, label: i === 0 ? 'النهارده' : i === 1 ? 'بكرة' : `${DAY_NAMES[d.getDay()]} ${d.getDate()}/${d.getMonth() + 1}` });
    }
    return out;
  }, []);
  const [day, setDay] = useState(days[1].key);
  const [time, setTime] = useState('19:00');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ tone: string; text: string; url?: string } | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const key = useRef(uuid());

  const c = content.data?.content;
  const page = pages.find((p) => String(p.id) === (pageId ?? String(pages[0]?.id ?? '')));
  const platformOptions = [{ key: 'facebook', label: 'فيسبوك' }, ...(page?.has_ig ? [{ key: 'instagram', label: 'إنستجرام' }, { key: 'both', label: 'الاتنين' }] : [])];

  const submit = async () => {
    if (!page || !c) return;
    setError(null);
    setResult(null);
    if (mode === 'schedule' && !/^([01]\d|2[0-3]):[0-5]\d$/.test(time)) {
      setResult({ tone: 'danger', text: 'اكتب الوقت بالشكل ده 19:30' });
      return;
    }
    setBusy(true);
    try {
      const r = await api.call<{ msg?: string; post_url?: string; partial_errors?: string[]; queued?: boolean }>('publish_direct', {
        method: 'POST',
        form: {
          content_id: c.id,
          connection_id: page.id,
          platform,
          mode,
          scheduled_at: mode === 'schedule' ? `${day} ${time}` : undefined,
        },
        idempotencyKey: key.current,
        timeoutMs: 120_000,
      });
      key.current = uuid();
      const partial = r.partial_errors?.length ? ` — تنبيه: ${r.partial_errors.join(' · ')}` : '';
      setResult({ tone: r.partial_errors?.length ? 'warn' : 'ok', text: (r.msg ?? (mode === 'now' ? 'اتنشر ✓' : 'اتجدول ✓')) + partial, url: r.post_url || undefined });
      invalidateAfterCharge();
      void qc.invalidateQueries({ queryKey: qk.content(cid) });
    } catch (e) {
      const err = e as ApiError;
      if (!err.isNetwork) key.current = uuid();
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const cancelSchedule = () =>
    Alert.alert('إلغاء الجدولة؟', 'المنشور هيرجع مسودة ومش هيتنشر في الميعاد.', [
      { text: 'لا', style: 'cancel' },
      {
        text: 'إلغاء الجدولة',
        style: 'destructive',
        onPress: async () => {
          try {
            await api.call('schedule_content', { method: 'POST', form: { content_id: cid, cancel: 1 } });
            setResult({ tone: 'ok', text: 'اتلغت الجدولة' });
            void qc.invalidateQueries({ queryKey: qk.content(cid) });
            void qc.invalidateQueries({ queryKey: ['library'] });
          } catch (e) {
            setResult({ tone: 'danger', text: (e as Error).message });
          }
        },
      },
    ]);

  return (
    <Screen header={<TopBar title="النشر" />}>
      {content.isLoading || settings.isLoading ? <SkeletonList rows={2} /> : null}
      {c ? (
        <Card style={{ flexDirection: 'row', gap: 12, alignItems: 'center' }}>
          {c.cover ? <Image source={{ uri: c.cover }} style={{ width: 64, height: 64, borderRadius: radius.md }} /> : null}
          <AppText variant="bodyStrong" numberOfLines={3} style={{ flex: 1 }}>
            {c.hook}
          </AppText>
        </Card>
      ) : null}

      {result ? <Banner tone={result.tone} text={result.text} icon={result.tone === 'ok' ? 'check-circle' : 'alert-circle'} /> : null}
      {result?.url ? <Button title="افتح المنشور على فيسبوك" kind="ghost" size="sm" icon="external-link" onPress={() => void import('expo-web-browser').then((w) => w.openBrowserAsync(result.url!))} /> : null}
      {error ? <AiErrorView error={error} onRetry={submit} /> : null}

      {c?.published ? <Banner tone="ok" text="المنشور ده اتنشر بالفعل" /> : null}
      {c?.scheduled ? (
        <Card style={{ gap: 8 }}>
          <AppText variant="bodyStrong">مجدول: {c.scheduled}</AppText>
          <Button title="إلغاء الجدولة" kind="ghost" size="sm" icon="x-circle" onPress={cancelSchedule} />
        </Card>
      ) : null}

      {c && !c.published && acc ? (
        !acc.allowed ? (
          <Banner tone="warn" text="النشر التلقائي مش مفعّل لحسابك — انسخ المنشور واحفظ التصميم وانشرهم يدوي." />
        ) : !pages.length ? (
          <EmptyState icon="link" title="اربط صفحة الأول" body="محتاج صفحة فيسبوك مربوطة علشان تنشر من التطبيق" action="اربط صفحة" onAction={() => void openOnWebsite('social-accounts.php')} />
        ) : !c.designs.length && c.format !== 'video' ? (
          <Card style={{ gap: 8 }}>
            <AppText variant="bodyStrong">المنشور محتاج تصميم قبل النشر</AppText>
            <Button title="صمّم المنشور" icon="image" kind="secondary" size="sm" onPress={() => router.back()} />
          </Card>
        ) : (
          <View style={{ gap: 16 }}>
            <ChipGroup label="الصفحة" options={pages.map((p) => ({ key: String(p.id), label: p.name }))} value={String(page?.id ?? '')} onChange={setPageId} />
            <ChipGroup label="المنصة" options={platformOptions} value={platform} onChange={setPlatform} />
            <ChipGroup<Mode>
              label="إمتى؟"
              options={[
                { key: 'now', label: 'انشر دلوقتي', emoji: '⚡' },
                { key: 'schedule', label: 'جدوِل', emoji: '🕒' },
              ]}
              value={mode}
              onChange={setMode}
            />
            {mode === 'schedule' ? (
              <Card style={{ gap: 12 }}>
                <ChipGroup label="اليوم" options={days} value={day} onChange={setDay} />
                <Input label="الوقت (24 ساعة)" value={time} onChangeText={setTime} keyboardType="numbers-and-punctuation" maxLength={5} hint="بتوقيت القاهرة — مثال 19:30" />
              </Card>
            ) : null}
            <Button title={mode === 'now' ? 'انشر دلوقتي' : 'جدوِل المنشور'} icon="send" size="lg" loading={busy} onPress={submit} />
          </View>
        )
      ) : null}
    </Screen>
  );
}
