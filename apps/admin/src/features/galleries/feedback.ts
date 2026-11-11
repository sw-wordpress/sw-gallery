import { Notify } from 'quasar';
import { useAppStore } from '@/stores/app';

export type FeedbackKind = 'success' | 'warning';

/**
 * Visible toast plus one polite live-region announcement. The toast itself is hidden from assistive
 * technology (`aria-hidden`), otherwise the message would be read twice (Quasar notifications are
 * `role="alert"` by default).
 */
export function useFeedback() {
  const app = useAppStore();
  return {
    notify(message: string, kind: FeedbackKind = 'success'): void {
      app.announce(message);
      Notify.create({
        message,
        type: kind === 'success' ? 'positive' : 'warning',
        position: 'bottom',
        timeout: 4500,
        attrs: { role: 'presentation', 'aria-hidden': 'true' },
        classes: 'swg-toast',
      });
    },
  };
}

/** Copies via the Clipboard API; `false` when it is unavailable (insecure context) or refuses. */
export async function copyToClipboard(text: string): Promise<boolean> {
  try {
    if (typeof navigator === 'undefined' || navigator.clipboard === undefined) return false;
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}
