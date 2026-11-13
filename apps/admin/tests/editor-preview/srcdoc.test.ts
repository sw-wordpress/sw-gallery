import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { ApiClient } from '../../src/api/client';
import { createPreviewApi, parsePreviewResponse } from '../../src/api/preview';
import {
  MAX_FRAME_HEIGHT,
  buildCsp,
  buildSrcdoc,
  escapeHtmlAttr,
  parseHeightMessage,
  randomToken,
  validateAssetUrl,
  validateAssets,
} from '../../src/features/editor/preview/srcdoc';
import type { SrcdocInput } from '../../src/features/editor/preview/srcdoc';

const ORIGIN = 'https://site.test';

function input(over: Partial<SrcdocInput> = {}): SrcdocInput {
  return {
    html: '<div class="swg-gallery">x</div>',
    assets: {
      css: [`${ORIGIN}/build/layouts.css?ver=1.0.0`],
      js: [`${ORIGIN}/build/lightbox.js?ver=1.0.0`],
      dropped: 0,
    },
    origin: ORIGIN,
    dark: false,
    dir: 'ltr',
    lang: 'en',
    token: 'tok123',
    nonce: 'nonce456',
    ...over,
  };
}

describe('asset validation', () => {
  it('accepts same-origin http(s) URLs and normalises relative ones', () => {
    expect(validateAssetUrl(`${ORIGIN}/a/b.css?ver=1`, ORIGIN)).toBe(`${ORIGIN}/a/b.css?ver=1`);
    expect(validateAssetUrl('/a/b.css', ORIGIN)).toBe(`${ORIGIN}/a/b.css`);
    expect(validateAssetUrl('/a b.css', ORIGIN)).toBe(`${ORIGIN}/a%20b.css`);
    expect(validateAssetUrl('http://site.test/a.css', 'http://site.test')).toBe(
      'http://site.test/a.css',
    );
  });

  it.each([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    'data:text/css,body{}',
    'data:text/javascript,alert(1)',
    'blob:https://site.test/x',
    'file:///etc/passwd',
    'https://evil.test/x.js',
    '//evil.test/x.js',
    'http://site.test/x.js',
    'https://user:pw@site.test/x.js',
    `${ORIGIN}/a;script-src *.js`,
    `${ORIGIN}/a,b.js`,
    `${ORIGIN}/a'b.js`,
    'https://',
    '',
  ])('drops %s', (raw) => {
    expect(validateAssetUrl(raw, ORIGIN)).toBeNull();
  });

  it('keeps the valid ones in order, removes duplicates and counts what was dropped', () => {
    const result = validateAssets(
      {
        css: [`${ORIGIN}/a.css`, 'javascript:1', `${ORIGIN}/a.css`],
        js: ['https://evil.test/x.js', `${ORIGIN}/b.js`, 'data:text/javascript,1'],
      },
      ORIGIN,
    );
    expect(result).toEqual({ css: [`${ORIGIN}/a.css`], js: [`${ORIGIN}/b.js`], dropped: 3 });
  });
});

