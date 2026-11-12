import { createElement } from '@wordpress/element';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Mounted } from './helpers';
import { change, click, GALLERIES, LAYOUTS, mount, scriptedFetcher, settle } from './helpers';
import { Edit } from '../src/Edit';
import { GallerySelector, optionLabel, SEARCH_DELAY_MS } from '../src/GallerySelector';
import { layoutOptions, PresentationControls } from '../src/PresentationControls';
import { server } from './stubs/server-side-render';

let mounted: Mounted | null = null;
afterEach(async () => {
    await mounted?.unmount();
    mounted = null;
    server.mode = 'ok';
    vi.restoreAllMocks();
});

const select = (container: HTMLElement, label: string): HTMLSelectElement => {
    const element = container.querySelector(`select[aria-label="${label}"]`);
    if (!(element instanceof HTMLSelectElement)) throw new Error(`no select ${label}`);
    return element;
};
const optionTexts = (element: HTMLSelectElement): string[] =>
    Array.from(element.options).map((option) => option.textContent ?? '');
const buttonByText = (container: HTMLElement, text: string): HTMLButtonElement => {
    const found = Array.from(container.querySelectorAll('button')).find((b) =>
        (b.textContent ?? '').includes(text),
    );
    if (!found) throw new Error(`no button ${text}`);
    return found;
};

describe('optionLabel', () => {
    it('marks galleries visitors cannot see yet', () => {
        expect(optionLabel({ id: 1, title: 'A', status: 'publish' })).toBe('A');
        expect(optionLabel({ id: 1, title: 'A', status: 'private' })).toBe('A (private)');
        expect(optionLabel({ id: 1, title: 'A', status: 'pending' })).toBe('A (pending review)');
        expect(optionLabel({ id: 1, title: 'A', status: 'draft' })).toBe('A (draft)');
        expect(optionLabel({ id: 1, title: '', status: 'publish' })).toBe('(no title)');
    });
});

describe('GallerySelector', () => {
    it('shows a loading note while the request is pending', async () => {
        mounted = await mount(
            <GallerySelector
                value={0}
                onChange={() => undefined}
                fetcher={() => new Promise(() => undefined)}
            />,
        );
        expect(mounted.container.querySelector('[role="status"]')?.textContent).toBe(
            'Loading galleries…',
        );
    });

    it('lists the galleries with their status', async () => {
        const { fetcher } = scriptedFetcher({ '/swg/v1/galleries?': () => GALLERIES });
        mounted = await mount(
            <GallerySelector value={0} onChange={() => undefined} fetcher={fetcher} />,
        );
        await settle();
        expect(mounted.container.textContent).not.toContain('Loading galleries…');
        expect(optionTexts(select(mounted.container, 'Gallery'))).toEqual([
            'none',
            'Summer',
            'Work in progress (draft)',
            'Family (private)',
            'Queue (pending review)',
            '(no title)',
        ]);
    });

    it('reports the chosen id and ignores an invalid choice', async () => {
        const onChange = vi.fn();
        const { fetcher } = scriptedFetcher({ '/swg/v1/galleries?': () => GALLERIES });
        mounted = await mount(<GallerySelector value={0} onChange={onChange} fetcher={fetcher} />);
        await settle();
        await change(select(mounted.container, 'Gallery'), '9');
        expect(onChange).toHaveBeenLastCalledWith(9);
        await change(select(mounted.container, 'Gallery'), '');
        expect(onChange).toHaveBeenLastCalledWith(0);
        await click(buttonByText(mounted.container, 'pick-invalid'));
        expect(onChange).toHaveBeenLastCalledWith(0);
    });

    it('says so when there are no galleries at all', async () => {
        const { fetcher } = scriptedFetcher({ '/swg/v1/galleries?': () => [] });
        mounted = await mount(
            <GallerySelector value={0} onChange={() => undefined} fetcher={fetcher} />,
        );
        await settle();
        expect(mounted.container.textContent).toContain(
            'There are no galleries yet. Create one first.',
        );
    });

    it('searches after a pause and says when nothing matches', async () => {
        const { fetcher, calls } = scriptedFetcher({
            '/swg/v1/galleries?': () => [],
        });
        mounted = await mount(
            <GallerySelector value={0} onChange={() => undefined} fetcher={fetcher} />,
        );
        await settle();
        const filter = mounted.container.querySelector('input[aria-label="Filter galleries"]');
        if (!filter) throw new Error('no filter');
        await change(filter, 'zz');
        await change(filter, 'zzz');
        expect(calls).toHaveLength(1);
        await settle(SEARCH_DELAY_MS + 100);
        expect(calls).toHaveLength(2);
        expect(calls[1]?.path).toContain('search=zzz');
        expect(mounted.container.textContent).toContain('No gallery matches "zzz".');
    });

    it('shows an honest error and recovers on retry', async () => {
        let fail = true;
        const { fetcher } = scriptedFetcher({
            '/swg/v1/galleries?': () => {
                if (fail) throw { data: { status: 403 }, message: 'internal detail' };
                return GALLERIES;
            },
        });
        mounted = await mount(
            <GallerySelector value={0} onChange={() => undefined} fetcher={fetcher} />,
        );
        await settle();
        const alert = mounted.container.querySelector('[role="alert"]');
        expect(alert?.textContent).toContain('You are not allowed to list galleries.');
        expect(alert?.textContent).not.toContain('internal detail');
        fail = false;
        await click(buttonByText(mounted.container, 'Try again'));
        await settle();
        expect(mounted.container.querySelector('[role="alert"]')).toBeNull();
        expect(optionTexts(select(mounted.container, 'Gallery'))).toContain('Summer');
    });

    it('adds a selected gallery that is not on the first page', async () => {
        const { fetcher } = scriptedFetcher({
            '/swg/v1/galleries?': () => GALLERIES,
            '/swg/v1/galleries/99': () => ({ id: 99, title: 'Old one', status: 'publish' }),
        });
        mounted = await mount(
            <GallerySelector value={99} onChange={() => undefined} fetcher={fetcher} />,
        );
        await settle();
        const select_ = select(mounted.container, 'Gallery');
        expect(optionTexts(select_)[1]).toBe('Old one');
        expect(select_.value).toBe('99');
    });

    it('warns about a missing gallery and lets the user clear it', async () => {
        const onChange = vi.fn();
        const { fetcher } = scriptedFetcher({
            '/swg/v1/galleries?': () => GALLERIES,
            '/swg/v1/galleries/55': () => {
                throw { code: 'swg_gallery_not_found' };
            },
        });
        mounted = await mount(<GallerySelector value={55} onChange={onChange} fetcher={fetcher} />);
        await settle();
        expect(mounted.container.querySelector('[data-status="warning"]')?.textContent).toContain(
            'Gallery #55 is not available',
        );
        await click(buttonByText(mounted.container, 'Clear selection'));
        expect(onChange).toHaveBeenCalledWith(0);
    });
});

