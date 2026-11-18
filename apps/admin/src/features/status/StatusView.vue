<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { errorMessage } from '@/api/errorMessage';
import type { CountedGalleryStatus } from '@/api/status';
import DataState from '@/components/ui/DataState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwgCard from '@/components/ui/SwgCard.vue';
import { getConfig } from '@/config';
import { copyToClipboard, useFeedback } from '@/features/galleries/feedback';
import { formatModified } from '@/features/galleries/format';
import { formatNumber } from '@/utils/format';
import { buildSummary, contextText } from './summary';
import { useStatusApi, useStatusReport } from './useStatusReport';

const GALLERY_STATUSES: readonly CountedGalleryStatus[] = [
  'publish',
  'draft',
  'pending',
  'private',
  'future',
  'trash',
];

const { t } = useI18n();
const config = getConfig();
const feedback = useFeedback();
const status = useStatusReport(useStatusApi());
void status.load();

const errorText = computed(() =>
  status.error.value === null ? null : errorMessage(status.error.value, t),
);
const errorDetail = computed(() => {
  const error = status.error.value;
  return error === null
    ? undefined
    : error.status > 0
      ? `${error.code} (HTTP ${error.status})`
      : error.code;
});
const report = computed(() => status.report.value);
const formatTime = (iso: string): string => formatModified(iso, config.locale, config.timezone);
const fetched = computed(() =>
  status.fetchedAt.value === null
    ? ''
    : formatModified(status.fetchedAt.value.toISOString(), config.locale, config.timezone),
);
const number = (value: number): string => formatNumber(value, config.locale);

const orphans = computed(() => report.value?.counts.orphanRelations ?? null);
/** Honest wording: a skipped scan is not "0", an interrupted scan is a lower bound. */
const orphanTile = computed(() => {
  const o = orphans.value;
  if (o === null) return { value: '–', hint: '', tone: 'default' as const };
  if (o.count === null) {
    return {
      value: t('status.counts.notChecked'),
      hint: t('status.counts.orphansSkipped'),
      tone: 'warning' as const,
    };
  }
  if (!o.complete) {
    return {
      value: t('status.counts.atLeast', { count: number(o.count) }),
      hint: t('status.counts.orphansIncomplete'),
      tone: 'warning' as const,
    };
  }
  return o.count === 0
    ? { value: number(0), hint: t('status.counts.orphansNone'), tone: 'success' as const }
    : { value: number(o.count), hint: t('status.counts.orphansFound'), tone: 'warning' as const };
});

const summary = ref('');
const showSummary = ref(false);
async function copySummary(): Promise<void> {
  if (report.value === null) return;
  summary.value = buildSummary(report.value);
  if (await copyToClipboard(summary.value)) {
    showSummary.value = false;
    feedback.notify(t('status.copy.done'));
  } else {
    // No clipboard (insecure context, refused): show the text so it can be selected by hand.
    showSummary.value = true;
    feedback.notify(t('status.copy.failed'), 'warning');
  }
}
</script>

