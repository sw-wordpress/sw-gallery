<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * Small "?" button that keeps an explanation out of the way: click, tap or Enter opens the text in a popover, Escape
 * or a click elsewhere closes it. `label` names what is explained (for the accessible name of the button).
 */
defineProps<{ text: string; label: string }>();
const { t } = useI18n();
</script>

<template>
  <q-btn
    flat
    round
    dense
    size="sm"
    icon="r_help_outline"
    class="swg-help"
    aria-haspopup="true"
    :aria-label="t('common.help', { label })"
  >
    <q-menu anchor="bottom middle" self="top middle" :max-width="'280px'">
      <p class="swg-help__text" role="note">{{ text }}</p>
    </q-menu>
  </q-btn>
</template>

<style scoped>
.swg-help {
  color: var(--swg-muted);
}
.swg-help__text {
  margin: 0;
  padding: 10px 12px;
  font-size: 13px;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
</style>
