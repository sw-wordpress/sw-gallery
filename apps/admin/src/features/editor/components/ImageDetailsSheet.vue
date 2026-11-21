<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SideSheet from '@/components/ui/SideSheet.vue';
import type { ItemTexts } from '@/api/items';
import type { JsonSchema } from '@/api/layouts';
import { itemFieldsOf, valueAtPath, withPath } from '../schema';
import FocalPicker from './FocalPicker.vue';
import SettingField from './SettingField.vue';
import type { EditorSession } from '../useGalleryEditor';

/**
 * Image details (M2-29). Global attachment texts are read-only; the gallery-specific texts are
 * overrides per field: switch off = inherit (`null`), on = own text, where an EMPTY text is an
 * intentional blank (the global text is then NOT shown in this gallery). Edits are staged.
 */
const open = defineModel<boolean>({ required: true });
const props = defineProps<{
  session: EditorSession;
  itemId: number | null;
  coverAttachmentId: number | null;
  /** Active layout when it declares per-item settings (`x-item-settings`), else null (no form). */
  itemLayout?: { id: string; label: string; schema: JsonSchema } | null;
  /** The working layout differs from the saved one: item settings cannot be validated yet. */
  itemLayoutStale?: boolean;
  readonly: boolean;
}>();
const emit = defineEmits<{
  (e: 'use-cover', attachmentId: number): void;
  (e: 'remove', itemId: number): void;
}>();
const { t } = useI18n();

type Field = keyof ItemTexts;
const FIELDS: Array<{ field: Field; max: number; textarea: boolean }> = [
  { field: 'title', max: 200, textarea: false },
  { field: 'alt', max: 500, textarea: false },
  { field: 'caption', max: 5000, textarea: true },
];

const view = computed(() => (props.itemId === null ? null : props.session.itemView(props.itemId)));
const position = computed(() =>
  props.itemId === null ? 0 : props.session.order.value.indexOf(props.itemId) + 1,
);
const title = computed(() => {
  const name = view.value?.effective.title;
  return name ? name : t('editor.details.imageTitle', { position: position.value });
});
const subtitle = computed(() =>
  view.value === null
    ? undefined
    : t('editor.details.ids', {
        item: view.value.item.id,
        attachment: view.value.item.attachmentId,
      }),
);

function globalText(field: Field): string {
  return view.value?.item.global?.[field] ?? '';
}
function isOwn(field: Field): boolean {
  return view.value !== null && view.value.texts[field] !== null;
}
function ownText(field: Field): string {
  return view.value?.texts[field] ?? '';
}
/** Switch on: start from the global text (so the author edits instead of retyping); off: inherit. */
function toggleOwn(field: Field, on: boolean): void {
  if (props.itemId === null) return;
  props.session.setItemTexts(props.itemId, { [field]: on ? globalText(field) : null });
}
function setOwn(field: Field, value: string | number | null): void {
  if (props.itemId === null) return;
  props.session.setItemTexts(props.itemId, { [field]: value === null ? '' : String(value) });
}
const itemFields = computed(() => itemFieldsOf(props.itemLayout?.schema));
const itemSettings = computed(() =>
  props.itemId === null ? {} : props.session.itemSettingsOf(props.itemId),
);
function labelOfItemField(path: string[], title: string | undefined): string {
  return title ?? (path.at(-1) ?? '').replace(/^./, (c) => c.toUpperCase());
}
function setItemSetting(path: string[], value: unknown): void {
  if (props.itemId === null) return;
  props.session.setItemSettings(props.itemId, withPath(itemSettings.value, path, value));
}
/** The active layout crops images and reads the focal point: show the click-to-set picker. */
const focalSupported = computed(
  () => props.itemLayoutStale !== true && itemFields.value.some((f) => f.path[0] === 'focal'),
);
function focalCoordinate(axis: 'x' | 'y'): number {
  const value = valueAtPath(itemSettings.value, ['focal', axis]);
  return typeof value === 'number' && Number.isFinite(value) ? value : 50;
}
function setFocal(x: number, y: number): void {
  if (props.itemId === null) return;
  const withX = withPath(itemSettings.value, ['focal', 'x'], x);
  props.session.setItemSettings(props.itemId, withPath(withX, ['focal', 'y'], y));
}
function resetFocal(): void {
  if (props.itemId === null) return;
  props.session.setItemSettings(props.itemId, withPath(itemSettings.value, ['focal'], undefined));
}
function resetItemSettings(): void {
  if (props.itemId !== null) props.session.setItemSettings(props.itemId, {});
}
const dimensions = computed(() => {
  const media = view.value?.item.media;
  return media === null || media === undefined ? null : `${media.width} × ${media.height}`;
});
const isCover = computed(
  () => view.value !== null && view.value.item.attachmentId === props.coverAttachmentId,
);
</script>

