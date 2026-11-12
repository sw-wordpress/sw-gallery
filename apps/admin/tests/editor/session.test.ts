import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../src/api/client';
import {
  classifyFailure,
  createEditorSession,
  fetchEditorState,
} from '../../src/features/editor/useGalleryEditor';
import { FakeServer, makeItem } from './support';

async function ready(server = new FakeServer()) {
  const session = createEditorSession(7, server.api());
  await session.load();
  return { server, session };
}

afterEach(() => {
  vi.useRealTimers();
});

describe('loading', () => {
  it('loads every page of 100 items and the revision of the snapshot', async () => {
    const { server, session } = await ready(new FakeServer(250));
    expect(server.calls).toEqual(['get', 'list:1', 'list:2', 'list:3']);
    expect(session.phase.value).toBe('ready');
    expect(session.order.value).toHaveLength(250);
    expect(session.revision.value).toBe(5);
    expect(session.truncated.value).toBe(false);
    expect(session.dirty.value).toBe(false);
  });

  it('stops at the reorder limit, says so and disables reordering', async () => {
    const { server, session } = await ready(new FakeServer(10050));
    expect(session.order.value).toHaveLength(10000);
    expect(session.truncated.value).toBe(true);
    expect(session.total.value).toBe(10050);
    expect(session.reorderEnabled.value).toBe(false);
    expect(session.move(new Set([1]), 'end')).toBe(false);
    expect(session.moveTo(new Set([1]), 5)).toBe(false);
    expect(server.calls.filter((c) => c.startsWith('list:'))).toHaveLength(100);
  });

  it('repeats a load whose pages carry different revisions and gives up after three tries', async () => {
    const server = new FakeServer(250);
    const api = server.api();
    let calls = 0;
    const original = api.items.list;
    api.items.list = async (...args) => {
      const page = await original(...args);
      calls += 1;
      return args[1] === 2 ? { ...page, revision: page.revision + 1 } : page;
    };
    const session = createEditorSession(7, api);
    await session.load();
    expect(session.phase.value).toBe('error');
    expect((session.loadError.value as ApiError).code).toBe('swg_unstable');
    expect(calls).toBe(6);
  });

  it('reports not found and other load errors, and ignores aborted loads', async () => {
    const server = new FakeServer();
    server.failures.set('get', new ApiError('swg_gallery_not_found', 'x', 404));
    const missing = createEditorSession(7, server.api());
    await missing.load();
    expect(missing.gone.value).toBe(true);
    expect(missing.phase.value).toBe('error');

    server.failures.set('get', new ApiError('swg_internal_error', 'x', 500));
    const broken = createEditorSession(7, server.api());
    await broken.load();
    expect(broken.gone.value).toBe(false);
    expect(broken.phase.value).toBe('error');
    server.calls = [];
    await broken.load();
    expect(broken.phase.value).toBe('ready');

    server.failures.set('get', new ApiError('swg_aborted', 'x', 0));
    const aborted = createEditorSession(7, server.api());
    await aborted.load();
    expect(aborted.loadError.value).toBeNull();
  });

  it('marks a trashed gallery as not editable', async () => {
    const { session } = await ready(new FakeServer(1, { status: 'trash' }));
    expect(session.invalidState.value).toBe(true);
  });

  it('rejects a response without the revision header as invalid', async () => {
    const server = new FakeServer();
    const api = server.api();
    await expect(fetchEditorState(api, 7)).resolves.toMatchObject({ revision: 5 });
    expect(classifyFailure(new ApiError('swg_invalid_response', 'x', 200))).toBe('other');
  });
});

describe('failure classification', () => {
  it('maps API errors to kinds', () => {
    const kind = (code: string, status: number): string =>
      classifyFailure(new ApiError(code, 'x', status));
    expect(kind('swg_network', 0)).toBe('network');
    expect(kind('swg_revision_conflict', 409)).toBe('conflict');
    expect(kind('swg_invalid_state', 409)).toBe('invalidState');
    expect(kind('swg_item_not_found', 404)).toBe('itemGone');
    expect(kind('swg_gallery_not_found', 404)).toBe('gone');
    expect(kind('swg_forbidden', 403)).toBe('forbidden');
    expect(kind('rest_invalid_param', 400)).toBe('invalid');
    expect(kind('swg_validation_failed', 422)).toBe('invalid');
    expect(kind('swg_internal_error', 500)).toBe('other');
    expect(classifyFailure(new Error('x'))).toBe('other');
  });
});

