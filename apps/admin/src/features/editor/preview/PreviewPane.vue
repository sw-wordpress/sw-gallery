<script setup lang="ts">
/*
 * Live preview of the gallery editor (M3-23). Renders with the SERVER renderer (route
 * `POST /galleries/{id}/preview`) using the STAGED, unsaved layout, sort mode and settings, and
 * shows the markup in a sandboxed iframe (PreviewFrame.vue / srcdoc.ts). Item order, removals and
 * text edits are not part of the request (the server has no staged-items input): the pane says so.
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useAppStore } from '@/stores/app';
import { useEditorApi } from '../injection';
import type { EditorSession } from '../useGalleryEditor';
import PreviewFrame from './PreviewFrame.vue';
import { buildSrcdoc, randomToken, validateAssets } from './srcdoc';
import type { ValidatedAssets } from './srcdoc';
import { usePreview } from './usePreview';
import type { PreviewErrorKind } from './usePreview';

type Viewport = 'mobile' | 'tablet' | 'desktop' | 'fit';
const VIEWPORT_WIDTH: Record<Viewport, number | null> = {
  mobile: 375,
  tablet: 768,
  desktop: 1280,
  fit: null,
};
/** Preview-only page sizes; the empty value keeps the site setting (nothing is sent). */
const PER_PAGE_CHOICES = [6, 12, 24, 48, 100];
/** Room for the lightbox, which is fixed inside the frame, even when the gallery itself is short. */
const MIN_FRAME_HEIGHT = 480;
const MAX_FRAME_HEIGHT = 2000;
/** At most one announcement in this window; the latest message wins. */
const ANNOUNCE_INTERVAL_MS = 3000;

const props = defineProps<{
  session: EditorSession;
  galleryId: number;
  /** The pane is visible (tab selected or side-by-side layout). */
  active: boolean;
  readonly: boolean;
}>();

const { t, locale } = useI18n();
const app = useAppStore();
const api = useEditorApi();

const ready = computed(() => props.session.phase.value === 'ready');
const canPreview = computed(() => ready.value && !props.readonly);

const preview = usePreview({
  api: api.preview,
  galleryId: props.galleryId,
  active: () => props.active && canPreview.value,
  state: () => {
    const working = props.session.working.value;
    if (!ready.value || working === null) return null;
    const { layout, sortMode, settings } = working.details;
    return { layout, sortMode, settings };
  },
  reloadKey: () => props.session.revision.value,
  onValidation: (error) => {
    hadValidation = true;
    props.session.recordValidation(error);
  },
});

let hadValidation = false;
watch(preview.renderId, () => {
  if (hadValidation) {
    hadValidation = false;
    props.session.clearValidation();
  }
});

// ---- viewport
const viewport = ref<Viewport>('fit');
const viewportOptions = computed(() =>
  (['mobile', 'tablet', 'desktop', 'fit'] as const).map((value) => ({
    value,
    label: t(`editor.preview.viewport.${value}`),
  })),
);
const viewportModel = computed<Viewport | null>({
  get: () => viewport.value,
  set: (value) => {
    if (value !== null) viewport.value = value;
  },
});
const deviceWidth = computed(() => VIEWPORT_WIDTH[viewport.value]);
const widthLabel = computed(() =>
  deviceWidth.value === null
    ? t('editor.preview.viewport.fitting')
    : t('editor.preview.viewport.width', { width: deviceWidth.value }),
);

const perPageValue = computed(() =>
  preview.perPage.value === null ? '' : String(preview.perPage.value),
);
function onPerPage(event: Event): void {
  const raw = (event.target as HTMLSelectElement).value;
  preview.setPerPage(raw === '' ? null : Number(raw));
}

// ---- document of the iframe (rebuilt per render and theme change, never mutated)
interface FrameDoc {
  key: string;
  srcdoc: string;
  token: string;
  assets: ValidatedAssets;
}
const frameDoc = shallowRef<FrameDoc | null>(null);

