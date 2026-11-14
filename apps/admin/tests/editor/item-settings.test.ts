import { flushPromises } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { createEditorSession } from '../../src/features/editor/useGalleryEditor';
import {
  buildPlan,
  changedItemSettingsIds,
  countChanges,
  initialWorking,
} from '../../src/features/editor/model';
import type { Base } from '../../src/features/editor/model';
import { itemFieldsOf, valueAtPath, withPath } from '../../src/features/editor/schema';
import { mountApp, resetEnvironment } from '../support/mountApp';
import { FakeServer, LAYOUTS, makeGallery, makeItem } from './support';

let mounted: VueWrapper | null = null;
afterEach(() => {
  mounted?.unmount();
  mounted = null;
  resetEnvironment();
});

async function ready(server = new FakeServer(3, { layout: 'mosaic' })) {
  const session = createEditorSession(7, server.api());
  await session.load();
  return { server, session };
}

describe('item settings model', () => {
  const base: Base = {
    gallery: makeGallery(),
    items: new Map([
      [1, makeItem(1, { settings: { emphasis: 2 } })],
      [2, makeItem(2)],
    ]),
    order: [1, 2],
    revision: 3,
  };

  it('detects staged maps that differ from the stored ones and plans one patch per item', () => {
    const work = {
      ...initialWorking(base),
      itemSettings: { 1: { emphasis: 2 }, 2: { emphasis: 3 } },
    };
    expect(changedItemSettingsIds(base, work)).toEqual([2]);
    expect(countChanges(base, work)).toBe(1);
    expect(buildPlan(base, work)).toEqual([
      { kind: 'itemSettings', itemId: 2, settings: { emphasis: 3 } },
    ]);
    expect(changedItemSettingsIds(base, { ...work, order: [1] })).toEqual([]);
  });

  it('expands the item schema one level and reads and writes key paths', () => {
    const schema = LAYOUTS.layouts.find((l) => l.id === 'mosaic')?.settingsSchema[
      'x-item-settings'
    ];
    const fields = itemFieldsOf(schema);
    expect(fields.map((f) => f.path.join('.'))).toEqual(['emphasis', 'focal.x', 'focal.y']);
    expect(fields[0]?.def.kind).toBe('enum');
    expect(itemFieldsOf(undefined)).toEqual([]);
    const map = withPath({}, ['focal', 'x'], 20);
    expect(map).toEqual({ focal: { x: 20 } });
    expect(valueAtPath(map, ['focal', 'x'])).toBe(20);
    expect(valueAtPath(map, ['focal', 'y'])).toBeUndefined();
    expect(valueAtPath(map, ['nope', 'x'])).toBeUndefined();
    expect(withPath(map, ['focal', 'x'], undefined)).toEqual({});
    expect(withPath({ a: 1 }, ['a'], undefined)).toEqual({});
    expect(withPath({ a: 1 }, [], 5)).toEqual({ a: 1 });
    expect(withPath({ focal: 'x' }, ['focal', 'y'], 1)).toEqual({ focal: { y: 1 } });
  });
});

describe('item settings session', () => {
  it('stages, undoes, saves through the items PATCH and clears the staging', async () => {
    const { server, session } = await ready();
    session.setItemSettings(2, { emphasis: 3, focal: { x: 10, y: 90 }, gone: undefined });
    expect(session.itemSettingsOf(2)).toEqual({ emphasis: 3, focal: { x: 10, y: 90 } });
    expect(session.dirty.value).toBe(true);
    expect(session.stagedSummary()?.itemSettings).toEqual([2]);
    session.undo();
    expect(session.dirty.value).toBe(false);
    session.redo();
    const report = await session.save();
    expect(report?.completed).toEqual(['itemSettings']);
    expect(server.items[1]?.settings).toEqual({ emphasis: 3, focal: { x: 10, y: 90 } });
    expect(session.dirty.value).toBe(false);
    // back to the stored value drops the staging
    session.setItemSettings(2, { emphasis: 3, focal: { x: 10, y: 90 } });
    expect(session.dirty.value).toBe(false);
    // unknown items are ignored
    session.setItemSettings(99, { emphasis: 2 });
    expect(session.dirty.value).toBe(false);
  });

  it('drops staged settings of removed items', async () => {
    const { session } = await ready();
    session.setItemSettings(1, { emphasis: 2 });
    session.setItemSettings(2, { emphasis: 3 });
    session.removeItems([1]);
    expect(session.stagedSummary()?.itemSettings).toEqual([2]);
  });

  it('keeps staged settings when a conflict is re-applied on top of the server state', async () => {
    const { server, session } = await ready();
    session.setItemSettings(2, { emphasis: 3 });
    server.beforeWrite = (label) => {
      if (label === 'itemSettings:2' || label.startsWith('override')) {
        server.beforeWrite = null;
        server.revision += 1;
      }
    };
    await session.save();
    await session.resolveConflict('reapply');
    expect(session.stagedSummary()?.itemSettings ?? []).toEqual([2]);
  });
});

describe('item settings form', () => {
  const settle = async (): Promise<void> => {
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
  };
  const buttonByLabel = (label: string): HTMLElement | undefined =>
    Array.from(document.body.querySelectorAll<HTMLElement>('button')).find(
      (b) => b.querySelector('.block')?.textContent?.trim() === label,
    );

  async function open(layout: string) {
    const server = new FakeServer(2, { layout });
    const result = await mountApp({
      path: '/galleries/7',
      capabilities: ['swg_manage_galleries', 'upload_files', 'edit_posts', 'swg_publish_galleries'],
      editorApi: server.api(),
    });
    mounted = result.wrapper;
    await settle();
    document.body
      .querySelectorAll<HTMLElement>('[role="option"]')[0]
      ?.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    await settle();
    return { server, sheet: document.body.querySelector('[role="dialog"]') as HTMLElement };
  }

  it('shows the schema driven form for a layout with x-item-settings and saves it', async () => {
    const { server, sheet } = await open('mosaic');
    expect(sheet.textContent).toContain('Layout settings for this image');
    expect(sheet.textContent).toContain('Size in the mosaic');
    expect(sheet.textContent).toContain('Focal point, horizontal (%)');
    expect(sheet.querySelectorAll('input[type="range"]')).toHaveLength(2);
    const range = sheet.querySelector<HTMLInputElement>('input[type="range"]') as HTMLInputElement;
    range.value = '20';
    range.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(sheet.textContent).toContain('Reset to defaults');
    buttonByLabel('Done')?.click();
    await settle();
    buttonByLabel('Save')?.click();
    await settle();
    expect(server.items[0]?.settings).toEqual({ focal: { x: 20 } });
  });

  it('resets the staged settings to the defaults', async () => {
    const { sheet } = await open('mosaic');
    const range = sheet.querySelector<HTMLInputElement>('input[type="range"]') as HTMLInputElement;
    range.value = '30';
    range.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(buttonByLabel('Reset to defaults')).toBeDefined();
    buttonByLabel('Reset to defaults')?.click();
    await settle();
    expect(buttonByLabel('Reset to defaults')).toBeUndefined();
  });

  it('shows no form for a layout without item settings', async () => {
    const { sheet } = await open('grid');
    expect(sheet.textContent).not.toContain('Layout settings for this image');
  });
});
