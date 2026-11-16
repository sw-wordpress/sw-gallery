<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  BREAKPOINTS,
  optionLabel,
  readResponsive,
  unknownBreakpoints,
  validateValue,
  writeResponsive,
} from '../schema';
import type { Breakpoint, FieldDef, ValueIssue } from '../schema';

/**
 * One setting generated from the layout schema. Responsive numbers use compact device inputs;
 * an empty input removes its override. The control emits the whole new
 * value of the key (`undefined` = back to the layout default); unknown breakpoint entries of the
 * stored value are written back untouched.
 */
const props = defineProps<{
  def: FieldDef;
  label: string;
  /** Raw stored value of this key (`undefined` = default applies). */
  value: unknown;
  /** Server-side problems for this key (code per breakpoint, `null` = the whole value). */
  serverIssues: Array<{ breakpoint: Breakpoint | null; code: string }>;
  readonly: boolean;
}>();
const emit = defineEmits<{ (e: 'update', value: unknown): void }>();

const { t } = useI18n();

const rows = computed(() => (props.def.responsive ? [...BREAKPOINTS] : [null]));
const map = computed(() =>
  props.def.responsive ? readResponsive(props.value) : { base: props.value },
);

function valueAt(bp: Breakpoint | null): unknown {
  return map.value[bp ?? 'base'];
}

function write(bp: Breakpoint | null, value: unknown): void {
  if (!props.def.responsive) {
    emit('update', value);
    return;
  }
  const next = { ...map.value, [bp ?? 'base']: value };
  emit('update', writeResponsive(next, unknownBreakpoints(props.value)));
}

function numberModel(bp: Breakpoint | null): string | number {
  const v = valueAt(bp);
  return typeof v === 'number' ? v : '';
}

function numeric(raw: string | number | null): number | undefined {
  if (raw === null || raw === '') return undefined;
  const value = typeof raw === 'number' ? raw : Number(raw);
  return Number.isFinite(value) ? value : undefined;
}

function onNumber(bp: Breakpoint | null, raw: string | number | null): void {
  write(bp, numeric(raw));
}

function onRange(bp: Breakpoint | null, event: Event): void {
  write(bp, Number((event.target as HTMLInputElement).value));
}

function issueText(issue: ValueIssue): string {
  switch (issue) {
    case 'min':
      return t('editor.settings.issue.min', { min: props.def.min ?? 0 });
    case 'max':
      return t('editor.settings.issue.max', { max: props.def.max ?? 0 });
    case 'integer':
      return t('editor.settings.issue.integer');
    case 'enum':
      return t('editor.settings.issue.enum');
    case 'length':
      return t('editor.settings.issue.length', { max: props.def.maxLength ?? 0 });
    default:
      return t('editor.settings.issue.type');
  }
}

/** Live client message first, then the server's complaint (by code, never its text). */
function errorAt(bp: Breakpoint | null): string | null {
  const live = valueAt(bp);
  if (live !== undefined) {
    const issue = validateValue(props.def, live);
    if (issue !== null) return issueText(issue);
  }
  // An issue without a breakpoint concerns the whole value, which the base row (or the single control) shows.
  const server = props.serverIssues.find(
    (issue) =>
      issue.breakpoint === bp || (issue.breakpoint === null && (bp === null || bp === 'base')),
  );
  return server === undefined ? null : t('editor.settings.issue.server');
}

const bpLabel = (bp: Breakpoint | null): string =>
  bp === null ? '' : t(`editor.settings.breakpoint.${bp}`);
const fullLabel = (bp: Breakpoint | null): string =>
  bp === null ? props.label : `${props.label}, ${bpLabel(bp)}`;
