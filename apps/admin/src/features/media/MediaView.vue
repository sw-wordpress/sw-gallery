<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { errorMessage } from '@/api/errorMessage';
import type { MediaLibraryItem, MediaListQuery, MediaOrder, MediaOrderBy } from '@/api/media';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { getConfig } from '@/config';
import { useItemSelection } from '@/features/editor/useItemSelection';
import { useUploads } from '@/features/editor/useUploads';
import { useFeedback } from '@/features/galleries/feedback';
import { formatModified } from '@/features/galleries/format';
import { useAppStore } from '@/stores/app';
import AddToGalleryDialog from './components/AddToGalleryDialog.vue';
import MediaDetailsSheet from './components/MediaDetailsSheet.vue';
import MediaTable from './components/MediaTable.vue';
import MediaTile from './components/MediaTile.vue';
import MediaUploadPanel from './components/MediaUploadPanel.vue';
import { useMediaFeatureApi } from './injection';
import { useAddToGallery } from './useAddToGallery';
import type { AddToGalleryResult } from './useAddToGallery';
import { useMediaList } from './useMediaList';

const PER_PAGE = 40;
const SEARCH_DEBOUNCE_MS = 300;
type SortValue = 'date:desc' | 'date:asc' | 'title:asc' | 'title:desc';
const SORTS: readonly SortValue[] = ['date:desc', 'date:asc', 'title:asc', 'title:desc'];

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const app = useAppStore();
const { can } = useCapabilities();
const config = getConfig();
const api = useMediaFeatureApi();
const feedback = useFeedback();

// --- URL state: page, search, sort and uploader live in the hash route's query (deep link, reload, back button)
function single(value: unknown): string {
  const first = Array.isArray(value) ? value[0] : value;
  return typeof first === 'string' ? first : '';
}
/** Newest first for dates, A-Z for titles. */
function defaultOrder(orderby: MediaOrderBy): MediaOrder {
  return orderby === 'title' ? 'asc' : 'desc';
}
interface UrlState {
  page: number;
  search: string;
  orderby: MediaOrderBy;
  order: MediaOrder;
  uploadedBy: number | null;
}
const current = computed<UrlState>(() => {
  const page = Number.parseInt(single(route.query.page), 10);
  const uploader = Number.parseInt(single(route.query.uploadedBy), 10);
  const orderby = single(route.query.orderby);
  const order = single(route.query.order);
  const by: MediaOrderBy = orderby === 'title' ? 'title' : 'date';
  return {
    page: Number.isFinite(page) && page >= 1 ? Math.min(page, 100000) : 1,
    search: single(route.query.search).trim().slice(0, 200),
    orderby: by,
    order: order === 'asc' || order === 'desc' ? order : defaultOrder(by),
    uploadedBy: Number.isFinite(uploader) && uploader > 0 ? uploader : null,
  };
});

function navigate(change: Partial<UrlState>, mode: 'push' | 'replace' = 'push'): void {
  const next = { ...current.value, ...change };
  const query: Record<string, string> = {};
  if (next.page > 1) query.page = String(next.page);
  if (next.search !== '') query.search = next.search;
  // Defaults stay out of the URL: date/desc; a title sort reads A-Z unless the order is explicit.
  if (next.orderby !== 'date') query.orderby = next.orderby;
  if (next.order !== defaultOrder(next.orderby)) query.order = next.order;
  if (next.uploadedBy !== null) query.uploadedBy = String(next.uploadedBy);
  void (mode === 'push' ? router.push({ query }) : router.replace({ query }));
}

const query = computed<MediaListQuery>(() => ({
  search: current.value.search === '' ? undefined : current.value.search,
  orderby: current.value.orderby,
  order: current.value.order,
  uploadedBy: can('edit_others_posts') ? (current.value.uploadedBy ?? undefined) : undefined,
  page: current.value.page,
  perPage: PER_PAGE,
}));
const list = useMediaList(api.media, query);

// --- toolbar
const searchInput = ref(current.value.search);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
function applySearch(): void {
  clearTimeout(searchTimer);
  const search = searchInput.value.trim();
  if (search !== current.value.search) navigate({ search, page: 1 }, 'replace');
}
function onSearch(value: string | number | null): void {
  searchInput.value = value === null ? '' : String(value);
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applySearch, SEARCH_DEBOUNCE_MS);
}
function clearSearch(): void {
  searchInput.value = '';
  applySearch();
}
watch(
  () => current.value.search,
  (search) => {
    if (search !== searchInput.value.trim()) searchInput.value = search;
  },
);

