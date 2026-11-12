import { createElement } from '@wordpress/element';
import type { ReactNode } from 'react';

export function InspectorControls(props: { children: ReactNode }) {
    return <aside data-testid="inspector">{props.children}</aside>;
}

export function useBlockProps(props: { className?: string } = {}) {
    return { className: `wp-block-sw-gallery-gallery ${props.className ?? ''}`.trim() };
}
