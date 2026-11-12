<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Gallery } from '@/api/galleries';
import { availableActions } from '../format';
import type { GalleryActionKind } from '../format';

const props = defineProps<{ gallery: Gallery }>();
const emit = defineEmits<{ (e: 'action', kind: GalleryActionKind, gallery: Gallery): void }>();
const { t } = useI18n();

const ICONS: Record<GalleryActionKind, string> = {
  editImages: 'r_photo_library',
  edit: 'r_edit',
  duplicate: 'r_content_copy',
  copy: 'r_code',
  trash: 'r_delete',
  restore: 'r_restore_from_trash',
  delete: 'r_delete_forever',
};

const actions = computed(() =>
  availableActions(props.gallery).map((kind) => ({
    kind,
    icon: ICONS[kind],
    label: t(`galleries.menu.${kind === 'copy' ? 'copyShortcode' : kind}`),
    danger: kind === 'delete',
  })),
);
const label = computed(() =>
  t('galleries.card.actionsFor', { title: props.gallery.title || t('galleries.card.untitled') }),
);

/** Arrow keys move between the items (menu pattern); Tab keeps working as well. */
function onKeydown(event: KeyboardEvent): void {
  if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
  const list = event.currentTarget as HTMLElement;
  const items = Array.from(list.querySelectorAll<HTMLElement>('[role="menuitem"]'));
  const index = items.indexOf(document.activeElement as HTMLElement);
  const next = event.key === 'ArrowDown' ? index + 1 : index - 1;
  items[(next + items.length) % items.length]?.focus();
  event.preventDefault();
}
</script>

<template>
  <q-btn
    v-if="actions.length > 0"
    flat
    round
    dense
    icon="r_more_vert"
    class="swg-actions-btn"
    aria-haspopup="menu"
    :aria-label="label"
  >
    <q-menu anchor="bottom end" self="top end" auto-close>
      <div @keydown="onKeydown">
        <q-list role="menu" :aria-label="label" class="swg-actions-menu">
          <q-item
            v-for="action in actions"
            :key="action.kind"
            clickable
            role="menuitem"
            v-bind="{ 'data-action': action.kind }"
            :class="{ 'swg-actions-menu__danger': action.danger }"
            @click="emit('action', action.kind, gallery)"
          >
            <q-item-section avatar class="swg-actions-menu__icon">
              <q-icon :name="action.icon" size="20px" aria-hidden="true" />
            </q-item-section>
            <q-item-section>{{ action.label }}</q-item-section>
          </q-item>
        </q-list>
      </div>
    </q-menu>
  </q-btn>
</template>

<style scoped>
.swg-actions-menu {
  min-width: 220px;
}
.swg-actions-menu__icon {
  min-width: 36px;
}
.swg-actions-menu__danger {
  color: var(--swg-danger-text);
}
</style>
