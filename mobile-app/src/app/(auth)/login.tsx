import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { Pressable, type TextInput, View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Input } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth-store';
import { isValidEmail } from '@/lib/format';
import type { LoginResult } from '@/lib/types';
import { colors } from '@/theme/tokens';

export default function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<{ msg: string; code?: string } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const pwRef = useRef<TextInput>(null);
  const signIn = useAuth((s) => s.signIn);
  const setChallenge = useAuth((s) => s.setChallenge);

  const submit = async () => {
    setError(null);
    setNotice(null);
    if (!isValidEmail(email)) return setError({ msg: 'اكتب إيميل صحيح' });
    if (!password) return setError({ msg: 'اكتب كلمة المرور' });
    setBusy(true);
    try {
      const r = await api.auth<LoginResult>('login', { method: 'POST', json: { email: email.trim(), password }, auth: false });
      if ('two_factor' in r && r.two_factor) {
        setChallenge({ token: r.challenge, emailHint: r.email_hint });
        router.push('/verify');
      } else if ('token' in r && r.token) {
        await signIn(r.token, r.user);
      }
    } catch (e) {
      const err = e as ApiError;
      setError({ msg: err.message, code: err.code });
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    try {
      const r = await api.auth<{ message: string }>('resend_verification', { method: 'POST', json: { email: email.trim() }, auth: false });
      setError(null);
      setNotice(r.message);
    } catch (e) {
      setError({ msg: (e as Error).message });
    }
  };

  return (
    <Screen header={<TopBar title="تسجيل الدخول" />}>
      <View style={{ gap: 6 }}>
        <AppText variant="h2">أهلًا بعودتك 👋</AppText>
        <AppText variant="caption">سجّل دخولك بنفس حسابك على منصة Spread AI</AppText>
      </View>
      {error ? <Banner tone="danger" icon="alert-circle" text={error.msg} /> : null}
      {notice ? <Banner tone="ok" icon="check-circle" text={notice} /> : null}
      {error?.code === 'email_unverified' ? <Button title="ابعتلي رابط تفعيل جديد" kind="secondary" size="sm" icon="mail" onPress={resend} /> : null}
      <Input
        label="البريد الإلكتروني"
        icon="mail"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        autoComplete="email"
        keyboardType="email-address"
        textContentType="emailAddress"
        returnKeyType="next"
        onSubmitEditing={() => pwRef.current?.focus()}
        placeholder="you@example.com"
      />
      <Input
        ref={pwRef}
        label="كلمة المرور"
        icon="lock"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="password"
        textContentType="password"
        returnKeyType="go"
        onSubmitEditing={submit}
      />
      <Pressable accessibilityRole="link" onPress={() => router.push('/forgot')} hitSlop={8}>
        <AppText variant="label" style={{ color: colors.blue }}>
          نسيت كلمة المرور؟
        </AppText>
      </Pressable>
      <Button title="دخول" icon="log-in" size="lg" loading={busy} onPress={submit} />
      {error?.code === 'use_google' ? (
        <AppText variant="caption">الدخول بجوجل متاح على الموقع حاليًا — أو اعمل كلمة مرور لحسابك من «نسيت كلمة المرور».</AppText>
      ) : null}
      <View style={{ flexDirection: 'row', gap: 6, justifyContent: 'center' }}>
        <AppText variant="caption">معندكش حساب؟</AppText>
        <Pressable accessibilityRole="link" onPress={() => router.replace('/register')}>
          <AppText variant="label" style={{ color: colors.blue }}>
            اعمل حساب جديد
          </AppText>
        </Pressable>
      </View>
    </Screen>
  );
}