const defaultText = computed(() =>
  props.def.defaultValue === undefined
    ? null
    : t('editor.settings.default', {
        value:
          props.def.kind === 'enum'
            ? optionLabel(props.def, props.def.defaultValue)
            : typeof props.def.defaultValue === 'boolean'
              ? t(props.def.defaultValue ? 'status.yes' : 'status.no')
              : String(props.def.defaultValue),
      }),
);
/** Native number input attributes (QInput forwards them to the `<input>`; its prop types do not list them). */
const numberAttrs = computed(() => ({
  min: props.def.min,
  max: props.def.max,
  step: props.def.kind === 'integer' ? 1 : 'any',
  placeholder: props.def.defaultValue === undefined ? undefined : String(props.def.defaultValue),
}));
const isNumber = computed(() => props.def.kind === 'integer' || props.def.kind === 'number');
const compactResponsive = computed(() => props.def.responsive && isNumber.value);
const deviceIcons: Record<Breakpoint, string> = {
  base: 'r_devices',
  sm: 'r_smartphone',
  md: 'r_tablet_mac',
  lg: 'r_laptop',
  xl: 'r_desktop_windows',
};
/** Empty breakpoints inherit the preceding configured value, just as the renderer does. */
function inheritedNumber(bp: Breakpoint): string {
  let value = props.def.defaultValue;
  for (const current of BREAKPOINTS) {
    if (current === bp) break;
    if (valueAt(current) !== undefined) value = valueAt(current);
  }
  return typeof value === 'number' ? String(value) : '';
}
const hasRange = computed(() => props.def.min !== undefined && props.def.max !== undefined);
const customised = computed(() => props.value !== undefined);
const rangeValue = (bp: Breakpoint | null): number => {
  const v = valueAt(bp);
  if (typeof v === 'number') return v;
  const d = props.def.defaultValue;
  return typeof d === 'number' ? d : (props.def.min ?? 0);
};
</script>

<template>
  <fieldset class="swg-sfield" :disabled="readonly">
    <legend class="swg-sfield__label">
      {{ label }}
      <span v-if="defaultText" class="swg-sfield__default">{{ defaultText }}</span>
      <q-btn
        v-if="customised && !readonly"
        flat
        dense
        round
        size="sm"
        class="swg-sfield__reset"
        color="primary"
        icon="r_restart_alt"
        :aria-label="`${t('editor.settings.reset')}: ${label}`"
        @click="emit('update', undefined)"
      >
        <q-tooltip>{{ t('editor.settings.reset') }}</q-tooltip>
      </q-btn>
    </legend>
    <p v-if="def.description" class="swg-sfield__desc">{{ def.description }}</p>

    <div v-if="def.kind === 'unsupported'" class="swg-sfield__kept">
      {{ t('editor.settings.unsupported') }}
    </div>

    <template v-else-if="compactResponsive">
      <div class="swg-sfield__devices">
        <div v-for="bp in BREAKPOINTS" :key="bp" class="swg-sfield__device">
          <span class="swg-sfield__device-icon" :title="bpLabel(bp)">
            <q-icon :name="deviceIcons[bp]" size="20px" aria-hidden="true" />
            <span class="swg-sr-only">{{ bpLabel(bp) }}</span>
            <q-tooltip>{{ bpLabel(bp) }}</q-tooltip>
          </span>
          <q-input
            type="number"
            outlined
            dense
            hide-bottom-space
            v-bind="{ ...numberAttrs, placeholder: inheritedNumber(bp) }"
            :model-value="numberModel(bp)"
            :aria-label="fullLabel(bp)"
            :disable="readonly"
            :error="errorAt(bp) !== null"
            :error-message="errorAt(bp) ?? ''"
            @update:model-value="(v) => onNumber(bp, v)"
          />
        </div>
      </div>
      <p class="swg-sfield__desc">{{ t('editor.settings.emptyDefault') }}</p>
    </template>
    <template v-else>
      <div
        v-for="bp in rows"
        :key="bp ?? 'single'"
        class="swg-sfield__row"
        :class="{ 'has-breakpoint': bp !== null }"
      >
        <span v-if="bp !== null" class="swg-sfield__bp">{{ bpLabel(bp) }}</span>
        <div class="swg-sfield__ctl" :class="{ 'is-number': isNumber }">
          <q-toggle
            v-if="bp !== null && bp !== 'base'"
            :disable="readonly"
            :model-value="valueAt(bp) !== undefined"
            :label="t('editor.settings.override')"
            :aria-label="`${t('editor.settings.override')}: ${fullLabel(bp)}`"
            @update:model-value="
              (on: boolean) =>
                write(bp, on ? (valueAt('base') ?? def.defaultValue ?? def.min ?? 0) : undefined)
            "
          />
          <template v-if="bp === null || bp === 'base' || valueAt(bp) !== undefined">
            <template v-if="isNumber">
              <input
                v-if="hasRange"
                type="range"
                class="swg-sfield__range"
                :min="def.min"
                :max="def.max"
                :step="def.kind === 'integer' ? 1 : 'any'"
                :value="rangeValue(bp)"
                :aria-label="fullLabel(bp)"
                @input="onRange(bp, $event)"
              />
              <q-input
                type="number"
                outlined
                dense
                class="swg-sfield__num"
                :disable="readonly"
                :model-value="numberModel(bp)"
                :aria-label="fullLabel(bp)"
                hide-bottom-space
                v-bind="numberAttrs"
                :error="errorAt(bp) !== null"
                :error-message="errorAt(bp) ?? ''"
                @update:model-value="(v) => onNumber(bp, v)"
              />
            </template>
            <q-toggle
              v-else-if="def.kind === 'boolean'"
              :disable="readonly"
              :model-value="
                valueAt(bp) === true || (valueAt(bp) === undefined && def.defaultValue === true)
              "
              :label="fullLabel(bp)"
              @update:model-value="(v: boolean) => write(bp, v)"
            />
            <q-select
              v-else-if="def.kind === 'enum'"
              outlined
              dense
              emit-value
              map-options
              class="swg-sfield__select"
              hide-bottom-space
              :disable="readonly"
              :model-value="valueAt(bp) ?? def.defaultValue ?? null"
              :options="def.options.map((o) => ({ value: o, label: optionLabel(def, o) }))"
              :label="fullLabel(bp)"
              :error="errorAt(bp) !== null"
              :error-message="errorAt(bp) ?? ''"
              @update:model-value="(v: unknown) => write(bp, v)"
            />
            <q-input
              v-else
              outlined
              dense
              class="swg-sfield__text"
              hide-bottom-space
              :disable="readonly"
              :model-value="typeof valueAt(bp) === 'string' ? (valueAt(bp) as string) : ''"
              :label="fullLabel(bp)"
              :maxlength="def.maxLength"
              :error="errorAt(bp) !== null"
              :error-message="errorAt(bp) ?? ''"
              @update:model-value="(v) => write(bp, v === '' || v === null ? undefined : String(v))"
            />
          </template>
        </div>
      </div>
    </template>
  </fieldset>
