import { registerBlockType } from '@wordpress/blocks';
import { createElement } from '@wordpress/element';
import './editor.css';
import type { BlockAttributes } from './attributes';
import { BLOCK_NAME } from './attributes';
import { readConfig } from './config';
import { Edit } from './Edit';

type Settings = Parameters<typeof registerBlockType<BlockAttributes>>[1];

/**
 * The block definition (title, attributes, supports) comes from block.json through the server registration, which
 * also translates it; the script only adds the editor UI, so the typed settings are completed by WordPress at
 * runtime. The block is dynamic: `save` stores no markup.
 */
export function register(): void {
    const config = readConfig(window);
    const settings = {
        edit: ({
            attributes,
            setAttributes,
        }: {
            attributes: BlockAttributes;
            setAttributes: (update: Partial<BlockAttributes>) => void;
        }) => <Edit attributes={attributes} setAttributes={setAttributes} config={config} />,
        save: () => null,
    };
    registerBlockType<BlockAttributes>(BLOCK_NAME, settings as unknown as Settings);
}

register();
