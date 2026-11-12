<script setup lang="ts">
/*
 * Gallery editor (M2-24..M2-32).
 *
 * STAGED EDITING MODEL (details in apps/admin/README.md):
 *  - The editor keeps a local working copy (useGalleryEditor.ts). Removals, reordering, text
 *    overrides, cover, status, layout, sort mode and settings are STAGED; nothing leaves the
 *    browser until "Save".
 *  - Save applies the staged changes in a fixed order with revision chaining (every response's
 *    revision becomes the next `expectedRevision`): remove -> text overrides -> order (complete
 *    list) -> gallery details (publishing last). A failure reports what was saved and what not.
 *  - Adding images (media modal, upload) is applied IMMEDIATELY because the server assigns the
 *    item ids; it is undone by removing the images.
 *  - Undo/redo covers staged changes (Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z); Save clears the history.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { errorMessage } from '@/api/errorMessage';
import type { LayoutsResponse } from '@/api/layouts';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFeedback } from '@/features/galleries/feedback';
import GalleryStatusChip from '@/features/galleries/components/GalleryStatusChip.vue';
import { useAppStore } from '@/stores/app';
import ConflictDialog from './components/ConflictDialog.vue';
import GalleryDetailsPanel from './components/GalleryDetailsPanel.vue';
import ImageDetailsSheet from './components/ImageDetailsSheet.vue';
import ItemsGrid from './components/ItemsGrid.vue';
import LayoutSettingsPanel from './components/LayoutSettingsPanel.vue';
import UploadPanel from './components/UploadPanel.vue';
import { useEditorApi } from './injection';
import { validateSettings } from './schema';
import { createEditorSession } from './useGalleryEditor';
import type { AddOutcome, FailureKind, SaveReport } from './useGalleryEditor';
import { useItemSelection } from './useItemSelection';
import { useMediaPicker } from './useMediaPicker';
import { useUnsavedGuard } from './useUnsavedGuard';
import { useUploads } from './useUploads';
import type { MoveMode } from './reorder';

/** Pause after the last change before the unsaved count is announced. */
const UNSAVED_ANNOUNCE_DELAY_MS = 1500;

const props = defineProps<{ galleryId: number }>();
const { t } = useI18n();
const app = useAppStore();
const { can } = useCapabilities();
const feedback = useFeedback();
const api = useEditorApi();

const session = createEditorSession(props.galleryId, api);
const selection = useItemSelection(session.order);
const picker = useMediaPicker();
const grid = ref<InstanceType<typeof ItemsGrid> | null>(null);

// ---- load
const layouts = ref<LayoutsResponse | null>(null);
const layoutsError = ref<string | null>(null);
const layoutsLoading = ref(false);
async function loadLayouts(): Promise<void> {
  layoutsLoading.value = true;
  layoutsError.value = null;
  try {
    layouts.value = await api.layouts.list();
  } catch (error) {
    layoutsError.value = errorMessage(error, t);
  } finally {
    layoutsLoading.value = false;
  }
}
onMounted(() => {
  void session.load();
  void loadLayouts();
});

const gallery = computed(() => session.base.value?.gallery ?? null);
const working = computed(() => session.working.value);
const title = computed(() => working.value?.details.title ?? '');
const displayTitle = computed(() => title.value.trim() || t('galleries.card.untitled'));
const readonly = computed(
  () => gallery.value === null || !gallery.value.canEdit || session.invalidState.value,
);
const canPublish = computed(() => gallery.value?.canPublish === true && !readonly.value);
const empty = computed(() => session.phase.value !== 'ready');
const errorText = computed(() =>
  session.loadError.value === null ? null : errorMessage(session.loadError.value, t),
);
const itemCount = computed(() => session.order.value.length);
const missingCount = computed(
  () => session.order.value.filter((id) => session.itemView(id)?.item.missing === true).length,
);