<template>
  <div class="swg-status">
    <PageHeader :title="t('nav.status')" :subtitle="t('status.subtitle')">
      <q-btn
        outline
        no-caps
        color="primary"
        icon="r_refresh"
        :label="t('status.refresh')"
        :loading="status.loading.value"
        @click="status.load()"
      />
      <q-btn
        outline
        no-caps
        color="primary"
        icon="r_content_copy"
        :label="t('status.copy.button')"
        :disable="report === null"
        @click="copySummary"
      />
    </PageHeader>

    <DataState
      :loading="status.loading.value"
      :error="errorText"
      :error-detail="errorDetail"
      :empty="report === null"
      :error-title="t('status.errorTitle')"
      :retry-label="t('common.retry')"
      :loading-label="t('status.loading')"
      variant="cards"
      :rows="4"
      @retry="status.load()"
    >
      <div v-if="report" class="swg-status__sections">
        <p class="swg-muted swg-status__fetched swg-num">
          {{ t('status.fetched', { time: fetched }) }}
        </p>

        <div v-if="showSummary" class="swg-status__summary">
          <label for="swg-status-summary">{{ t('status.copy.manual') }}</label>
          <textarea id="swg-status-summary" readonly rows="14" :value="summary" />
        </div>

        <SwgCard :title="t('status.schema.title')" icon="r_storage" tag="section">
          <ul class="swg-status__checks">
            <li>
              <StatusChip
                v-if="report.schema.newerThanCode"
                kind="danger"
                :label="t('status.schema.newerThanCode')"
              />
              <StatusChip
                v-else
                :kind="report.schema.migrationPending ? 'warning' : 'success'"
                :label="
                  report.schema.migrationPending
                    ? t('status.schema.pending')
                    : t('status.schema.upToDate')
                "
              />
              <span class="swg-muted swg-num">{{
                t('status.schema.versions', {
                  stored: report.schema.stored,
                  current: report.schema.current,
                })
              }}</span>
            </li>
            <li>
              <StatusChip
                :kind="report.schema.itemTablePresent ? 'success' : 'danger'"
                :label="
                  report.schema.itemTablePresent
                    ? t('status.schema.tablePresent')
                    : t('status.schema.tableMissing')
                "
              />
            </li>
          </ul>
        </SwgCard>

        <SwgCard :title="t('status.counts.title')" icon="r_bar_chart" tag="section">
          <h4 class="swg-eyebrow swg-status__sub">{{ t('status.counts.galleries') }}</h4>
          <div class="swg-grid swg-status__tiles">
            <StatTile
              v-for="key in GALLERY_STATUSES.filter(
                (k) => report?.counts.galleries[k] !== undefined,
              )"
              :key="key"
              :label="t(`status.counts.status.${key}`)"
              :value="number(report.counts.galleries[key] ?? 0)"
            />
          </div>
          <h4 class="swg-eyebrow swg-status__sub">{{ t('status.counts.relations') }}</h4>
          <div class="swg-grid swg-status__tiles">
            <StatTile
              :label="t('status.counts.itemRows')"
              :value="
                report.counts.itemRows.capped
                  ? t('status.counts.atLeast', { count: number(report.counts.itemRows.count) })
                  : number(report.counts.itemRows.count)
              "
              :hint="report.counts.itemRows.capped ? t('status.counts.capped') : undefined"
              :tone="report.counts.itemRows.capped ? 'warning' : 'default'"
            />
            <StatTile
              :label="t('status.counts.orphans')"
              :value="orphanTile.value"
              :hint="orphanTile.hint"
              :tone="orphanTile.tone"
            />
          </div>
        </SwgCard>

        <SwgCard :title="t('status.errors.title')" icon="r_error" tag="section">
          <EmptyState
            v-if="report.recentErrors.length === 0"
            compact
            illustration="gallery"
            :title="t('status.errors.noneTitle')"
            :text="t('status.errors.noneText')"
          />
          <ul v-else class="swg-status__errors" :aria-label="t('status.errors.title')">
            <li v-for="(error, index) in report.recentErrors" :key="index">
              <time class="swg-num swg-muted" :datetime="error.time">{{
                formatTime(error.time)
              }}</time>
              <code>{{ error.code }}</code>
              <span v-if="contextText(error.context) !== ''" class="swg-mono swg-muted">{{
                contextText(error.context)
              }}</span>
            </li>
          </ul>
        </SwgCard>

        <SwgCard :title="t('status.versions.title')" icon="r_info" tag="section">
          <dl class="swg-status__dl">
            <div>
              <dt>{{ t('status.versions.plugin') }}</dt>
              <dd class="swg-num">{{ report.versions.plugin }}</dd>
            </div>
            <div>
              <dt>{{ t('status.versions.wordpress') }}</dt>
              <dd class="swg-num">{{ report.versions.wordpress }}</dd>
            </div>
            <div>
              <dt>{{ t('status.versions.php') }}</dt>
              <dd class="swg-num">{{ report.versions.php }}</dd>
            </div>
            <div>
              <dt>{{ t('status.versions.database') }}</dt>
              <dd class="swg-num">
                {{ report.versions.database.type }} {{ report.versions.database.version }}
              </dd>
            </div>
            <div>
              <dt>{{ t('status.versions.multisite') }}</dt>
              <dd>{{ report.multisite ? t('status.yes') : t('status.no') }}</dd>
            </div>
          </dl>
        </SwgCard>

        <SwgCard :title="t('status.runtime.title')" icon="r_memory" tag="section">
          <ul class="swg-status__checks">
            <li>
              <StatusChip
                :kind="report.cache.state === 'none' ? 'neutral' : 'info'"
                :label="
                  report.cache.state === 'none'
                    ? t('status.runtime.cacheNone')
                    : t('status.runtime.cacheState', { state: report.cache.state })
                "
              />
              <span class="swg-muted">{{ t('status.runtime.cacheHint') }}</span>
            </li>
            <li>
              <StatusChip
                :kind="report.adminBuild ? 'success' : 'danger'"
                :label="
                  report.adminBuild ? t('status.runtime.buildOk') : t('status.runtime.buildMissing')
                "
              />
              <span v-if="!report.adminBuild" class="swg-muted">{{
                t('status.runtime.buildHint')
              }}</span>
            </li>
          </ul>
        </SwgCard>

        <SwgCard
          v-if="report.hints.length > 0"
          :title="t('status.hints.title')"
          icon="r_lightbulb"
          tag="section"
        >
          <ul class="swg-status__hints">
            <li v-for="hint in report.hints" :key="hint.key">{{ hint.message }}</li>
          </ul>
        </SwgCard>
      </div>
    </DataState>
  </div>
</template>

<style scoped>
.swg-status__sections {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.swg-status__fetched {
  font-size: 13px;
}
.swg-status__sub {
  margin-block: 4px 10px;
  /* Scoped, because the global Quasar heading rule (`.swg-root h4`) outranks the plain `.swg-eyebrow` class. */
  font-size: 12px;
  font-weight: 600;
  line-height: 1.4;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--swg-muted);
}
.swg-status__tiles {
  margin-block-end: 14px;
}
.swg-status__checks {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.swg-status__checks li {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 12px;
}
.swg-status__errors {
  display: flex;
  flex-direction: column;
}
.swg-status__errors li {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px 14px;
  padding-block: 8px;
  border-block-end: 1px solid var(--swg-border);
  font-size: 14px;
}
.swg-status__errors li:last-child {
  border-block-end: 0;
}
.swg-status__dl {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 14px;
  margin: 0;
}
.swg-status__dl dt {
  font-size: 13px;
  font-weight: 600;
  color: var(--swg-muted);
}
.swg-status__dl dd {
  margin: 0;
  font-weight: 600;
}
.swg-status__hints {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-inline-size: 70ch;
}
.swg-status__summary {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.swg-status__summary textarea {
  inline-size: 100%;
  padding: 10px;
  border: 1px solid var(--swg-border);
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface);
  color: var(--swg-text);
  font-family: var(--swg-mono);
  font-size: 13px;
}
</style>
