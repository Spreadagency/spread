import Constants from 'expo-constants';
import { Platform } from 'react-native';

/**
 * Backend base URL. Set EXPO_PUBLIC_API_URL per build profile (eas.json / .env).
 * Production default: the live platform. Must be HTTPS in release builds.
 */
const RAW = (process.env.EXPO_PUBLIC_API_URL ?? 'https://ai.spreadagency.net').trim();

export const API_URL = RAW.replace(/\/+$/, '');
export const API_V1 = `${API_URL}/api/v1`;

/** 'off' (store builds — no selling inside the app) | 'web' (direct/internal builds may open web checkout) */
export const PURCHASES_BUILD_FLAG = (process.env.EXPO_PUBLIC_PURCHASES ?? 'off') === 'web' ? 'web' : 'off';

export const APP_VERSION = Constants.expoConfig?.version ?? '1.0.0';
export const PLATFORM = Platform.OS;

export const TIMEOUTS = {
  default: 30_000,
  upload: 90_000,
  /** AI generation is synchronous on the server (gateway budget ≈ 240 s) */
  ai: 300_000,
} as const;