// ---- settings validation (live) blocks saving
const settingProblems = computed(() => {
  const layout = layouts.value?.layouts.find((l) => l.id === working.value?.details.layout);
  return layout === undefined || working.value === null
    ? []
    : validateSettings(layout.settingsSchema, working.value.details.settings);
});
const titleMissing = computed(() => title.value.trim() === '');

// ---- unsaved changes
const uploads = useUploads(
  api.media,
  (ids) => session.addAttachments(ids),
  ({ uploaded, failed, outcome }) => {
    if (outcome.ok) {
      const message = t('editor.upload.summary', {
        added: outcome.added,
        total: uploaded + failed,
        failed,
      });
      feedback.notify(message, failed > 0 || outcome.added === 0 ? 'warning' : 'success');
    }
  },
);
const isDirty = (): boolean => session.dirty.value || uploads.busy.value;
const guard = useUnsavedGuard(isDirty);

// The count is announced once the user paused: an immediate announcement would overwrite the
// message of the action itself ("moved to position 3 of 12") in the single live region.
let announceTimer: ReturnType<typeof setTimeout> | undefined;
watch(session.changeCount, (now, before) => {
  clearTimeout(announceTimer);
  announceTimer = setTimeout(() => {
    if (now > 0) app.announce(t('editor.status.unsaved', { count: now }, now));
    else if (before > 0 && !session.saving.value) app.announce(t('editor.status.saved'));
  }, UNSAVED_ANNOUNCE_DELAY_MS);
});
onBeforeUnmount(() => clearTimeout(announceTimer));

// ---- save / publish / discard
const failureKey: Record<FailureKind, string> = {
  conflict: 'editor.save.reason.conflict',
  gone: 'editor.save.reason.gone',
  itemGone: 'editor.save.reason.itemGone',
  invalidState: 'editor.save.reason.invalidState',
  forbidden: 'editor.save.reason.forbidden',
  invalid: 'editor.save.reason.invalid',
  network: 'editor.save.reason.network',
  other: 'editor.save.reason.other',
};

const saveBlocker = computed<string | null>(() => {
  if (titleMissing.value) return t('editor.save.blockedTitle');
  if (settingProblems.value.length > 0) return t('editor.save.blockedSettings');
  return null;
});
const canSave = computed(
  () =>
    !readonly.value &&
    session.dirty.value &&
    !session.saving.value &&
    saveBlocker.value === null &&
    !uploads.busy.value,
);
const canPublishNow = computed(
  () =>
    canPublish.value &&
    gallery.value?.status !== 'publish' &&
    !session.saving.value &&
    saveBlocker.value === null &&
    !uploads.busy.value,
);

function reportText(report: SaveReport): string {
  const saved = [...new Set(report.completed)].map((kind) => t(`editor.save.step.${kind}`));
  const failed = report.failed;
  if (failed === null) return '';
  return t('editor.save.partial', {
    saved: saved.length > 0 ? saved.join(', ') : t('editor.save.nothing'),
    failed: t(`editor.save.step.${failed.kind}`),
    reason:
      failed.failure === 'invalid' || failed.failure === 'forbidden'
        ? errorMessage(failed.error, t)
        : t(failureKey[failed.failure]),
  });
}

async function onSave(): Promise<void> {
  if (!canSave.value) return;
  const report = await session.save();
  if (report === null) return;
  if (report.ok) {
    feedback.notify(t('editor.save.done'));
  } else {
    app.announce(reportText(report));
  }
}
async function onPublish(): Promise<void> {
  if (!canPublishNow.value) return;
  const report = await session.publish();
  if (report?.ok === true) feedback.notify(t('editor.save.published'));
  else if (report !== null) app.announce(reportText(report));
}
const saveLabel = computed(() => {
  const p = session.progress.value;
  return p === null
    ? t('editor.actions.save')
    : t('editor.save.progress', { done: p.done + 1, total: p.total });
});

