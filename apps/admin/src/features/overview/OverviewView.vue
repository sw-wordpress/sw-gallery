<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { errorMessage } from '@/api/errorMessage';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwgCard from '@/components/ui/SwgCard.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { getConfig } from '@/config';
import GalleryCover from '@/features/galleries/components/GalleryCover.vue';
import GalleryStatusChip from '@/features/galleries/components/GalleryStatusChip.vue';
import { editorPath, formatModified } from '@/features/galleries/format';
import { useGalleriesApi } from '@/features/galleries/injection';
import { useStatusApi } from '@/features/status/useStatusReport';
import { useOverview } from './useOverview';

const { t } = useI18n();
const router = useRouter();
const { can } = useCapabilities();
const config = getConfig();

const isManager = can('manage_options');
const canCreate = can('edit_posts');
const canMedia = can('upload_files');
/** Users who see every owner's galleries read "Galleries"; everybody else only counts their own. */
const seesAll = can('edit_others_posts');

const overview = useOverview(useGalleriesApi(), isManager ? useStatusApi() : null, isManager);
void overview.load();

const errorText = computed(() =>
  overview.error.value === null ? null : errorMessage(overview.error.value, t),
);
const errorDetail = computed(() => {
  const error = overview.error.value;
  return error === null
    ? undefined
    : error.status > 0
      ? `${error.code} (HTTP ${error.status})`
      : error.code;
});
const modified = (iso: string): string => formatModified(iso, config.locale, config.timezone);

/** "New gallery" opens the create sheet of the gallery list (`?new=1`, see GalleriesView). */
function newGallery(): void {
  void router.push({ path: '/galleries', query: { new: '1' } });
}

const trashed = computed(() => overview.report.value?.counts.galleries.trash);
const health = computed(() => {
  const report = overview.report.value;
  if (report === null) return null;
  return {
    schema: report.schema.migrationPending
      ? t('overview.health.migrationPending')
      : t('overview.health.schemaOk'),
    schemaKind: report.schema.migrationPending ? ('warning' as const) : ('success' as const),
    errors: report.recentErrors.length,
    attention: !report.schema.itemTablePresent || !report.adminBuild,
  };
});
</script>

