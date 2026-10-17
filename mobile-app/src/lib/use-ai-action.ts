import { activateKeepAwakeAsync, deactivateKeepAwake } from 'expo-keep-awake';
import { useCallback, useRef, useState } from 'react';

import { ApiError } from './api';
import { uuid } from './encoding';
import { invalidateAfterCharge } from './queries';

/**
 * Runs one expensive AI action (charges credits on the server).
 * - one Idempotency-Key per user action, REUSED when the user taps "retry" after a network drop,
 *   so the server replays the finished result instead of charging again
 * - a new key only after success or after a business error (the server refunded / never charged)
 * - double taps are ignored while running; the screen stays awake
 */
export function useAiAction<TArgs, TResult>(run: (args: TArgs, idempotencyKey: string) => Promise<TResult>) {
  const keyRef = useRef<string>(uuid());
  const runningRef = useRef(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const execute = useCallback(
    async (args: TArgs): Promise<TResult | null> => {
      if (runningRef.current) return null;
      runningRef.current = true;
      setBusy(true);
      setError(null);
      try {
        await activateKeepAwakeAsync('ai').catch(() => undefined);
        const res = await run(args, keyRef.current);
        keyRef.current = uuid();
        invalidateAfterCharge();
        return res;
      } catch (e) {
        const err = e instanceof ApiError ? e : new ApiError((e as Error)?.message ?? 'حصلت مشكلة', 'error', 0);
        // network/timeout: the request may still have completed server-side → keep the same key for the retry
        if (!err.isNetwork && err.code !== 'in_progress') keyRef.current = uuid();
        if (!err.isNetwork) invalidateAfterCharge();
        setError(err);
        return null;
      } finally {
        // web: throws if the wake lock never activated — never let that break the result
        void Promise.resolve()
          .then(() => deactivateKeepAwake('ai'))
          .catch(() => undefined);
        runningRef.current = false;
        setBusy(false);
      }
    },
    [run],
  );

  return { execute, busy, error, clearError: () => setError(null) };
}
