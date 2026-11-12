import { createElement } from '@wordpress/element';
import type { ComponentType } from 'react';

/** `mode` lets a test choose what the stubbed server answers with. */
export const server = { mode: 'ok' as 'ok' | 'empty' | 'error' };

export default function ServerSideRender(props: {
    block: string;
    attributes: Record<string, unknown>;
    EmptyResponsePlaceholder: ComponentType;
    ErrorResponsePlaceholder: ComponentType;
}) {
    if (server.mode === 'empty') return <props.EmptyResponsePlaceholder />;
    if (server.mode === 'error') return <props.ErrorResponsePlaceholder />;
    return (
        <div
            data-testid="preview"
            data-block={props.block}
            data-attributes={JSON.stringify(props.attributes)}
        />
    );
}
