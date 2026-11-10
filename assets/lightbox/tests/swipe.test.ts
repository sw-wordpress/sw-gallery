import { afterEach, describe, expect, it } from 'vitest';
import { counterText, click, galleryHtml, key, links, mount, pointer } from './helpers.ts';

afterEach(() => {
  key('Escape');
  document.body.innerHTML = '';
  document.documentElement.removeAttribute('dir');
});

function open(): HTMLElement {
  click(links()[0]!);
  return document.querySelector<HTMLElement>('.swg-lightbox__stage')!;
}

function swipe(stage: HTMLElement, from: [number, number], to: [number, number], extra = {}): void {
  pointer(stage, 'pointerdown', from[0], from[1], extra);
  pointer(stage, 'pointermove', (from[0] + to[0]) / 2, (from[1] + to[1]) / 2, extra);
  pointer(stage, 'pointermove', to[0], to[1], extra);
  pointer(stage, 'pointerup', to[0], to[1], extra);
}

describe('swipe', () => {
  it('shows the next image on a left swipe and the previous on a right swipe', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    swipe(stage, [200, 100], [100, 105]);
    expect(counterText()).toBe('2 of 3');
    swipe(stage, [100, 100], [200, 95]);
    expect(counterText()).toBe('1 of 3');
  });

  it('is mirrored in RTL', () => {
    document.documentElement.dir = 'rtl';
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    swipe(stage, [100, 100], [200, 100]);
    expect(counterText()).toBe('2 of 3');
  });

  it('ignores movement below the threshold', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    swipe(stage, [100, 100], [70, 100]);
    expect(counterText()).toBe('1 of 3');
  });

  it('locks to the vertical axis and never navigates after vertical movement', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    pointer(stage, 'pointerdown', 200, 100);
    pointer(stage, 'pointermove', 195, 140); // vertical lock
    pointer(stage, 'pointermove', 60, 150); // later horizontal drift is ignored
    pointer(stage, 'pointerup', 60, 150);
    expect(counterText()).toBe('1 of 3');
  });

  it('ignores tiny moves before the axis lock distance', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    pointer(stage, 'pointerdown', 200, 100);
    pointer(stage, 'pointermove', 196, 100);
    pointer(stage, 'pointerup', 90, 100);
    expect(counterText()).toBe('2 of 3');
  });

  it('navigates on a quick flick without move events when clearly horizontal', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    pointer(stage, 'pointerdown', 200, 100);
    pointer(stage, 'pointerup', 100, 102);
    expect(counterText()).toBe('2 of 3');
  });

  it('does not navigate for a mostly vertical gesture without move events', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    pointer(stage, 'pointerdown', 200, 100);
    pointer(stage, 'pointerup', 130, 300);
    expect(counterText()).toBe('1 of 3');
  });

  it('cancels on pointercancel', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    pointer(stage, 'pointerdown', 200, 100);
    pointer(stage, 'pointermove', 100, 100);
    pointer(stage, 'pointercancel', 100, 100);
    pointer(stage, 'pointerup', 100, 100);
    expect(counterText()).toBe('1 of 3');
  });

  it('cancels when a second finger touches (pinch)', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    pointer(stage, 'pointerdown', 200, 100, { pointerId: 1 });
    pointer(stage, 'pointerdown', 220, 100, { pointerId: 2 });
    pointer(stage, 'pointermove', 100, 100, { pointerId: 1 });
    pointer(stage, 'pointerup', 100, 100, { pointerId: 1 });
    pointer(stage, 'pointerup', 120, 100, { pointerId: 2 });
    expect(counterText()).toBe('1 of 3');
  });

  it('ignores mouse pointers and foreign pointer ids', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    swipe(stage, [200, 100], [50, 100], { pointerType: 'mouse' });
    expect(counterText()).toBe('1 of 3');
    pointer(stage, 'pointerdown', 200, 100, { pointerId: 1 });
    pointer(stage, 'pointermove', 100, 100, { pointerId: 9 });
    pointer(stage, 'pointerup', 100, 100, { pointerId: 9 });
    expect(counterText()).toBe('1 of 3');
  });

  it('stops listening after close', () => {
    mount(galleryHtml({ count: 3 }));
    const stage = open();
    key('Escape');
    swipe(stage, [200, 100], [50, 100]);
    expect(document.querySelector('.swg-lightbox')).toBeNull();
  });
});