describe('PresentationControls', () => {
    const setup = async (
        attributes: Parameters<typeof PresentationControls>[0]['attributes'],
        layouts: () => unknown = () => LAYOUTS,
    ) => {
        const setAttributes = vi.fn();
        const { fetcher } = scriptedFetcher({ '/swg/v1/layouts': layouts });
        mounted = await mount(
            <PresentationControls
                attributes={attributes}
                setAttributes={setAttributes}
                fetcher={fetcher}
            />,
        );
        await settle();
        return { setAttributes, container: (mounted as Mounted).container };
    };

    it('offers the registered layouts and stores a valid choice', async () => {
        const { setAttributes, container } = await setup({ galleryId: 1 });
        expect(optionTexts(select(container, 'Layout'))).toEqual(['Gallery default', 'Grid']);
        await change(select(container, 'Layout'), 'grid');
        expect(setAttributes).toHaveBeenLastCalledWith({ layout: 'grid' });
        await change(select(container, 'Layout'), '');
        expect(setAttributes).toHaveBeenLastCalledWith({ layout: undefined });
    });

    it('keeps a stored layout that is no longer registered visible', async () => {
        const { container } = await setup({ galleryId: 1, layout: 'old-one' });
        expect(optionTexts(select(container, 'Layout'))).toContain('old-one (not available)');
        expect(layoutOptions([], undefined)).toEqual([{ value: '', label: 'Gallery default' }]);
    });

    it('validates columns and gap and can reset them', async () => {
        const { setAttributes, container } = await setup({ galleryId: 1, columns: 3, gap: 8 });
        const columns = container.querySelector('input[aria-label="Columns"]');
        const gap = container.querySelector('input[aria-label="Gap between images (px)"]');
        if (!columns || !gap) throw new Error('missing inputs');
        await change(columns, '5');
        expect(setAttributes).toHaveBeenLastCalledWith({ columns: 5 });
        await change(columns, '99');
        expect(setAttributes).toHaveBeenLastCalledWith({ columns: undefined });
        await change(gap, '0');
        expect(setAttributes).toHaveBeenLastCalledWith({ gap: 0 });
        await change(gap, '65');
        expect(setAttributes).toHaveBeenLastCalledWith({ gap: undefined });
        await click(buttonByText(container, 'reset Columns'));
        expect(setAttributes).toHaveBeenLastCalledWith({ columns: undefined });
    });

    it('stores eager only when it is switched off', async () => {
        const { setAttributes, container } = await setup({ galleryId: 1 });
        const toggle = container.querySelector('input[type="checkbox"]');
        if (!(toggle instanceof HTMLInputElement)) throw new Error('no toggle');
        expect(toggle.checked).toBe(true);
        await click(toggle);
        expect(setAttributes).toHaveBeenLastCalledWith({ eager: false });
        await mounted?.rerender(
            <PresentationControls
                attributes={{ galleryId: 1, eager: false }}
                setAttributes={setAttributes}
                fetcher={scriptedFetcher({ '/swg/v1/layouts': () => LAYOUTS }).fetcher}
            />,
        );
        const again = container.querySelector('input[type="checkbox"]');
        if (!(again instanceof HTMLInputElement)) throw new Error('no toggle');
        expect(again.checked).toBe(false);
        await click(again);
        expect(setAttributes).toHaveBeenLastCalledWith({ eager: undefined });
    });

    it('degrades honestly when the layouts cannot be loaded', async () => {
        const { container } = await setup({ galleryId: 1 }, () => {
            throw { code: 'fetch_error' };
        });
        expect(container.querySelector('[data-status="warning"]')?.textContent).toContain(
            'could not be reached',
        );
        expect(select(container, 'Layout').disabled).toBe(true);
    });
});

