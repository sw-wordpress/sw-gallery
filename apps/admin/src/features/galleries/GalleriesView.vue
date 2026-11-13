<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { errorMessage } from '@/api/errorMessage';
import type { GalleryListQuery, GalleryStatus } from '@/api/galleries';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { getConfig } from '@/config';
import { useAppStore } from '@/stores/app';
import GalleryCard from './components/GalleryCard.vue';
import GalleryDuplicateSheet from './components/GalleryDuplicateSheet.vue';
import GalleryFormSheet from './components/GalleryFormSheet.vue';
import GalleryTable from './components/GalleryTable.vue';
import ShortcodeDialog from './components/ShortcodeDialog.vue';
import { formatModified } from './format';
import { useGalleriesApi } from './injection';
import { useGalleryActions } from './useGalleryActions';
import { useGalleryList } from './useGalleryList';

const PER_PAGE = 20;
const SEARCH_DEBOUNCE_MS = 300;

type StatusFilter = 'all' | 'publish' | 'draft' | 'private' | 'trash';
const FILTERS: readonly StatusFilter[] = ['all', 'publish', 'draft', 'private', 'trash'];
/** "All" = the API default (everything except the trash); "Drafts" includes pending review. */
const FILTER_STATUSES: Record<StatusFilter, GalleryStatus[] | undefined> = {
  all: undefined,
  publish: ['publish'],
  draft: ['draft', 'pending'],
  private: ['private'],
  trash: ['trash'],
};

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const app = useAppStore();
const { can } = useCapabilities();
const config = getConfig();
const api = useGalleriesApi();

// --- URL state: page, search and status live in the hash route's query (deep link, reload, back button)
function single(value: unknown): string {
  const first = Array.isArray(value) ? value[0] : value;
  return typeof first === 'string' ? first : '';
}
interface UrlState {
  page: number;
  search: string;
  status: StatusFilter;
}
const current = computed<UrlState>(() => {
  const page = Number.parseInt(single(route.query.page), 10);
  const status = single(route.query.status);
  return {
    page: Number.isFinite(page) && page >= 1 ? Math.min(page, 100000) : 1,
    search: single(route.query.search).trim().slice(0, 200),
    status: (FILTERS as readonly string[]).includes(status) ? (status as StatusFilter) : 'all',
  };
});

function navigate(change: Partial<UrlState>, mode: 'push' | 'replace' = 'push'): void {
  const next = { ...current.value, ...change };
  const query: Record<string, string> = {};
  if (next.page > 1) query.page = String(next.page);
  if (next.search !== '') query.search = next.search;
  if (next.status !== 'all') query.status = next.status;
  void (mode === 'push' ? router.push({ query }) : router.replace({ query }));
}

const query = computed<GalleryListQuery>(() => ({
  search: current.value.search === '' ? undefined : current.value.search,
  status: FILTER_STATUSES[current.value.status],
  page: current.value.page,
  perPage: PER_PAGE,
}));
const list = useGalleryList(api, query);
const actions = useGalleryActions(api, list.reload);
const form = reactive(actions.form);
const duplicate = reactive(actions.duplicate);
const trash = reactive(actions.trash);
const remove = reactive(actions.remove);
const shortcode = reactive(actions.shortcode);

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
// Back/forward or a link changes the URL: keep the input in sync.
watch(
  () => current.value.search,
  (search) => {
    if (search !== searchInput.value.trim()) searchInput.value = search;
  },
);
onBeforeUnmount(() => clearTimeout(searchTimer));

const statusFilter = computed<StatusFilter | null>({
  get: () => current.value.status,
  set: (value) => {
    if (value !== null && value !== current.value.status) navigate({ status: value, page: 1 });
  },
});
const statusOptions = computed(() =>
  FILTERS.map((value) => ({ value, label: t(`galleries.filter.${value}`) })),
);
const viewMode = computed<'grid' | 'table' | null>({
  get: () => app.galleryView,
  set: (value) => {
    if (value !== null) app.setGalleryView(value);
  },
});
const viewOptions = computed(() => [
  { value: 'grid' as const, label: t('galleries.toolbar.viewGrid') },
  { value: 'table' as const, label: t('galleries.toolbar.viewTable') },
]);

