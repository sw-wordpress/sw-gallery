import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

const messages = {
  singleOne: 'Used in %d gallery. Delete?',
  singleMany: 'Used in %d galleries. Delete?',
  bulkOne: '%d selected image is used. Delete?',
  bulkMany: '%d selected images are used. Delete?',
};

beforeAll(async () => {
  (window as unknown as { swgMediaLibrary: typeof messages }).swgMediaLibrary = messages;
  // The script is classic JavaScript without exports: a variable path keeps it out of the type checker.
  const path = '../media-library.js';
  await import(/* @vite-ignore */ path);
});

afterEach(() => {
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

function click(target: Element): Event {
  const event = new MouseEvent('click', { bubbles: true, cancelable: true });
  target.dispatchEvent(event);
  return event;
}

function row(used: number | null, href = 'post.php?post=5&action=delete&_wpnonce=x'): HTMLElement {
  document.body.innerHTML = `<table><tbody><tr>
    <th class="check-column"><input type="checkbox" checked></th>
    <td>${used === null ? '' : `<span data-swg-used="${used}"></span>`}<a class="submitdelete" href="${href}">Delete</a></td>
  </tr></tbody></table>`;
  return document.querySelector('a.submitdelete') as HTMLElement;
}

describe('delete link', () => {
  it('asks with the singular message and keeps the deletion when confirmed', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const event = click(row(1));
    expect(confirm).toHaveBeenCalledWith('Used in 1 gallery. Delete?');
    expect(event.defaultPrevented).toBe(false);
  });

  it('asks with the plural message and stops the deletion when declined', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const event = click(row(3));
    expect(confirm).toHaveBeenCalledWith('Used in 3 galleries. Delete?');
    expect(event.defaultPrevented).toBe(true);
  });

  it.each([[0], [null]])('does not ask when the image is unused (%s)', (used) => {
    const confirm = vi.spyOn(window, 'confirm');
    click(row(used));
    expect(confirm).not.toHaveBeenCalled();
  });

  it('ignores other links and other clicks', () => {
    const confirm = vi.spyOn(window, 'confirm');
    click(row(2, 'post.php?post=5&action=edit'));
    document.body.innerHTML = '<span data-swg-used="2"></span><a class="other" href="x">x</a>';
    click(document.querySelector('a')!);
    document.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(confirm).not.toHaveBeenCalled();
  });

  it('reads the count from the page on the edit screen (no table row)', () => {
    document.body.innerHTML =
      '<span data-swg-used="2"></span><a class="submitdelete" href="post.php?action=delete&post=1">x</a>';
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const event = click(document.querySelector('a')!);
    expect(confirm).toHaveBeenCalledWith('Used in 2 galleries. Delete?');
    expect(event.defaultPrevented).toBe(true);
  });
});

function bulk(action: string, action2: string, used: Array<number | null>): HTMLFormElement {
  document.body.innerHTML = `<form id="posts-filter">
    <select name="action"><option value="-1"></option><option value="delete"></option></select>
    <select name="action2"><option value="-1"></option><option value="delete"></option></select>
    <table><tbody>${used
      .map(
        (count) =>
          `<tr><th class="check-column"><input type="checkbox" checked></th><td>${count === null ? '' : `<span data-swg-used="${count}"></span>`}</td></tr>`,
      )
      .join('')}</tbody></table></form>`;
  const form = document.getElementById('posts-filter') as HTMLFormElement;
  (form.querySelector('[name="action"]') as HTMLSelectElement).value = action;
  (form.querySelector('[name="action2"]') as HTMLSelectElement).value = action2;
  return form;
}

function submit(form: HTMLFormElement): Event {
  const event = new Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(event);
  return event;
}

describe('bulk delete', () => {
  it('asks with the number of used images and can be declined', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const event = submit(bulk('delete', '-1', [2, 0, 1]));
    expect(confirm).toHaveBeenCalledWith('2 selected images are used. Delete?');
    expect(event.defaultPrevented).toBe(true);
  });

  it('uses the singular text, also for the bottom selector, and passes when confirmed', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const event = submit(bulk('-1', 'delete', [1, null]));
    expect(confirm).toHaveBeenCalledWith('1 selected image is used. Delete?');
    expect(event.defaultPrevented).toBe(false);
  });

  it('does not ask for other actions, unused images or other forms', () => {
    const confirm = vi.spyOn(window, 'confirm');
    submit(bulk('-1', '-1', [2]));
    submit(bulk('delete', '-1', [0, null]));
    document.body.innerHTML = '<form id="other"></form>';
    document.getElementById('other')!.dispatchEvent(new Event('submit', { bubbles: true }));
    expect(confirm).not.toHaveBeenCalled();
  });
});
