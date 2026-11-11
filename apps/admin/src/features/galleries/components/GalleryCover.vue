<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{ url: string | null; small?: boolean }>();
const { t } = useI18n();
/** A cover URL that fails to load falls back to the neutral placeholder. */
const broken = ref(false);
watch(
  () => props.url,
  () => {
    broken.value = false;
  },
);
</script>

<template>
  <div class="swg-cover" :class="{ 'swg-cover--small': small }">
    <img
      v-if="url && !broken"
      :src="url"
      alt=""
      loading="lazy"
      decoding="async"
      @error="broken = true"
    />
    <div v-else class="swg-cover__placeholder" v-bind="{ 'data-testid': 'cover-placeholder' }">
      <q-icon name="r_photo_library" :size="small ? '22px' : '40px'" aria-hidden="true" />
      <span v-if="!small" class="swg-cover__label">{{ t('galleries.card.noCover') }}</span>
    </div>
  </div>
</template>

<style scoped>
.swg-cover {
  position: relative;
  aspect-ratio: 4 / 3;
  overflow: hidden;
  background: var(--swg-surface-3);
}
.swg-cover--small {
  width: 64px;
  border-radius: 8px;
  flex: none;
}
.swg-cover img {
  display: block;
  inline-size: 100%;
  block-size: 100%;
  object-fit: cover;
}
.swg-cover__placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  inline-size: 100%;
  block-size: 100%;
  color: var(--swg-muted);
  background: var(--swg-gradient-soft);
}
.swg-cover__label {
  font-size: 13px;
}
</style>
