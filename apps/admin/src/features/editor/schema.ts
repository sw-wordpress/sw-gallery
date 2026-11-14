import type { JsonSchema } from '@/api/layouts';

/** Helpers that turn a layout's `settingsSchema` (JSON-schema subset, docs/rest-api.md) into form fields. */

export const BREAKPOINTS = ['base', 'sm', 'md', 'lg', 'xl'] as const;
export type Breakpoint = (typeof BREAKPOINTS)[number];

export type FieldKind = 'integer' | 'number' | 'boolean' | 'enum' | 'string' | 'unsupported';

export interface FieldDef {
  key: string;
  kind: FieldKind;
  /** Schema `title`, if the layout provides one (the app has its own catalog labels first). */
  title: string | undefined;
  description: string | undefined;
  responsive: boolean;
  min: number | undefined;
  max: number | undefined;
  maxLength: number | undefined;
  options: unknown[];
  /** Display labels from the optional `x-enum-titles` keyword (`{ value: label }`); only string labels count. */
  optionTitles: Record<string, string>;
  defaultValue: unknown;
}

function kindOf(schema: JsonSchema): FieldKind {
  const type = Array.isArray(schema.type) ? schema.type[0] : schema.type;
  if (Array.isArray(schema.enum) && schema.enum.length > 0) return 'enum';
  switch (type) {
    case 'integer':
      return 'integer';
    case 'number':
      return 'number';
    case 'boolean':
      return 'boolean';
    case 'string':
      return 'string';
    default:
      return 'unsupported';
  }
}

function enumTitles(value: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    for (const [key, title] of Object.entries(value)) {
      if (typeof title === 'string' && title !== '') out[key] = title;
    }
  }
  return out;
}

/** Label of one enum value: the schema's translated title, else the raw value. */
export function optionLabel(def: Pick<FieldDef, 'optionTitles'>, value: unknown): string {
  const key = String(value);
  return Object.hasOwn(def.optionTitles, key) ? (def.optionTitles[key] as string) : key;
}

export function fieldsOf(schema: JsonSchema | undefined): FieldDef[] {
  const properties = schema?.properties;
  if (properties === undefined || properties === null || typeof properties !== 'object') return [];
  return Object.entries(properties).map(([key, def]) => ({
    key,
    kind: kindOf(def),
    title: typeof def.title === 'string' ? def.title : undefined,
    description: typeof def.description === 'string' ? def.description : undefined,
    responsive: def['x-responsive'] === true,
    min: typeof def.minimum === 'number' ? def.minimum : undefined,
    max: typeof def.maximum === 'number' ? def.maximum : undefined,
    maxLength: typeof def.maxLength === 'number' ? def.maxLength : undefined,
    options: Array.isArray(def.enum) ? def.enum : [],
    optionTitles: enumTitles(def['x-enum-titles']),
    defaultValue: def.default,
  }));
}

export type ValueIssue = 'type' | 'integer' | 'min' | 'max' | 'enum' | 'length';

/** Live client-side check; the server validates again and stays authoritative. */
export function validateValue(def: FieldDef, value: unknown): ValueIssue | null {
  switch (def.kind) {
    case 'integer':
    case 'number': {
      if (typeof value !== 'number' || !Number.isFinite(value)) return 'type';
      if (def.kind === 'integer' && !Number.isInteger(value)) return 'integer';
      if (def.min !== undefined && value < def.min) return 'min';
      if (def.max !== undefined && value > def.max) return 'max';
      return null;
    }
    case 'boolean':
      return typeof value === 'boolean' ? null : 'type';
    case 'enum':
      return def.options.includes(value) ? null : 'enum';
    case 'string':
      if (typeof value !== 'string') return 'type';
      return def.maxLength !== undefined && value.length > def.maxLength ? 'length' : null;
    default:
      return null;
  }
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** A responsive value is a plain value (= base) or an object keyed by breakpoint. */
export function readResponsive(value: unknown): Partial<Record<Breakpoint, unknown>> {
  if (value === undefined) return {};
  if (!isPlainObject(value)) return { base: value };
  const out: Partial<Record<Breakpoint, unknown>> = {};
  for (const bp of BREAKPOINTS) if (bp in value) out[bp] = value[bp];
  return out;
}

/** Inverse of `readResponsive`: nothing set = `undefined` (default applies), only base = plain value. */
export function writeResponsive(
  map: Partial<Record<Breakpoint, unknown>>,
  extra: Record<string, unknown> = {},
): unknown {
  const entries = BREAKPOINTS.filter((bp) => map[bp] !== undefined);
  if (entries.length === 0 && Object.keys(extra).length === 0) return undefined;
  if (entries.length === 1 && entries[0] === 'base' && Object.keys(extra).length === 0) {
    return map.base;
  }
  const out: Record<string, unknown> = { ...extra };
  for (const bp of entries) out[bp] = map[bp];
  return out;
}

/** Breakpoint entries of a stored value that this form does not know (kept untouched on write). */
export function unknownBreakpoints(value: unknown): Record<string, unknown> {
  if (!isPlainObject(value)) return {};
  return Object.fromEntries(
    Object.entries(value).filter(([key]) => !(BREAKPOINTS as readonly string[]).includes(key)),
  );
}

export interface SettingProblem {
  key: string;
  breakpoint: Breakpoint | null;
  issue: ValueIssue;
}

/** All live problems of a settings map against the layout schema (unknown keys are ignored). */
export function validateSettings(
  schema: JsonSchema | undefined,
  settings: Record<string, unknown>,
): SettingProblem[] {
  const problems: SettingProblem[] = [];
  for (const def of fieldsOf(schema)) {
    const value = settings[def.key];
    if (value === undefined || def.kind === 'unsupported') continue;
    if (def.responsive) {
      for (const [bp, entry] of Object.entries(readResponsive(value))) {
        const issue = validateValue(def, entry);
        if (issue !== null) problems.push({ key: def.key, breakpoint: bp as Breakpoint, issue });
      }
    } else {
      const issue = validateValue(def, value);
      if (issue !== null) problems.push({ key: def.key, breakpoint: null, issue });
    }
  }
  return problems;
}
