import { describe, expect, it } from 'vitest';
import { baseFromFresh, diffServer, rebase } from '../../src/features/editor/conflict';
import {
  buildPlan,
  changedDetailKeys,
  countChanges,
  deepEqual,
  detailsOf,
  effectiveTexts,
  initialWorking,
  orderChanged,
} from '../../src/features/editor/model';
import { moveIds, moveIdsTo } from '../../src/features/editor/reorder';
import {
  BREAKPOINTS,
  fieldsOf,
  readResponsive,
  unknownBreakpoints,
  validateSettings,
  validateValue,
  writeResponsive,
} from '../../src/features/editor/schema';
import { computeWindow, scrollTopForRow } from '../../src/features/editor/virtual';
import { LAYOUTS, makeGallery, makeItem } from './support';

const fresh = (ids: number[], gallery = makeGallery()) => ({
  gallery,
  items: ids.map((id) => makeItem(id)),
  revision: gallery.revision,
});

describe('model', () => {
  it('compares JSON-like values structurally', () => {
    expect(deepEqual({ a: [1, { b: 2 }] }, { a: [1, { b: 2 }] })).toBe(true);
    expect(deepEqual({ a: 1 }, { a: 1, b: 2 })).toBe(false);
    expect(deepEqual([1], { 0: 1 })).toBe(false);
    expect(deepEqual(null, {})).toBe(false);
    expect(deepEqual({ a: 1 }, { b: 1 })).toBe(false);
    expect(deepEqual([1, 2], [1, 3])).toBe(false);
  });

  it('plans removals in batches, then texts, then the order, then the details', () => {
    const base = baseFromFresh(fresh([1, 2, 3, 4]));
    const work = initialWorking(base);
    expect(buildPlan(base, work)).toEqual([]);
    const staged = {
      ...work,
      order: [4, 1, 3],
      overrides: { 3: { title: 'T', caption: null, alt: '' } },
      details: { ...work.details, title: 'New' },
    };
    expect(buildPlan(base, staged)).toEqual([
      { kind: 'remove', itemIds: [2] },
      { kind: 'override', itemId: 3, overrides: { title: 'T', alt: '' } },
      { kind: 'order', itemIds: [4, 1, 3] },
      { kind: 'details', changes: { title: 'New' } },
    ]);
    expect(countChanges(base, staged)).toBe(4);
  });

  it('does not count a removal as a reorder', () => {
    const base = baseFromFresh(fresh([1, 2, 3]));
    const work = { ...initialWorking(base), order: [1, 3] };
    expect(orderChanged(base, work)).toBe(false);
    expect(changedDetailKeys(detailsOf(base.gallery), work.details)).toEqual([]);
  });

  it('ignores staged texts of items that are gone from the base', () => {
    const base = baseFromFresh(fresh([1]));
    const work = {
      ...initialWorking(base),
      order: [1, 9],
      overrides: { 9: { title: 'x', caption: null, alt: null } },
    };
    expect(buildPlan(base, work).some((s) => s.kind === 'override')).toBe(false);
  });

  it('applies overrides on top of the global texts', () => {
    const item = makeItem(1);
    expect(effectiveTexts(item, { title: '', caption: null, alt: 'A' })).toEqual({
      title: '',
      caption: '',
      alt: 'A',
    });
    expect(
      effectiveTexts(makeItem(2, { global: null }), { title: null, caption: null, alt: null }),
    ).toEqual({ title: '', caption: '', alt: '' });
  });
});

describe('reorder', () => {
  const order = [1, 2, 3, 4, 5];
  it('moves single and grouped selections one step', () => {
    expect(moveIds(order, new Set([3]), 'earlier')).toEqual([1, 3, 2, 4, 5]);
    expect(moveIds(order, new Set([3]), 'later')).toEqual([1, 2, 4, 3, 5]);
    expect(moveIds(order, new Set([2, 3]), 'earlier')).toEqual([2, 3, 1, 4, 5]);
    expect(moveIds(order, new Set([3, 4]), 'later')).toEqual([1, 2, 5, 3, 4]);
    expect(moveIds(order, new Set([1, 3]), 'earlier')).toEqual([1, 3, 2, 4, 5]);
  });
  it('gathers the selection at the edges and keeps its relative order', () => {
    expect(moveIds(order, new Set([4, 2]), 'start')).toEqual([2, 4, 1, 3, 5]);
    expect(moveIds(order, new Set([4, 2]), 'end')).toEqual([1, 3, 5, 2, 4]);
  });
  it('returns the same array when nothing moves', () => {
    expect(moveIds(order, new Set([1]), 'earlier')).toBe(order);
    expect(moveIds(order, new Set([5]), 'later')).toBe(order);
    expect(moveIds(order, new Set([1]), 'start')).toBe(order);
    expect(moveIds(order, new Set(), 'end')).toBe(order);
    expect(moveIds(order, new Set([99]), 'end')).toBe(order);
  });
  it('drops dragged items before the target index', () => {
    expect(moveIdsTo(order, new Set([5]), 1)).toEqual([1, 5, 2, 3, 4]);
    expect(moveIdsTo(order, new Set([1, 2]), 5)).toEqual([3, 4, 5, 1, 2]);
    expect(moveIdsTo(order, new Set([2]), 2)).toBe(order);
    expect(moveIdsTo(order, new Set([3]), -4)).toEqual([3, 1, 2, 4, 5]);
    expect(moveIdsTo(order, new Set([99]), 1)).toBe(order);
  });
});