function buildFrame(): void {
  const result = preview.result.value;
  if (result === null || result.html.trim() === '') {
    frameDoc.value = null;
    return;
  }
  const origin = window.location.origin;
  const assets = validateAssets(result.assets, origin);
  const token = randomToken();
  const root = document.documentElement;
  frameDoc.value = {
    key: `${preview.renderId.value}:${app.isDark ? 'd' : 'l'}`,
    srcdoc: buildSrcdoc({
      html: result.html,
      assets,
      origin,
      dark: app.isDark,
      dir: root.getAttribute('dir') === 'rtl' ? 'rtl' : 'ltr',
      lang: root.getAttribute('lang') || locale.value.replace('_', '-'),
      token,
      nonce: randomToken(),
    }),
    token,
    assets,
  };
}
watch([() => preview.renderId.value, () => app.isDark], buildFrame, { immediate: true });

const blank = computed(() => preview.result.value !== null && frameDoc.value === null);
const assetsDropped = computed(() => (frameDoc.value?.assets.dropped ?? 0) > 0);
const assetsMissing = computed(
  () =>
    frameDoc.value !== null &&
    frameDoc.value.assets.dropped === 0 &&
    frameDoc.value.assets.css.length === 0,
);

// ---- notices
const staged = computed(() => props.session.stagedSummary());
const settingsStaged = computed(
  () =>
    staged.value?.details.some((k) => k === 'layout' || k === 'sortMode' || k === 'settings') ===
    true,
);
const itemsStaged = computed(
  () =>
    staged.value !== null &&
    (staged.value.order || staged.value.removed > 0 || staged.value.overrides.length > 0),
);
const diagnostics = computed(() => preview.result.value?.diagnostics ?? []);
const diagnosticsOpen = computed(
  () => blank.value || (preview.result.value?.totalItems ?? 1) === 0,
);

const ERROR_KEY: Record<PreviewErrorKind, string> = {
  validation: 'editor.preview.error.validation',
  unknownLayout: 'editor.preview.error.unknownLayout',
  forbidden: 'editor.preview.error.forbidden',
  notFound: 'editor.preview.error.notFound',
  network: 'editor.preview.error.network',
  invalidResponse: 'editor.preview.error.invalidResponse',
  server: 'editor.preview.error.server',
};
const errorText = computed(() =>
  preview.error.value === null ? null : t(ERROR_KEY[preview.error.value.kind]),
);

const pagerText = computed(() => {
  const result = preview.result.value;
  return result === null
    ? ''
    : t(
        'editor.preview.pager.status',
        { page: result.page, total: result.totalPages, count: result.totalItems },
        result.totalItems,
      );
});

// ---- live region (rate-limited, latest message wins)
let lastAnnounce = Number.NEGATIVE_INFINITY;
let announceTimer: ReturnType<typeof setTimeout> | undefined;
let pending: string | null = null;
function announceLimited(message: string): void {
  pending = message;
  const wait = lastAnnounce + ANNOUNCE_INTERVAL_MS - Date.now();
  if (wait <= 0) {
    flushAnnounce();
  } else if (announceTimer === undefined) {
    announceTimer = setTimeout(flushAnnounce, wait);
  }
}
function flushAnnounce(): void {
  announceTimer = undefined;
  if (pending === null) return;
  lastAnnounce = Date.now();
  app.announce(pending);
  pending = null;
}
onBeforeUnmount(() => clearTimeout(announceTimer));

watch(preview.renderId, () => {
  const result = preview.result.value;
  if (result !== null && props.active) {
    announceLimited(t('editor.preview.updated', { page: result.page, total: result.totalPages }));
  }
});
watch(preview.error, (error) => {
  if (error !== null && props.active) announceLimited(t(ERROR_KEY[error.kind]));
});
</script>

