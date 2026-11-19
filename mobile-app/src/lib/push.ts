import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { router } from 'expo-router';
import { useEffect } from 'react';
import { Platform } from 'react-native';

import { api } from './api';
import { useAuth } from './auth-store';
import { screenHref } from './navigation';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: false,
    shouldSetBadge: false,
  }),
});

/**
 * Registers this device for push (publishing results, payment/plan updates) after sign-in.
 * Needs an EAS project id (set by `eas init`) — without it, in-app notifications still work.
 */
export async function registerForPush(): Promise<string | null> {
  if (Platform.OS === 'web' || !Device.isDevice) return null;
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'Spread AI',
      importance: Notifications.AndroidImportance.DEFAULT,
      lightColor: '#0C87EF',
    });
  }
  const current = await Notifications.getPermissionsAsync();
  let granted = current.granted;
  if (!granted && current.canAskAgain) granted = (await Notifications.requestPermissionsAsync()).granted;
  if (!granted) return null;
  const projectId = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId;
  if (!projectId) return null;
  try {
    const { data } = await Notifications.getExpoPushTokenAsync({ projectId });
    await api.auth('push_register', { method: 'POST', json: { push_token: data } });
    return data;
  } catch {
    return null;
  }
}

/** register once per sign-in + open the right screen when a notification is tapped */
export function usePushNotifications() {
  const status = useAuth((s) => s.status);
  useEffect(() => {
    if (status === 'signedIn') void registerForPush();
  }, [status]);

  useEffect(() => {
    if (Platform.OS === 'web') return;
    const sub = Notifications.addNotificationResponseReceivedListener((resp) => {
      const d = resp.notification.request.content.data as { screen?: string; id?: number } | undefined;
      if (d?.screen) router.push(screenHref(d.screen, d.id ?? null));
    });
    return () => sub.remove();
  }, []);
}