<template>
  <SideSheet
    v-model="open"
    :title="title"
    :subtitle="subtitle"
    :close-label="t('editor.details.close')"
    :width="520"
  >
    <div v-if="view" class="swg-ids">
      <div v-if="view.item.missing" class="swg-ids__missing" role="status">
        <q-icon name="r_broken_image" size="22px" aria-hidden="true" />
        <span>{{ t('editor.details.missing') }}</span>
      </div>
      <FocalPicker
        v-else-if="focalSupported && view.item.media?.thumbnailUrl"
        :src="view.item.media.thumbnailUrl"
        :x="focalCoordinate('x')"
        :y="focalCoordinate('y')"
        :disabled="readonly"
        @update="setFocal"
        @reset="resetFocal"
      />
      <img
        v-else-if="view.item.media?.thumbnailUrl"
        class="swg-ids__img"
        :src="view.item.media.thumbnailUrl"
        alt=""
      />

      <dl v-if="!view.item.missing" class="swg-ids__facts">
        <div>
          <dt>{{ t('editor.details.dimensions') }}</dt>
          <dd class="swg-num">{{ dimensions ?? '—' }}</dd>
        </div>
        <div>
          <dt>{{ t('editor.details.type') }}</dt>
          <dd class="swg-mono">{{ view.item.media?.mimeType ?? '—' }}</dd>
        </div>
        <div>
          <dt>{{ t('editor.details.itemId') }}</dt>
          <dd class="swg-num">{{ view.item.id }}</dd>
        </div>
        <div>
          <dt>{{ t('editor.details.attachmentId') }}</dt>
          <dd class="swg-num">{{ view.item.attachmentId }}</dd>
        </div>
      </dl>

      <p class="swg-ids__intro">{{ t('editor.details.intro') }}</p>

      <section v-for="f in FIELDS" :key="f.field" class="swg-ids__field">
        <h3 class="swg-ids__h">{{ t(`editor.details.field.${f.field}`) }}</h3>
        <div class="swg-ids__global">
          <span class="swg-eyebrow">{{ t('editor.details.global') }}</span>
          <p class="swg-ids__global-text">
            {{ globalText(f.field) || t('editor.details.globalEmpty') }}
          </p>
        </div>
        <q-toggle
          :model-value="isOwn(f.field)"
          :label="t('editor.details.useOwn')"
          :aria-label="`${t('editor.details.useOwn')}: ${t(`editor.details.field.${f.field}`)}`"
          :disable="readonly || view.item.missing"
          @update:model-value="(on: boolean) => toggleOwn(f.field, on)"
        />
        <template v-if="isOwn(f.field)">
          <q-input
            outlined
            :dense="!f.textarea"
            counter
            :type="f.textarea ? 'textarea' : 'text'"
            :autogrow="f.textarea"
            :maxlength="f.max"
            :model-value="ownText(f.field)"
            :label="t('editor.details.ownLabel', { field: t(`editor.details.field.${f.field}`) })"
            :hint="
              ownText(f.field) === ''
                ? t('editor.details.blankHint')
                : f.field === 'alt'
                  ? t('editor.details.altHint')
                  : undefined
            "
            :disable="readonly"
            @update:model-value="(v) => setOwn(f.field, v)"
          />
        </template>
        <p v-else class="swg-ids__inherit">{{ t('editor.details.inherits') }}</p>
        <p v-if="f.field === 'alt' && !isOwn(f.field)" class="swg-ids__hint">
          {{ t('editor.details.altHint') }}
        </p>
      </section>

      <section v-if="itemLayout && !view.item.missing" class="swg-ids__field swg-ids__layout">
        <h3 class="swg-ids__h">{{ t('editor.details.layoutSettings') }}</h3>
        <p v-if="itemLayoutStale" class="swg-ids__hint" role="status">
          {{ t('editor.details.layoutSettingsStale') }}
        </p>
        <template v-else>
          <p class="swg-ids__hint">
            {{ t('editor.details.layoutSettingsIntro', { layout: itemLayout.label }) }}
          </p>
          <SettingField
            v-for="f in itemFields"
            :key="`${itemLayout.id}:${f.path.join('.')}`"
            :def="f.def"
            :label="labelOfItemField(f.path, f.def.title)"
            :value="valueAtPath(itemSettings, f.path)"
            :server-issues="[]"
            :readonly="readonly"
            @update="(value) => setItemSetting(f.path, value)"
          />
          <q-btn
            v-if="!readonly && Object.keys(itemSettings).length > 0"
            flat
            dense
            no-caps
            icon="r_restart_alt"
            :label="t('editor.details.layoutSettingsReset')"
            @click="resetItemSettings"
          />
        </template>
      </section>
    </div>

    <template #footer>
      <q-btn
        v-if="view && !view.item.missing && !readonly"
        flat
        no-caps
        icon="r_star"
        :disable="isCover"
        :label="isCover ? t('editor.details.isCover') : t('editor.details.useAsCover')"
        @click="emit('use-cover', view.item.attachmentId)"
      />
      <q-btn
        v-if="view && !readonly"
        flat
        no-caps
        color="negative"
        icon="r_delete"
        :label="t('editor.details.remove')"
        @click="emit('remove', view.item.id)"
      />
      <q-btn
        unelevated
        no-caps
        color="primary"
        :label="t('editor.details.done')"
        @click="open = false"
      />
    </template>
  </SideSheet>
</template>

<style scoped>
.swg-ids {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding-block-start: 12px;
}
.swg-ids__img {
  inline-size: 100%;
  max-block-size: 240px;
  object-fit: contain;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-3);
}
.swg-ids__missing {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 12px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
}
.swg-ids__facts {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px 16px;
  margin: 0;
  font-size: 13px;
}
.swg-ids__facts dt {
  color: var(--swg-muted);
}
.swg-ids__facts dd {
  margin: 0;
  font-weight: 600;
}
.swg-ids__intro {
  color: var(--swg-muted);
  font-size: 13px;
}
.swg-ids__field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-block: 12px;
  border-block-start: 1px solid var(--swg-border);
}
.swg-ids__h {
  font-size: 15px;
  font-weight: 700;
}
.swg-ids__global {
  padding: 8px 10px;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-2);
  border: 1px solid var(--swg-border);
}
.swg-ids__global-text {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font-size: 14px;
}
.swg-ids__inherit,
.swg-ids__hint {
  color: var(--swg-muted);
  font-size: 13px;
}
</style>