describe('staged edits, undo and redo', () => {
  it('counts changes, undoes, redoes and coalesces typing into one step', async () => {
    const { session } = await ready();
    session.setDetail('title', 'A');
    session.setDetail('title', 'AB');
    session.setDetail('title', 'ABC');
    expect(session.working.value?.details.title).toBe('ABC');
    expect(session.changeCount.value).toBe(1);
    expect(session.dirty.value).toBe(true);
    expect(session.undo()).toBe(true);
    expect(session.working.value?.details.title).toBe('Summer 2025');
    expect(session.dirty.value).toBe(false);
    expect(session.canUndo.value).toBe(false);
    expect(session.canRedo.value).toBe(true);
    expect(session.redo()).toBe(true);
    expect(session.working.value?.details.title).toBe('ABC');
    expect(session.redo()).toBe(false);
  });

  it('does not coalesce after the pause and a new edit clears the redo stack', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-01-01T10:00:00Z'));
    const { session } = await ready();
    session.setDetail('title', 'One');
    vi.setSystemTime(new Date('2026-01-01T10:00:05Z'));
    session.setDetail('title', 'Two');
    session.undo();
    expect(session.working.value?.details.title).toBe('One');
    session.setDetail('description', 'Text');
    expect(session.canRedo.value).toBe(false);
    session.undo();
    session.undo();
    expect(session.working.value?.details.title).toBe('Summer 2025');
    expect(session.undo()).toBe(false);
  });

  it('ignores edits that change nothing', async () => {
    const { session } = await ready();
    session.setDetail('title', 'Summer 2025');
    session.setSetting(['columns'], undefined);
    expect(session.canUndo.value).toBe(false);
  });

  it('stages removals (clearing their texts), reordering and discards everything undoably', async () => {
    const { session } = await ready();
    session.setItemTexts(2, { title: 'Two' });
    expect(session.removeItems([2, 3, 99])).toBe(2);
    expect(session.removeItems([99])).toBe(0);
    expect(session.order.value).toEqual([1, 4]);
    expect(session.removedCount.value).toBe(2);
    expect(session.working.value?.overrides[2]).toBeUndefined();
    expect(session.move(new Set([4]), 'start')).toBe(true);
    expect(session.move(new Set([4]), 'start')).toBe(false);
    expect(session.moveTo(new Set([4]), 2)).toBe(true);
    expect(session.order.value).toEqual([1, 4]);
    session.discard();
    expect(session.dirty.value).toBe(false);
    expect(session.order.value).toEqual([1, 2, 3, 4]);
    session.undo();
    expect(session.dirty.value).toBe(true);
    expect(session.order.value).toEqual([1, 4]);
  });

  it('keeps unknown settings keys and removes emptied branches', async () => {
    const server = new FakeServer(1, { settings: { future: { a: 1 }, columns: { base: 3 } } });
    const { session } = await ready(server);
    session.setSetting(['columns', 'md'], 4);
    expect(session.working.value?.details.settings).toEqual({
      future: { a: 1 },
      columns: { base: 3, md: 4 },
    });
    session.setSetting(['columns', 'md'], undefined);
    session.setSetting(['columns', 'base'], undefined);
    expect(session.working.value?.details.settings).toEqual({ future: { a: 1 } });
    session.setSetting(['gap'], 12);
    expect(session.working.value?.details.settings).toEqual({ future: { a: 1 }, gap: 12 });
    session.setSetting(['fresh', 'deep'], 1);
    expect(session.working.value?.details.settings.fresh).toEqual({ deep: 1 });
    session.setSetting([], 1);
  });

  it('distinguishes inherit (null), blank and own text of an item', async () => {
    const { session } = await ready();
    expect(session.itemView(1)?.effective.title).toBe('Global 1');
    session.setItemTexts(1, { title: '' });
    expect(session.itemView(1)).toMatchObject({ effective: { title: '' }, edited: true });
    session.setItemTexts(1, { title: 'Own' });
    expect(session.itemView(1)?.effective.title).toBe('Own');
    session.setItemTexts(1, { title: null });
    expect(session.itemView(1)?.edited).toBe(false);
    expect(session.dirty.value).toBe(false);
    expect(session.itemView(1234)).toBeNull();
    session.setItemTexts(1234, { title: 'x' });
    expect(session.dirty.value).toBe(false);
  });

  it('shows an unavailable item with its server texts', async () => {
    const server = new FakeServer(2);
    server.items[1] = makeItem(2, { missing: true, global: null, media: null });
    const { session } = await ready(server);
    expect(session.itemView(2)?.item.missing).toBe(true);
  });
});

