import { Button, ComboboxControl, Notice } from '@wordpress/components';
import { createElement, Fragment, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { Fetcher, GallerySummary } from './api';
import { describeError, getGallery, listGalleries } from './api';
import { useDebounced, useLoadable } from './hooks';

export const SEARCH_DELAY_MS = 300;

export interface GallerySelectorProps {
    /** The selected gallery id, 0 for none. */
    value: number;
    onChange: (galleryId: number) => void;
    /** Injectable for tests; defaults to `@wordpress/api-fetch`. */
    fetcher?: Fetcher;
}

/** The option label: the title, marked when visitors cannot see the gallery yet. */
export function optionLabel(gallery: GallerySummary): string {
    const title = gallery.title === '' ? __('(no title)', 'sw-gallery') : gallery.title;
    switch (gallery.status) {
        case 'publish':
            return title;
        case 'private':
            /* translators: %s: gallery title */
            return sprintf(__('%s (private)', 'sw-gallery'), title);
        case 'pending':
            /* translators: %s: gallery title */
            return sprintf(__('%s (pending review)', 'sw-gallery'), title);
        default:
            /* translators: %s: gallery title */
            return sprintf(__('%s (draft)', 'sw-gallery'), title);
    }
}

/**
 * Searchable choice of an existing gallery. It only selects: galleries are created and edited in the plugin's
 * admin app (no gallery management in Gutenberg).
 */
export function GallerySelector({ value, onChange, fetcher }: GallerySelectorProps) {
    const [search, setSearch] = useState('');
    const [attempt, setAttempt] = useState(0);
    const term = useDebounced(search, SEARCH_DELAY_MS);

    const list = useLoadable(
        (signal) => listGalleries(term, fetcher, signal),
        [term, attempt],
        (error) => describeError(error, 'galleries'),
    );
    // The selected gallery may not be on the first page of results, so it is looked up on its own.
    const selected = useLoadable<GallerySummary | null>(
        (signal) => (value > 0 ? getGallery(value, fetcher, signal) : Promise.resolve(null)),
        [value, attempt],
        (error) => describeError(error, 'galleries'),
    );

    const galleries = list.kind === 'ready' ? list.value : [];
    const current = selected.kind === 'ready' ? selected.value : null;
    const options = galleries.map((gallery) => ({
        value: String(gallery.id),
        label: optionLabel(gallery),
    }));
    if (current !== null && !galleries.some((gallery) => gallery.id === current.id)) {
        options.unshift({ value: String(current.id), label: optionLabel(current) });
    }

    const retry = () => setAttempt((count) => count + 1);
    const missing = value > 0 && selected.kind === 'ready' && selected.value === null;

    return (
        <div className="swg-block-selector">
            <ComboboxControl
                __nextHasNoMarginBottom
                __next40pxDefaultSize
                label={__('Gallery', 'sw-gallery')}
                help={__('Type to search your galleries.', 'sw-gallery')}
                value={value > 0 ? String(value) : null}
                options={options}
                onChange={(next: string | null | undefined) => {
                    const id = Number(next);
                    onChange(Number.isInteger(id) && id > 0 ? id : 0);
                }}
                onFilterValueChange={setSearch}
            />
            {list.kind === 'loading' ? (
                <p role="status" className="swg-block-note">
                    {__('Loading galleries…', 'sw-gallery')}
                </p>
            ) : null}
            {list.kind === 'error' ? (
                <Notice status="error" isDismissible={false}>
                    <p>{list.message}</p>
                    <Button variant="secondary" onClick={retry}>
                        {__('Try again', 'sw-gallery')}
                    </Button>
                </Notice>
            ) : null}
            {list.kind === 'ready' && galleries.length === 0 ? (
                <p role="status" className="swg-block-note">
                    {term.trim() === ''
                        ? __('There are no galleries yet. Create one first.', 'sw-gallery')
                        : /* translators: %s: search text */ sprintf(
                              __('No gallery matches "%s".', 'sw-gallery'),
                              term.trim(),
                          )}
                </p>
            ) : null}
            {missing ? (
                <Fragment>
                    <Notice status="warning" isDismissible={false}>
                        <p>
                            {/* translators: %d: gallery id */}
                            {sprintf(
                                __(
                                    'Gallery #%d is not available. It may have been deleted or you may not view it.',
                                    'sw-gallery',
                                ),
                                value,
                            )}
                        </p>
                        <Button variant="secondary" onClick={() => onChange(0)}>
                            {__('Clear selection', 'sw-gallery')}
                        </Button>
                    </Notice>
                </Fragment>
            ) : null}
        </div>
    );
}