describe('srcdoc document', () => {
  it('puts the CSP first and allows exactly the validated assets', () => {
    const doc = buildSrcdoc(input());
    const csp = (
      doc.match(/http-equiv="Content-Security-Policy" content="([^"]*)"/)?.[1] ?? ''
    ).replace(/&#39;/g, "'");
    expect(doc.indexOf('Content-Security-Policy')).toBeLessThan(doc.indexOf('<link'));
    expect(doc.indexOf('Content-Security-Policy')).toBeLessThan(doc.indexOf('<style>'));
    expect(csp).toContain("default-src 'none'");
    expect(csp).toContain(`style-src ${ORIGIN}/build/layouts.css 'unsafe-inline'`);
    expect(csp).toContain(`script-src ${ORIGIN}/build/lightbox.js 'nonce-nonce456'`);
    expect(csp).toContain(`img-src ${ORIGIN} data:`);
    for (const directive of [
      "connect-src 'none'",
      "frame-src 'none'",
      "object-src 'none'",
      "base-uri 'none'",
      "form-action 'none'",
    ]) {
      expect(csp).toContain(directive);
    }
    expect(csp).not.toContain('unsafe-eval');
    expect(csp).not.toContain('*');
    expect(csp).not.toMatch(/script-src[^;]*'unsafe-inline'/);
  });

  it('emits link and script tags for exactly the given assets', () => {
    const doc = buildSrcdoc(input());
    expect(doc.match(/<link rel="stylesheet" href="[^"]*">/g)).toEqual([
      `<link rel="stylesheet" href="${ORIGIN}/build/layouts.css?ver=1.0.0">`,
    ]);
    expect(doc.match(/<script defer src="[^"]*"><\/script>/g)).toEqual([
      `<script defer src="${ORIGIN}/build/lightbox.js?ver=1.0.0"></script>`,
    ]);
    const noAssets = buildSrcdoc(input({ assets: { css: [], js: [], dropped: 0 } }));
    expect(noAssets).not.toContain('<link');
    expect(noAssets).not.toContain('defer');
  });

  it('keeps hostile markup inside the document body and gives only the reporter the nonce', () => {
    const hostile =
      '<img src=x onerror="parent.__pwned=1"><script>parent.__pwned=2</script></div></body><base href="https://evil.test/">';
    const doc = buildSrcdoc(input({ html: hostile }));
    expect(doc).toContain(hostile);
    // The hostile script has no nonce; the only nonce-carrying script is the reporter.
    expect(doc.match(/<script nonce="nonce456">/g)).toHaveLength(1);
    expect(doc).toContain('base-uri &#39;none&#39;');
  });

  it('escapes asset URLs and makes unsafe language tags harmless', () => {
    const doc = buildSrcdoc(
      input({
        lang: 'en"><script>1</script>',
        assets: { css: [`${ORIGIN}/a.css?x="><b>`], js: [], dropped: 0 },
      }),
    );
    expect(doc).toContain('<html lang="en" dir="ltr">');
    expect(doc).not.toContain('"><b>');
    expect(doc).toContain('&quot;&gt;&lt;b&gt;');
    expect(escapeHtmlAttr(`<>&"'`)).toBe('&lt;&gt;&amp;&quot;&#39;');
  });

  it('follows direction, language and theme', () => {
    const light = buildSrcdoc(input());
    const dark = buildSrcdoc(input({ dark: true, dir: 'rtl', lang: 'ar-EG' }));
    expect(light).toContain('background:#ffffff');
    expect(light).toContain('<meta name="color-scheme" content="light">');
    expect(dark).toContain('dir="rtl"');
    expect(dark).toContain('lang="ar-EG"');
    expect(dark).toContain('background:#131a2e');
    expect(dark).toContain('color-scheme:dark');
  });

  it('reports height to the admin origin only and guards anchors and forms', () => {
    const doc = buildSrcdoc(input());
    expect(doc).toContain(`"${ORIGIN}"`);
    expect(doc).toContain('"tok123"');
    expect(doc).toContain("postMessage({swgPreview:T,type:'height',height:h},O)");
    expect(doc).toContain('a[href]');
    expect(doc).toContain("'submit'");
    expect(doc).not.toContain("postMessage(msg,'*')");
    expect(doc).not.toMatch(/postMessage\([^)]*'\*'/);
  });

  it('escapes script-breaking characters in embedded values', () => {
    const doc = buildSrcdoc(input({ token: 'a</script><b>' }));
    expect(doc).not.toContain('a</script>');
    expect(doc).toContain('\\u003c/script\\u003e');
  });

  it('buildCsp is deterministic for the same input', () => {
    expect(buildCsp(input())).toBe(buildCsp(input()));
  });

  it('creates random tokens', () => {
    expect(randomToken()).toMatch(/^[0-9a-f]{32}$/);
    expect(randomToken()).not.toBe(randomToken());
    vi.stubGlobal('crypto', undefined);
    try {
      expect(randomToken(4)).toMatch(/^[0-9a-f]{8}$/);
    } finally {
      vi.unstubAllGlobals();
    }
  });
});

describe('height messages', () => {
  const frame = {};
  const good = { swgPreview: 'tok', type: 'height', height: 640.2 };

  it('accepts a well-formed message from the frame and rounds up', () => {
    expect(parseHeightMessage({ origin: 'null', source: frame, data: good }, frame, 'tok')).toBe(
      641,
    );
  });

  it('ignores another window, a real origin, a wrong token, wrong shape and silly numbers', () => {
    const parse = (event: { origin: string; source: unknown; data: unknown }): number | null =>
      parseHeightMessage(event, frame, 'tok');
    expect(parse({ origin: 'null', source: {}, data: good })).toBeNull();
    expect(parse({ origin: 'https://evil.test', source: frame, data: good })).toBeNull();
    expect(parse({ origin: ORIGIN, source: frame, data: good })).toBeNull();
    expect(parse({ origin: 'null', source: frame, data: { ...good, swgPreview: 'x' } })).toBeNull();
    expect(parse({ origin: 'null', source: frame, data: { ...good, type: 'nav' } })).toBeNull();
    expect(parse({ origin: 'null', source: frame, data: { ...good, extra: 1 } })).toBeNull();
    expect(
      parse({ origin: 'null', source: frame, data: { swgPreview: 'tok', type: 'height' } }),
    ).toBeNull();
    for (const height of ['640', Number.NaN, Infinity, -1, MAX_FRAME_HEIGHT + 1, null]) {
      expect(parse({ origin: 'null', source: frame, data: { ...good, height } })).toBeNull();
    }
    for (const data of [null, undefined, 'height', 12, [good]]) {
      expect(parse({ origin: 'null', source: frame, data })).toBeNull();
    }
    expect(
      parseHeightMessage({ origin: 'null', source: null, data: good }, null, 'tok'),
    ).toBeNull();
    expect(
      parseHeightMessage({ origin: 'null', source: frame, data: good }, undefined, 'tok'),
    ).toBeNull();
  });
});