const discardOpen = ref(false);
function confirmDiscard(): void {
  session.discard();
  selection.prune();
  discardOpen.value = false;
  app.announce(t('editor.status.discarded'));
}

function onUndo(): void {
  if (session.undo()) app.announce(t('editor.status.undone'));
}
function onRedo(): void {
  if (session.redo()) app.announce(t('editor.status.redone'));
}

function isTextTarget(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  return (
    el !== null && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)
  );
}
function onWindowKeydown(event: KeyboardEvent): void {
  const mod = event.ctrlKey || event.metaKey;
  if (!mod) return;
  const key = event.key.toLowerCase();
  if (key === 's') {
    event.preventDefault();
    void onSave();
  } else if (!isTextTarget(event.target) && key === 'z') {
    event.preventDefault();
    if (event.shiftKey) onRedo();
    else onUndo();
  } else if (!isTextTarget(event.target) && key === 'y') {
    event.preventDefault();
    onRedo();
  }
}
onMounted(() => window.addEventListener('keydown', onWindowKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onWindowKeydown));

// ---- removal (staged, with confirmation naming the count)
const removeOpen = ref(false);
const removeIds = ref<number[]>([]);
function requestRemove(ids?: number[]): void {
  if (readonly.value) return;
  const list = ids ?? [...selection.targets.value];
  if (list.length === 0) return;
  removeIds.value = list;
  removeOpen.value = true;
}
const removeTargets = computed(() => {
  const names = removeIds.value.slice(0, 5).map((id) => {
    const view = session.itemView(id);
    if (view === null) return String(id);
    return view.item.missing
      ? t('editor.grid.unavailable')
      : view.effective.title || t('editor.grid.untitled', { id: view.item.attachmentId });
  });
  const rest = removeIds.value.length - names.length;
  return rest > 0 ? [...names, t('editor.remove.more', { count: rest }, rest)] : names;
});
function confirmRemove(): void {
  const count = session.removeItems(removeIds.value);
  selection.clear();
  selection.prune();
  removeOpen.value = false;
  detailsOpen.value = false;
  app.announce(t('editor.remove.done', { count }, count));
}

// ---- reordering via buttons (the grid also has keys and drag and drop)
function move(mode: MoveMode): void {
  void grid.value?.moveTargets(mode);
}

// ---- image details
const detailsOpen = ref(false);
const detailsItemId = ref<number | null>(null);
function openDetails(itemId: number): void {
  detailsItemId.value = itemId;
  detailsOpen.value = true;
}
function openDetailsOfTarget(): void {
  const id = selection.activeId.value;
  const only = selection.count.value === 1 ? [...selection.selected.value][0] : id;
  if (only !== undefined && only !== null) openDetails(only);
}
function useAsCover(attachmentId: number): void {
  session.setDetail('coverAttachmentId', attachmentId);
  app.announce(t('editor.details.coverSet'));
}
const coverId = computed(() => working.value?.details.coverAttachmentId ?? null);
const singleAttachment = computed<number | null>(() => {
  if (selection.count.value !== 1) return null;
  const id = [...selection.selected.value][0];
  const view = id === undefined ? null : session.itemView(id);
  return view === null || view.item.missing ? null : view.item.attachmentId;
});

// ---- adding images
const uploadAllowed = computed(() => can('upload_files') && !readonly.value);
const pickerReason = computed(() => {
  if (readonly.value) return t('editor.readonly.short');
  if (!can('upload_files')) return t('editor.picker.noRight');
  return null;
});

function reportAdd(outcome: AddOutcome): void {
  if (!outcome.ok) {
    if (outcome.failure !== 'conflict') {
      feedback.notify(t(failureKey[outcome.failure] ?? 'editor.save.reason.other'), 'warning');
    }
    return;
  }
  const parts = [t('editor.picker.added', { count: outcome.added }, outcome.added)];
  if (outcome.alreadyAssigned > 0) {
    parts.push(
      t('editor.picker.already', { count: outcome.alreadyAssigned }, outcome.alreadyAssigned),
    );
  }
  if (outcome.missing > 0) {
    parts.push(t('editor.picker.missing', { count: outcome.missing }, outcome.missing));
  }
  feedback.notify(
    parts.join(' · '),
    outcome.added > 0 && outcome.missing === 0 ? 'success' : 'warning',
  );
}

