import { Redirect } from 'expo-router';

import { useAuth } from '@/lib/auth-store';

export default function Index() {
  const status = useAuth((s) => s.status);
  return <Redirect href={status === 'signedIn' ? '/home' : '/welcome'} />;
}