describe('Edit', () => {
    const config = { galleriesUrl: 'https://x.test/wp-admin/admin.php?page=swg-galleries' };
    const routes = {
        '/swg/v1/galleries?': () => GALLERIES,
        '/swg/v1/galleries/7': () => GALLERIES[0],
        '/swg/v1/layouts': () => LAYOUTS,
    };

    it('shows the placeholder with a selector and only the create link without a gallery', async () => {
        const setAttributes = vi.fn();
        const { fetcher } = scriptedFetcher(routes);
        mounted = await mount(
            <Edit
                attributes={{ galleryId: 0 }}
                setAttributes={setAttributes}
                config={config}
                fetcher={fetcher}
            />,
        );
        await settle();
        const placeholder = mounted.container.querySelector('[data-testid="placeholder"]');
        if (!(placeholder instanceof HTMLElement)) throw new Error('no placeholder');
        expect(mounted.container.querySelector('[data-testid="preview"]')).toBeNull();
        expect(placeholder.textContent).toContain('Create a new gallery');
        expect(mounted.container.textContent).not.toContain('Edit this gallery');
        expect(
            mounted.container.querySelector(`a[href="${config.galleriesUrl}#/galleries"]`),
        ).not.toBeNull();
        await change(select(placeholder, 'Gallery'), '7');
        expect(setAttributes).toHaveBeenCalledWith({ galleryId: 7 });
    });

    it('renders the server preview with sanitised attributes and the edit link', async () => {
        const { fetcher } = scriptedFetcher(routes);
        mounted = await mount(
            <Edit
                attributes={{ galleryId: 7, layout: 'grid', columns: 99, gap: 4, eager: true }}
                setAttributes={() => undefined}
                config={config}
                fetcher={fetcher}
            />,
        );
        await settle();
        const preview = mounted.container.querySelector('[data-testid="preview"]');
        expect(preview?.getAttribute('data-block')).toBe('sw-gallery/gallery');
        expect(JSON.parse(preview?.getAttribute('data-attributes') ?? '{}')).toEqual({
            galleryId: 7,
            layout: 'grid',
            gap: 4,
        });
        expect(
            mounted.container.querySelector(`a[href="${config.galleriesUrl}#/galleries/7"]`)
                ?.textContent,
        ).toBe('Edit this gallery');
        expect(mounted.container.querySelector('[data-testid="inspector"]')).not.toBeNull();
        expect(
            mounted.container.querySelector('.wp-block-sw-gallery-gallery.swg-block'),
        ).not.toBeNull();
    });

    it('explains an empty and a failed preview', async () => {
        const { fetcher } = scriptedFetcher(routes);
        const node = (
            <Edit
                attributes={{ galleryId: 7 }}
                setAttributes={() => undefined}
                config={config}
                fetcher={fetcher}
            />
        );
        server.mode = 'empty';
        mounted = await mount(node);
        await settle();
        expect(mounted.container.textContent).toContain('nothing to show for visitors yet');
        server.mode = 'error';
        await mounted.rerender(
            <Edit
                attributes={{ galleryId: 7, gap: 1 }}
                setAttributes={() => undefined}
                config={config}
                fetcher={fetcher}
            />,
        );
        expect(mounted.container.querySelector('p[role="alert"]')?.textContent).toBe(
            'The preview could not be loaded.',
        );
    });
});