async function addFromLibrary(): Promise<void> {
  if (!uploadAllowed.value) return;
  const result = await picker.open({
    title: t('editor.picker.title'),
    button: t('editor.picker.button'),
  });
  if (result.status === 'unavailable') {
    feedback.notify(t('editor.picker.unavailable'), 'warning');
  } else if (result.status === 'selected') {
    reportAdd(await session.addAttachments(result.attachmentIds));
  }
}

// ---- files dropped on the images area
const fileDragging = ref(false);
let dragDepth = 0;
function hasFiles(event: DragEvent): boolean {
  return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}
function onFileEnter(event: DragEvent): void {
  if (!hasFiles(event)) return;
  event.preventDefault();
  dragDepth += 1;
  fileDragging.value = true;
}
function onFileOver(event: DragEvent): void {
  if (!hasFiles(event)) return;
  event.preventDefault();
  if (event.dataTransfer !== null)
    event.dataTransfer.dropEffect = uploadAllowed.value ? 'copy' : 'none';
}
function onFileLeave(event: DragEvent): void {
  if (!hasFiles(event)) return;
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) fileDragging.value = false;
}
function onFileDrop(event: DragEvent): void {
  if (!hasFiles(event)) return;
  event.preventDefault();
  dragDepth = 0;
  fileDragging.value = false;
  if (!uploadAllowed.value) {
    feedback.notify(pickerReason.value ?? t('editor.picker.noRight'), 'warning');
    return;
  }
  uploads.add(Array.from(event.dataTransfer?.files ?? []));
}

// ---- side panel
type Panel = 'details' | 'layout';
const panel = ref<Panel | null>('details');
const panelOptions = computed(() => [
  { value: 'details' as const, label: t('editor.panel.details') },
  { value: 'layout' as const, label: t('editor.panel.layout') },
]);

// ---- conflict
function reapply(): void {
  session.resolveConflict('reapply');
  selection.prune();
  app.announce(t('editor.conflict.reapplied'));
}
function discardMine(): void {
  session.resolveConflict('discard');
  selection.prune();
  app.announce(t('editor.conflict.discarded'));
}
function dismissConflict(): void {
  session.conflict.value = null;
}

const stateText = computed(() => {
  if (session.saving.value) return t('editor.status.saving');
  if (uploads.busy.value) return t('editor.status.uploading');
  return session.dirty.value
    ? t('editor.status.unsaved', { count: session.changeCount.value }, session.changeCount.value)
    : t('editor.status.saved');
});
const rebaseLines = computed(() => {
  const n = session.rebaseNotices.value;
  if (n === null) return [];
  const lines: string[] = [];
  if (n.droppedOrder) lines.push(t('editor.conflict.droppedOrder'));
  if (n.droppedOverrides > 0) {
    lines.push(
      t('editor.conflict.droppedTexts', { count: n.droppedOverrides }, n.droppedOverrides),
    );
  }
  if (n.overwrittenDetails.length > 0) {
    lines.push(
      t('editor.conflict.overwritten', {
        fields: n.overwrittenDetails.map((k) => t(`editor.conflict.field.${k}`)).join(', '),
      }),
    );
  }
  if (n.overwrittenOverrides > 0) {
    lines.push(
      t(
        'editor.conflict.overwrittenTexts',
        { count: n.overwrittenOverrides },
        n.overwrittenOverrides,
      ),
    );
  }
  return lines;
});
const reportAlert = computed(() => {
  const report = session.report.value;
  return report !== null && !report.ok ? reportText(report) : null;
});
</script>

