import { base64FromBytes, utf8Bytes, utf8ToBase64, uuid } from '@/lib/encoding';

describe('utf8ToBase64', () => {
  it('matches Node Buffer for Arabic, emoji and ASCII', () => {
    for (const s of ['', 'a', 'ab', 'abc', 'منشور تجريبي 👋 #عرض', '{"action":"save","text":"نص معدّل"}', '𝒜 مرحبا']) {
      expect(utf8ToBase64(s)).toBe(Buffer.from(s, 'utf8').toString('base64'));
    }
  });
  it('encodes code points to the same bytes as Buffer', () => {
    const s = 'سبريد 🚀';
    expect(utf8Bytes(s)).toEqual([...Buffer.from(s, 'utf8')]);
    expect(base64FromBytes([0xff, 0x00])).toBe('/wA=');
  });
});

describe('uuid', () => {
  it('produces RFC 4122 v4 ids that the server accepts as Idempotency-Key', () => {
    const seen = new Set<string>();
    for (let i = 0; i < 500; i++) {
      const id = uuid();
      expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
      // backend rule (includes/mobile.php mobile_idem_key): [A-Za-z0-9_-]{8,80}
      expect(id).toMatch(/^[A-Za-z0-9_-]{8,80}$/);
      seen.add(id);
    }
    expect(seen.size).toBe(500);
  });
});