describe('virtual window', () => {
  const input = { width: 960, height: 600, minTile: 150, gap: 12, labelHeight: 26 };
  it('renders only the visible rows plus the overscan of a huge gallery', () => {
    const top = computeWindow({ ...input, count: 10000, scrollTop: 0 });
    expect(top.columns).toBe(6);
    expect(top.start).toBe(0);
    expect(top.end).toBeLessThan(120);
    expect(top.padTop).toBe(0);
    expect(top.padBottom).toBeGreaterThan(0);
    const middle = computeWindow({ ...input, count: 10000, scrollTop: 50 * top.rowHeight });
    expect(middle.start).toBe(48 * 6);
    expect(middle.end - middle.start).toBeLessThan(80);
    expect(middle.padTop).toBe(48 * top.rowHeight);
    expect(
      middle.padTop + ((middle.end - middle.start) / 6) * top.rowHeight + middle.padBottom,
    ).toBe(top.rows * top.rowHeight);
    const end = computeWindow({ ...input, count: 10000, scrollTop: 1e9 });
    expect(end.end).toBe(10000);
    expect(end.padBottom).toBe(0);
  });
  it('copes with an empty gallery and a tiny container', () => {
    const empty = computeWindow({ ...input, count: 0, scrollTop: 0 });
    expect(empty).toMatchObject({ start: 0, end: 0, padBottom: 0 });
    const narrow = computeWindow({ ...input, width: 0, count: 5, scrollTop: -10 });
    expect(narrow.columns).toBe(1);
  });
  it('scrolls a row into view only when needed', () => {
    expect(scrollTopForRow(2, 100, 300, 0)).toBe(0);
    expect(scrollTopForRow(5, 100, 300, 0)).toBe(300);
    expect(scrollTopForRow(1, 100, 300, 400)).toBe(100);
  });
});

describe('settings schema helpers', () => {
  const schema = LAYOUTS.layouts[0]?.settingsSchema;
  it('builds field definitions incl. responsive and bounds', () => {
    const fields = fieldsOf(schema);
    expect(fields.map((f) => f.key)).toEqual(['columns', 'gap']);
    expect(fields[0]).toMatchObject({
      kind: 'integer',
      responsive: true,
      min: 1,
      max: 12,
      defaultValue: 3,
    });
    expect(fields[1]?.responsive).toBe(false);
    expect(fieldsOf(undefined)).toEqual([]);
    const kinds = fieldsOf({
      properties: {
        a: { type: 'boolean' },
        b: { type: 'string', maxLength: 5 },
        c: { enum: ['x', 'y'] },
        d: { type: 'object' },
        e: { type: ['number', 'null'], title: 'E', description: 'd' },
      },
    });
    expect(kinds.map((f) => f.kind)).toEqual([
      'boolean',
      'string',
      'enum',
      'unsupported',
      'number',
    ]);
  });

  it('validates values live', () => {
    const [columns, gap] = fieldsOf(schema);
    if (columns === undefined || gap === undefined) throw new Error('fields');
    expect(validateValue(columns, 3)).toBeNull();
    expect(validateValue(columns, 0)).toBe('min');
    expect(validateValue(columns, 13)).toBe('max');
    expect(validateValue(columns, 2.5)).toBe('integer');
    expect(validateValue(columns, '3')).toBe('type');
    expect(validateValue(columns, Number.NaN)).toBe('type');
    const extra = fieldsOf({
      properties: {
        a: { type: 'boolean' },
        b: { type: 'string', maxLength: 2 },
        c: { enum: [1] },
        d: {},
      },
    });
    expect(validateValue(extra[0]!, 'x')).toBe('type');
    expect(validateValue(extra[0]!, true)).toBeNull();
    expect(validateValue(extra[1]!, 'abc')).toBe('length');
    expect(validateValue(extra[1]!, 4)).toBe('type');
    expect(validateValue(extra[2]!, 2)).toBe('enum');
    expect(validateValue(extra[3]!, 'whatever')).toBeNull();
  });

  it('reads and writes responsive values and keeps unknown breakpoint keys', () => {
    expect(readResponsive(undefined)).toEqual({});
    expect(readResponsive(4)).toEqual({ base: 4 });
    expect(readResponsive({ base: 2, md: 3, huge: 9 })).toEqual({ base: 2, md: 3 });
    expect(writeResponsive({})).toBeUndefined();
    expect(writeResponsive({ base: 3 })).toBe(3);
    expect(writeResponsive({ base: 3, lg: 5 })).toEqual({ base: 3, lg: 5 });
    expect(writeResponsive({ base: 3 }, { future: 1 })).toEqual({ future: 1, base: 3 });
    expect(writeResponsive({}, { future: 1 })).toEqual({ future: 1 });
    expect(unknownBreakpoints({ base: 1, future: 2 })).toEqual({ future: 2 });
    expect(unknownBreakpoints(5)).toEqual({});
    expect(BREAKPOINTS).toEqual(['base', 'sm', 'md', 'lg', 'xl']);
  });

  it('collects problems of a whole settings map and ignores unknown keys', () => {
    expect(validateSettings(schema, { columns: { base: 3, md: 20 }, gap: -1, other: 'x' })).toEqual(
      [
        { key: 'columns', breakpoint: 'md', issue: 'max' },
        { key: 'gap', breakpoint: null, issue: 'min' },
      ],
    );
    expect(validateSettings(schema, { columns: 4 })).toEqual([]);
    expect(validateSettings(undefined, { columns: 'x' })).toEqual([]);
  });
});

