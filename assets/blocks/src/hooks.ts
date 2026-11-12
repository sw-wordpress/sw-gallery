import { useEffect, useState } from '@wordpress/element';
import { isAbort } from './api';

export type Loadable<T> =
  { kind: 'loading' } | { kind: 'ready'; value: T } | { kind: 'error'; message: string };

/**
 * Runs `load` whenever `deps` change and exposes loading / ready / error. A superseded request is aborted and its
 * result ignored, so a slow answer to an old search can never overwrite a newer one.
 */
export function useLoadable<T>(
  load: (signal: AbortSignal) => Promise<T>,
  deps: readonly unknown[],
  describe: (error: unknown) => string,
): Loadable<T> {
  const [state, setState] = useState<Loadable<T>>({ kind: 'loading' });
  useEffect(() => {
    const controller = new AbortController();
    let current = true;
    setState({ kind: 'loading' });
    load(controller.signal).then(
      (value) => {
        if (current) setState({ kind: 'ready', value });
      },
      (error: unknown) => {
        if (current && !isAbort(error)) setState({ kind: 'error', message: describe(error) });
      },
    );
    return () => {
      current = false;
      controller.abort();
    };
    // `load` and `describe` are recreated on every render; only `deps` decide when to reload.
  }, deps);
  return state;
}

/** The value after it stayed unchanged for `delay` ms (keeps typing from firing one request per key). */
export function useDebounced<T>(value: T, delay: number): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}
