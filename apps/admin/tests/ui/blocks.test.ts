import { RouterLinkStub, mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { describe, expect, it } from 'vitest';
import type { Component } from 'vue';
import DataState from '../../src/components/ui/DataState.vue';
import EmptyState from '../../src/components/ui/EmptyState.vue';
import ErrorState from '../../src/components/ui/ErrorState.vue';
import PageHeader from '../../src/components/ui/PageHeader.vue';
import PaginationBar from '../../src/components/ui/PaginationBar.vue';
import SegmentedControl from '../../src/components/ui/SegmentedControl.vue';
import StatTile from '../../src/components/ui/StatTile.vue';
import StatusChip from '../../src/components/ui/StatusChip.vue';
import SwgCard from '../../src/components/ui/SwgCard.vue';

const global = {
  plugins: [[Quasar, { iconSet }]] as never[],
  stubs: { RouterLink: RouterLinkStub },
};

function mountUi(component: Component, props: Record<string, unknown>, slots = {}) {
  return mount(component, { props, slots, global });
}

describe('PageHeader', () => {
  it('renders an h2 title by default (the PHP page owns the h1), subtitle and actions', () => {
    const w = mountUi(
      PageHeader,
      { title: 'Galleries', subtitle: 'All your galleries' },
      { default: '<button>New</button>' },
    );
    expect(w.find('h2').text()).toBe('Galleries');
    expect(w.find('h1').exists()).toBe(false);
    expect(w.text()).toContain('All your galleries');
    expect(w.find('.swg-page-header__actions button').text()).toBe('New');
  });

  it('supports h3, hides empty parts and renders a back link', () => {
    const w = mountUi(PageHeader, {
      title: 'T',
      headingLevel: 3,
      backTo: '/galleries',
      backLabel: 'Back',
    });
    expect(w.find('h3').exists()).toBe(true);
    expect(w.find('.swg-page-header__subtitle').exists()).toBe(false);
    expect(w.find('.swg-page-header__actions').exists()).toBe(false);
    const link = w.findComponent(RouterLinkStub);
    expect(link.props('to')).toBe('/galleries');
    expect(link.text()).toContain('Back');
  });
});

describe('SwgCard', () => {
  it('renders title, subtitle, icon, body, actions and footer slots', () => {
    const w = mountUi(
      SwgCard,
      { title: 'Card', subtitle: 'Sub', icon: 'r_settings' },
      { default: '<p class="b">Body</p>', actions: '<a>act</a>', footer: '<span>foot</span>' },
    );
    expect(w.find('h3.swg-card__title').text()).toBe('Card');
    expect(w.find('.swg-card__subtitle').text()).toBe('Sub');
    expect(w.find('.swg-card__icon').attributes('aria-hidden')).toBe('true');
    expect(w.find('.b').exists()).toBe(true);
    expect(w.find('.swg-card__actions').text()).toBe('act');
    expect(w.find('.swg-card__footer').text()).toBe('foot');
    expect(w.element.tagName).toBe('SECTION');
  });

  it('handles tag, heading level, flags and a custom header slot', () => {
    const w = mountUi(
      SwgCard,
      { tag: 'div', headingLevel: 2, interactive: true, accent: true, padding: false, title: 'x' },
      { header: '<h4 class="h">Custom</h4>' },
    );
    expect(w.element.tagName).toBe('DIV');
    expect(w.classes()).toEqual(
      expect.arrayContaining(['swg-card--interactive', 'swg-card--accent']),
    );
    expect(w.find('.h').exists()).toBe(true);
    expect(w.find('.swg-card__body--flush').exists()).toBe(true);
    expect(mountUi(SwgCard, {}).find('header').exists()).toBe(false);
    expect(mountUi(SwgCard, { title: 'T', headingLevel: 2 }).find('h2').exists()).toBe(true);
  });
});

describe('StatusChip', () => {
  it('shows label and a kind-specific icon so status is not colour-only', () => {
    const w = mountUi(StatusChip, { label: 'Published', kind: 'success' });
    expect(w.text()).toContain('Published');
    expect(w.classes()).toContain('swg-chip--success');
    expect(w.find('.q-icon').text()).toBe('check_circle');
    expect(w.find('.q-icon').attributes('aria-hidden')).toBe('true');
  });

  it('defaults to neutral, allows icon override and dense', () => {
    const w = mountUi(StatusChip, { label: 'Draft', icon: 'r_edit', dense: true });
    expect(w.classes()).toEqual(expect.arrayContaining(['swg-chip--neutral', 'swg-chip--dense']));
    expect(w.find('.q-icon').text()).toBe('edit');
    for (const kind of ['warning', 'danger', 'info', 'running', 'primary'] as const) {
      expect(mountUi(StatusChip, { label: 'x', kind }).classes()).toContain(`swg-chip--${kind}`);
    }
  });
});

describe('StatTile', () => {
  it('renders label, value, hint and icon; falls back to a dash', () => {
    const w = mountUi(StatTile, {
      label: 'Galleries',
      value: '12',
      hint: 'in total',
      icon: 'r_photo_library',
      tone: 'success',
    });
    expect(w.find('.swg-stat__label').text()).toBe('Galleries');
    expect(w.find('.swg-stat__value').text()).toBe('12');
    expect(w.find('.swg-stat__hint').text()).toBe('in total');
    expect(w.classes()).toContain('swg-stat--success');
    expect(mountUi(StatTile, { label: 'x' }).find('.swg-stat__value').text()).toBe('–');
  });

  it('supports slots and a loading state with an accessible status text', () => {
    const slotted = mountUi(
      StatTile,
      { label: 'x' },
      { default: '<b>custom</b>', hint: '<i>h</i>' },
    );
    expect(slotted.find('b').exists()).toBe(true);
    expect(slotted.find('i').exists()).toBe(true);
    const loading = mountUi(StatTile, { label: 'x', loading: true, loadingLabel: 'Loading' });
    expect(loading.attributes('aria-busy')).toBe('true');
    expect(loading.find('[role="status"]').text()).toBe('Loading');
    expect(loading.find('.swg-stat__value').exists()).toBe(false);
    expect(mountUi(StatTile, { label: 'x', loading: true }).find('[role="status"]').exists()).toBe(
      false,
    );
  });
});

describe('EmptyState', () => {
  it('renders title (h3), text, actions and a decorative illustration', () => {
    const w = mountUi(
      EmptyState,
      { title: 'Nothing here', text: 'Add one' },
      { default: '<button>Go</button>' },
    );
    expect(w.find('h3').text()).toBe('Nothing here');
    expect(w.find('.swg-empty__text').text()).toBe('Add one');
    expect(w.find('.swg-empty__actions button').text()).toBe('Go');
    expect(w.find('svg').attributes('aria-hidden')).toBe('true');
  });

  it('supports every illustration, compact mode and heading level', () => {
    for (const illustration of ['gallery', 'media', 'search', 'settings', 'error'] as const) {
      const w = mountUi(EmptyState, { title: 't', illustration });
      expect(w.find('svg g').exists()).toBe(true);
    }
    const w = mountUi(EmptyState, { title: 't', compact: true, headingLevel: 2 });
    expect(w.classes()).toContain('swg-empty--compact');
    expect(w.find('h2').exists()).toBe(true);
    expect(w.find('.swg-empty__text').exists()).toBe(false);
    expect(w.find('.swg-empty__actions').exists()).toBe(false);
  });

  it('gives each instance a unique gradient id', () => {
    const a = mountUi(EmptyState, { title: 'a' }).find('linearGradient').attributes('id');
    const b = mountUi(EmptyState, { title: 'b' }).find('linearGradient').attributes('id');
    expect(a).toBeDefined();
    expect(a).not.toBe(b);
  });
});

describe('ErrorState', () => {
  it('is an alert with message, detail and a retry button that emits retry', async () => {
    const w = mountUi(ErrorState, {
      title: 'Could not load',
      message: 'Server unreachable',
      detail: 'swg_network',
      retryLabel: 'Retry',
    });
    expect(w.attributes('role')).toBe('alert');
    expect(w.text()).toContain('Could not load');
    expect(w.text()).toContain('Server unreachable');
    expect(w.find('.swg-mono').text()).toBe('swg_network');
    await w.find('button').trigger('click');
    expect(w.emitted('retry')).toHaveLength(1);
  });

  it('omits the detail when not given', () => {
    const w = mountUi(ErrorState, { title: 't', retryLabel: 'Retry', compact: true });
    expect(w.find('.swg-mono').exists()).toBe(false);
  });
});

describe('DataState', () => {
  const base = { errorTitle: 'Failed', retryLabel: 'Retry', loadingLabel: 'Loading' };
  const slots = { default: '<p class="ready">data</p>', empty: '<p class="none">none</p>' };

  it('shows a skeleton with a status text while loading with nothing to display', () => {
    for (const variant of ['list', 'cards', 'block'] as const) {
      const w = mountUi(
        DataState,
        { ...base, loading: true, error: null, empty: true, variant },
        slots,
      );
      expect(w.find('[aria-busy="true"]').exists()).toBe(true);
      expect(w.find('[role="status"]').text()).toBe('Loading');
      expect(w.find('.ready').exists()).toBe(false);
      expect(w.find('.none').exists()).toBe(false);
    }
  });

  it('shows the error state with retry when there is nothing to display', async () => {
    const w = mountUi(
      DataState,
      { ...base, loading: false, error: 'Boom', empty: true, errorDetail: 'x' },
      slots,
    );
    expect(w.find('[role="alert"]').text()).toContain('Boom');
    await w.find('button').trigger('click');
    expect(w.emitted('retry')).toHaveLength(1);
  });

  it('shows the empty slot, then the ready content', () => {
    const empty = mountUi(DataState, { ...base, loading: false, error: null, empty: true }, slots);
    expect(empty.find('.none').exists()).toBe(true);
    const ready = mountUi(DataState, { ...base, loading: false, error: null }, slots);
    expect(ready.find('.ready').exists()).toBe(true);
    expect(ready.find('[role="alert"]').exists()).toBe(false);
  });

  it('keeps content visible during a refresh and shows a refresh error inline with retry', async () => {
    const refreshing = mountUi(DataState, { ...base, loading: true, error: null }, slots);
    expect(refreshing.find('.ready').exists()).toBe(true);
    expect(refreshing.find('[aria-busy="true"]').exists()).toBe(false);
    const failed = mountUi(DataState, { ...base, loading: false, error: 'Stale' }, slots);
    expect(failed.find('.ready').exists()).toBe(true);
    expect(failed.find('.swg-inline-error').text()).toContain('Stale');
    await failed.find('.swg-inline-error button').trigger('click');
    expect(failed.emitted('retry')).toHaveLength(1);
  });
});

describe('PaginationBar', () => {
  const props = {
    page: 2,
    pages: 4,
    info: '38 galleries',
    pageLabel: 'Page 2 of 4',
    navLabel: 'Pagination',
    previousLabel: 'Previous page',
    nextLabel: 'Next page',
  };

  it('is a labelled navigation with a polite info text and labelled buttons', () => {
    const w = mountUi(PaginationBar, props);
    expect(w.find('nav').attributes('aria-label')).toBe('Pagination');
    expect(w.find('[aria-live="polite"]').text()).toBe('38 galleries');
    expect(w.text()).toContain('Page 2 of 4');
    expect(w.findAll('button').map((b) => b.attributes('aria-label'))).toEqual([
      'Previous page',
      'Next page',
    ]);
  });

  it('emits update:page and disables the ends', async () => {
    const w = mountUi(PaginationBar, props);
    const [prev, next] = w.findAll('button');
    await prev?.trigger('click');
    await next?.trigger('click');
    expect(w.emitted('update:page')).toEqual([[1], [3]]);

    const first = mountUi(PaginationBar, { ...props, page: 1 });
    expect(first.findAll('button')[0]?.attributes('disabled')).toBeDefined();
    const last = mountUi(PaginationBar, { ...props, page: 4 });
    expect(last.findAll('button')[1]?.attributes('disabled')).toBeDefined();
  });

  it('hides the controls for a single page', () => {
    const w = mountUi(PaginationBar, { ...props, pages: 1, page: 1 });
    expect(w.findAll('button')).toHaveLength(0);
    expect(w.text()).toContain('38 galleries');
  });
});

describe('SegmentedControl', () => {
  const options = [
    { value: 'grid', label: 'Grid' },
    { value: 'table', label: 'Table' },
  ];

  it('is a fieldset with a legend and native radios (keyboard: arrow keys)', () => {
    const w = mount(SegmentedControl, {
      props: { options, legend: 'View', modelValue: 'grid' },
      global,
    });
    expect(w.find('fieldset').exists()).toBe(true);
    expect(w.find('legend').text()).toBe('View');
    const radios = w.findAll('input[type="radio"]');
    expect(radios).toHaveLength(2);
    expect(new Set(radios.map((r) => r.attributes('name'))).size).toBe(1);
    expect((radios[0]?.element as HTMLInputElement).checked).toBe(true);
    expect(w.findAll('.swg-seg__opt')[0]?.classes()).toContain('is-on');
  });

  it('emits update:modelValue on change and honours disabled', async () => {
    const w = mount(SegmentedControl, {
      props: { options, legend: 'View', modelValue: 'grid' },
      global,
    });
    await w.findAll('input')[1]?.setValue(true);
    expect(w.emitted('update:modelValue')).toEqual([['table']]);
    await w.setProps({ disabled: true });
    expect(w.find('fieldset').attributes('disabled')).toBeDefined();
  });
});
