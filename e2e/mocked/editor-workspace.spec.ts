import { expect, test } from '@playwright/test';
import { expectDesktopWorkspace, expectMobileWorkspace } from '../support/editor-workspace';
import type { Gallery } from '../../apps/admin/src/api/galleries';
import type { GalleryItem } from '../../apps/admin/src/api/items';
import type { LayoutsResponse } from '../../apps/admin/src/api/layouts';

const gallery: Gallery = {
  id: 7,
  title: 'Workspace',
  description: '',
  status: 'draft',
  ownerId: 1,
  coverAttachmentId: null,
  coverUrl: null,
  layout: 'grid',
  sortMode: 'manual',
  perPage: null,
  hover: null,
  settings: {},
  passwordProtected: false,
  modified: '2026-11-16T00:00:00Z',
  revision: 1,
  itemCount: 6,
  canEdit: true,
  canDelete: true,
  canPublish: true,
};
// Same defaults/schema as Rendering/Layout/GridLayout.php, including the aspect selector.
const layouts: LayoutsResponse = {
  fallbackId: 'grid',
  layouts: [
    {
      id: 'grid',
      label: 'Grid',
      settingsSchema: {
        type: 'object',
        properties: {
          columns: { type: 'integer', minimum: 1, maximum: 12, default: 3, 'x-responsive': true },
          gap: { type: 'integer', minimum: 0, maximum: 64, default: 8 },
          aspect: { type: 'string', enum: ['auto', '1:1', '4:3', '3:2', '16:9'], default: 'auto' },
        },
      },
    },
  ],
};
const items: GalleryItem[] = Array.from({ length: 6 }, (_, i) => ({
  id: i + 1,
  attachmentId: i + 101,
  position: i,
  overrides: { title: null, caption: null, alt: null },
  effective: { title: `Image ${i + 1}`, caption: '', alt: '' },
  global: { title: `Image ${i + 1}`, caption: '', alt: '' },
  settings: {},
  media: {
    mimeType: 'image/png',
    width: 800,
    height: 600,
    thumbnailUrl: null,
    fullUrl: null,
  },
  missing: false,
}));

test('workspace with the actual admin app and mocked REST', async ({ page }) => {
  const unexpected: string[] = [];
  page.on('pageerror', (error) => unexpected.push(error.message));
  await page.route('**/wp-json/swg/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const data = path.endsWith('/layouts')
      ? layouts
      : path.endsWith('/galleries/7/items')
        ? items
        : path.endsWith('/galleries/7')
          ? gallery
          : undefined;
    if (route.request().method() !== 'GET' || data === undefined) {
      unexpected.push(`${route.request().method()} ${path}`);
      await route.abort();
      return;
    }
    await route.fulfill({
      json: data,
      headers: { 'X-SWG-Revision': '1', 'X-WP-Total': '6', 'X-WP-TotalPages': '1' },
    });
  });
  await page.setViewportSize({ width: 1500, height: 1000 });
  await page.goto('/?caps=edit_posts,edit_others_posts,upload_files#/galleries/7');
  await expect(page.locator('.swg-ed__title')).toHaveText('Workspace');
  await expectDesktopWorkspace(page);
  await page.getByRole('button', { name: 'Upload images', exact: true }).focus();
  const [chooser] = await Promise.all([
    page.waitForEvent('filechooser'),
    page.keyboard.press('Enter'),
  ]);
  expect(chooser.isMultiple()).toBe(true);
  expect(
    await chooser.element().evaluate((el) => {
      const input = el as HTMLInputElement;
      return { id: input.id, label: input.labels?.[0]?.textContent, disabled: input.disabled };
    }),
  ).toEqual({
    id: expect.stringMatching(/^swg-upload-.+/),
    label: 'Upload images',
    disabled: false,
  });
  await expectMobileWorkspace(page);
  expect(unexpected).toEqual([]);
});
