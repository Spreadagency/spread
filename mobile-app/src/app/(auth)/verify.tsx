import { Redirect } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Input } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth-store';
import type { User } from '@/lib/types';

/** 2FA: 6-digit code sent by e-mail; the challenge token (not a session) authorizes only this step */
export default function Verify() {
  const challenge = useAuth((s) => s.challenge);
  const signIn = useAuth((s) => s.signIn);
  const setChallenge = useAuth((s) => s.setChallenge);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ tone: 'danger' | 'ok'; text: string } | null>(null);

  if (!challenge) return <Redirect href="/login" />;

  const verify = async () => {
    if (!/^\d{6}$/.test(code)) return setMsg({ tone: 'danger', text: 'الكود 6 أرقام' });
    setBusy(true);
    setMsg(null);
    try {
      const r = await api.auth<{ token: string; user: User }>('verify_code', { method: 'POST', json: { code }, token: challenge.token });
      await signIn(r.token, r.user);
    } catch (e) {
      const err = e as ApiError;
      setMsg({ tone: 'danger', text: err.message });
      if (err.code === 'challenge_expired') setChallenge(null);
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    try {
      await api.auth('resend_code', { method: 'POST', json: {}, token: challenge.token });
      setMsg({ tone: 'ok', text: 'بعتنالك كود جديد على الإيميل' });
    } catch (e) {
      setMsg({ tone: 'danger', text: (e as Error).message });
    }
  };

  return (
    <Screen header={<TopBar title="التحقق بخطوتين" />}>
      <View style={{ gap: 6 }}>
        <AppText variant="h2">اكتب الكود</AppText>
        <AppText variant="caption">بعتنا كود من 6 أرقام على {challenge.emailHint} — صالح 10 دقايق.</AppText>
      </View>
      {msg ? <Banner tone={msg.tone} text={msg.text} /> : null}
      <Input
        label="كود التحقق"
        value={code}
        onChangeText={(t) => setCode(t.replace(/\D/g, '').slice(0, 6))}
        keyboardType="number-pad"
        textContentType="oneTimeCode"
        autoComplete="one-time-code"
        maxLength={6}
        style={{ letterSpacing: 8, fontSize: 22 }}
        onSubmitEditing={verify}
      />
      <Button title="تأكيد ودخول" size="lg" icon="shield" loading={busy} onPress={verify} />
      <Button title="ابعت كود تاني" kind="ghost" size="sm" onPress={resend} />
    </Screen>
  );
}
