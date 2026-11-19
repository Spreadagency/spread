/**
 * Web build (development preview only): no media library / native share sheet.
 * Same exports as media.ts — Metro picks this file for the web platform.
 */
import * as ImagePicker from 'expo-image-picker';

import type { FileField } from './api';

export const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

export type PickResult = { ok: true; file: FileField; previewUri: string } | { ok: false; error: string } | null;

export async function pickImage(): Promise<PickResult> {
  const res = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 0.8 });
  if (res.canceled || !res.assets?.length) return null;
  const a = res.assets[0];
  const mime = a.mimeType ?? 'image/jpeg';
  if (!ALLOWED.includes(mime)) return { ok: false, error: 'الصيغة لازم تكون JPG أو PNG أو WebP' };
  if (a.fileSize && a.fileSize > MAX_UPLOAD_BYTES) return { ok: false, error: 'الصورة أكبر من 5 ميجا — اختار صورة أصغر' };
  return { ok: true, previewUri: a.uri, file: { uri: a.uri, name: a.fileName ?? 'upload.jpg', type: mime } };
}

export async function saveImageToGallery(url: string): Promise<{ ok: boolean; message: string }> {
  globalThis.open?.(url, '_blank');
  return { ok: true, message: 'اتفتحت الصورة في تبويب جديد' };
}

export async function shareImage(url: string): Promise<void> {
  globalThis.open?.(url, '_blank');
}
