<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { uid } from '@/utils/ids';
import type { EditorSession } from '../useGalleryEditor';
import PreviewPane from './PreviewPane.vue';
import type { Viewport } from './PreviewPane.vue';

/**
 * Live preview in a modal that fills the whole viewport (responsive views need the room). The
 * pane inside only exists while the dialog is open: it requests nothing while closed, cancels on
 * close and builds a fresh iframe on every open. The chosen width and page size stay here for as
 * long as the editor is mounted. Quasar's dialog traps focus, closes on Esc and refocuses the
 * trigger; the editor also focuses the Preview button on `hide` (Safari does not focus buttons
 * on click).
 */
const open = defineModel<boolean>({ required: true });
defineProps<{
  session: EditorSession;
  galleryId: number;
  readonly: boolean;
}>();
const emit = defineEmits<{ (e: 'hide'): void }>();

const { t } = useI18n();
const titleId = uid('swg-pvd');
const viewport = ref<Viewport>('fit');
const perPage = ref<number | null>(null);

/** Reduced motion: no slide animation. */
const reduced = computed(
  () =>
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches === true,
);
</script>

<template>
  <q-dialog
    v-model="open"
    maximized
    role="dialog"
    aria-modal="true"
    :aria-labelledby="titleId"
    :transition-duration="reduced ? 0 : 300"
    @hide="emit('hide')"
  >
    <q-card class="swg-pvd">
      <div class="swg-pvd__head">
        <div class="swg-pvd__titles">
          <h2 :id="titleId" class="swg-pvd__title">{{ t('editor.preview.title') }}</h2>
          <p class="swg-pvd__hint">{{ t('editor.preview.dialog.hint') }}</p>
        </div>
        <q-btn
          flat
          no-caps
          icon="r_close"
          class="swg-pvd__close"
          :label="t('editor.preview.dialog.close')"
          @click="open = false"
        />
      </div>
      <div class="swg-pvd__body">
        <PreviewPane
          v-model:viewport="viewport"
          v-model:per-page="perPage"
          :session="session"
          :gallery-id="galleryId"
          :active="open"
          :readonly="readonly"
        />
      </div>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swg-pvd {
  display: flex;
  flex-direction: column;
  inline-size: 100vw;
  max-inline-size: none;
  block-size: 100%;
  max-block-size: none;
  border-radius: 0;
}
.swg-pvd__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px clamp(16px, 2vw, 28px);
  border-block-end: 1px solid var(--swg-border);
  background: var(--swg-gradient-soft);
}
.swg-pvd__titles {
  min-inline-size: 0;
}
.swg-pvd__title {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  letter-spacing: -0.01em;
}
.swg-pvd__hint {
  margin: 0;
  font-size: 13px;
  color: var(--swg-muted);
}
.swg-pvd__close {
  flex: none;
}
.swg-pvd__body {
  flex: 1;
  min-block-size: 0;
  padding: 12px clamp(16px, 2vw, 28px) 16px;
  overflow: hidden;
}
</style>