<template>
  <div class="swg-ed">
    <nav class="swg-ed__crumbs" :aria-label="t('editor.breadcrumb')">
      <router-link to="/galleries">
        <q-icon name="r_arrow_back" size="18px" class="swg-flip-rtl" aria-hidden="true" />
        {{ t('nav.galleries') }}
      </router-link>
      <span aria-hidden="true">/</span>
      <span aria-current="page" class="swg-truncate">{{ displayTitle }}</span>
    </nav>

    <DataState
      :loading="session.phase.value === 'loading'"
      :error="session.gone.value ? null : errorText"
      :empty="empty"
      :error-title="t('editor.loadError')"
      :retry-label="t('common.retry')"
      :loading-label="t('editor.loading')"
      variant="block"
      @retry="session.load()"
    >
      <template #empty>
        <div class="swg-ed__gone">
          <EmptyState
            v-if="session.gone.value"
            illustration="error"
            :title="t('editor.notFound.title')"
            :text="t('editor.notFound.text')"
          >
            <q-btn
              color="primary"
              unelevated
              no-caps
              to="/galleries"
              :label="t('editor.notFound.back')"
            />
          </EmptyState>
        </div>
      </template>

      <template v-if="gallery && working">
        <header class="swg-ed__head">
          <div class="swg-ed__titles">
            <h2 class="swg-ed__title">{{ displayTitle }}</h2>
            <GalleryStatusChip
              :gallery="{
                status: working.details.status,
                passwordProtected: gallery.passwordProtected,
              }"
            />
          </div>
          <div class="swg-ed__actions" role="group" :aria-label="t('editor.actions.label')">
            <q-btn
              flat
              round
              dense
              icon="r_undo"
              :disable="!session.canUndo.value || session.saving.value"
              :aria-label="t('editor.actions.undo')"
              @click="onUndo"
            >
              <q-tooltip>{{ t('editor.actions.undo') }} (Ctrl+Z)</q-tooltip>
            </q-btn>
            <q-btn
              flat
              round
              dense
              icon="r_redo"
              :disable="!session.canRedo.value || session.saving.value"
              :aria-label="t('editor.actions.redo')"
              @click="onRedo"
            >
              <q-tooltip>{{ t('editor.actions.redo') }} (Ctrl+Shift+Z)</q-tooltip>
            </q-btn>
            <q-btn
              flat
              no-caps
              icon="r_delete_sweep"
              :label="t('editor.actions.discard')"
              :disable="!session.dirty.value || session.saving.value || readonly"
              @click="discardOpen = true"
            />
            <q-btn
              v-if="canPublish && gallery.status !== 'publish'"
              outline
              no-caps
              color="primary"
              icon="r_public"
              :label="t('editor.actions.publish')"
              :disable="!canPublishNow"
              @click="onPublish"
            />
            <q-btn
              unelevated
              no-caps
              color="primary"
              icon="r_save"
              :label="saveLabel"
              :loading="session.saving.value"
              :disable="!canSave"
              class="swg-ed__save"
              @click="onSave"
            />
          </div>
        </header>

        <p class="swg-ed__state" :class="{ 'is-dirty': session.dirty.value }">
          <q-spinner
            v-if="session.saving.value || uploads.busy.value"
            size="16px"
            aria-hidden="true"
          />
          <q-icon
            v-else
            :name="session.dirty.value ? 'r_edit_note' : 'r_check_circle'"
            size="18px"
            aria-hidden="true"
          />
          <span>{{ stateText }}</span>
          <span v-if="saveBlocker && session.dirty.value" class="swg-ed__blocker"
            >· {{ saveBlocker }}</span
          >
        </p>
        <q-linear-progress
          v-if="session.progress.value"
          :value="session.progress.value.done / session.progress.value.total"
          :aria-label="t('editor.save.progressLabel')"
          class="swg-ed__progress"
        />

        <div v-if="readonly" class="swg-ed__alert is-info" role="status">
          <q-icon name="r_lock" size="20px" aria-hidden="true" />
          <span>{{
            session.invalidState.value ? t('editor.readonly.state') : t('editor.readonly.text')
          }}</span>
        </div>
        <div v-if="reportAlert" class="swg-ed__alert is-danger" role="alert">
          <q-icon name="r_error" size="20px" aria-hidden="true" />
          <span>{{ reportAlert }}</span>
          <q-btn
            flat
            dense
            no-caps
            :label="t('editor.save.dismiss')"
            @click="session.report.value = null"
          />
        </div>
        <div
          v-if="session.rebaseNotices.value !== null"
          class="swg-ed__alert is-warning"
          role="status"
        >
          <q-icon name="r_info" size="20px" aria-hidden="true" />
          <div>
            <strong>{{ t('editor.conflict.reappliedTitle') }}</strong>
            <ul v-if="rebaseLines.length > 0">
              <li v-for="line in rebaseLines" :key="line">{{ line }}</li>
            </ul>
          </div>
          <q-btn
            flat
            dense
            no-caps
            :label="t('editor.save.dismiss')"
            @click="session.rebaseNotices.value = null"
          />
        </div>
        <div v-if="session.truncated.value" class="swg-ed__alert is-warning" role="status">
          <q-icon name="r_info" size="20px" aria-hidden="true" />
          <span>{{
            t('editor.truncated', { loaded: itemCount, total: session.total.value })
          }}</span>
        </div>

        <div class="swg-ed__cols">
          <section
            class="swg-ed__images"
            :aria-label="t('editor.images.region')"
            @dragenter="onFileEnter"
            @dragover="onFileOver"
            @dragleave="onFileLeave"
            @drop="onFileDrop"
          >
            <div class="swg-ed__bar">
              <q-btn
                color="primary"
                unelevated
                no-caps
                icon="r_photo_library"
                :label="t('editor.actions.add')"
                :disable="!uploadAllowed"
                class="swg-ed__add"
                @click="addFromLibrary"
              />
              <span v-if="pickerReason" class="swg-ed__reason">{{ pickerReason }}</span>
              <span class="swg-ed__count swg-num">
                {{ t('galleries.card.images', { count: itemCount }, itemCount) }}
                <template v-if="missingCount > 0">
                  · {{ t('editor.images.unavailableCount', { count: missingCount }, missingCount) }}
                </template>
              </span>
            </div>

            <UploadPanel
              :uploads="uploads"
              :dragging="fileDragging"
              :disabled="!uploadAllowed"
              :reason="pickerReason"
            />

            <div
              v-if="itemCount > 0"
              class="swg-ed__bar swg-ed__selbar"
              role="toolbar"
              :aria-label="t('editor.selection.toolbar')"
            >
              <span class="swg-ed__sel">
                {{
                  t(
                    'editor.selection.count',
                    { count: selection.count.value },
                    selection.count.value,
                  )
                }}
              </span>
              <q-btn
                flat
                dense
                no-caps
                :label="t('editor.selection.all')"
                @click="selection.selectAll()"
              />
              <q-btn
                flat
                dense
                no-caps
                :label="t('editor.selection.clear')"
                :disable="selection.count.value === 0"
                @click="selection.clear()"
              />
              <span class="swg-ed__sep" aria-hidden="true" />
              <q-btn
                flat
                dense
                round
                icon="r_first_page"
                class="swg-flip-rtl"
                :aria-label="t('editor.reorder.start')"
                :disable="!session.reorderEnabled.value || readonly"
                @click="move('start')"
              />
              <q-btn
                flat
                dense
                round
                icon="r_chevron_left"
                class="swg-flip-rtl"
                :aria-label="t('editor.reorder.earlier')"
                :disable="!session.reorderEnabled.value || readonly"
                @click="move('earlier')"
              />
              <q-btn
                flat
                dense
                round
                icon="r_chevron_right"
                class="swg-flip-rtl"
                :aria-label="t('editor.reorder.later')"
                :disable="!session.reorderEnabled.value || readonly"
                @click="move('later')"
              />
              <q-btn
                flat
                dense
                round
                icon="r_last_page"
                class="swg-flip-rtl"
                :aria-label="t('editor.reorder.end')"
                :disable="!session.reorderEnabled.value || readonly"
                @click="move('end')"
              />
              <span class="swg-ed__sep" aria-hidden="true" />
              <q-btn
                flat
                dense
                no-caps
                icon="r_tune"
                :label="t('editor.selection.details')"
                @click="openDetailsOfTarget"
              />
              <q-btn
                v-if="!readonly"
                flat
                dense
                no-caps
                icon="r_star"
                :label="t('editor.selection.cover')"
                :disable="singleAttachment === null"
                @click="singleAttachment !== null && useAsCover(singleAttachment)"
              />
              <q-btn
                v-if="!readonly"
                flat
                dense
                no-caps
                color="negative"
                icon="r_delete"
                :label="t('editor.selection.remove')"
                class="swg-ed__remove"
                @click="requestRemove()"
              />
            </div>
            <p v-if="session.truncated.value" class="swg-ed__reason">
              {{ t('editor.reorder.disabled') }}
            </p>
            <p v-else-if="working.details.sortMode !== 'manual'" class="swg-ed__reason">
              {{ t('editor.sort.activeNote') }}
            </p>

            <ItemsGrid
              v-if="itemCount > 0"
              ref="grid"
              :session="session"
              :selection="selection"
              :cover-attachment-id="coverId"
              :readonly="readonly"
              @open-details="openDetails"
              @remove-request="requestRemove()"
            />
            <div v-else class="swg-ed__empty">
              <EmptyState
                illustration="media"
                :title="t('editor.empty.title')"
                :text="t('editor.empty.text')"
              >
                <q-btn
                  color="primary"
                  unelevated
                  no-caps
                  icon="r_photo_library"
                  :label="t('editor.actions.add')"
                  :disable="!uploadAllowed"
                  @click="addFromLibrary"
                />
                <span v-if="pickerReason" class="swg-muted">{{ pickerReason }}</span>
              </EmptyState>
            </div>
          </section>

          <aside class="swg-ed__side" :aria-label="t('editor.panel.label')">
            <SegmentedControl
              v-model="panel"
              :options="panelOptions"
              :legend="t('editor.panel.legend')"
            />
            <div v-show="panel === 'details'" class="swg-ed__panel">
              <GalleryDetailsPanel
                :session="session"
                :can-publish="canPublish"
                :readonly="readonly"
              />
            </div>
            <div v-show="panel === 'layout'" class="swg-ed__panel">
              <LayoutSettingsPanel
                :session="session"
                :layouts="layouts"
                :layouts-error="layoutsError"
                :layouts-loading="layoutsLoading"
                :readonly="readonly"
                @retry="loadLayouts"
              />
            </div>
          </aside>
        </div>
      </template>
    </DataState>

    <ImageDetailsSheet
      v-model="detailsOpen"
      :session="session"
      :item-id="detailsItemId"
      :cover-attachment-id="coverId"
      :readonly="readonly"
      @use-cover="useAsCover"
      @remove="(id) => requestRemove([id])"
    />

    <ConflictDialog
      :state="session.conflict.value"
      @reapply="reapply"
      @discard="discardMine"
      @dismiss="dismissConflict"
    />

    <ConfirmDialog
      v-model="removeOpen"
      :title="t('editor.remove.title', { count: removeIds.length }, removeIds.length)"
      :message="t('editor.remove.message')"
      :targets="removeTargets"
      :targets-label="t('editor.remove.affects')"
      :consequences="[t('editor.remove.imagesStay'), t('editor.remove.staged')]"
      :confirm-label="t('editor.remove.confirm', { count: removeIds.length }, removeIds.length)"
      :cancel-label="t('galleries.form.cancel')"
      :danger="false"
      @confirm="confirmRemove"
    />
    <ConfirmDialog
      v-model="discardOpen"
      :title="t('editor.discard.title')"
      :message="t('editor.discard.message')"
      :targets="[displayTitle]"
      :targets-label="t('editor.discard.affects')"
      :consequences="[
        t('editor.discard.count', { count: session.changeCount.value }, session.changeCount.value),
      ]"
      :confirm-label="t('editor.discard.confirm')"
      :cancel-label="t('galleries.form.cancel')"
      @confirm="confirmDiscard"
    />
    <ConfirmDialog
      v-model="guard.open.value"
      :title="t('editor.leave.title')"
      :message="t('editor.leave.message')"
      :targets="[displayTitle]"
      :targets-label="t('editor.discard.affects')"
      :consequences="[
        t('editor.discard.count', { count: session.changeCount.value }, session.changeCount.value),
      ]"
      :confirm-label="t('editor.leave.confirm')"
      :cancel-label="t('editor.leave.stay')"
      @confirm="guard.confirmLeave"
    />
  </div>
