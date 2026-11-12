/** Records what the glue registers, instead of talking to the real block registry. */
export interface Registration {
    name: string;
    settings: { edit: (props: never) => unknown; save: () => unknown };
}

export const registered: Registration[] = [];

export function registerBlockType(name: string, settings: Registration['settings']): void {
    registered.push({ name, settings });
}