const uploaderInput = ref(
  current.value.uploadedBy === null ? '' : String(current.value.uploadedBy),
);
let uploaderTimer: ReturnType<typeof setTimeout> | undefined;
function onUploader(value: string | number | null): void {
  uploaderInput.value = value === null ? '' : String(value);
  clearTimeout(uploaderTimer);
  uploaderTimer = setTimeout(() => {
    const id = Number.parseInt(uploaderInput.value, 10);
    const next = Number.isFinite(id) && id > 0 ? id : null;
    if (next !== current.value.uploadedBy) navigate({ uploadedBy: next, page: 1 }, 'replace');
  }, SEARCH_DEBOUNCE_MS);
}
watch(
  () => current.value.uploadedBy,
  (id) => {
    const text = id === null ? '' : String(id);
    if (text !== uploaderInput.value.trim()) uploaderInput.value = text;
  },
);
onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  clearTimeout(uploaderTimer);
});

const sortValue = computed<SortValue>(
  () => `${current.value.orderby}:${current.value.order}` as SortValue,
);
function onSort(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  if (!(SORTS as readonly string[]).includes(value)) return;
  const [orderby, order] = value.split(':') as [MediaOrderBy, MediaOrder];
  navigate({ orderby, order, page: 1 });
}
const viewMode = computed<'grid' | 'table' | null>({
  get: () => app.mediaView,
  set: (value) => {
    if (value !== null) app.setMediaView(value);
  },
});
const viewOptions = computed(() => [
  { value: 'grid' as const, label: t('media.toolbar.viewGrid') },
  { value: 'table' as const, label: t('media.toolbar.viewTable') },
]);

// --- results
const forbidden = computed(() => list.error.value !== null && list.error.value.status === 403);
const errorText = computed(() =>
  list.error.value === null ? null : errorMessage(list.error.value, t),
);
const errorDetail = computed(() => {
  const error = list.error.value;
  return error === null
    ? undefined
    : error.status > 0
      ? `${error.code} (HTTP ${error.status})`
      : error.code;
});
const formatDate = (iso: string): string => formatModified(iso, config.locale, config.timezone);

// A page past the end (stale link) steps back to the last page.
watch(
  () => list.settled.value,
  (settled) => {
    if (
      settled &&
      list.error.value === null &&
      list.items.value.length === 0 &&
      current.value.page > 1
    ) {
      navigate({ page: Math.max(1, list.totalPages.value) }, 'replace');
    }
  },
);

const filtered = computed(
  () =>
    current.value.search !== '' || (can('edit_others_posts') && current.value.uploadedBy !== null),
);
function resetFilters(): void {
  searchInput.value = '';
  navigate({ search: '', uploadedBy: null, page: 1 });
}

// --- selection (ids on the current page; a new page or query forgets the rest)
const order = computed(() => list.items.value.map((item) => item.id));
const selection = useItemSelection(order);
watch(order, () => selection.prune());
const allState = computed<'true' | 'false' | 'mixed'>(() => {
  if (selection.count.value === 0) return 'false';
  return selection.count.value === order.value.length ? 'true' : 'mixed';
});
function toggleAll(): void {
  if (allState.value === 'true') selection.clear();
  else selection.selectAll();
}
function onToggle(index: number, event: MouseEvent): void {
  if (event.shiftKey) selection.range(index);
  else selection.toggle(index);
}
function onGridKeydown(event: KeyboardEvent): void {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a') {
    event.preventDefault();
    selection.selectAll();
  } else if (event.key === 'Escape' && selection.count.value > 0) {
    selection.clear();
  }
}
let quietClear = false;
watch(
  () => selection.count.value,
  (count, before) => {
    if (quietClear) {
      quietClear = false;
      return;
    }
    if (count !== before) {
      app.announce(
        count === 0 ? t('media.selection.none') : t('media.selection.count', { count }, count),
      );
    }
  },
);

// --- details sheet
const detailsOpen = ref(false);
const detailsId = ref<number | null>(null);
const detailsItem = computed<MediaLibraryItem | null>(
  () => list.items.value.find((item) => item.id === detailsId.value) ?? null,
);
function openDetails(index: number): void {
  const item = list.items.value[index];
  if (item === undefined) return;
  detailsId.value = item.id;
  detailsOpen.value = true;
}