describe('preview API', () => {
  const valid = {
    html: '<p>x</p>',
    layoutId: 'grid',
    page: 2,
    totalPages: 3,
    totalItems: 25,
    diagnostics: [{ code: 'empty_gallery', message: 'none' }],
    assets: { css: ['a'], js: [] },
  };

  it('parses a valid body and clamps odd numbers', () => {
    expect(parsePreviewResponse(valid)).toEqual(valid);
    expect(parsePreviewResponse({ ...valid, page: 0, totalPages: 0 })).toMatchObject({
      page: 1,
      totalPages: 1,
    });
  });

  it.each([
    null,
    'x',
    {},
    { ...valid, html: 1 },
    { ...valid, layoutId: null },
    { ...valid, page: 'a' },
    { ...valid, totalItems: -1 },
    { ...valid, diagnostics: {} },
    { ...valid, diagnostics: [{ code: 1, message: 'x' }] },
    { ...valid, diagnostics: [null] },
    { ...valid, assets: null },
    { ...valid, assets: { css: 'a', js: [] } },
    { ...valid, assets: { css: [1], js: [] } },
  ])('rejects a body of the wrong shape (%#)', (body) => {
    expect(() => parsePreviewResponse(body)).toThrowError(
      expect.objectContaining({ code: 'swg_invalid_response' }),
    );
  });

  it('posts the staged state to the preview route through the typed client', async () => {
    const fetchImpl = vi.fn(
      async (_url: RequestInfo | URL, _init?: RequestInit) =>
        new Response(JSON.stringify(valid), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
    );
    const api = createPreviewApi(
      new ApiClient({ restRoot: 'https://site.test/wp-json/swg/v1/', nonce: 'n', fetchImpl }),
    );
    const controller = new AbortController();
    const result = await api.render(
      7,
      { layout: 'grid', settings: { columns: 3 }, page: 2 },
      controller.signal,
    );
    expect(result.totalItems).toBe(25);
    const [url, init] = fetchImpl.mock.calls[0] ?? [];
    expect(String(url)).toContain('galleries/7/preview');
    expect(init?.method).toBe('POST');
    expect(JSON.parse(String(init?.body))).toEqual({
      layout: 'grid',
      settings: { columns: 3 },
      page: 2,
    });
    expect(init?.signal).toBeDefined();
  });
});

describe('source scan', () => {
  const root = resolve(__dirname, '../../src/features/editor');
  const files: string[] = [];
  const walk = (dir: string): void => {
    for (const name of readdirSync(dir)) {
      const path = join(dir, name);
      if (statSync(path).isDirectory()) walk(path);
      else if (/\.(vue|ts)$/.test(name)) files.push(path);
    }
  };
  walk(root);

  it('never inserts markup into the admin DOM (no v-html, no HTML sinks) anywhere in the editor feature', () => {
    expect(files.length).toBeGreaterThan(10);
    const sinks =
      /v-html|innerHTML|outerHTML|insertAdjacentHTML|document\.write|DOMParser|createContextualFragment/;
    for (const file of files) {
      expect(readFileSync(file, 'utf8'), file).not.toMatch(sinks);
    }
  });

  it('the preview frame is sandboxed without allow-same-origin and uses srcdoc only', () => {
    const frame = readFileSync(join(root, 'preview/PreviewFrame.vue'), 'utf8');
    expect(frame.match(/<iframe[^>]*\ssandbox="([^"]*)"/)?.[1]).toBe('allow-scripts');
    expect(frame.match(/<iframe/g)).toHaveLength(1);
    expect(frame).toContain(':srcdoc="srcdoc"');
    expect(frame).not.toMatch(/\bsrc="/);
  });
});
