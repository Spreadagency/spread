import { useState } from 'react';

import { Screen, TopBar } from '@/components/screen';
import { AppText, Banner, Button, Input } from '@/components/ui';
import { api, type ApiError } from '@/lib/api';
import { isValidEmail } from '@/lib/format';

/** Sends the website's reset e-mail; the reset link opens the secure web page (same flow as the site). */
export default function Forgot() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ tone: 'ok' | 'danger'; text: string } | null>(null);

  const submit = async () => {
    if (!isValidEmail(email)) return setMsg({ tone: 'danger', text: 'اكتب إيميل صحيح' });
    setBusy(true);
    try {
      const r = await api.auth<{ message: string }>('forgot_password', { method: 'POST', json: { email: email.trim() }, auth: false });
      setMsg({ tone: 'ok', text: r.message });
    } catch (e) {
      setMsg({ tone: 'danger', text: (e as ApiError).message });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen header={<TopBar title="نسيت كلمة المرور" />}>
      <AppText variant="body">اكتب إيميلك وهنبعتلك رابط تعيين كلمة مرور جديدة.</AppText>
      {msg ? <Banner tone={msg.tone} text={msg.text} /> : null}
      <Input label="البريد الإلكتروني" icon="mail" value={email} onChangeText={setEmail} autoCapitalize="none" keyboardType="email-address" autoComplete="email" onSubmitEditing={submit} />
      <Button title="ابعت الرابط" icon="send" size="lg" loading={busy} onPress={submit} />
    </Screen>
  );
}