// --- add to gallery
function onAdded(result: AddToGalleryResult): void {
  const parts = [t('media.add.added', { count: result.added }, result.added)];
  if (result.alreadyAssigned > 0) {
    parts.push(t('media.add.already', { count: result.alreadyAssigned }));
  }
  if (result.missing > 0) parts.push(t('media.add.missing', { count: result.missing }));
  feedback.notify(
    t('media.add.result', {
      gallery: result.gallery.title || t('galleries.card.untitled'),
      details: parts.join(', '),
    }),
    result.added > 0 ? 'success' : 'warning',
  );
  if (result.added > 0 && selection.count.value > 0) {
    quietClear = true; // the result message is the one announcement
    selection.clear();
  }
  detailsOpen.value = false;
  void list.reload(); // usage counts changed
}
const addFlow = useAddToGallery(api, onAdded);
function addSelected(): void {
  addFlow.openFor([...selection.selected.value]);
}
function addOne(item: MediaLibraryItem): void {
  addFlow.openFor([item.id]);
}

// --- upload (drop anywhere on the list section, or the button)
const uploads = useUploads(
  api.media,
  (ids) => Promise.resolve({ ok: true, added: ids.length, alreadyAssigned: 0, missing: 0 }),
  ({ uploaded, failed }) => {
    if (uploaded > 0) {
      feedback.notify(t('media.upload.summary', { uploaded, failed }, uploaded));
      void list.reload();
    }
  },
);
const dragging = ref(false);
let dragDepth = 0;
function hasFiles(event: DragEvent): boolean {
  return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}
function onDragEnter(event: DragEvent): void {
  if (!hasFiles(event)) return;
  dragDepth += 1;
  dragging.value = true;
}
function onDragLeave(event: DragEvent): void {
  if (!hasFiles(event)) return;
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) dragging.value = false;
}
function onDrop(event: DragEvent): void {
  dragDepth = 0;
  dragging.value = false;
  uploads.add(Array.from(event.dataTransfer?.files ?? []));
}
</script>

