import { mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { describe, expect, it } from 'vitest';
import SettingField from '../../src/features/editor/components/SettingField.vue';
import { fieldsOf, optionLabel } from '../../src/features/editor/schema';
import { createAppI18n } from '../../src/i18n';

const schema = {
  type: 'object',
  properties: {
    lastRow: {
      type: 'string',
      enum: ['left', 'justify', 'center'],
      default: 'left',
      'x-enum-titles': { left: 'Left-aligned', justify: 'Full width', bogus: 5, other: '' },
    },
    plain: { type: 'string', enum: ['a', 'b'] },
  },
};

describe('x-enum-titles (FU-26)', () => {
  it('reads string titles only and falls back to the raw value', () => {
    const [lastRow, plain] = fieldsOf(schema);
    expect(lastRow?.optionTitles).toEqual({ left: 'Left-aligned', justify: 'Full width' });
    expect(optionLabel(lastRow!, 'left')).toBe('Left-aligned');
    expect(optionLabel(lastRow!, 'center')).toBe('center'); // no title: raw value
    expect(optionLabel(plain!, 'a')).toBe('a');
    expect(optionLabel(plain!, 'toString')).toBe('toString'); // no prototype lookups
    expect(
      fieldsOf({ properties: { x: { enum: [1], 'x-enum-titles': ['no'] as never } } })[0]
        ?.optionTitles,
    ).toEqual({});
  });

  it('shows the translated title in the form control, and the raw value without one', async () => {
    const [lastRow, plain] = fieldsOf(schema);
    const mountField = (def: typeof lastRow, value: unknown) =>
      mount(SettingField, {
        props: { def: def!, label: 'Last row', value, serverIssues: [], readonly: false },
        global: { plugins: [createAppI18n('en_US'), [Quasar, {}]] },
        attachTo: document.body,
      });
    const titled = mountField(lastRow, 'justify');
    expect(titled.text()).toContain('Full width');
    expect(titled.text()).not.toContain('justify');
    const raw = mountField(plain, 'b');
    expect(raw.text()).toContain('b');
    titled.unmount();
    raw.unmount();
  });
});
