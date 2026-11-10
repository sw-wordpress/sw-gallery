export interface Labels {
  dialog: string;
  close: string;
  previous: string;
  next: string;
  /** Placeholders: {current}, {total}. */
  counter: string;
  error: string;
}

export const DEFAULT_LABELS: Readonly<Labels> = {
  dialog: 'Image viewer',
  close: 'Close',
  previous: 'Previous image',
  next: 'Next image',
  counter: '{current} of {total}',
  error: 'The image could not be loaded.',
};

/** Parses `data-swg-labels` defensively: anything unusable falls back to the English defaults. */
export function parseLabels(raw: string | null | undefined): Labels {
  const labels: Labels = { ...DEFAULT_LABELS };
  if (!raw) return labels;
  let data: unknown;
  try {
    data = JSON.parse(raw);
  } catch {
    return labels;
  }
  if (typeof data !== 'object' || data === null || Array.isArray(data)) return labels;
  const source = data as Record<string, unknown>;
  for (const key of Object.keys(DEFAULT_LABELS) as (keyof Labels)[]) {
    const value = source[key];
    if (typeof value === 'string' && value.trim() !== '') labels[key] = value;
  }
  return labels;
}

export function formatCounter(template: string, current: number, total: number): string {
  return template
    .replace(/\{current\}/g, () => String(current))
    .replace(/\{total\}/g, () => String(total));
}