<template>
  <div class="swg-media">
    <PageHeader :title="t('nav.media')" :subtitle="t('media.subtitle')" />

    <EmptyState
      v-if="forbidden"
      illustration="media"
      :title="t('media.forbidden.title')"
      :text="t('media.forbidden.text')"
    />
    <section
      v-else
      :aria-label="t('media.region')"
      :aria-busy="list.loading.value"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent
      @dragleave="onDragLeave"
      @drop.prevent="onDrop"
    >
      <MediaUploadPanel :uploads="uploads" :dragging="dragging" />

      <div class="swg-mtoolbar">
        <q-input
          :model-value="searchInput"
          class="swg-mtoolbar__search"
          type="search"
          outlined
          dense
          autocomplete="off"
          :label="t('media.toolbar.search')"
          @update:model-value="onSearch"
          @keyup.enter="applySearch"
        >
          <template #prepend><q-icon name="r_search" aria-hidden="true" /></template>
          <template v-if="searchInput !== ''" #append>
            <q-btn
              flat
              round
              dense
              size="sm"
              icon="r_close"
              :aria-label="t('media.toolbar.clearSearch')"
              @click="clearSearch"
            />
          </template>
        </q-input>
        <label class="swg-mtoolbar__sort">
          <span class="swg-sr-only">{{ t('media.toolbar.sort') }}</span>
          <select :value="sortValue" :aria-label="t('media.toolbar.sort')" @change="onSort">
            <option v-for="s in SORTS" :key="s" :value="s">
              {{ t(`media.sort.${s.replace(':', '_')}`) }}
            </option>
          </select>
        </label>
        <q-input
          v-if="can('edit_others_posts')"
          :model-value="uploaderInput"
          class="swg-mtoolbar__user"
          type="number"
          outlined
          dense
          autocomplete="off"
          :label="t('media.toolbar.uploader')"
          @update:model-value="onUploader"
        />
        <SegmentedControl
          v-model="viewMode"
          class="swg-mtoolbar__view"
          :options="viewOptions"
          :legend="t('media.toolbar.viewLegend')"
        />
      </div>

      <div class="swg-msel">
        <button
          type="button"
          role="checkbox"
          class="swg-msel__all"
          :aria-checked="allState"
          :disabled="list.items.value.length === 0"
          @click="toggleAll"
        >
          <q-icon
            :name="
              allState === 'true'
                ? 'r_check_box'
                : allState === 'mixed'
                  ? 'r_indeterminate_check_box'
                  : 'r_check_box_outline_blank'
            "
            size="24px"
            aria-hidden="true"
          />
          <span>{{ t('media.selectAllPage') }}</span>
        </button>
        <span class="swg-msel__count swg-num">{{
          selection.count.value > 0
            ? t('media.selection.count', { count: selection.count.value }, selection.count.value)
            : t('media.selection.hint')
        }}</span>
        <template v-if="selection.count.value > 0">
          <q-btn
            unelevated
            no-caps
            color="primary"
            icon="r_playlist_add"
            :label="t('media.addToGallery')"
            @click="addSelected"
          />
          <q-btn flat no-caps :label="t('media.selection.clear')" @click="selection.clear()" />
        </template>
      </div>

      <DataState
        :loading="list.loading.value"
        :error="errorText"
        :error-detail="errorDetail"
        :empty="list.items.value.length === 0"
        :error-title="t('media.list.errorTitle')"
        :retry-label="t('common.retry')"
        :loading-label="t('media.list.loading')"
        variant="cards"
        :rows="8"
        @retry="list.reload()"
      >
        <template #empty>
          <div class="swg-mempty">
            <EmptyState
              v-if="filtered"
              illustration="search"
              :title="t('media.empty.filteredTitle')"
              :text="t('media.empty.filteredText')"
            >
              <q-btn
                outline
                no-caps
                color="primary"
                :label="t('media.empty.reset')"
                @click="resetFilters"
              />
            </EmptyState>
            <EmptyState
              v-else
              illustration="media"
              :title="t('media.empty.firstTitle')"
              :text="t('media.empty.firstText')"
            />
          </div>
        </template>

        <ul
          v-if="app.mediaView === 'grid'"
          class="swg-mgrid"
          :aria-label="t('media.list.region')"
          @keydown="onGridKeydown"
        >
          <MediaTile
            v-for="(item, index) in list.items.value"
            :key="item.id"
            :item="item"
            :selected="selection.isSelected(item.id)"
            @toggle="(event) => onToggle(index, event)"
            @open="openDetails(index)"
          />
        </ul>
        <MediaTable
          v-else
          :items="list.items.value"
          :selected="selection.selected.value"
          :all-state="allState"
          :format-date="formatDate"
          @toggle="onToggle"
          @toggle-all="toggleAll"
          @open="openDetails"
        />

        <PaginationBar
          :page="current.page"
          :pages="list.totalPages.value"
          :info="t('media.list.count', { count: list.total.value }, list.total.value)"
          :page-label="t('media.list.pageOf', { page: current.page, pages: list.totalPages.value })"
          :nav-label="t('media.list.pagination')"
          :previous-label="t('media.list.previous')"
          :next-label="t('media.list.next')"
          @update:page="(page) => navigate({ page })"
        />
      </DataState>
    </section>

    <MediaDetailsSheet
      v-model="detailsOpen"
      :item="detailsItem"
      :format-date="formatDate"
      @add="addOne"
    />
    <AddToGalleryDialog :flow="addFlow" />
  </div>
</template>

<style scoped>
.swg-mtoolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
  margin-block-end: 12px;
}
.swg-mtoolbar__search {
  flex: 1 1 260px;
  max-inline-size: 420px;
  background: var(--swg-surface);
  border-radius: var(--swg-radius-sm);
}
.swg-mtoolbar__user {
  inline-size: 170px;
  background: var(--swg-surface);
  border-radius: var(--swg-radius-sm);
}
.swg-mtoolbar__sort select {
  min-block-size: 40px;
  padding: 0 12px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
  color: var(--swg-text);
  font: inherit;
}
.swg-mtoolbar__view {
  margin-inline-start: auto;
}
.swg-msel {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 14px;
  min-block-size: 44px;
  margin-block-end: 12px;
}
.swg-msel__all {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-block-size: 36px;
  padding: 0 8px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--swg-text);
  font: inherit;
  font-size: 14px;
  cursor: pointer;
}
.swg-msel__all:disabled {
  cursor: default;
  opacity: 0.6;
}
.swg-msel__all .q-icon {
  color: var(--swg-primary-text);
}
.swg-msel__count {
  font-size: 14px;
  color: var(--swg-muted);
}
.swg-mgrid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 170px), 1fr));
  margin: 0;
  padding: 0;
}
.swg-mempty {
  background: var(--swg-surface);
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
}
@media (max-width: 520px) {
  .swg-mtoolbar__view {
    margin-inline-start: 0;
    inline-size: 100%;
  }
  .swg-mtoolbar__search {
    max-inline-size: none;
  }
}
</style>
