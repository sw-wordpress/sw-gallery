import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { ExternalLink, PanelBody, Placeholder } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import type { Fetcher } from './api';
import type { BlockAttributes } from './attributes';
import { BLOCK_NAME, sanitizeAttributes } from './attributes';
import type { BlockConfig } from './config';
import { GallerySelector } from './GallerySelector';
import { editGalleryUrl, galleryListUrl } from './links';
import { PresentationControls } from './PresentationControls';

export interface EditProps {
    attributes: BlockAttributes;
    setAttributes: (update: Partial<BlockAttributes>) => void;
    config: BlockConfig;
    fetcher?: Fetcher;
}

/** Links to the admin app, where galleries are created and edited. */
function GalleryLinks({ galleryId, config }: { galleryId: number; config: BlockConfig }) {
    const edit = editGalleryUrl(config.galleriesUrl, galleryId);
    return (
        <div className="swg-block-links">
            {edit !== null ? (
                <p>
                    <ExternalLink href={edit}>{__('Edit this gallery', 'sw-gallery')}</ExternalLink>
                </p>
            ) : null}
            <p>
                <ExternalLink href={galleryListUrl(config.galleriesUrl)}>
                    {__('Create a new gallery', 'sw-gallery')}
                </ExternalLink>
            </p>
        </div>
    );
}

/**
 * The block's editor UI, deliberately small: pick a gallery, see the server-rendered result, adjust four
 * presentation overrides. Everything about the gallery itself happens in the plugin's admin app.
 */
export function Edit({ attributes, setAttributes, config, fetcher }: EditProps) {
    const blockProps = useBlockProps({ className: 'swg-block' });
    const clean = sanitizeAttributes(attributes);
    const selectGallery = (galleryId: number) => setAttributes({ galleryId });

    return (
        <div {...blockProps}>
            <InspectorControls>
                <PanelBody title={__('Gallery', 'sw-gallery')} initialOpen>
                    <GallerySelector
                        value={clean.galleryId}
                        onChange={selectGallery}
                        fetcher={fetcher}
                    />
                    <GalleryLinks galleryId={clean.galleryId} config={config} />
                </PanelBody>
                <PanelBody title={__('Presentation', 'sw-gallery')} initialOpen>
                    <PresentationControls
                        attributes={clean}
                        setAttributes={setAttributes}
                        fetcher={fetcher}
                    />
                </PanelBody>
            </InspectorControls>
            {clean.galleryId === 0 ? (
                <Placeholder
                    icon="format-gallery"
                    label={__('SW Gallery', 'sw-gallery')}
                    instructions={__('Choose one of your galleries to show it here.', 'sw-gallery')}
                >
                    <GallerySelector value={0} onChange={selectGallery} fetcher={fetcher} />
                    <GalleryLinks galleryId={0} config={config} />
                </Placeholder>
            ) : (
                <ServerSideRender
                    block={BLOCK_NAME}
                    attributes={clean}
                    EmptyResponsePlaceholder={() => (
                        <p className="swg-block-note">
                            {__(
                                'This gallery has nothing to show for visitors yet: it may be a draft, empty or private.',
                                'sw-gallery',
                            )}
                        </p>
                    )}
                    ErrorResponsePlaceholder={() => (
                        <p className="swg-block-note" role="alert">
                            {__('The preview could not be loaded.', 'sw-gallery')}
                        </p>
                    )}
                />
            )}
        </div>
    );
}
