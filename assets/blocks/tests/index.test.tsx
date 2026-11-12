import { expect, it } from 'vitest';
import { mount, settle } from './helpers';
import { registered } from './stubs/blocks';

it('registers the dynamic block with an editor UI and no saved markup', async () => {
    window.swgBlock = { galleriesUrl: 'https://x.test/wp-admin/admin.php?page=swg-galleries' };
    await import('../src/index');
    expect(registered).toHaveLength(1);
    const [registration] = registered;
    expect(registration?.name).toBe('sw-gallery/gallery');
    expect(registration?.settings.save()).toBeNull();

    const element = registration?.settings.edit({
        attributes: { galleryId: 0 },
        setAttributes: () => undefined,
    } as never);
    const mounted = await mount(element as never);
    await settle();
    expect(mounted.container.querySelector('a[href$="#/galleries"]')?.getAttribute('href')).toBe(
        'https://x.test/wp-admin/admin.php?page=swg-galleries#/galleries',
    );
    await mounted.unmount();
});
