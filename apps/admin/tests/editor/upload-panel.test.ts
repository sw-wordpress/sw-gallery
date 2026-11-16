import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import UploadPanel from '../../src/features/editor/components/UploadPanel.vue';
import { ACCEPTED_TYPES, useUploads } from '../../src/features/editor/useUploads';
import { createAppI18n } from '../../src/i18n';

describe('upload file chooser', () => {
  it.each([
    ['en_US', 'Upload images'],
    ['de_DE', 'Bilder hochladen'],
  ])(
    'associates a translated label with each input without duplicate IDs (%s)',
    (locale, label) => {
      const host = defineComponent({
        setup() {
          const uploads = useUploads({ upload: vi.fn() }, vi.fn());
          return () => [
            h(UploadPanel, { uploads, disabled: false }),
            h(UploadPanel, { uploads, disabled: false }),
          ];
        },
      });
      const wrapper = mount(host, { global: { plugins: [createAppI18n(locale)] } });
      try {
        const inputs = wrapper.findAll('input[type="file"]');
        expect(inputs).toHaveLength(2);
        const ids = inputs.map((input) => input.attributes('id'));
        expect(ids.every((id) => typeof id === 'string' && id.length > 0)).toBe(true);
        expect(new Set(ids).size).toBe(2);
        for (const input of inputs) {
          const el = input.element as HTMLInputElement;
          expect(el.labels).toHaveLength(1);
          expect(el.labels?.[0]?.textContent).toBe(label);
          expect(el.labels?.[0]?.htmlFor).toBe(el.id);
          expect(el.accept).toBe(ACCEPTED_TYPES.join(','));
          expect(el.multiple).toBe(true);
          expect(el.disabled).toBe(false);
          expect(el.tabIndex).toBe(-1);
        }
      } finally {
        wrapper.unmount();
      }
    },
  );
});
