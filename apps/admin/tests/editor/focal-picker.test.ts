import { mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { describe, expect, it } from 'vitest';
import FocalPicker from '../../src/features/editor/components/FocalPicker.vue';
import { createAppI18n } from '../../src/i18n';

function picker(props: { x?: number; y?: number; disabled?: boolean } = {}) {
  const wrapper = mount(FocalPicker, {
    props: { src: 'https://example.test/a.jpg', x: 50, y: 50, ...props },
    global: { plugins: [createAppI18n('en_US'), [Quasar, {}]] },
  });
  const stage = wrapper.find('.swg-fp__stage');
  // jsdom has no layout: a 200 × 100 box at (10, 20)
  (stage.element as HTMLElement).getBoundingClientRect = () =>
    ({ left: 10, top: 20, width: 200, height: 100, right: 210, bottom: 120 }) as DOMRect;
  return { wrapper, stage };
}

const press = (clientX: number, clientY: number): MouseEvent =>
  new MouseEvent('pointerdown', { clientX, clientY, bubbles: true });

describe('focal point picker', () => {
  it('turns a click into percentages of the image box, rounded to one decimal', async () => {
    const { wrapper, stage } = picker();
    stage.element.dispatchEvent(press(10 + 50, 20 + 25));
    stage.element.dispatchEvent(press(10 + 133.33, 20 + 100));
    expect(wrapper.emitted('update')).toEqual([
      [25, 25],
      [66.7, 100],
    ]);
  });

  it('clamps clicks outside the box to 0..100', () => {
    const { wrapper, stage } = picker();
    stage.element.dispatchEvent(press(-500, 9999));
    expect(wrapper.emitted('update')?.[0]).toEqual([0, 100]);
  });

  it('shows the point and the square crop at the current position', () => {
    const { wrapper } = picker({ x: 20, y: 80 });
    const dot = wrapper.find('.swg-fp__dot').attributes('style');
    expect(dot).toContain('left: 20%');
    expect(dot).toContain('top: 80%');
    expect(wrapper.find('.swg-fp__crop img').attributes('style')).toContain(
      'object-position: 20% 80%',
    );
  });

  it('moves with the arrow keys, ten times as far with Shift, and stays within 0..100', async () => {
    const { wrapper, stage } = picker({ x: 50, y: 5 });
    await stage.trigger('keydown', { key: 'ArrowRight' });
    await stage.trigger('keydown', { key: 'ArrowUp', shiftKey: true });
    await stage.trigger('keydown', { key: 'a' });
    expect(wrapper.emitted('update')).toEqual([
      [51, 5],
      [50, 0],
    ]);
  });

  it('offers a reset only when the point is off-centre', async () => {
    const { wrapper } = picker({ x: 50, y: 50 });
    expect(wrapper.text()).not.toContain('Centre again');
    const moved = picker({ x: 30, y: 50 });
    expect(moved.wrapper.text()).toContain('Centre again');
    await moved.wrapper.find('button').trigger('click');
    expect(moved.wrapper.emitted('reset')).toHaveLength(1);
  });

  it('ignores clicks and keys when disabled and hides the reset', async () => {
    const { wrapper, stage } = picker({ x: 30, y: 30, disabled: true });
    stage.element.dispatchEvent(press(60, 50));
    await stage.trigger('keydown', { key: 'ArrowLeft' });
    expect(wrapper.emitted('update')).toBeUndefined();
    expect(wrapper.text()).not.toContain('Centre again');
    expect(stage.attributes('tabindex')).toBe('-1');
  });
});
