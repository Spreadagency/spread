import { QueryClient, useQuery } from '@tanstack/react-query';

import { api, ApiError } from './api';
import { useAuth } from './auth-store';
import type { Bootstrap, CreditsResp, Dashboard, NotificationsResp, Package, SettingsHome } from './types';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 10 * 60_000,
      // only transient network failures are retried (never 4xx business errors)
      retry: (count, err) => err instanceof ApiError && err.isNetwork && count < 2,
      refetchOnWindowFocus: false,
    },
    mutations: { retry: false },
  },
});

export const qk = {
  bootstrap: ['bootstrap'] as const,
  dashboard: ['dashboard'] as const,
  notifications: ['notifications'] as const,
  credits: ['credits'] as const,
  packages: ['packages'] as const,
  library: (f: Record<string, unknown>) => ['library', f] as const,
  content: (id: number) => ['content', id] as const,
  settings: ['settings'] as const,
  studio: ['studio'] as const,
  studioDesign: (id: number) => ['studioDesign', id] as const,
  brand: ['brand'] as const,
  campaigns: ['campaigns'] as const,
  board: (id: number) => ['board', id] as const,
};

export function useBootstrap() {
  return useQuery({
    queryKey: qk.bootstrap,
    queryFn: () => api.app<Bootstrap>('bootstrap'),
    staleTime: 10 * 60_000,
  });
}

/** queries that need a session only run while signed in (re-renders on sign-in/out) */
const useSignedIn = () => useAuth((s) => s.status === 'signedIn');

export function useDashboard() {
  const on = useSignedIn();
  return useQuery({ queryKey: qk.dashboard, queryFn: () => api.app<Dashboard>('dashboard'), enabled: on });
}

export function useNotifications() {
  const on = useSignedIn();
  return useQuery({ queryKey: qk.notifications, queryFn: () => api.app<NotificationsResp>('notifications'), enabled: on });
}

export function useCredits() {
  const on = useSignedIn();
  return useQuery({ queryKey: qk.credits, queryFn: () => api.app<CreditsResp>('credits'), enabled: on });
}

export function usePackages() {
  const on = useSignedIn();
  return useQuery({
    queryKey: qk.packages,
    queryFn: () => api.app<{ packages: Package[]; purchases: 'off' | 'web' }>('packages'),
    enabled: on,
    staleTime: 5 * 60_000,
  });
}

export function useSettingsHome() {
  const on = useSignedIn();
  return useQuery({ queryKey: qk.settings, queryFn: () => api.call<SettingsHome>('settings', { query: { action: 'home' } }), enabled: on });
}

/** after any credit-consuming action: balance/usage/dashboard must refresh from the server (source of truth) */
export function invalidateAfterCharge() {
  void queryClient.invalidateQueries({ queryKey: qk.dashboard });
  void queryClient.invalidateQueries({ queryKey: qk.credits });
  void queryClient.invalidateQueries({ queryKey: ['library'] });
}
