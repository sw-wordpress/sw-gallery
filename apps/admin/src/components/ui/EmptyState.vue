<script setup lang="ts">
import { uid } from '@/utils/ids';

export type Illustration = 'gallery' | 'media' | 'search' | 'settings' | 'error';

withDefaults(
  defineProps<{
    title: string;
    text?: string;
    illustration?: Illustration;
    compact?: boolean;
    /** Level of the title; the page title is h2, so this defaults to h3. */
    headingLevel?: 2 | 3 | 4;
  }>(),
  { text: undefined, illustration: 'gallery', compact: false, headingLevel: 3 },
);

const gradientId = uid('swg-eg');
</script>

<template>
  <div class="swg-empty" :class="{ 'swg-empty--compact': compact }">
    <svg class="swg-empty__art" viewBox="0 0 160 120" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient :id="gradientId" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#4f46e5" />
          <stop offset="1" stop-color="#14b8a6" />
        </linearGradient>
      </defs>
      <ellipse cx="80" cy="106" rx="52" ry="7" fill="currentColor" opacity="0.08" />
      <rect x="28" y="22" width="104" height="76" rx="14" fill="currentColor" opacity="0.06" />
      <rect
        x="36"
        y="14"
        width="88"
        height="76"
        rx="14"
        fill="var(--swg-surface)"
        stroke="currentColor"
        stroke-opacity="0.18"
        stroke-width="2"
      />
      <rect
        x="36"
        y="14"
        width="88"
        height="20"
        rx="14"
        :fill="`url(#${gradientId})`"
        opacity="0.9"
      />
      <rect x="36" y="26" width="88" height="8" :fill="`url(#${gradientId})`" opacity="0.9" />
      <circle cx="48" cy="24" r="2.5" fill="#fff" opacity="0.9" />
      <circle cx="57" cy="24" r="2.5" fill="#fff" opacity="0.6" />
      <circle cx="66" cy="24" r="2.5" fill="#fff" opacity="0.4" />
      <g
        v-if="illustration === 'gallery'"
        fill="none"
        :stroke="`url(#${gradientId})`"
        stroke-width="5"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <rect x="58" y="44" width="44" height="34" rx="6" />
        <circle cx="70" cy="55" r="3.5" />
        <path d="M60 74l14-12 10 8 8-6 10 9" />
      </g>
      <g
        v-else-if="illustration === 'media'"
        fill="none"
        :stroke="`url(#${gradientId})`"
        stroke-width="5"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <rect x="56" y="42" width="22" height="18" rx="4" />
        <rect x="82" y="42" width="22" height="18" rx="4" />
        <rect x="56" y="64" width="22" height="14" rx="4" />
        <rect x="82" y="64" width="22" height="14" rx="4" />
      </g>
      <g
        v-else-if="illustration === 'search'"
        fill="none"
        :stroke="`url(#${gradientId})`"
        stroke-width="5"
        stroke-linecap="round"
      >
        <circle cx="77" cy="58" r="14" />
        <path d="M88 69l11 11" />
      </g>
      <g
        v-else-if="illustration === 'settings'"
        fill="none"
        :stroke="`url(#${gradientId})`"
        stroke-width="5"
        stroke-linecap="round"
      >
        <path d="M60 50h36M60 62h36M60 74h36" />
        <circle cx="72" cy="50" r="3" fill="var(--swg-surface)" />
        <circle cx="86" cy="62" r="3" fill="var(--swg-surface)" />
        <circle cx="70" cy="74" r="3" fill="var(--swg-surface)" />
      </g>
      <g
        v-else
        fill="none"
        stroke="#dc2626"
        stroke-width="5"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path d="M80 44l20 34H60z" />
        <path d="M80 56v10M80 72v.5" />
      </g>
    </svg>
    <component :is="`h${headingLevel}`" class="swg-empty__title">{{ title }}</component>
    <p v-if="text" class="swg-empty__text">{{ text }}</p>
    <div v-if="$slots.default" class="swg-empty__actions"><slot /></div>
  </div>
</template>

<style scoped>
.swg-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 8px;
  padding: 40px 20px;
  color: var(--swg-muted);
}
.swg-empty--compact {
  padding: 20px 12px;
}
.swg-empty__art {
  width: 150px;
  height: auto;
  color: var(--swg-primary);
}
.swg-empty--compact .swg-empty__art {
  width: 100px;
}
.swg-empty__title {
  font-size: 17px;
  font-weight: 650;
  color: var(--swg-text);
}
.swg-empty__text {
  max-width: 46ch;
}
.swg-empty__actions {
  margin-top: 10px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: center;
}
</style>
