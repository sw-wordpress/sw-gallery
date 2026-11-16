import { mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { describe, expect, it } from 'vitest';
import { ApiClient } from '../../src/api/client';
import { createLayoutsApi } from '../../src/api/layouts';
import SettingField from '../../src/features/editor/components/SettingField.vue';
import { fieldsOf } from '../../src/features/editor/schema';
import { createAppI18n } from '../../src/i18n';

function field(value: unknown = undefined, readonly = false) {
  const def = fieldsOf({
    properties: {
      columns: {
        type: 'integer',
        minimum: 1,
        maximum: 12,
        default: 4,
        'x-responsive': true,
      },
    },
  })[0]!;
  return mount(SettingField, {
    props: { def, label: 'Spalten', value, readonly, serverIssues: [] },
    global: { plugins: [createAppI18n('de_DE'), [Quasar, {}]] },
  });
}

describe('compact layout settings', () => {
  it('offers all device inputs, inherits placeholders and clears only the selected override', async () => {
    const wrapper = field({ base: 5, sm: 2, md: 3, future: 7 });
    expect(wrapper.findAll('.swg-sfield__device')).toHaveLength(5);
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(0);
    expect(wrapper.text()).toContain('Leer = Standard');
    const inputs = wrapper.findAll('input[type="number"]');
    expect(inputs.map((input) => input.attributes('aria-label'))).toEqual([
      'Spalten, Alle Größen',
      'Spalten, Klein',
      'Spalten, Mittel',
      'Spalten, Groß',
      'Spalten, Sehr groß',
    ]);
    expect(inputs.map((input) => input.attributes('placeholder'))).toEqual([
      '4',
      '5',
      '2',
      '3',
      '3',
    ]);
    await inputs[2]!.setValue('');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ base: 5, sm: 2, future: 7 }]);
    await wrapper.setProps({ value: { base: 5, sm: 2, future: 7 } });
    expect(inputs[2]!.attributes('placeholder')).toBe('2');
    await inputs[4]!.setValue('6');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ base: 5, sm: 2, xl: 6, future: 7 }]);
    wrapper.unmount();
  });

  it('removes the setting when the last value is cleared, validates and respects read-only', async () => {
    const wrapper = field(5);
    await wrapper.find('input').setValue('');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([undefined]);
    await wrapper.setProps({ value: { md: 20 } });
    expect(wrapper.text()).toContain('Darf höchstens 12 sein.');
    await wrapper.setProps({ value: undefined, readonly: true });
    expect(
      wrapper.findAll('input').every((input) => input.attributes('disabled') !== undefined),
    ).toBe(true);
    expect(wrapper.find('.swg-sfield__reset').exists()).toBe(false);
    wrapper.unmount();
  });

  it('edits slider values and resets a numeric setting without changing its contract', async () => {
    const def = fieldsOf({
      properties: {
        gap: {
          type: 'number',
          minimum: 0,
          maximum: 20,
          default: 2,
        },
      },
    })[0]!;
    const wrapper = mount(SettingField, {
      props: { def, label: 'Abstand', value: 3, readonly: false, serverIssues: [] },
      global: { plugins: [createAppI18n('de_DE'), [Quasar, {}]] },
    });
    const slider = wrapper.find('input[type="range"]');
    expect(slider.attributes('step')).toBe('any');
    await slider.setValue('4.5');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([4.5]);
    await wrapper.find('.swg-sfield__reset').trigger('click');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([undefined]);
    wrapper.unmount();
  });

  it('requests translated schema labels in the current WordPress user locale', async () => {
    const calls: string[] = [];
    const client = new ApiClient({
      restRoot: 'https://example.test/wp-json/swg/v1/',
      nonce: 'nonce',
      fetchImpl: async (url) => {
        calls.push(String(url));
        return new Response(JSON.stringify({ layouts: [], fallbackId: 'grid' }));
      },
    });
    await createLayoutsApi(client).list();
    expect(new URL(calls[0]!).searchParams.get('_locale')).toBe('user');
  });
});