</template>

<style scoped>
.swg-sfield {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 8px 0;
  min-inline-size: 0;
  border: 0;
  border-bottom: 1px solid var(--swg-border);
}
.swg-sfield__label {
  padding: 0;
  inline-size: 100%;
  font-weight: 650;
  font-size: 14px;
}
.swg-sfield__default {
  margin-inline-start: 8px;
  font-weight: 400;
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-sfield__desc {
  color: var(--swg-muted);
  font-size: 13px;
}
.swg-sfield__kept {
  padding: 8px 10px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-2);
  color: var(--swg-muted);
  font-size: 13px;
}
.swg-sfield__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-items: center;
  gap: 8px 12px;
}
.swg-sfield__row.has-breakpoint {
  grid-template-columns: 76px minmax(0, 1fr);
}
.swg-sfield__ctl {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
  min-inline-size: 0;
}
.swg-sfield__bp {
  font-size: 13px;
  font-weight: 600;
  color: var(--swg-muted);
}
.swg-sfield__range {
  inline-size: 100%;
  min-inline-size: 0;
  accent-color: var(--swg-primary);
}
.swg-sfield__ctl.is-number {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 76px;
  gap: 10px;
}
.swg-sfield__num {
  grid-column: 2;
}
.swg-sfield__select,
.swg-sfield__text {
  inline-size: 100%;
}
.swg-sfield__reset {
  float: inline-end;
}
.swg-sfield__devices {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 6px;
}
.swg-sfield__device {
  min-inline-size: 0;
}
.swg-sfield__device-icon {
  display: flex;
  justify-content: center;
  margin-block-end: 6px;
  color: var(--swg-muted);
}
.swg-sfield__device :deep(.q-field__native) {
  padding-inline: 4px;
  text-align: center;
  appearance: textfield;
  -moz-appearance: textfield;
}
.swg-sfield__device :deep(input::-webkit-inner-spin-button),
.swg-sfield__device :deep(input::-webkit-outer-spin-button) {
  -webkit-appearance: none;
  margin: 0;
}
</style>
