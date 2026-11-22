import { mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { afterEach, describe, expect, it } from 'vitest';
import HelpHint from '../src/components/ui/HelpHint.vue';
import { createAppI18n } from '../src/i18n';

afterEach(() => {
  document.body.innerHTML = '';
});

describe('HelpHint', () => {
  function hint() {
    return mount(HelpHint, {
      props: { text: 'Explains the field.', label: 'Columns' },
      attachTo: document.body,
      global: { plugins: [createAppI18n('en_US'), [Quasar, {}]] },
    });
  }

  it('hides the text, names the button after the field and opens the text on click', async () => {
    const wrapper = hint();
    const button = wrapper.find('button');
    expect(button.attributes('aria-label')).toBe('Explanation: Columns');
    expect(document.body.textContent).not.toContain('Explains the field.');

    await button.trigger('click');
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(document.body.querySelector('[role="note"]')?.textContent).toBe('Explains the field.');
    wrapper.unmount();
  });

  it('is named in German as well', () => {
    const wrapper = mount(HelpHint, {
      props: { text: 'x', label: 'Spalten' },
      global: { plugins: [createAppI18n('de_DE'), [Quasar, {}]] },
    });
    expect(wrapper.find('button').attributes('aria-label')).toBe('Erklärung: Spalten');
  });
});
