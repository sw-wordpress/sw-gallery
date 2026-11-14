<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useAppStore } from '@/stores/app';
import { TILE_SIZES } from '../virtual';
import type { TileSize } from '../virtual';

/**
 * Thumbnail size of the items grid: three real buttons in a labelled group (keyboard: Tab, Enter/Space), the
 * current one is `aria-pressed`. The choice is per viewer (localStorage via the app store).
 */
const { t } = useI18n();
const app = useAppStore();

const SHORT: Record<TileSize, string> = { small: 'S', medium: 'M', large: 'L' };

function choose(size: TileSize): void {
  if (app.tileSize === size) return;
  app.setTileSize(size);
  app.announce(t(`editor.tileSize.${size}Set`));
}
</script>

<template>
  <div class="swg-tilesize" role="group" :aria-label="t('editor.tileSize.label')">
    <span class="swg-tilesize__label" aria-hidden="true">{{ t('editor.tileSize.short') }}</span>
    <button
      v-for="size in TILE_SIZES"
      :key="size"
      type="button"
      class="swg-tilesize__btn"
      :class="{ 'is-on': app.tileSize === size }"
      :aria-pressed="app.tileSize === size ? 'true' : 'false'"
      :aria-label="t(`editor.tileSize.${size}`)"
      @click="choose(size)"
    >
      <span aria-hidden="true">{{ SHORT[size] }}</span>
    </button>
  </div>
</template>

<style scoped>
.swg-tilesize {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-inline-start: auto;
}
.swg-tilesize__label {
  margin-inline-end: 4px;
  color: var(--swg-muted);
  font-size: 13px;
}
.swg-tilesize__btn {
  min-inline-size: 32px;
  min-block-size: 32px;
  border: 1px solid var(--swg-border);
  border-radius: 8px;
  background: var(--swg-surface);
  color: var(--swg-text);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
.swg-tilesize__btn:hover {
  background: var(--swg-surface-3);
}
.swg-tilesize__btn.is-on {
  border-color: var(--swg-primary);
  background: var(--swg-primary-bg);
  color: var(--swg-primary-text);
}
.swg-tilesize__btn:focus-visible {
  outline: 2px solid var(--swg-primary);
  outline-offset: 2px;
}
</style>
