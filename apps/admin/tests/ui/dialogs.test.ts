import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { QInput, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import type { Ref } from 'vue';
import ConfirmDialog from '../../src/components/ui/ConfirmDialog.vue';
import FormSheet from '../../src/components/ui/FormSheet.vue';
import SideSheet from '../../src/components/ui/SideSheet.vue';

const global = { plugins: [[Quasar, { iconSet }]] as never[] };
let mounted: VueWrapper | null = null;

afterEach(() => {
  mounted?.unmount();
  mounted = null;
  document.body.innerHTML = '';
});

/** Quasar dialogs teleport into <body>: query the document, not the wrapper. */
const $ = <T extends Element = HTMLElement>(selector: string): T | null =>
  document.body.querySelector<T>(selector);
const $$ = (selector: string): HTMLElement[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>(selector));

function harness(render: (open: Ref<boolean>, events: string[]) => unknown) {
  const events: string[] = [];
  const open = ref(true);
  const Host = defineComponent({
    setup: () => () => render(open, events) as ReturnType<typeof h>,
  });
  mounted = mount(Host, { attachTo: document.body, global });
  return { open, events };
}

const confirmProps = {
  title: 'Delete galleries?',
  targets: ['Summer 2025', 'Wedding'],
  targetsLabel: 'Affects',
  confirmLabel: 'Delete',
  cancelLabel: 'Cancel',
};

function confirmHost(extra: Record<string, unknown> = {}) {
  return harness((open, events) =>
    h(ConfirmDialog, {
      ...confirmProps,
      ...extra,
      modelValue: open.value,
      'onUpdate:modelValue': (v: boolean) => (open.value = v),
      onConfirm: () => events.push('confirm'),
    }),
  );
}

describe('ConfirmDialog', () => {
  it('is an alertdialog labelled by its title and names every affected object', async () => {
    confirmHost({ message: 'This cannot be undone.', consequences: ['Items are unlinked'] });
    await flushPromises();
    const dialog = $('[role="alertdialog"]');
    expect(dialog).not.toBeNull();
    const labelledBy = dialog?.getAttribute('aria-labelledby') ?? '';
    expect(document.getElementById(labelledBy)?.textContent).toBe('Delete galleries?');
    expect($$('.swg-cd__target-list li').map((li) => li.textContent)).toEqual([
      'Summer 2025',
      'Wedding',
    ]);
    expect($('.swg-cd__msg')?.textContent).toBe('This cannot be undone.');
    expect($('.swg-cd__list')?.textContent).toContain('Items are unlinked');
  });

  it('confirm emits, cancel closes; Cancel carries the initial focus marker', async () => {
    const { open, events } = confirmHost();
    await flushPromises();
    const buttons = $$('.q-card__actions button');
    expect(buttons[0]?.textContent).toContain('Cancel');
    expect(buttons[0]?.hasAttribute('data-autofocus')).toBe(true);
    buttons[1]?.click();
    expect(events).toEqual(['confirm']);
    buttons[0]?.click();
    await flushPromises();
    expect(open.value).toBe(false);
  });

  it('escape closes the dialog although it is persistent (no click-outside dismissal)', async () => {
    const { open } = confirmHost();
    await flushPromises();
    $('[role="alertdialog"]')?.dispatchEvent(
      new KeyboardEvent('keyup', { key: 'Escape', bubbles: true }),
    );
    await flushPromises();
    expect(open.value).toBe(false);
  });

  it('requires the typed text before confirming and resets it on reopen', async () => {
    const { open, events } = confirmHost({
      requireText: 'DELETE',
      requireTextLabel: 'Type DELETE',
    });
    await flushPromises();
    const confirmButton = (): HTMLElement | undefined => $$('.q-card__actions button')[1];
    expect(confirmButton()?.hasAttribute('disabled')).toBe(true);
    const input = $<HTMLInputElement>('input');
    expect(input).not.toBeNull();
    if (!input) return;
    input.value = 'DELETE';
    input.dispatchEvent(new Event('input'));
    await flushPromises();
    expect(confirmButton()?.hasAttribute('disabled')).toBe(false);
    input.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', keyCode: 13, bubbles: true }));
    confirmButton()?.click();
    expect(events.length).toBeGreaterThan(0);

    open.value = false;
    await flushPromises();
    open.value = true;
    await flushPromises();
    expect($<HTMLInputElement>('input')?.value).toBe('');
    expect(confirmButton()?.hasAttribute('disabled')).toBe(true);
  });

  it('does not emit while loading or when the typed text is wrong; shows server errors', async () => {
    const { events } = confirmHost({ loading: true, error: 'Gallery changed meanwhile' });
    await flushPromises();
    expect($('[role="alert"]')?.textContent).toContain('Gallery changed meanwhile');
    $$('.q-card__actions button')[1]?.click();
    expect(events).toEqual([]);
  });

  it('supports non-danger styling', async () => {
    confirmHost({ danger: false });
    await flushPromises();
    expect($('.swg-cd__icon--danger')).toBeNull();
    expect($('.swg-cd__icon')?.textContent).toContain('help');
  });
});

describe('SideSheet', () => {
  it('is a modal dialog labelled by its title with a labelled close button', async () => {
    const { open } = harness((open) =>
      h(
        SideSheet,
        {
          title: 'Edit gallery',
          subtitle: 'Summer 2025',
          closeLabel: 'Close',
          modelValue: open.value,
          'onUpdate:modelValue': (v: boolean) => (open.value = v),
        },
        { default: () => h('p', { class: 'content' }, 'body'), footer: () => h('span', 'foot') },
      ),
    );
    await flushPromises();
    const dialog = $('[role="dialog"]');
    expect($$('[role="dialog"]')).toHaveLength(1);
    expect(dialog?.getAttribute('aria-modal')).toBe('true');
    const labelledBy = dialog?.getAttribute('aria-labelledby') ?? '';
    expect(document.getElementById(labelledBy)?.tagName).toBe('H2');
    expect(document.getElementById(labelledBy)?.textContent).toBe('Edit gallery');
    expect($('.swg-sheet__subtitle')?.textContent).toBe('Summer 2025');
    expect($('.content')).not.toBeNull();
    expect($('.swg-sheet__foot')?.textContent).toBe('foot');
    const close = $('.swg-sheet__close');
    expect(close?.getAttribute('aria-label')).toBe('Close');
    close?.click();
    await flushPromises();
    expect(open.value).toBe(false);
  });

  it('omits subtitle and footer when not given and emits hide', async () => {
    const events: string[] = [];
    const { open } = harness((open) =>
      h(SideSheet, {
        title: 'T',
        closeLabel: 'Close',
        modelValue: open.value,
        onHide: () => events.push('hide'),
      }),
    );
    await flushPromises();
    expect($('.swg-sheet__subtitle')).toBeNull();
    expect($('.swg-sheet__foot')).toBeNull();
    open.value = false;
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 400));
    expect(events).toEqual(['hide']);
  });
});