describe('conflict diff and rebase', () => {
  const base = baseFromFresh(fresh([1, 2, 3, 4]));

  it('counts added, removed, moved and re-texted items and changed details', () => {
    const changed = fresh([4, 2, 3, 9], makeGallery({ title: 'Other', revision: 9 }));
    changed.items[1] = makeItem(2, { overrides: { title: 'Fresh', caption: null, alt: null } });
    expect(diffServer(base, baseFromFresh(changed))).toEqual({
      detailKeys: ['title'],
      itemsAdded: 1,
      itemsRemoved: 1,
      itemsMoved: 3,
      overridesChanged: 1,
    });
  });

  it('keeps staged details and texts, merges additions and keeps a still-valid order', () => {
    const work = {
      ...initialWorking(base),
      order: [2, 1, 3],
      overrides: { 1: { title: 'Mine', caption: null, alt: null } },
      details: { ...initialWorking(base).details, description: 'my text' },
    };
    const result = rebase(
      base,
      work,
      fresh([1, 2, 3, 4, 5], makeGallery({ revision: 8, title: 'Theirs' })),
    );
    expect(result.working.details).toMatchObject({ title: 'Theirs', description: 'my text' });
    // user removed 4, somebody added 5, the user's new order survives, new items go to the end
    expect(result.working.order).toEqual([2, 1, 3, 5]);
    expect(result.working.overrides[1]?.title).toBe('Mine');
    expect(result.notices).toEqual({
      droppedOrder: false,
      droppedOverrides: 0,
      overwrittenDetails: [],
      overwrittenOverrides: 0,
    });
    expect(result.base.revision).toBe(8);
  });

  it('reports overwritten values, dropped texts and a dropped order', () => {
    const work = {
      ...initialWorking(base),
      order: [4, 3, 2, 1],
      overrides: {
        2: { title: 'Mine', caption: null, alt: null },
        3: { title: 'Gone', caption: null, alt: null },
      },
      details: { ...initialWorking(base).details, title: 'Mine' },
    };
    const theirs = fresh([1, 2, 4], makeGallery({ title: 'Theirs', revision: 9 }));
    theirs.items[1] = makeItem(2, { overrides: { title: 'Theirs', caption: null, alt: null } });
    theirs.items.reverse();
    const result = rebase(base, work, theirs);
    expect(result.notices).toEqual({
      droppedOrder: true,
      droppedOverrides: 1,
      overwrittenDetails: ['title'],
      overwrittenOverrides: 1,
    });
    expect(result.working.details.title).toBe('Mine');
    expect(result.working.order).toEqual([4, 2, 1]);
  });

  it('applies the server order when the user did not reorder', () => {
    const work = { ...initialWorking(base), order: [1, 2, 3] };
    const result = rebase(base, work, fresh([3, 2, 1, 4]));
    expect(result.working.order).toEqual([3, 2, 1]);
    expect(result.notices.droppedOrder).toBe(false);
  });
});