// --- results
const canCreate = computed(() => can('edit_posts'));

// The overview's "New gallery" arrives as `?new=1`: open the create sheet once and drop the flag again.
onMounted(() => {
  if (single(route.query.new) !== '1') return;
  const { new: _flag, ...rest } = route.query;
  void router.replace({ query: rest });
  if (canCreate.value) actions.openCreate();
});
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
const modified = (iso: string): string => formatModified(iso, config.locale, config.timezone);

// A page past the end (deleted the last row of the last page, stale link) steps back to the last page.
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

type EmptyKind = 'first' | 'search' | 'trash' | 'filter';
const emptyKind = computed<EmptyKind>(() => {
  if (current.value.search !== '') return 'search';
  if (current.value.status === 'trash') return 'trash';
  if (current.value.status !== 'all') return 'filter';
  return 'first';
});
const filterLabel = computed(() => t(`galleries.filter.${current.value.status}`));

function showAll(): void {
  navigate({ status: 'all', search: '', page: 1 });
}
</script>

<template>
  <div class="swg-galleries">
    <PageHeader :title="t('nav.galleries')" :subtitle="t('galleries.subtitle')">
      <q-btn
        v-if="canCreate"
        color="primary"
        unelevated
        no-caps
        icon="r_add"
        :label="t('galleries.newGallery')"
        @click="actions.openCreate"
      />
    </PageHeader>

    <div class="swg-gtoolbar">
      <q-input
        :model-value="searchInput"
        class="swg-gtoolbar__search"
        type="search"
        outlined
        dense
        autocomplete="off"
        :label="t('galleries.toolbar.search')"
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
            :aria-label="t('galleries.toolbar.clearSearch')"
            @click="clearSearch"
          />
        </template>
      </q-input>
      <SegmentedControl
        v-model="statusFilter"
        :options="statusOptions"
        :legend="t('galleries.toolbar.statusLegend')"
      />
      <SegmentedControl
        v-model="viewMode"
        class="swg-gtoolbar__view"
        :options="viewOptions"
        :legend="t('galleries.toolbar.viewLegend')"
      />
    </div>

    <section :aria-label="t('galleries.list.region')" :aria-busy="list.loading.value">
      <DataState
        :loading="list.loading.value"
        :error="errorText"
        :error-detail="errorDetail"
        :empty="list.items.value.length === 0"
        :error-title="t('galleries.list.errorTitle')"
        :retry-label="t('common.retry')"
        :loading-label="t('galleries.list.loading')"
        variant="cards"
        :rows="6"
        @retry="list.reload()"
      >
        <template #empty>
          <div class="swg-gempty">
            <EmptyState
              v-if="emptyKind === 'search'"
              illustration="search"
              :title="t('galleries.empty.searchTitle')"
              :text="t('galleries.empty.searchText', { search: current.search })"
            >
              <q-btn
                outline
                no-caps
                color="primary"
                :label="t('galleries.empty.clearSearch')"
                @click="clearSearch"
              />
            </EmptyState>
            <EmptyState
              v-else-if="emptyKind === 'trash'"
              illustration="gallery"
              :title="t('galleries.empty.trashTitle')"
              :text="t('galleries.empty.trashText')"
            />
            <EmptyState
              v-else-if="emptyKind === 'filter'"
              illustration="gallery"
              :title="t('galleries.empty.filterTitle', { filter: filterLabel })"
              :text="t('galleries.empty.filterText')"
            >
              <q-btn
                outline
                no-caps
                color="primary"
                :label="t('galleries.empty.showAll')"
                @click="showAll"
              />
            </EmptyState>
            <EmptyState
              v-else
              illustration="gallery"
              :title="t('galleries.empty.firstTitle')"
              :text="t('galleries.empty.firstText')"
            >
              <q-btn
                v-if="canCreate"
                color="primary"
                unelevated
                no-caps
                icon="r_add"
                :label="t('galleries.empty.firstCta')"
                @click="actions.openCreate"
              />
            </EmptyState>
          </div>
        </template>

        <ul v-if="app.galleryView === 'grid'" class="swg-ggrid">
          <GalleryCard
            v-for="gallery in list.items.value"
            :key="gallery.id"
            :gallery="gallery"
            :modified="modified(gallery.modified)"
            @action="actions.run"
          />
        </ul>
        <GalleryTable
          v-else
          :galleries="list.items.value"
          :format-modified="modified"
          @action="actions.run"
        />

        <PaginationBar
          :page="current.page"
          :pages="list.totalPages.value"
          :info="t('galleries.list.count', { count: list.total.value }, list.total.value)"
          :page-label="
            t('galleries.list.pageOf', { page: current.page, pages: list.totalPages.value })
          "
          :nav-label="t('galleries.list.pagination')"
          :previous-label="t('galleries.list.previous')"
          :next-label="t('galleries.list.next')"
          @update:page="(page) => navigate({ page })"
        />
      </DataState>
    </section>

    <GalleryFormSheet
      v-model="form.open"
      :mode="form.mode"
      :gallery="form.target"
      :loading="form.loading"
      :error-message="form.error"
      :field-errors="form.fieldErrors"
      @submit="form.submit"
    />
    <GalleryDuplicateSheet
      v-model="duplicate.open"
      :gallery="duplicate.target"
      :loading="duplicate.loading"
      :error-message="duplicate.error"
      @submit="duplicate.submit"
    />
    <ConfirmDialog
      v-model="trash.open"
      :title="t('galleries.trash.title')"
      :message="t('galleries.trash.message')"
      :targets="trash.target ? [trash.target.title || t('galleries.card.untitled')] : []"
      :targets-label="t('galleries.trash.affects')"
      :consequences="[t('galleries.trash.imagesStay'), t('galleries.trash.restorable')]"
      :confirm-label="t('galleries.trash.confirm')"
      :cancel-label="t('galleries.form.cancel')"
      :loading="actions.confirmLoading.value"
      :error="actions.confirmError.value"
      :danger="false"
      @confirm="trash.confirm"
    />
    <ConfirmDialog
      v-model="remove.open"
      :title="t('galleries.delete.title')"
      :message="t('galleries.delete.message')"
      :targets="remove.target ? [remove.target.title || t('galleries.card.untitled')] : []"
      :targets-label="t('galleries.delete.affects')"
      :consequences="[t('galleries.delete.removes'), t('galleries.delete.imagesStay')]"
      :confirm-label="t('galleries.delete.confirm')"
      :cancel-label="t('galleries.form.cancel')"
      :loading="actions.confirmLoading.value"
      :error="actions.confirmError.value"
      :require-text="remove.target ? remove.target.title.trim() : undefined"
      :require-text-label="
        t('galleries.delete.typeLabel', { title: remove.target?.title.trim() ?? '' })
      "
      @confirm="remove.confirm"
    />
    <ShortcodeDialog v-model="shortcode.open" :shortcode="shortcode.text" />
  </div>
</template>

<style scoped>
.swg-gtoolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
  margin-block-end: 20px;
}
.swg-gtoolbar__search {
  flex: 1 1 260px;
  max-inline-size: 420px;
  background: var(--swg-surface);
  border-radius: var(--swg-radius-sm);
}
.swg-gtoolbar__view {
  margin-inline-start: auto;
}
.swg-ggrid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr));
  margin: 0;
  padding: 0;
}
.swg-gempty {
  background: var(--swg-surface);
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius);
}
@media (max-width: 520px) {
  .swg-gtoolbar__view {
    margin-inline-start: 0;
    inline-size: 100%;
  }
  .swg-gtoolbar__search {
    max-inline-size: none;
  }
}
</style>
