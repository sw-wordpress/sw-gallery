import { mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { describe, expect, it } from 'vitest';
import LayoutSettingsPanel from '../../src/features/editor/components/LayoutSettingsPanel.vue';
import { createEditorSession } from '../../src/features/editor/useGalleryEditor';
import { createAppI18n } from '../../src/i18n';
import { FakeServer, LAYOUTS } from './support';

async function panel() {
  const session = createEditorSession(7, new FakeServer().api());
  await session.load();
  const wrapper = mount(LayoutSettingsPanel, {
    props: {
      session,
      layouts: LAYOUTS,
      layoutsError: null,
      layoutsLoading: false,
      readonly: false,
    },
    global: { plugins: [createAppI18n('en_US'), [Quasar, {}]] },
  });
  const input = wrapper.find('input[type="number"]');
  return { session, wrapper, input };
}

describe('gallery page size field', () => {
  it('stores valid numbers and treats empty or out-of-range input as the site default', async () => {
    const { session, input } = await panel();
    expect(session.working.value?.details.perPage).toBeNull();

    await input.setValue('24');
    expect(session.working.value?.details.perPage).toBe(24);

    await input.setValue('0');
    expect(session.working.value?.details.perPage).toBeNull();

    await input.setValue('30');
    await input.setValue('201');
    expect(session.working.value?.details.perPage).toBeNull();

    await input.setValue('30');
    await input.setValue('');
    expect(session.working.value?.details.perPage).toBeNull();
  });

  it('is disabled for read-only users', async () => {
    const session = createEditorSession(7, new FakeServer().api());
    await session.load();
    const wrapper = mount(LayoutSettingsPanel, {
      props: {
        session,
        layouts: LAYOUTS,
        layoutsError: null,
        layoutsLoading: false,
        readonly: true,
      },
      global: { plugins: [createAppI18n('en_US'), [Quasar, {}]] },
    });
    expect(wrapper.find('input[type="number"]').attributes('disabled')).toBeDefined();
  });
});

describe('gallery hover texts field', () => {
  it('inherits the site default until customised, then edits title, caption and animation', async () => {
    const { session, wrapper } = await panel();
    expect(session.working.value?.details.hover).toBeNull();
    expect(wrapper.text()).toContain('Use the site default');
    expect(wrapper.findAll('[role="checkbox"]')).toHaveLength(0);

    session.setDetail('hover', { title: true, caption: false, animation: 'fade' });
    await wrapper.vm.$nextTick();
    const boxes = wrapper.findAll('[role="checkbox"]');
    expect(boxes).toHaveLength(2);

    await boxes[1]?.trigger('click');
    expect(session.working.value?.details.hover).toEqual({
      title: true,
      caption: true,
      animation: 'fade',
    });
    await boxes[0]?.trigger('click');
    expect(session.working.value?.details.hover?.title).toBe(false);

    session.setDetail('hover', null);
    await wrapper.vm.$nextTick();
    expect(wrapper.findAll('[role="checkbox"]')).toHaveLength(0);
  });

  it('switches to a custom setting with the title shown and back to the site default', async () => {
    const { session, wrapper } = await panel();
    const toggle = wrapper.find('[role="switch"]');
    await toggle.trigger('click');
    expect(session.working.value?.details.hover).toEqual({
      title: true,
      caption: false,
      animation: 'fade',
    });
    await wrapper.find('[role="switch"]').trigger('click');
    expect(session.working.value?.details.hover).toBeNull();
  });

  it('saves the hover setting with the gallery', async () => {
    const server = new FakeServer();
    const session = createEditorSession(7, server.api());
    await session.load();
    session.setDetail('hover', { title: true, caption: true, animation: 'cover' });
    expect(await session.save()).toMatchObject({ ok: true, completed: ['details'] });
    expect(server.gallery.hover).toEqual({ title: true, caption: true, animation: 'cover' });
  });
});