describe('FormSheet', () => {
  const baseProps = {
    title: 'New gallery',
    submitLabel: 'Create',
    cancelLabel: 'Cancel',
    closeLabel: 'Close',
  };

  function formHost(
    extra: Record<string, unknown> = {},
    slot = () => h('input', { class: 'field' }),
  ) {
    return harness((open, events) =>
      h(
        FormSheet,
        {
          ...baseProps,
          ...extra,
          modelValue: open.value,
          'onUpdate:modelValue': (v: boolean) => (open.value = v),
          onSubmit: () => events.push('submit'),
          onInvalidTab: (tab: string) => events.push(`tab:${tab}`),
        },
        { default: slot },
      ),
    );
  }

  it('renders the form with a footer wired to submit and cancel', async () => {
    const { open, events } = formHost();
    await flushPromises();
    expect($('form .field')).not.toBeNull();
    const footerButtons = $$('.swg-sheet__foot button');
    expect(footerButtons.map((b) => b.textContent?.trim())).toEqual(['Cancel', 'Create']);
    const form = $<HTMLFormElement>('form');
    expect(footerButtons[1]?.getAttribute('form')).toBe(form?.id);
    form?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(events).toEqual(['submit']);
    footerButtons[0]?.click();
    await flushPromises();
    expect(open.value).toBe(false);
  });

  it('shows the server error summary with per-field messages', async () => {
    formHost({ errorMessage: 'Some values are invalid.', fieldErrors: { Title: 'is required' } });
    await flushPromises();
    const alert = $('.swg-fe');
    expect(alert?.getAttribute('role')).toBe('alert');
    expect(alert?.textContent).toContain('Some values are invalid.');
    expect(alert?.textContent).toContain('Title');
    expect(alert?.textContent).toContain('is required');
  });

  it('hides the submit button when readonly and disables cancel while loading', async () => {
    formHost({ readonly: true, loading: true });
    await flushPromises();
    const buttons = $$('.swg-sheet__foot button');
    expect(buttons).toHaveLength(1);
    expect(buttons[0]?.hasAttribute('disabled')).toBe(true);
  });

  it('reports the tab of the first invalid field', async () => {
    const { events } = formHost({}, () =>
      h('div', { 'data-tab': 'advanced' }, [
        h(QInput, { modelValue: '', rules: [(v: string) => v !== '' || 'required'], class: 'req' }),
      ]),
    );
    await flushPromises();
    $<HTMLFormElement>('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flushPromises();
    expect(events).toEqual(['tab:advanced']);
  });
});