<template>
  <div class="swg-pv">
    <div class="swg-pv__bar">
      <SegmentedControl
        v-model="viewportModel"
        :options="viewportOptions"
        :legend="t('editor.preview.viewport.legend')"
        :disabled="!canPreview"
      />
      <q-btn
        flat
        round
        dense
        icon="r_refresh"
        :aria-label="t('editor.preview.refresh')"
        :disable="!canPreview || preview.loading.value"
        class="swg-pv__refresh"
        @click="preview.refresh()"
      >
        <q-tooltip>{{ t('editor.preview.refresh') }}</q-tooltip>
      </q-btn>
      <label class="swg-pv__perpage">
        <span>{{ t('editor.preview.perPage.label') }}</span>
        <select :value="perPageValue" :disabled="!canPreview" @change="onPerPage">
          <option value="">{{ t('editor.preview.perPage.site') }}</option>
          <option v-for="n in PER_PAGE_CHOICES" :key="n" :value="String(n)">{{ n }}</option>
        </select>
      </label>
      <div
        v-if="preview.result.value !== null && preview.totalPages.value > 0"
        class="swg-pv__pager"
        role="group"
        :aria-label="t('editor.preview.pager.label')"
      >
        <q-btn
          flat
          round
          dense
          icon="r_chevron_left"
          class="swg-flip-rtl swg-pv__prev"
          :aria-label="t('editor.preview.pager.prev')"
          :disable="preview.page.value <= 1"
          @click="preview.goto(preview.page.value - 1)"
        />
        <span class="swg-pv__pagetext swg-num">{{ pagerText }}</span>
        <q-btn
          flat
          round
          dense
          icon="r_chevron_right"
          class="swg-flip-rtl swg-pv__next"
          :aria-label="t('editor.preview.pager.next')"
          :disable="preview.page.value >= preview.totalPages.value"
          @click="preview.goto(preview.page.value + 1)"
        />
      </div>
    </div>
    <p class="swg-pv__width swg-num">{{ widthLabel }} · {{ t('editor.preview.perPage.hint') }}</p>

    <p v-if="!canPreview" class="swg-pv__note" role="note">
      <q-icon name="r_lock" size="18px" aria-hidden="true" />
      {{ t('editor.preview.notEditable') }}
    </p>
    <template v-else>
      <p v-if="settingsStaged" class="swg-pv__note is-info swg-pv__unsaved-settings" role="note">
        <q-icon name="r_info" size="18px" aria-hidden="true" />
        {{ t('editor.preview.unsavedSettings') }}
      </p>
      <p v-if="itemsStaged" class="swg-pv__note is-info swg-pv__unsaved-items" role="note">
        <q-icon name="r_info" size="18px" aria-hidden="true" />
        {{ t('editor.preview.unsavedItems') }}
      </p>
    </template>
    <p v-if="assetsDropped" class="swg-pv__note is-warning swg-pv__assets-dropped" role="note">
      <q-icon name="r_warning" size="18px" aria-hidden="true" />
      {{ t('editor.preview.assetsDropped') }}
    </p>
    <p v-else-if="assetsMissing" class="swg-pv__note is-warning" role="note">
      <q-icon name="r_warning" size="18px" aria-hidden="true" />
      {{ t('editor.preview.noAssets') }}
    </p>

    <div v-if="errorText" class="swg-pv__alert swg-pv__error">
      <q-icon name="r_error" size="20px" aria-hidden="true" />
      <span>
        {{ errorText }}
        <template v-if="preview.result.value !== null"> {{ t('editor.preview.stale') }}</template>
      </span>
      <q-btn
        flat
        dense
        no-caps
        :label="t('editor.preview.error.retry')"
        class="swg-pv__retry"
        @click="preview.refresh()"
      />
    </div>

    <details
      v-if="diagnostics.length > 0"
      class="swg-pv__diag"
      :open="diagnosticsOpen ? true : undefined"
    >
      <summary>
        {{
          t('editor.preview.diagnostics.title', { count: diagnostics.length }, diagnostics.length)
        }}
      </summary>
      <p class="swg-pv__hint">{{ t('editor.preview.diagnostics.hint') }}</p>
      <ul>
        <li v-for="(d, index) in diagnostics" :key="`${d.code}:${index}`">{{ d.message }}</li>
      </ul>
    </details>

    <div
      class="swg-pv__stage"
      :class="{ 'is-stale': errorText !== null && frameDoc !== null }"
      :aria-busy="preview.loading.value"
    >
      <div v-if="blank" class="swg-pv__empty">
        <q-icon name="r_photo_library" size="32px" aria-hidden="true" />
        <strong>{{ t('editor.preview.empty.title') }}</strong>
        <span>{{ t('editor.preview.empty.text') }}</span>
      </div>
      <div
        v-else-if="frameDoc !== null"
        class="swg-pv__device"
        :style="{ inlineSize: deviceWidth === null ? '100%' : `${deviceWidth}px` }"
      >
        <PreviewFrame
          :key="frameDoc.key"
          :srcdoc="frameDoc.srcdoc"
          :token="frameDoc.token"
          :title="t('editor.preview.frameTitle')"
          :min-height="MIN_FRAME_HEIGHT"
          :max-height="MAX_FRAME_HEIGHT"
        />
      </div>
      <div v-else-if="!errorText" class="swg-pv__empty" aria-hidden="true">
        <q-spinner size="24px" />
      </div>
      <div v-if="preview.loading.value" class="swg-pv__loading" role="presentation">
        <q-spinner size="28px" aria-hidden="true" />
        <span class="swg-sr-only">{{ t('editor.preview.loading') }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.swg-pv {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-inline-size: 0;
}
.swg-pv__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
}
.swg-pv__perpage {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-pv__perpage select {
  block-size: 32px;
  padding-inline: 8px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
  color: var(--swg-text);
  font: inherit;
  font-size: 13px;
}
.swg-pv__perpage select:focus-visible {
  outline: 2px solid var(--swg-focus);
  outline-offset: 2px;
}
.swg-pv__pager {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-inline-start: auto;
}
.swg-pv__pagetext {
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-pv__width {
  font-size: 12px;
  color: var(--swg-muted);
  margin: 0;
}
.swg-pv__note,
.swg-pv__alert {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 0;
  padding: 8px 12px;
  border-radius: var(--swg-radius-sm);
  font-size: 13px;
  background: var(--swg-surface-2);
  color: var(--swg-muted);
}
.swg-pv__note.is-info {
  background: var(--swg-info-bg);
  color: var(--swg-info-text);
}
.swg-pv__note.is-warning {
  background: var(--swg-warning-bg);
  color: var(--swg-warning-text);
}
.swg-pv__alert {
  align-items: center;
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
  font-size: 14px;
}
.swg-pv__retry {
  flex: none;
  white-space: nowrap;
  margin-inline-start: auto;
}
.swg-pv__diag {
  padding: 8px 12px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
  font-size: 13px;
}
.swg-pv__diag summary {
  cursor: pointer;
  font-weight: 600;
}
.swg-pv__diag ul {
  margin: 6px 0 0;
  padding-inline-start: 18px;
  list-style: disc;
}
.swg-pv__hint {
  margin: 4px 0 0;
  color: var(--swg-muted);
}
.swg-pv__stage {
  position: relative;
  padding: 12px;
  overflow-x: auto;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
  background: var(--swg-surface-2);
  min-block-size: 160px;
}
.swg-pv__stage.is-stale .swg-pv__device {
  opacity: 0.45;
  filter: grayscale(1);
}
.swg-pv__device {
  margin-inline: auto;
  max-inline-size: 100%;
  overflow: hidden;
  /* A ring instead of a border: the frame is exactly as wide as the chosen viewport. */
  box-shadow: 0 0 0 1px var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
}
@media (prefers-reduced-motion: no-preference) {
  .swg-pv__device {
    transition:
      inline-size 0.2s ease,
      opacity 0.2s ease;
  }
}
.swg-pv__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 32px 16px;
  color: var(--swg-muted);
  text-align: center;
}
.swg-pv__loading {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  background: var(--swg-overlay);
  border-radius: var(--swg-radius);
  color: var(--swg-text);
}
</style>
