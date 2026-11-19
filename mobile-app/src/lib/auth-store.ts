import { create } from 'zustand';

import { api, configureApi } from './api';
import { secureDelete, secureGet, secureSet } from './secure-storage';
import type { User } from './types';

const TOKEN_KEY = 'spread.token.v1';

type AuthState = {
  status: 'loading' | 'signedOut' | 'signedIn';
  token: string | null;
  user: User | null;
  /** 2FA in progress: short-lived challenge token + masked e-mail */
  challenge: { token: string; emailHint: string } | null;
  restore: () => Promise<void>;
  signIn: (token: string, user: User) => Promise<void>;
  setUser: (user: User) => void;
  setChallenge: (c: AuthState['challenge']) => void;
  /** local sign-out (server already rejected the token, or user logged out) */
  clear: () => Promise<void>;
  logout: () => Promise<void>;
};

export const useAuth = create<AuthState>((set, get) => ({
  status: 'loading',
  token: null,
  user: null,
  challenge: null,

  restore: async () => {
    const token = await secureGet(TOKEN_KEY);
    if (!token) {
      set({ status: 'signedOut', token: null, user: null });
      return;
    }
    set({ token });
    try {
      const me = await api.auth<{ user: User }>('me');
      set({ status: 'signedIn', user: me.user });
    } catch (e) {
      const err = e as { isAuth?: boolean; isNetwork?: boolean };
      if (err.isNetwork) {
        // offline at launch: keep the session, screens show their own offline state
        set({ status: 'signedIn' });
      } else {
        await secureDelete(TOKEN_KEY);
        set({ status: 'signedOut', token: null, user: null });
      }
    }
  },

  signIn: async (token, user) => {
    await secureSet(TOKEN_KEY, token);
    set({ status: 'signedIn', token, user, challenge: null });
  },

  setUser: (user) => set({ user }),
  setChallenge: (challenge) => set({ challenge }),

  clear: async () => {
    await secureDelete(TOKEN_KEY);
    set({ status: 'signedOut', token: null, user: null, challenge: null });
  },

  logout: async () => {
    try {
      if (get().token) await api.auth('logout', { method: 'POST', json: {} });
    } catch {
      /* token may already be invalid — local sign-out still happens */
    }
    await get().clear();
  },
}));

configureApi({
  getToken: () => useAuth.getState().token,
  onUnauthorized: () => {
    if (useAuth.getState().status === 'signedIn') void useAuth.getState().clear();
  },
});
