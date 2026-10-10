import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Token storage: iOS Keychain / Android Keystore via expo-secure-store.
 * The web build (development preview only) falls back to sessionStorage.
 */
const webStore = {
  get(key: string): string | null {
    try {
      return globalThis.sessionStorage?.getItem(key) ?? null;
    } catch {
      return null;
    }
  },
  set(key: string, value: string) {
    try {
      globalThis.sessionStorage?.setItem(key, value);
    } catch {
      /* private mode */
    }
  },
  del(key: string) {
    try {
      globalThis.sessionStorage?.removeItem(key);
    } catch {
      /* ignore */
    }
  },
};

export async function secureGet(key: string): Promise<string | null> {
  if (Platform.OS === 'web') return webStore.get(key);
  try {
    return await SecureStore.getItemAsync(key);
  } catch {
    return null;
  }
}

export async function secureSet(key: string, value: string): Promise<void> {
  if (Platform.OS === 'web') return webStore.set(key, value);
  await SecureStore.setItemAsync(key, value, { keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY });
}

export async function secureDelete(key: string): Promise<void> {
  if (Platform.OS === 'web') return webStore.del(key);
  try {
    await SecureStore.deleteItemAsync(key);
  } catch {
    /* already gone */
  }
}
