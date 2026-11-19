import { router, type Href } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';

import { api } from './api';
import type { Route } from './types';

/** backend "route" objects (mapped from website URLs) → app screens */
export function screenHref(screen: string, id?: number | null): Href {
  switch (screen) {
    case 'projects':
      return '/projects';
    case 'content':
      return id ? { pathname: '/content/[id]', params: { id: String(id) } } : '/projects';
    case 'credits':
      return '/credits';
    case 'packages':
      return '/packages';
    case 'settings':
      return '/settings';
    case 'brand':
      return '/brand';
    case 'create':
      return '/create';
    case 'ideas':
      return '/ideas';
    case 'studio':
      return '/studio';
    case 'publishing':
      return '/publishing';
    case 'notifications':
      return '/notifications';
    case 'help':
      return '/help';
    default:
      return '/home';
  }
}

export function openRoute(r: Route | null | undefined) {
  if (!r) return;
  if (r.kind === 'external') {
    void WebBrowser.openBrowserAsync(r.url);
    return;
  }
  if (r.kind === 'notification') {
    void api.app('notif_read', { method: 'POST', json: { id: r.id } }).catch(() => undefined);
    router.push('/notifications');
    return;
  }
  router.push(screenHref(r.screen, r.id));
}

/**
 * Pages that only exist on the website (Facebook page connection, brand sources, …):
 * the server issues a one-time link (2 minutes) that opens the site already signed in.
 */
export async function openOnWebsite(target: string) {
  const r = await api.app<{ url: string }>('web_handoff', { method: 'POST', json: { target } });
  await WebBrowser.openBrowserAsync(r.url, { dismissButtonStyle: 'close', showTitle: true });
}
