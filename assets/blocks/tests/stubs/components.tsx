/* Light stand-ins for @wordpress/components: plain elements that expose the props the glue relies on. */
import { createElement } from '@wordpress/element';
import type { ReactNode } from 'react';

interface Option {
    value: string;
    label: string;
}

export function ComboboxControl(props: {
    label: string;
    help?: string;
    value: string | null;
    options: Option[];
    onChange: (value: string | null | undefined) => void;
    onFilterValueChange?: (value: string) => void;
}) {
    return (
        <div data-testid="combobox">
            <input
                aria-label="Filter galleries"
                onChange={(event) => props.onFilterValueChange?.(event.target.value)}
            />
            <select
                aria-label={props.label}
                value={props.value ?? ''}
                onChange={(event) =>
                    props.onChange(event.target.value === '' ? null : event.target.value)
                }
            >
                <option value="">none</option>
                {props.options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <button type="button" onClick={() => props.onChange('abc')}>
                pick-invalid
            </button>
            <p>{props.help}</p>
        </div>
    );
}

export function SelectControl(props: {
    label: string;
    value: string;
    disabled?: boolean;
    options: Option[];
    onChange: (value: string) => void;
}) {
    return (
        <select
            aria-label={props.label}
            value={props.value}
            disabled={props.disabled}
            onChange={(event) => props.onChange(event.target.value)}
        >
            {props.options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

export function RangeControl(props: {
    label: string;
    value?: number;
    min: number;
    max: number;
    onChange: (value?: number) => void;
}) {
    return (
        <div>
            <input
                type="number"
                aria-label={props.label}
                value={props.value ?? ''}
                onChange={(event) =>
                    props.onChange(
                        event.target.value === '' ? undefined : Number(event.target.value),
                    )
                }
            />
            <button type="button" onClick={() => props.onChange(undefined)}>
                reset {props.label}
            </button>
        </div>
    );
}

export function ToggleControl(props: {
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <label>
            <input
                type="checkbox"
                checked={props.checked}
                onChange={(event) => props.onChange(event.target.checked)}
            />
            {props.label}
        </label>
    );
}

export function Notice(props: { status: string; children: ReactNode }) {
    return (
        <div role="alert" data-status={props.status}>
            {props.children}
        </div>
    );
}

export function Button(props: { onClick: () => void; children: ReactNode }) {
    return (
        <button type="button" onClick={props.onClick}>
            {props.children}
        </button>
    );
}

export function ExternalLink(props: { href: string; children: ReactNode }) {
    return <a href={props.href}>{props.children}</a>;
}

export function PanelBody(props: { title: string; children: ReactNode }) {
    return (
        <section aria-label={props.title}>
            <h2>{props.title}</h2>
            {props.children}
        </section>
    );
}

export function Placeholder(props: { label: string; instructions: string; children: ReactNode }) {
    return (
        <div data-testid="placeholder" aria-label={props.label}>
            <p>{props.instructions}</p>
            {props.children}
        </div>
    );
}
