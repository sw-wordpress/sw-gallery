import { createRoot } from '@wordpress/element';
import type { ReactNode } from 'react';
import { act } from 'react';
import type { Fetcher } from '../src/api';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

export interface Mounted {
    container: HTMLElement;
    rerender: (node: ReactNode) => Promise<void>;
    unmount: () => Promise<void>;
}

export async function mount(node: ReactNode): Promise<Mounted> {
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    await act(async () => {
        root.render(node);
    });
    return {
        container,
        rerender: async (next) => {
            await act(async () => {
                root.render(next);
            });
        },
        unmount: async () => {
            await act(async () => {
                root.unmount();
            });
            container.remove();
        },
    };
}

/** Lets pending promises and timers (fake or real) settle inside act(). */
export async function settle(ms = 0): Promise<void> {
    await act(async () => {
        if (ms > 0) await new Promise((resolve) => setTimeout(resolve, ms));
        await Promise.resolve();
    });
}

export async function change(element: Element, value: string): Promise<void> {
    const proto = Object.getPrototypeOf(element) as object;
    const setter = Object.getOwnPropertyDescriptor(proto, 'value')?.set;
    await act(async () => {
        setter?.call(element, value);
        element.dispatchEvent(
            new Event(element instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }),
        );
    });
}

export async function click(element: Element): Promise<void> {
    await act(async () => {
        (element as HTMLElement).click();
    });
}

export interface Call {
    path: string;
}

/** A fetcher that answers by path prefix and records every request. */
export function scriptedFetcher(routes: Record<string, () => unknown>): {
    fetcher: Fetcher;
    calls: Call[];
} {
    const calls: Call[] = [];
    const fetcher: Fetcher = (options) => {
        calls.push({ path: options.path });
        const key = Object.keys(routes).find((prefix) => options.path.startsWith(prefix));
        if (key === undefined) return Promise.reject(new Error(`unexpected ${options.path}`));
        try {
            return Promise.resolve((routes[key] as () => unknown)());
        } catch (error) {
            return Promise.reject(error);
        }
    };
    return { fetcher, calls };
}

export const GALLERIES = [
    { id: 7, title: 'Summer', status: 'publish' },
    { id: 8, title: 'Work in progress', status: 'draft' },
    { id: 9, title: 'Family', status: 'private' },
    { id: 10, title: 'Queue', status: 'pending' },
    { id: 11, title: '', status: 'publish' },
];

export const LAYOUTS = { fallbackId: 'grid', layouts: [{ id: 'grid', label: 'Grid' }] };
