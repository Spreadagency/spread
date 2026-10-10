import { compareVersions, fmtDate, fmtNumber, isValidEmail, isValidPhone, ratioToAspect, timeAgo } from '@/lib/format';

describe('format helpers', () => {
  it('fmtNumber groups thousands and handles empty values', () => {
    expect(fmtNumber(1234567)).toBe('1,234,567');
    expect(fmtNumber(0)).toBe('0');
    expect(fmtNumber(null)).toBe('—');
  });
  it('fmtDate renders Arabic month names from server datetimes', () => {
    expect(fmtDate('2026-10-10 09:03:16')).toBe('10 أكتوبر 2026');
    expect(fmtDate('2026-01-05 21:30:00', true)).toBe('5 يناير 2026 · 9:30 م');
    expect(fmtDate('')).toBe('');
  });
  it('timeAgo is relative to Cairo server time', () => {
    const now = new Date('2026-10-10T12:00:00+03:00');
    expect(timeAgo('2026-10-10 11:59:30', now)).toBe('دلوقتي');
    expect(timeAgo('2026-10-10 11:55:00', now)).toBe('من 5 دقيقة');
    expect(timeAgo('2026-10-10 10:00:00', now)).toBe('من ساعتين');
    expect(timeAgo('2026-10-09 11:00:00', now)).toBe('امبارح');
  });
  it('ratioToAspect parses design ratios', () => {
    expect(ratioToAspect('4:5')).toBeCloseTo(0.8);
    expect(ratioToAspect('9:16')).toBeCloseTo(0.5625);
    expect(ratioToAspect('1080x1350')).toBeCloseTo(0.8);
    expect(ratioToAspect(undefined)).toBe(1);
    expect(ratioToAspect('bad')).toBe(1);
  });
  it('compareVersions', () => {
    expect(compareVersions('1.0.0', '1.0.0')).toBe(0);
    expect(compareVersions('1.2.0', '1.10.0')).toBe(-1);
    expect(compareVersions('2.0', '1.9.9')).toBe(1);
  });
  it('validates e-mail and phone like the backend', () => {
    expect(isValidEmail('a@b.co')).toBe(true);
    expect(isValidEmail('bad@')).toBe(false);
    expect(isValidPhone('01012345678')).toBe(true);
    expect(isValidPhone('+201012345678')).toBe(true);
    expect(isValidPhone('0101')).toBe(false);
  });
});