describe('save pipeline', () => {
  it('applies removals, texts, order and details in order and chains the revisions', async () => {
    const { server, session } = await ready();
    session.removeItems([2]);
    session.setItemTexts(3, { title: 'Three' });
    session.move(new Set([4]), 'start');
    session.setDetail('title', 'New title');
    session.setSetting(['columns'], { base: 3, md: 4 });
    // title + settings (details), one removal, one edited item, the order
    expect(session.changeCount.value).toBe(5);
    server.calls = [];
    const report = await session.save();
    expect(report).toMatchObject({
      ok: true,
      total: 4,
      completed: ['remove', 'override', 'order', 'details'],
    });
    expect(server.calls).toEqual(['remove', 'override:3', 'order', 'details']);
    // every write sends the revision returned by the previous one
    expect(server.expected).toEqual([5, 6, 7, 8]);
    expect(session.revision.value).toBe(9);
    expect(server.ids()).toEqual([4, 1, 3]);
    expect(server.gallery.title).toBe('New title');
    expect(server.gallery.settings).toEqual({ columns: { base: 3, md: 4 } });
    expect(session.dirty.value).toBe(false);
    expect(session.canUndo.value).toBe(false);
    expect(session.saving.value).toBe(false);
    expect(session.progress.value).toBeNull();
  });

  it('chunks big removals into batches of 500', async () => {
    const { server, session } = await ready(new FakeServer(1200));
    session.removeItems(server.ids().slice(0, 1100));
    server.calls = [];
    await session.save();
    expect(server.calls).toEqual(['remove', 'remove', 'remove']);
  });

  it('sends only changed override fields and the complete settings map', async () => {
    const server = new FakeServer(2, { settings: { gap: 4, custom: true } });
    const { session } = await ready(server);
    session.setItemTexts(1, { alt: 'Own alt' });
    session.setSetting(['gap'], 9);
    await session.save();
    expect(server.items[0]?.overrides).toEqual({ title: null, caption: null, alt: 'Own alt' });
    expect(server.gallery.settings).toEqual({ gap: 9, custom: true });
  });

  it('does nothing without changes, while saving, or before loading', async () => {
    const { session } = await ready();
    expect(await session.save()).toBeNull();
    const fresh = createEditorSession(7, new FakeServer().api());
    expect(await fresh.save()).toBeNull();
    expect(await fresh.addAttachments([1])).toEqual({ ok: false, failure: 'other' });
    fresh.discard();
    fresh.undo();
  });

  it('publish stages the status and saves it last', async () => {
    const { server, session } = await ready();
    session.removeItems([1]);
    const report = await session.publish();
    expect(report?.completed).toEqual(['remove', 'details']);
    expect(server.gallery.status).toBe('publish');
  });

  it('reports a partial failure honestly and keeps the rest staged', async () => {
    const { server, session } = await ready();
    session.removeItems([2]);
    session.setItemTexts(3, { title: 'Three' });
    session.move(new Set([4]), 'start');
    session.setDetail('title', 'New title');
    server.failures.set('override:3', new ApiError('swg_internal_error', 'x', 500));
    const report = await session.save();
    expect(report).toMatchObject({
      ok: false,
      completed: ['remove'],
      failed: { kind: 'override', failure: 'other' },
    });
    // the removal is part of the server state now, the rest is still staged
    expect(server.ids()).toEqual([1, 3, 4]);
    expect(session.base.value?.order).toEqual([1, 3, 4]);
    expect(session.stagedSummary()).toEqual({
      details: ['title'],
      overrides: [3],
      order: true,
      removed: 0,
    });
    expect(session.lastFailure.value).toBe('other');
    expect(session.conflict.value).toBeNull();
    // saving again finishes the remaining steps with the chained revision
    server.expected = [];
    const again = await session.save();
    expect(again?.ok).toBe(true);
    expect(server.expected).toEqual([6, 7, 8]);
    expect(server.ids()).toEqual([4, 1, 3]);
    expect(session.dirty.value).toBe(false);
  });

  it('opens the conflict dialog on 409, explains the difference and re-applies the staged changes', async () => {
    const { server, session } = await ready();
    session.setDetail('title', 'Mine');
    session.setItemTexts(2, { caption: 'My caption' });
    session.removeItems([4]);
    server.beforeWrite = (label) => {
      if (label === 'remove') {
        server.beforeWrite = null;
        server.externalChange(() => {
          server.items.push(makeItem(50));
          server.gallery = { ...server.gallery, title: 'Theirs', description: 'Changed' };
        });
      }
    };
    const report = await session.save();
    expect(report?.failed?.failure).toBe('conflict');
    const conflict = session.conflict.value;
    expect(conflict?.diff).toMatchObject({ itemsAdded: 1, itemsRemoved: 0, itemsMoved: 0 });
    expect(conflict?.diff.detailKeys).toEqual(['title', 'description']);
    expect(conflict?.stagedCount).toBe(3);
    expect(conflict?.notices.overwrittenDetails).toEqual(['title']);
    expect(session.dirty.value).toBe(true);

    session.resolveConflict('reapply');
    expect(session.conflict.value).toBeNull();
    expect(session.working.value?.details.title).toBe('Mine');
    expect(session.working.value?.details.description).toBe('Changed');
    expect(session.working.value?.overrides[2]?.caption).toBe('My caption');
    expect(session.order.value).toEqual([1, 2, 3, 50]);
    expect(session.revision.value).toBe(6);
    const again = await session.save();
    expect(again?.ok).toBe(true);
    expect(server.gallery.title).toBe('Mine');
    expect(server.ids()).toEqual([1, 2, 3, 50]);
  });

  it('can discard the staged changes in favour of the server state', async () => {
    const { server, session } = await ready();
    session.setDetail('title', 'Mine');
    server.externalChange(() => {
      server.gallery = { ...server.gallery, title: 'Theirs' };
    });
    await session.save();
    expect(session.conflict.value).not.toBeNull();
    session.resolveConflict('discard');
    expect(session.working.value?.details.title).toBe('Theirs');
    expect(session.dirty.value).toBe(false);
    expect(session.rebaseNotices.value).toBeNull();
    session.resolveConflict('discard');
  });

  it('drops a staged order that no longer applies and says so', async () => {
    const { server, session } = await ready();
    session.move(new Set([4]), 'start');
    server.externalChange(() => {
      server.items.reverse();
    });
    await session.save();
    expect(session.conflict.value?.notices.droppedOrder).toBe(true);
    session.resolveConflict('reapply');
    expect(session.order.value).toEqual([4, 3, 2, 1]);
    expect(session.rebaseNotices.value?.droppedOrder).toBe(true);
    expect(session.dirty.value).toBe(false);
  });

  it('records field problems of a 422 and keeps everything staged', async () => {
    const { server, session } = await ready();
    session.setSetting(['columns'], { base: 3, md: 99 });
    server.failures.set(
      'details',
      new ApiError(
        'swg_validation_failed',
        'x',
        422,
        {},
        {
          status: 422,
          errors: [
            { path: '/columns/md', code: 'swg_above_maximum', message: 'no' },
            { path: '/gap', code: 'swg_below_minimum' },
            { nope: true },
          ],
        },
      ),
    );
    const report = await session.save();
    expect(report?.failed).toMatchObject({ kind: 'details', failure: 'invalid' });
    expect(session.settingIssues.value).toEqual([
      { path: ['columns', 'md'], code: 'swg_above_maximum' },
      { path: ['gap'], code: 'swg_below_minimum' },
    ]);
    expect(session.invalidDetails.value).toContain('columns');
    expect(session.dirty.value).toBe(true);
    session.setSetting(['columns', 'md'], 5);
    expect(session.settingIssues.value).toEqual([]);
  });

  it('handles a vanished gallery, a changed state, forbidden and network failures', async () => {
    const a = await ready();
    a.session.setDetail('title', 'x');
    a.server.failures.set('details', new ApiError('swg_gallery_not_found', 'x', 404));
    await a.session.save();
    expect(a.session.gone.value).toBe(true);

    const b = await ready();
    b.session.setDetail('title', 'x');
    b.server.failures.set('details', new ApiError('swg_invalid_state', 'x', 409));
    await b.session.save();
    expect(b.session.invalidState.value).toBe(true);
    expect(b.session.lastFailure.value).toBe('invalidState');

    const c = await ready();
    c.session.setDetail('title', 'x');
    c.server.failures.set('details', new ApiError('swg_cannot_publish', 'x', 403));
    const report = await c.session.save();
    expect(report?.failed?.failure).toBe('forbidden');
    expect(c.session.dirty.value).toBe(true);

    const d = await ready();
    d.session.setDetail('title', 'x');
    d.server.failures.set('details', new ApiError('swg_network', 'x', 0));
    await d.session.save();
    expect(d.session.lastFailure.value).toBe('network');
    expect(d.session.conflict.value).toBeNull();
  });

  it('re-syncs after a vanished item and shows what others changed', async () => {
    const { server, session } = await ready();
    session.setItemTexts(3, { title: 'x' });
    server.failures.set('override:3', new ApiError('swg_item_not_found', 'x', 404));
    server.externalChange(() => {
      server.items = server.items.filter((i) => i.id !== 3);
    });
    server.failures.set('override:3', new ApiError('swg_item_not_found', 'x', 404));
    // the stale expected revision is irrelevant here: the 404 comes first
    const report = await session.save();
    expect(report?.failed?.failure).toBe('itemGone');
    expect(session.conflict.value?.diff.itemsRemoved).toBe(1);
    expect(session.conflict.value?.notices.droppedOverrides).toBe(1);
    session.resolveConflict('reapply');
    expect(session.dirty.value).toBe(false);
  });

  it('keeps the dialog state when the re-sync itself fails', async () => {
    const { server, session } = await ready();
    session.setDetail('title', 'x');
    server.failures.set('details', new ApiError('swg_internal_error', 'x', 500));
    server.failures.set('get', new ApiError('swg_gallery_not_found', 'x', 404));
    await session.save();
    expect(session.gone.value).toBe(true);
  });
});