<template>
  <div class="swg-overview">
    <PageHeader :title="t('nav.overview')" :subtitle="t('overview.subtitle')" />

    <DataState
      :loading="overview.loading.value"
      :error="errorText"
      :error-detail="errorDetail"
      :empty="overview.counts.value === null"
      :error-title="t('overview.errorTitle')"
      :retry-label="t('common.retry')"
      :loading-label="t('overview.loading')"
      variant="cards"
      :rows="4"
      @retry="overview.load()"
    >
      <div v-if="overview.counts.value" class="swg-overview__stack">
        <section :aria-label="t('overview.counts.region')">
          <div class="swg-grid swg-overview__tiles">
            <StatTile
              :label="seesAll ? t('overview.counts.all') : t('overview.counts.mine')"
              :value="String(overview.counts.value.all)"
              icon="r_photo_library"
            />
            <StatTile
              :label="t('overview.counts.publish')"
              :value="String(overview.counts.value.publish)"
              icon="r_check_circle"
              tone="success"
            />
            <StatTile
              :label="t('overview.counts.draft')"
              :value="String(overview.counts.value.draft)"
              icon="r_edit_note"
            />
            <StatTile
              :label="t('overview.counts.private')"
              :value="String(overview.counts.value.private)"
              icon="r_lock"
            />
            <StatTile
              v-if="trashed !== undefined"
              :label="t('overview.counts.trash')"
              :value="String(trashed)"
              icon="r_delete"
            />
          </div>
        </section>

        <div class="swg-overview__cols">
          <SwgCard :title="t('overview.recent.title')" icon="r_history" tag="section">
            <EmptyState
              v-if="overview.recent.value.length === 0"
              compact
              illustration="gallery"
              :title="t('overview.recent.emptyTitle')"
              :text="t('overview.recent.emptyText')"
            >
              <q-btn
                v-if="canCreate"
                color="primary"
                unelevated
                no-caps
                icon="r_add"
                :label="t('galleries.newGallery')"
                @click="newGallery"
              />
            </EmptyState>
            <ul v-else class="swg-overview__recent">
              <li
                v-for="gallery in overview.recent.value"
                :key="gallery.id"
                v-bind="{ 'data-gallery-id': gallery.id }"
              >
                <GalleryCover :url="gallery.coverUrl" small />
                <div class="swg-overview__rtext">
                  <router-link
                    v-if="editorPath(gallery)"
                    :to="editorPath(gallery) ?? ''"
                    class="swg-overview__rtitle"
                    >{{ gallery.title || t('galleries.card.untitled') }}</router-link
                  >
                  <span v-else class="swg-overview__rtitle">{{
                    gallery.title || t('galleries.card.untitled')
                  }}</span>
                  <span class="swg-muted swg-num swg-overview__rmeta">
                    {{
                      t('galleries.card.images', { count: gallery.itemCount }, gallery.itemCount)
                    }}
                    ·
                    <time :datetime="gallery.modified">{{ modified(gallery.modified) }}</time>
                  </span>
                </div>
                <GalleryStatusChip :gallery="gallery" />
              </li>
            </ul>
            <template #footer>
              <router-link to="/galleries" class="swg-overview__all">{{
                t('overview.recent.all')
              }}</router-link>
            </template>
          </SwgCard>

          <div class="swg-overview__side">
            <SwgCard :title="t('overview.actions.title')" icon="r_bolt" tag="section">
              <ul class="swg-overview__actions">
                <li v-if="canCreate">
                  <q-btn
                    unelevated
                    no-caps
                    color="primary"
                    icon="r_add"
                    :label="t('galleries.newGallery')"
                    @click="newGallery"
                  />
                </li>
                <li>
                  <q-btn
                    outline
                    no-caps
                    color="primary"
                    icon="r_photo_library"
                    :label="t('overview.actions.galleries')"
                    to="/galleries"
                  />
                </li>
                <li v-if="canMedia">
                  <q-btn
                    outline
                    no-caps
                    color="primary"
                    icon="r_perm_media"
                    :label="t('overview.actions.media')"
                    to="/media"
                  />
                </li>
              </ul>
            </SwgCard>

            <SwgCard
              v-if="isManager"
              :title="t('overview.health.title')"
              icon="r_monitor_heart"
              tag="section"
            >
              <p
                v-if="overview.statusLoading.value && health === null"
                class="swg-muted"
                role="status"
              >
                {{ t('overview.health.loading') }}
              </p>
              <div v-else-if="overview.statusError.value" class="swg-overview__health-error">
                <span>{{ t('overview.health.unavailable') }}</span>
                <q-btn
                  flat
                  dense
                  no-caps
                  color="primary"
                  :label="t('common.retry')"
                  @click="overview.load()"
                />
              </div>
              <ul v-else-if="health" class="swg-overview__health">
                <li>
                  <StatusChip :kind="health.schemaKind" :label="health.schema" />
                </li>
                <li v-if="health.attention">
                  <StatusChip kind="danger" :label="t('overview.health.attention')" />
                </li>
                <li>
                  <StatusChip
                    :kind="health.errors > 0 ? 'warning' : 'success'"
                    :label="
                      health.errors > 0
                        ? t('overview.health.errors', { count: health.errors }, health.errors)
                        : t('overview.health.noErrors')
                    "
                  />
                </li>
              </ul>
              <template #footer>
                <router-link to="/status" class="swg-overview__all">{{
                  t('overview.health.link')
                }}</router-link>
              </template>
            </SwgCard>
          </div>
        </div>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.swg-overview__stack {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.swg-overview__cols {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}
.swg-overview__side {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.swg-overview__recent {
  display: flex;
  flex-direction: column;
}
.swg-overview__recent li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-block: 10px;
  border-block-end: 1px solid var(--swg-border);
}
.swg-overview__recent li:last-child {
  border-block-end: 0;
}
.swg-overview__rtext {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-inline-size: 0;
}
.swg-overview__rtitle {
  font-weight: 600;
  overflow-wrap: anywhere;
}
.swg-overview__rmeta {
  font-size: 13px;
}
.swg-overview__actions {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
}
.swg-overview__health {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
}
.swg-overview__health-error {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.swg-overview__all {
  font-weight: 600;
}
@media (max-width: 960px) {
  .swg-overview__cols {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
