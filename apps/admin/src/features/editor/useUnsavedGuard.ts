import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';

/**
 * Unsaved-changes protection: a router guard that asks (in an app dialog, not `window.confirm`)
 * and a `beforeunload` handler for reloads, tab close and navigation to other WordPress screens.
 */
export function useUnsavedGuard(isDirty: () => boolean) {
  const open = ref(false);
  let resolver: ((leave: boolean) => void) | null = null;

  function settle(leave: boolean): void {
    const pending = resolver;
    resolver = null;
    pending?.(leave);
  }

  onBeforeRouteLeave(() => {
    if (!isDirty()) return true;
    settle(false);
    open.value = true;
    return new Promise<boolean>((resolve) => {
      resolver = resolve;
    });
  });

  /** "Leave without saving" was confirmed. */
  function confirmLeave(): void {
    settle(true);
    open.value = false;
  }

  // The dialog closed without confirmation ("Keep editing", Escape).
  watch(open, (isOpen) => {
    if (!isOpen) settle(false);
  });

  function beforeUnload(event: BeforeUnloadEvent): void {
    if (!isDirty()) return;
    // Browsers show their own generic text; the custom message is ignored by all of them.
    event.preventDefault();
    event.returnValue = '';
  }
  onMounted(() => window.addEventListener('beforeunload', beforeUnload));
  onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

  return { open, confirmLeave, beforeUnload };
}
