import { apiRequest, ApiError, configureApi } from '@/lib/api';

// jest hoists jest.mock() above the imports
jest.mock('expo-device', () => ({ manufacturer: 'Test', modelName: 'Phone' }));
jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { version: '1.0.0' } } }));

type Call = { url: string; init: RequestInit };
const calls: Call[] = [];
function mockFetch(status: number, body: unknown) {
  global.fetch = jest.fn(async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return { ok: status < 400, status, text: async () => (typeof body === 'string' ? body : JSON.stringify(body)) } as Response;
  }) as unknown as typeof fetch;
}

describe('apiRequest', () => {
  const onUnauthorized = jest.fn();
  beforeEach(() => {
    calls.length = 0;
    onUnauthorized.mockReset();
    configureApi({ getToken: () => 'spm_test', onUnauthorized });
  });

  it('sends the device token in both headers, never cookies, and base64 JSON', async () => {
    mockFetch(200, { ok: true, saved: true });
    const r = await apiRequest('call.php', { query: { ep: 'contents' }, json: { action: 'save', text: 'نص' }, idempotencyKey: 'abc-12345' });
    expect(r.saved).toBe(true);
    const h = calls[0].init.headers as Record<string, string>;
    expect(h.Authorization).toBe('Bearer spm_test');
    expect(h['X-Spread-Token']).toBe('spm_test');
    expect(h['X-Payload']).toBe('b64');
    expect(h['Idempotency-Key']).toBe('abc-12345');
    expect(calls[0].init.credentials).toBe('omit');
    expect(JSON.parse(Buffer.from(String(calls[0].init.body), 'base64').toString('utf8'))).toEqual({ action: 'save', text: 'نص' });
    expect(calls[0].url).toMatch(/\/api\/v1\/call\.php\?ep=contents$/);
  });

  it('encodes listed form fields as base64 and declares them in _b64', async () => {
    mockFetch(200, { ok: true });
    await apiRequest('call.php', { form: { content_id: 3, extra_notes: 'عرض', use_logo: true }, b64Fields: ['extra_notes'] });
    const body = String(calls[0].init.body);
    expect(body).toContain('content_id=3');
    expect(body).toContain('use_logo=1');
    expect(body).toContain(`extra_notes=${encodeURIComponent(Buffer.from('عرض').toString('base64'))}`);
    expect(body).toContain('_b64=extra_notes');
  });

  it('maps {ok:false} replies to ApiError with code and payload (paywall)', async () => {
    mockFetch(200, { ok: false, code: 'subscription', error: 'اشترك', paywall: { title: 't' } });
    await expect(apiRequest('call.php')).rejects.toMatchObject({ code: 'subscription', isPaywall: true });
  });

  it('signs out locally on 401 for the session token only', async () => {
    mockFetch(401, { ok: false, code: 'auth', error: 'انتهت الجلسة' });
    await expect(apiRequest('app.php')).rejects.toBeInstanceOf(ApiError);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
    await expect(apiRequest('auth.php', { token: 'spc_challenge' })).rejects.toBeInstanceOf(ApiError);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('reports network failures as retry-able', async () => {
    global.fetch = jest.fn(async () => {
      throw new TypeError('Network request failed');
    }) as unknown as typeof fetch;
    await expect(apiRequest('app.php')).rejects.toMatchObject({ code: 'network', isNetwork: true });
  });

  it('rejects non-JSON server errors cleanly', async () => {
    mockFetch(500, '<html>Fatal</html>');
    await expect(apiRequest('app.php')).rejects.toMatchObject({ code: 'server_error' });
  });
});
