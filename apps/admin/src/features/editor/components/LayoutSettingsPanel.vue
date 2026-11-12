<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { LayoutInfo, LayoutsResponse } from '@/api/layouts';
import { fieldsOf } from '../schema';
import type { Breakpoint } from '../schema';
import type { EditorSession } from '../useGalleryEditor';
import SettingField from './SettingField.vue';

/**
 * Layout, sort mode and the settings form generated from the layout's schema (M2-30). Everything is
 * staged in the working copy and sent with Save (the complete merged settings map).
 */
const props = defineProps<{
  session: EditorSession;
  layouts: LayoutsResponse | null;
  layoutsError: string | null;
  layoutsLoading: boolean;
  readonly: boolean;
}>();
const emit = defineEmits<{ (e: 'retry'): void }>();
const { t, te } = useI18n();

const SORT_MODES = ['manual', 'date_asc', 'date_desc', 'title_asc', 'title_desc'] as const;

const details = computed(() => props.session.working.value?.details ?? null);
const layout = computed<LayoutInfo | null>(
  () => props.layouts?.layouts.find((l) => l.id === details.value?.layout) ?? null,
);
const layoutOptions = computed(() =>
  (props.layouts?.layouts ?? []).map((l) => ({ value: l.id, label: l.label })),
);
const unknownLayout = computed(
  () => props.layouts !== null && details.value !== null && layout.value === null,
);
const sortOptions = computed(() =>
  SORT_MODES.map((value) => ({ value, label: t(`editor.sort.${value}`) })),
);
const fields = computed(() => fieldsOf(layout.value?.settingsSchema));

function labelFor(key: string, title: string | undefined): string {
  const k = `editor.settings.field.${key}`;
  return te(k) ? t(k) : (title ?? key.replace(/[_-]+/g, ' ').replace(/^./, (c) => c.toUpperCase()));
}

function issuesFor(key: string): Array<{ breakpoint: Breakpoint | null; code: string }> {
  return props.session.settingIssues.value
    .filter((issue) => issue.path[0] === key)
    .map((issue) => ({
      breakpoint: (issue.path[1] ?? null) as Breakpoint | null,
      code: issue.code,
    }));
}
const otherIssues = computed(
  () =>
    props.session.settingIssues.value.filter((i) => !fields.value.some((f) => f.key === i.path[0]))
      .length,
);
const issueCount = computed(() => props.session.settingIssues.value.length);
</script>

<template>
  <div v-if="details" class="swg-lsp">
    <div v-if="issueCount > 0" class="swg-lsp__alert" role="alert">
      <q-icon name="r_error" size="20px" aria-hidden="true" />
      <span>{{ t('editor.settings.serverIssues', { count: issueCount }) }}</span>
    </div>

    <div v-if="layoutsError" class="swg-lsp__alert" role="alert">
      <q-icon name="r_error" size="20px" aria-hidden="true" />
      <span>{{ layoutsError }}</span>
      <q-btn flat dense no-caps color="primary" :label="t('common.retry')" @click="emit('retry')" />
    </div>
    <q-linear-progress
      v-else-if="layoutsLoading"
      indeterminate
      :aria-label="t('editor.settings.loading')"
    />

    <q-select
      outlined
      dense
      emit-value
      map-options
      :model-value="details.layout"
      :options="layoutOptions"
      :label="t('editor.settings.layout')"
      :disable="readonly || layouts === null"
      @update:model-value="(v: string) => session.setDetail('layout', v)"
    />
    <p v-if="unknownLayout" class="swg-lsp__note" role="status">
      <q-icon name="r_info" size="18px" aria-hidden="true" />
      {{
        t('editor.settings.unknownLayout', {
          layout: details.layout,
          fallback: layouts?.fallbackId ?? '',
        })
      }}
    </p>
    <p v-else class="swg-lsp__hint">
      {{ t('editor.settings.fallbackNote', { fallback: layouts?.fallbackId ?? 'grid' }) }}
    </p>

    <q-select
      outlined
      dense
      emit-value
      map-options
      :model-value="details.sortMode"
      :options="sortOptions"
      :label="t('editor.sort.label')"
      :disable="readonly"
      @update:model-value="(v: string) => session.setDetail('sortMode', v)"
    />
    <p class="swg-lsp__hint">{{ t('editor.sort.note') }}</p>

    <h3 class="swg-lsp__heading">{{ t('editor.settings.heading') }}</h3>
    <p v-if="layout && fields.length === 0" class="swg-lsp__hint">
      {{ t('editor.settings.none') }}
    </p>
    <SettingField
      v-for="def in fields"
      :key="`${details.layout}:${def.key}`"
      :def="def"
      :label="labelFor(def.key, def.title)"
      :value="details.settings[def.key]"
      :server-issues="issuesFor(def.key)"
      :readonly="readonly"
      @update="(value) => session.setSetting([def.key], value)"
    />
    <p v-if="otherIssues > 0" class="swg-lsp__hint">
      {{ t('editor.settings.otherIssues', { count: otherIssues }) }}
    </p>
  </div>
</template>

<style scoped>
.swg-lsp {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swg-lsp__heading {
  margin-block-start: 8px;
  font-size: 15px;
  font-weight: 700;
}
.swg-lsp__hint {
  margin-block-start: -4px;
  color: var(--swg-muted);
  font-size: 13px;
}
.swg-lsp__note {
  display: flex;
  gap: 6px;
  align-items: flex-start;
  padding: 8px 10px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-warning-bg);
  color: var(--swg-warning-text);
  font-size: 13px;
}
.swg-lsp__alert {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 10px 12px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
  font-size: 14px;
}
</style>
