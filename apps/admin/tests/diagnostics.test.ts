import { describe, expect, it } from 'vitest';
import { diagnosticMessage } from '../src/api/preview';
import { createAppI18n } from '../src/i18n';

const codes = [
  'gallery_not_found',
  'gallery_not_public',
  'unknown_layout',
  'layout_failed',
  'invalid_override',
  'empty_gallery',
  'images_unavailable',
];

describe('preview diagnostics translation (FU-28)', () => {
  it('translates every known code from the catalog in English and German', () => {
    const en = createAppI18n('en_US').global;
    const de = createAppI18n('de_DE').global;
    for (const code of codes) {
      const d = { code, message: 'SERVER TEXT' };
      const english = diagnosticMessage(d, en.t);
      const german = diagnosticMessage(d, de.t);
      expect(english).not.toBe('SERVER TEXT');
      expect(german).not.toBe('SERVER TEXT');
      expect(german).not.toBe(english);
    }
  });

  it('falls back to the server text for unknown codes, including prototype names', () => {
    const t = createAppI18n('en_US').global.t;
    expect(diagnosticMessage({ code: 'swg_custom', message: 'From an add-on' }, t)).toBe(
      'From an add-on',
    );
    expect(diagnosticMessage({ code: 'toString', message: 'x' }, t)).toBe('x');
  });
});
