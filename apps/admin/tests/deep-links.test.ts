import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { createMemoryHistory } from 'vue-router';
import { editGalleryUrl, galleryListUrl } from '../../../assets/blocks/src/links';
import { editorPath } from '../src/features/galleries/format';
import { VIEWS, createAppRouter } from '../src/router';

const Stub = defineComponent({ render: () => h('p', 'stub') });
const views = Object.fromEntries(Object.keys(VIEWS).map((name) => [name, async () => Stub]));
const repo = resolve(__dirname, '../../..');

function newRouter(initial = '/') {
  const history = createMemoryHistory();
  const router = createAppRouter(history, views);
  history.replace(initial);
  return router;
}

describe('editor deep link contract (M2-19)', () => {
  it('resolves #/galleries/<id> to the editor with the numeric id as parameter', async () => {
    const router = newRouter();
    await router.push('/galleries/42');
    expect(router.currentRoute.value.name).toBe('gallery-editor');
    expect(router.currentRoute.value.params.id).toBe('42');
    expect(router.currentRoute.value.meta.fullWidth).toBe(true);
  });

  it('sends non-numeric ids and unknown paths to the overview instead of a broken editor', async () => {
    const router = newRouter();
    for (const path of ['/galleries/abc', '/galleries/1/extra', '/nope', '/galleries/-3']) {
      await router.push(path);
      expect(router.currentRoute.value.name, path).toBe('overview');
    }
  });

  it('keeps back and forward consistent between list and editor', async () => {
    const router = newRouter();
    await router.push('/galleries');
    await router.push('/galleries/7');
    router.back();
    await new Promise((r) => setTimeout(r, 0));
    expect(router.currentRoute.value.fullPath).toBe('/galleries');
    router.forward();
    await new Promise((r) => setTimeout(r, 0));
    expect(router.currentRoute.value.fullPath).toBe('/galleries/7');
  });

  it('every producer of the link builds a hash that the router resolves to the same route', async () => {
    const router = newRouter();
    const fromList = editorPath({ id: 5, canEdit: true, status: 'publish' });
    const fromBlock = editGalleryUrl('https://x.test/wp-admin/admin.php?page=swg-galleries', 5);
    expect(fromList).toBe('/galleries/5');
    expect(fromBlock).toBe('https://x.test/wp-admin/admin.php?page=swg-galleries#/galleries/5');
    expect(galleryListUrl('https://x.test/a.php?page=swg-galleries')).toMatch(/#\/galleries$/);
    const hash = (fromBlock ?? '').split('#')[1] ?? '';
    for (const path of [fromList ?? '', hash]) {
      await router.push(path);
      expect(router.currentRoute.value.name).toBe('gallery-editor');
      expect(router.currentRoute.value.params.id).toBe('5');
    }
    expect(editGalleryUrl('x', 0)).toBeNull();
    expect(editGalleryUrl('x', 1.5)).toBeNull();
  });

  it('matches the PHP contract constant that the admin bar and the Media Library column use', () => {
    const php = readFileSync(
      resolve(repo, 'packages/wordpress/src/Admin/AdminRegistrar.php'),
      'utf8',
    );
    const match = /const EDITOR_ROUTE = '([^']+)';/.exec(php);
    expect(match?.[1]).toBe('#/galleries/%d');
    // The route record behind the contract (see src/router/index.ts): `/galleries/:id(\d+)`.
    const route = createAppRouter(createMemoryHistory(), views)
      .getRoutes()
      .find((r) => r.name === 'gallery-editor');
    expect(route?.path).toBe('/galleries/:id(\\d+)');
  });
});
