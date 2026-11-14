import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { buildCsp } from '../../src/features/editor/preview/srcdoc';

/**
 * Security guard (M5-04 j): the exact sandbox attribute and the exact CSP of the live preview, as documented in
 * docs/decisions.md ("Live preview") and docs/security.md. A change to either is a deliberate security decision and
 * must update this test and the documents together.
 */
const origin = 'https://site.test';

describe('preview isolation contract', () => {
  it('the CSP is exactly the documented policy', () => {
    const csp = buildCsp({
      assets: {
        css: [`${origin}/build/layouts.css?ver=1`],
        js: [`${origin}/build/lightbox.js?ver=1`],
        dropped: 0,
      },
      origin,
      nonce: 'n0nce',
    });
    expect(csp).toBe(
      [
        "default-src 'none'",
        `style-src ${origin}/build/layouts.css 'unsafe-inline'`,
        `script-src ${origin}/build/lightbox.js 'nonce-n0nce'`,
        `img-src ${origin} data:`,
        `font-src ${origin} data:`,
        "connect-src 'none'",
        "frame-src 'none'",
        "object-src 'none'",
        "media-src 'none'",
        "base-uri 'none'",
        "form-action 'none'",
      ].join('; '),
    );
  });

  it('the iframe sandbox is exactly allow-scripts (no same-origin, forms, popups or navigation)', () => {
    const source = readFileSync(
      resolve(import.meta.dirname, '../../src/features/editor/preview/PreviewFrame.vue'),
      'utf8',
    );
    const attributes = [...source.matchAll(/\ssandbox="([^"]*)"/g)].map((match) => match[1]);
    expect(attributes).toEqual(['allow-scripts']);
    const tag = source.match(/<iframe[\s\S]*?>/)?.[0] ?? '';
    expect(tag).toContain(':srcdoc="srcdoc"');
    expect(tag).not.toMatch(/\s:?src=/);
  });
});
