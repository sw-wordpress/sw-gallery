import { Notice, RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { Fetcher, LayoutSummary } from './api';
import { describeError, listLayouts } from './api';
import type { BlockAttributes } from './attributes';
import { integerInRange, isLayoutId, LIMITS } from './attributes';
import { useLoadable } from './hooks';

export interface PresentationControlsProps {
    attributes: BlockAttributes;
    /** Partial update; `undefined` removes an override (inherit from the gallery). */
    setAttributes: (update: Partial<BlockAttributes>) => void;
    fetcher?: Fetcher;
}

/** Layout choices: the gallery default, every registered layout, and a stored id that is no longer registered. */
export function layoutOptions(
    layouts: LayoutSummary[],
    current: string | undefined,
): { value: string; label: string }[] {
    const options = [
        { value: '', label: __('Gallery default', 'sw-gallery') },
        ...layouts.map((layout) => ({ value: layout.id, label: layout.label })),
    ];
    if (current !== undefined && !layouts.some((layout) => layout.id === current)) {
        /* translators: %s: layout id */
        options.push({
            value: current,
            label: sprintf(__('%s (not available)', 'sw-gallery'), current),
        });
    }
    return options;
}

/** The four presentation overrides. Values are validated before they reach the attributes. */
export function PresentationControls({
    attributes,
    setAttributes,
    fetcher,
}: PresentationControlsProps) {
    const layouts = useLoadable(
        (signal) => listLayouts(fetcher, signal),
        [],
        (error) => describeError(error, 'layouts'),
    );

    return (
        <div className="swg-block-presentation">
            {layouts.kind === 'error' ? (
                <Notice status="warning" isDismissible={false}>
                    {layouts.message}
                </Notice>
            ) : null}
            <SelectControl
                label={__('Layout', 'sw-gallery')}
                help={__('Overrides the layout of the gallery for this block only.', 'sw-gallery')}
                value={attributes.layout ?? ''}
                disabled={layouts.kind !== 'ready'}
                options={layoutOptions(
                    layouts.kind === 'ready' ? layouts.value : [],
                    attributes.layout,
                )}
                onChange={(next: string) =>
                    setAttributes({ layout: isLayoutId(next) ? next : undefined })
                }
            />
            <RangeControl
                label={__('Columns', 'sw-gallery')}
                help={__('Leave unset to use the gallery setting.', 'sw-gallery')}
                value={attributes.columns}
                min={LIMITS.columns.min}
                max={LIMITS.columns.max}
                allowReset
                onChange={(next?: number) =>
                    setAttributes({
                        columns: integerInRange(next, LIMITS.columns.min, LIMITS.columns.max),
                    })
                }
            />
            <RangeControl
                label={__('Gap between images (px)', 'sw-gallery')}
                help={__('Leave unset to use the gallery setting.', 'sw-gallery')}
                value={attributes.gap}
                min={LIMITS.gap.min}
                max={LIMITS.gap.max}
                allowReset
                onChange={(next?: number) =>
                    setAttributes({ gap: integerInRange(next, LIMITS.gap.min, LIMITS.gap.max) })
                }
            />
            <ToggleControl
                label={__('Load the first images eagerly', 'sw-gallery')}
                help={__('Turn off when the gallery is far down the page.', 'sw-gallery')}
                checked={attributes.eager !== false}
                onChange={(next: boolean) => setAttributes({ eager: next ? undefined : false })}
            />
        </div>
    );
}
