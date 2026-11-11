import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { authFile, PLUGIN_PAGE } from '../support/env';

test.use({ storageState: authFile('administrator') });

interface Edges {
  clientWidth: number;
  adminBarBottom: number;
  menuRight: number;
  rootTop: number;
  rootLeft: number;
  rootRight: number;
}

async function edges(page: Page): Promise<Edges> {
  await page.goto(PLUGIN_PAGE);
  await expect(page.locator('#swg-admin-root .swg-shell')).toBeVisible();
  return page.evaluate(() => {
    const rect = (selector: string): DOMRect => {
      const el = document.querySelector(selector);
      if (el === null) throw new Error(`missing ${selector}`);
      return el.getBoundingClientRect();
    };
    const menu = document.querySelector('#adminmenuwrap');
    const menuVisible =
      menu !== null &&
      menu.getBoundingClientRect().width > 0 &&
      getComputedStyle(menu).display !== 'none';
    return {
      clientWidth: document.documentElement.clientWidth,
      adminBarBottom: rect('#wpadminbar').bottom,
      menuRight: menuVisible ? rect('#adminmenuwrap').right : 0,
      rootTop: rect('#swg-admin-root').top,
      rootLeft: rect('#swg-admin-root').left,
      rootRight: rect('#swg-admin-root').right,
    };
  });
}

// wp-admin pads its content area (10px/20px wrap margin, 20px left padding of #wpcontent). The app owns the whole
// content area, like the other sw-* plugins: no stripe of WordPress background above or to the right of it.
for (const width of [1760, 1280, 700]) {
  test(`the app sits flush with the admin bar, the admin menu and the right edge at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    const e = await edges(page);

    expect(e.rootTop, 'no gap below the admin bar').toBe(e.adminBarBottom);
    expect(e.rootLeft, 'no gap next to the admin menu').toBe(e.menuRight);
    expect(Math.abs(e.rootRight - e.clientWidth), 'no gap at the right edge').toBeLessThanOrEqual(
      1,
    );
  });
}
