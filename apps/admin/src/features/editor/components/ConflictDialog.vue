<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { uid } from '@/utils/ids';
import type { ConflictState } from '../useGalleryEditor';

/**
 * 409 `swg_revision_conflict` (M2-32): explains that someone else changed the gallery, shows what
 * differs and offers to re-apply the staged changes onto the fresh state or to discard them.
 * Staged texts are never dropped silently: every deviation is listed here first.
 */
const props = defineProps<{ state: ConflictState | null }>();
const emit = defineEmits<{
  (e: 'reapply'): void;
  (e: 'discard'): void;
  (e: 'dismiss'): void;
}>();
const { t } = useI18n();
const titleId = uid('swg-conflict');

const open = computed(() => props.state !== null);
const server = computed(() => {
  const diff = props.state?.diff;
  if (diff === undefined) return [];
  const lines: string[] = [];
  if (diff.detailKeys.length > 0) {
    lines.push(
      t('editor.conflict.details', {
        fields: diff.detailKeys.map((k) => t(`editor.conflict.field.${k}`)).join(', '),
      }),
    );
  }
  if (diff.itemsAdded > 0)
    lines.push(t('editor.conflict.added', { count: diff.itemsAdded }, diff.itemsAdded));
  if (diff.itemsRemoved > 0) {
    lines.push(t('editor.conflict.removed', { count: diff.itemsRemoved }, diff.itemsRemoved));
  }
  if (diff.itemsMoved > 0)
    lines.push(t('editor.conflict.moved', { count: diff.itemsMoved }, diff.itemsMoved));
  if (diff.overridesChanged > 0) {
    lines.push(t('editor.conflict.texts', { count: diff.overridesChanged }, diff.overridesChanged));
  }
  if (lines.length === 0) lines.push(t('editor.conflict.nothing'));
  return lines;
});
const notices = computed(() => {
  const n = props.state?.notices;
  if (n === undefined) return [];
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
</script>

<template>
  <q-dialog
    :model-value="open"
    persistent
    role="alertdialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    @keyup.esc="emit('dismiss')"
  >
    <q-card v-if="state" class="swg-conf">
      <q-card-section class="swg-conf__head">
        <span class="swg-conf__icon" aria-hidden="true"
          ><q-icon name="r_sync_problem" size="24px"
        /></span>
        <div>
          <h2 :id="titleId" class="swg-conf__title">{{ t('editor.conflict.title') }}</h2>
          <p class="swg-conf__msg">
            {{ t('editor.conflict.message', { title: state.serverTitle }) }}
          </p>
        </div>
      </q-card-section>
      <q-card-section class="swg-conf__body">
        <section>
          <h3 class="swg-conf__h">{{ t('editor.conflict.serverChanges') }}</h3>
          <ul class="swg-conf__list">
            <li v-for="line in server" :key="line">{{ line }}</li>
          </ul>
        </section>
        <section>
          <h3 class="swg-conf__h">{{ t('editor.conflict.yours') }}</h3>
          <p>{{ t('editor.conflict.staged', { count: state.stagedCount }, state.stagedCount) }}</p>
          <ul v-if="notices.length > 0" class="swg-conf__list swg-conf__notes">
            <li v-for="line in notices" :key="line">{{ line }}</li>
          </ul>
        </section>
      </q-card-section>
      <q-card-actions align="right" class="swg-conf__actions">
        <q-btn
          flat
          no-caps
          :label="t('editor.conflict.notNow')"
          data-autofocus
          @click="emit('dismiss')"
        />
        <q-btn
          outline
          no-caps
          color="negative"
          :label="t('editor.conflict.discard')"
          @click="emit('discard')"
        />
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="t('editor.conflict.reapply')"
          @click="emit('reapply')"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swg-conf {
  width: min(560px, calc(100vw - 24px));
  max-width: none;
}
.swg-conf__head {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}
.swg-conf__icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--swg-warning-bg);
  color: var(--swg-warning-text);
}
.swg-conf__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.swg-conf__msg {
  margin-block-start: 4px;
  color: var(--swg-muted);
}
.swg-conf__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding-block-start: 0;
}
.swg-conf__h {
  font-size: 12px;
  font-weight: 600;
  line-height: 1.4;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--swg-muted);
}
.swg-conf__list {
  margin: 4px 0 0;
  padding-inline-start: 20px;
  list-style: disc;
  font-size: 14px;
}
.swg-conf__notes {
  color: var(--swg-warning-text);
}
.swg-conf__actions {
  padding: 8px 16px 16px;
  gap: 8px;
}
</style>
