import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert, View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Card, Divider, ErrorState, Input, Pill, SectionTitle, SkeletonList } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth-store';
import { isValidPhone } from '@/lib/format';
import { qk, queryClient, useSettingsHome } from '@/lib/queries';
import type { User } from '@/lib/types';

/** Account settings on the website's own settings API (api/settings.php) */
export default function Settings() {
  const q = useSettingsHome();
  const qc = useQueryClient();
  const setUser = useAuth((s) => s.setUser);
  const user = useAuth((s) => s.user);
  const logout = useAuth((s) => s.logout);
  const s = q.data;
  // null = untouched → show the server value (no state sync effect needed)
  const [nameEdit, setName] = useState<string | null>(null);
  const [phoneEdit, setPhone] = useState<string | null>(null);
  const name = nameEdit ?? s?.profile.name ?? '';
  const phone = phoneEdit ?? s?.profile.phone ?? '';
  const [pw, setPw] = useState({ current: '', new: '', confirm: '' });
  const [delPw, setDelPw] = useState('');
  const [msg, setMsg] = useState<{ tone: string; text: string } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const call = async (key: string, json: Record<string, unknown>, ok: string) => {
    setBusy(key);
    setMsg(null);
    try {
      const r = await api.call<Record<string, unknown>>('settings', { method: 'POST', json });
      setMsg({ tone: 'ok', text: ok });
      void qc.invalidateQueries({ queryKey: qk.settings });
      return r;
    } catch (e) {
      setMsg({ tone: 'danger', text: (e as ApiError).message });
      return null;
    } finally {
      setBusy(null);
    }
  };

  const saveProfile = async () => {
    if (name.trim().length < 2) return setMsg({ tone: 'danger', text: 'اكتب اسمك' });
    if (phone && !isValidPhone(phone)) return setMsg({ tone: 'danger', text: 'رقم الموبايل غير صحيح' });
    const r = await call('profile', { action: 'profile_save', name: name.trim(), phone }, 'اتحفظ ✓');
    if (r && user) setUser({ ...user, name: name.trim(), phone } as User);
  };

  const changePassword = async () => {
    if (pw.new.length < 8) return setMsg({ tone: 'danger', text: 'كلمة المرور الجديدة 8 حروف على الأقل' });
    if (pw.new !== pw.confirm) return setMsg({ tone: 'danger', text: 'كلمتين المرور مش متطابقين' });
    const r = await call('pw', { action: 'password', current: pw.current, new: pw.new, confirm: pw.confirm }, 'اتغيّرت كلمة المرور ✓');
    if (r) setPw({ current: '', new: '', confirm: '' });
  };

  const requestDeletion = () =>
    Alert.alert('حذف الحساب', 'هيتم حذف حسابك وكل بياناتك (المنشورات · التصميمات · الهوية) نهائيًا بعد 14 يوم. تقدر تلغي الطلب خلال المدة دي.', [
      { text: 'إلغاء', style: 'cancel' },
      {
        text: 'اطلب الحذف',
        style: 'destructive',
        onPress: () => void call('del', s?.security.has_password ? { action: 'delete_request', password: delPw } : { action: 'delete_request', email: user?.email }, 'اتسجّل طلب حذف الحساب'),
      },
    ]);

  return (
    <Screen header={<TopBar title="إعدادات الحساب" />} refreshing={q.isRefetching} onRefresh={() => q.refetch()}>
      {q.isLoading ? <SkeletonList rows={3} /> : null}
      {q.isError ? <ErrorState message={(q.error as Error).message} onRetry={() => q.refetch()} /> : null}
      {msg ? <Banner tone={msg.tone} text={msg.text} /> : null}
      {s ? (
        <>
          <Card style={{ gap: 12 }}>
            <SectionTitle title="البيانات الشخصية" />
            <Input label="الاسم" value={name} onChangeText={setName} />
            <Input label="الإيميل" value={s.profile.email} editable={false} hint="لتغيير الإيميل كلم الدعم" />
            <Input label="رقم الموبايل" value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
            <Button title="حفظ" icon="save" size="sm" loading={busy === 'profile'} onPress={saveProfile} />
          </Card>

          <Card style={{ gap: 12 }}>
            <SectionTitle title={s.security.has_password ? 'تغيير كلمة المرور' : 'اعمل كلمة مرور'} />
            {s.security.has_password ? <Input label="كلمة المرور الحالية" value={pw.current} onChangeText={(t) => setPw((p) => ({ ...p, current: t }))} secureTextEntry /> : null}
            <Input label="كلمة المرور الجديدة" value={pw.new} onChangeText={(t) => setPw((p) => ({ ...p, new: t }))} secureTextEntry autoComplete="new-password" />
            <Input label="تأكيدها" value={pw.confirm} onChangeText={(t) => setPw((p) => ({ ...p, confirm: t }))} secureTextEntry />
            <Button title="تغيير" icon="lock" size="sm" loading={busy === 'pw'} onPress={changePassword} />
          </Card>

          <Card style={{ gap: 10 }}>
            <SectionTitle title="الأجهزة والجلسات" />
            {s.security.sessions.map((x, i) => (
              <View key={x.id}>
                {i > 0 ? <Divider /> : null}
                <View style={{ flexDirection: 'row', alignItems: 'center', paddingVertical: 8, gap: 8 }}>
                  <View style={{ flex: 1 }}>
                    <AppText variant="bodyStrong">{x.device}</AppText>
                    <AppText variant="small">{x.where}</AppText>
                  </View>
                  {x.current ? (
                    <Pill label="الجهاز ده" tone="ok" />
                  ) : (
                    <Button title="إنهاء" kind="ghost" size="sm" onPress={() => void call('sess', { action: 'session_end', id: x.id }, 'اتنهت الجلسة')} />
                  )}
                </View>
              </View>
            ))}
            {s.security.sessions.length > 1 ? (
              <Button title="خروج من كل الأجهزة التانية" kind="ghost" size="sm" icon="log-out" loading={busy === 'others'} onPress={() => void call('others', { action: 'sessions_end_others' }, 'اتقفلت كل الجلسات التانية')} />
            ) : null}
            <AppText variant="small">التحقق بخطوتين: {s.security.two_fa ? 'مفعّل ✓' : 'مش مفعّل'} — بيتظبط من إعدادات الموقع.</AppText>
          </Card>

          <Card style={{ gap: 10 }}>
            <SectionTitle title="حذف الحساب" />
            {s.security.deletion ? (
              <>
                <Banner tone="danger" icon="alert-triangle" text={`حسابك هيتمسح يوم ${s.security.deletion.date} (باقي ${s.security.deletion.days} يوم).`} />
                <Button title="إلغاء طلب الحذف" kind="secondary" loading={busy === 'cancel'} onPress={() => void call('cancel', { action: 'delete_cancel' }, 'اتلغى طلب الحذف ✓')} />
              </>
            ) : (
              <>
                <AppText variant="caption">بنحتفظ بالحساب 14 يوم بعد الطلب علشان لو غيّرت رأيك، وبعدها البيانات بتتمسح نهائيًا.</AppText>
                {s.security.has_password ? <Input label="اكتب كلمة المرور للتأكيد" value={delPw} onChangeText={setDelPw} secureTextEntry /> : null}
                <Button title="اطلب حذف الحساب" kind="danger" size="sm" icon="trash-2" loading={busy === 'del'} onPress={requestDeletion} disabled={s.security.has_password && !delPw} />
              </>
            )}
          </Card>
          <Button
            title="خروج من الجهاز ده"
            kind="ghost"
            icon="log-out"
            onPress={async () => {
              await logout();
              queryClient.clear();
            }}
          />
        </>
      ) : null}
    </Screen>
  );
}
