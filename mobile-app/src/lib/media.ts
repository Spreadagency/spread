import { Directory, File, Paths } from 'expo-file-system';
import * as ImagePicker from 'expo-image-picker';
import { Asset, requestPermissionsAsync } from 'expo-media-library';
import * as Sharing from 'expo-sharing';

import type { FileField } from './api';

/** Server limits (includes/uploader.php): JPEG/PNG/WebP, 5 MB */
export const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

export type PickResult = { ok: true; file: FileField; previewUri: string } | { ok: false; error: string } | null;

export async function pickImage(source: 'library' | 'camera' = 'library'): Promise<PickResult> {
  const perm = source === 'camera' ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
  if (!perm.granted) return { ok: false, error: 'محتاجين إذن الوصول للصور — فعّله من إعدادات الموبايل' };
  const opts: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.8, allowsEditing: false, exif: false };
  const res = source === 'camera' ? await ImagePicker.launchCameraAsync(opts) : await ImagePicker.launchImageLibraryAsync(opts);
  if (res.canceled || !res.assets?.length) return null;
  const a = res.assets[0];
  const mime = a.mimeType ?? (a.uri.toLowerCase().endsWith('.png') ? 'image/png' : 'image/jpeg');
  if (!ALLOWED.includes(mime)) return { ok: false, error: 'الصيغة لازم تكون JPG أو PNG أو WebP' };
  if (a.fileSize && a.fileSize > MAX_UPLOAD_BYTES) return { ok: false, error: 'الصورة أكبر من 5 ميجا — اختار صورة أصغر' };
  const ext = mime === 'image/png' ? 'png' : mime === 'image/webp' ? 'webp' : 'jpg';
  return { ok: true, previewUri: a.uri, file: { uri: a.uri, name: a.fileName ?? `upload.${ext}`, type: mime } };
}

async function downloadToCache(url: string): Promise<File> {
  const dir = new Directory(Paths.cache, 'spread');
  if (!dir.exists) dir.create({ intermediates: true, idempotent: true });
  const name = url.split('/').pop()?.split('?')[0] || `design-${Date.now()}.png`;
  const target = new File(dir, name);
  if (target.exists) return target;
  return File.downloadFileAsync(url, target);
}

export async function saveImageToGallery(url: string): Promise<{ ok: boolean; message: string }> {
  const perm = await requestPermissionsAsync(true, ['photo']);
  if (!perm.granted) return { ok: false, message: 'محتاجين إذن حفظ الصور — فعّله من إعدادات الموبايل' };
  try {
    const f = await downloadToCache(url);
    await Asset.create(f.uri);
    return { ok: true, message: 'التصميم اتحفظ في معرض الصور ✓' };
  } catch {
    return { ok: false, message: 'تعذّر حفظ الصورة — جرّب تاني' };
  }
}

export async function shareImage(url: string): Promise<void> {
  if (!(await Sharing.isAvailableAsync())) return;
  const f = await downloadToCache(url);
  await Sharing.shareAsync(f.uri, { mimeType: 'image/png', dialogTitle: 'مشاركة التصميم' });
}