describe('adding images (applied immediately)', () => {
  it('assigns, fetches only the tail page and keeps staged edits and history intact', async () => {
    const { server, session } = await ready(new FakeServer(250));
    session.move(new Set([250]), 'start');
    server.calls = [];
    const outcome = await session.addAttachments([5001, 5002, 1001, -1, 5001]);
    expect(outcome).toEqual({ ok: true, added: 2, alreadyAssigned: 1, missing: 1 });
    expect(server.calls).toEqual(['assign', 'list:3']);
    expect(session.order.value).toHaveLength(252);
    expect(session.order.value.slice(0, 2)).toEqual([250, 1]);
    expect(session.order.value.slice(-2)).toEqual([251, 252]);
    expect(session.revision.value).toBe(6);
    expect(session.total.value).toBe(252);
    // undoing the move must not make the new images look removed
    session.undo();
    expect(session.order.value).toHaveLength(252);
    expect(session.removedCount.value).toBe(0);
    session.redo();
    expect(session.order.value.slice(0, 2)).toEqual([250, 1]);
  });

  it('reports nothing added without fetching the list again', async () => {
    const { server, session } = await ready();
    server.calls = [];
    expect(await session.addAttachments([1001])).toEqual({
      ok: true,
      added: 0,
      alreadyAssigned: 1,
      missing: 0,
    });
    expect(server.calls).toEqual(['assign']);
  });

  it('opens the conflict dialog when the revision is stale', async () => {
    const { server, session } = await ready();
    server.externalChange(() => server.items.push(makeItem(60)));
    const outcome = await session.addAttachments([7000]);
    expect(outcome).toEqual({ ok: false, failure: 'conflict' });
    expect(session.conflict.value?.diff.itemsAdded).toBe(1);
  });

  it('asks the user to reconcile when somebody else added images in between', async () => {
    const server = new FakeServer(2);
    const api = server.api();
    const original = api.items.list;
    let first = true;
    api.items.list = async (...args) => {
      if (first && server.calls.includes('assign')) {
        first = false;
        server.externalChange(() => server.items.push(makeItem(70)));
      }
      return original(...args);
    };
    const session = createEditorSession(7, api);
    await session.load();
    const outcome = await session.addAttachments([7100]);
    expect(outcome).toMatchObject({ ok: true, added: 1 });
    expect(session.conflict.value).not.toBeNull();
    expect(session.order.value).toEqual([1, 2]);
  });
});
