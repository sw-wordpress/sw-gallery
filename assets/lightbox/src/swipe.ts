export const SWIPE_THRESHOLD = 50;
export const AXIS_LOCK_DISTANCE = 10;

/**
 * Horizontal swipe via Pointer Events (touch and pen only). The stage uses
 * `touch-action: pan-y pinch-zoom`, so the browser keeps vertical scrolling and pinch-zoom and
 * sends pointercancel for them; this code never calls preventDefault.
 * `onSwipe(-1)` means the finger moved left, `onSwipe(1)` right.
 */
export function attachSwipe(
  el: HTMLElement,
  onSwipe: (direction: -1 | 1) => void,
  signal: AbortSignal,
): void {
  let pointerId: number | null = null;
  let startX = 0;
  let startY = 0;
  let axis: 'x' | 'y' | null = null;

  const reset = (): void => {
    pointerId = null;
    axis = null;
  };

  el.addEventListener(
    'pointerdown',
    (e) => {
      if (e.pointerType === 'mouse') return;
      if (pointerId !== null) {
        // A second finger (pinch) cancels the gesture.
        reset();
        return;
      }
      pointerId = e.pointerId;
      startX = e.clientX;
      startY = e.clientY;
      axis = null;
    },
    { signal },
  );

  el.addEventListener(
    'pointermove',
    (e) => {
      if (e.pointerId !== pointerId || axis !== null) return;
      const dx = Math.abs(e.clientX - startX);
      const dy = Math.abs(e.clientY - startY);
      if (Math.max(dx, dy) < AXIS_LOCK_DISTANCE) return;
      axis = dx > dy ? 'x' : 'y';
    },
    { signal },
  );

  el.addEventListener(
    'pointerup',
    (e) => {
      if (e.pointerId !== pointerId) return;
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      const horizontal = axis === 'x' || (axis === null && Math.abs(dx) > Math.abs(dy));
      reset();
      if (horizontal && Math.abs(dx) >= SWIPE_THRESHOLD) onSwipe(dx < 0 ? -1 : 1);
    },
    { signal },
  );

  el.addEventListener('pointercancel', reset, { signal });
}
