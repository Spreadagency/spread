import * as WebBrowser from 'expo-web-browser';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Input } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { isValidEmail, isValidPhone } from '@/lib/format';
import { useBootstrap } from '@/lib/queries';
import { colors } from '@/theme/tokens';

type Form = { name: string; email: string; phone: string; business_name: string; password: string; password_confirm: string; ref_code: string };

export default function Register() {
  const boot = useBootstrap();
  const [f, setF] = useState<Form>({ name: '', email: '', phone: '', business_name: '', password: '', password_confirm: '', ref_code: '' });
  const [errors, setErrors] = useState<Partial<Record<keyof Form, string>>>({});
  const [busy, setBusy] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const set = (k: keyof Form) => (v: string) => setF((p) => ({ ...p, [k]: v }));

  const validate = () => {
    const e: typeof errors = {};
    if (f.name.trim().length < 2) e.name = 'الاسم قصير جدًا';
    if (!isValidEmail(f.email)) e.email = 'الإيميل غير صحيح';
    if (!isValidPhone(f.phone)) e.phone = 'اكتب رقم موبايل صحيح (مثال: 01xxxxxxxxx)';
    if (f.password.length < 8) e.password = 'كلمة المرور 8 حروف على الأقل';
    if (f.password !== f.password_confirm) e.password_confirm = 'كلمتا المرور مش متطابقتين';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const submit = async () => {
    setServerError(null);
    if (!validate()) return;
    setBusy(true);
    try {
      const r = await api.auth<{ message: string }>('register', { method: 'POST', json: { ...f, email: f.email.trim() }, auth: false });
      setDone(r.message);
    } catch (e) {
      setServerError((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };

  if (done) {
    return (
      <Screen header={<TopBar title="إنشاء حساب" />}>
        <Banner tone="ok" icon="check-circle" text={done} />
        <AppText variant="body">بعد ما تفعّل الحساب من رابط الإيميل، ارجع هنا وسجّل دخولك.</AppText>
        <Button title="تسجيل الدخول" size="lg" onPress={() => router.replace('/login')} />
      </Screen>
    );
  }

  return (
    <Screen header={<TopBar title="إنشاء حساب" />}>
      <AppText variant="caption">نفس الحساب بيشتغل على الموقع والتطبيق.</AppText>
      {serverError ? <Banner tone="danger" icon="alert-circle" text={serverError} /> : null}
      <Input label="الاسم" value={f.name} onChangeText={set('name')} error={errors.name} autoComplete="name" textContentType="name" />
      <Input label="البريد الإلكتروني" value={f.email} onChangeText={set('email')} error={errors.email} autoCapitalize="none" keyboardType="email-address" autoComplete="email" />
      <Input label="رقم الموبايل" value={f.phone} onChangeText={set('phone')} error={errors.phone} keyboardType="phone-pad" autoComplete="tel" placeholder="01xxxxxxxxx" />
      <Input label="اسم النشاط (اختياري)" value={f.business_name} onChangeText={set('business_name')} hint="بيتحفظ في هوية البراند وتقدر تعدّله بعدين" />
      <Input label="كلمة المرور" value={f.password} onChangeText={set('password')} error={errors.password} secureTextEntry autoComplete="new-password" textContentType="newPassword" />
      <Input label="تأكيد كلمة المرور" value={f.password_confirm} onChangeText={set('password_confirm')} error={errors.password_confirm} secureTextEntry autoComplete="new-password" />
      <Input label="كود الإحالة (اختياري)" value={f.ref_code} onChangeText={(v) => set('ref_code')(v.toUpperCase())} autoCapitalize="characters" />
      <Button title="إنشاء الحساب" size="lg" loading={busy} onPress={submit} />
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 4, justifyContent: 'center' }}>
        <AppText variant="small">بإنشاء الحساب بتوافق على</AppText>
        <Pressable accessibilityRole="link" onPress={() => boot.data && WebBrowser.openBrowserAsync(boot.data.links.terms)}>
          <AppText variant="small" style={{ color: colors.blue }}>الشروط</AppText>
        </Pressable>
        <AppText variant="small">و</AppText>
        <Pressable accessibilityRole="link" onPress={() => boot.data && WebBrowser.openBrowserAsync(boot.data.links.privacy)}>
          <AppText variant="small" style={{ color: colors.blue }}>سياسة الخصوصية</AppText>
        </Pressable>
      </View>
    </Screen>
  );
}