</template>

<style scoped>
.swg-ed__crumbs {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-block-end: 8px;
  font-size: 13px;
  font-weight: 600;
  min-inline-size: 0;
}
.swg-ed__crumbs a {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.swg-ed__head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px 16px;
}
.swg-ed__titles {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-inline-size: 0;
}
.swg-ed__title {
  font-size: 26px;
  line-height: 1.2;
  font-weight: 750;
  letter-spacing: -0.02em;
  overflow-wrap: anywhere;
}
.swg-ed__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.swg-ed__state {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-block: 12px;
  color: var(--swg-muted);
  font-size: 14px;
}
.swg-ed__state.is-dirty {
  color: var(--swg-warning-text);
  font-weight: 600;
}
.swg-ed__blocker {
  color: var(--swg-danger-text);
}
.swg-ed__progress {
  margin-block-end: 12px;
}
.swg-ed__alert {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-block-end: 12px;
  padding: 10px 14px;
  border-radius: var(--swg-radius-sm);
  font-size: 14px;
}
.swg-ed__alert ul {
  margin: 4px 0 0;
  padding-inline-start: 18px;
  list-style: disc;
}
.swg-ed__alert.is-danger {
  background: var(--swg-danger-bg);
  color: var(--swg-danger-text);
}
.swg-ed__alert.is-warning {
  background: var(--swg-warning-bg);
  color: var(--swg-warning-text);
}
.swg-ed__alert.is-info {
  background: var(--swg-info-bg);
  color: var(--swg-info-text);
}
.swg-ed__cols {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 380px;
  gap: 20px;
  align-items: start;
}
.swg-ed__images {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-inline-size: 0;
}
.swg-ed__bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
}
.swg-ed__selbar {
  padding: 4px 8px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
}
.swg-ed__sep {
  inline-size: 1px;
  block-size: 20px;
  background: var(--swg-border);
}
.swg-ed__sel {
  font-size: 14px;
  font-weight: 600;
  min-inline-size: 90px;
}
.swg-ed__count {
  margin-inline-start: auto;
  color: var(--swg-muted);
  font-size: 14px;
}
.swg-ed__reason {
  color: var(--swg-muted);
  font-size: 13px;
}
.swg-ed__empty,
.swg-ed__gone {
  background: var(--swg-surface);
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
}
.swg-ed__side {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
  background: var(--swg-surface);
}
@media (max-width: 1100px) {
  .swg-ed__cols {
    grid-template-columns: minmax(0, 1fr);
  }
}
@media (max-width: 520px) {
  .swg-ed__title {
    font-size: 22px;
  }
}
</style>
