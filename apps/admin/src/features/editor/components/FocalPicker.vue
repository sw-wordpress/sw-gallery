<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { uid } from '@/utils/ids';

/**
 * Click-to-set focal point (percent from the left and the top; 50 / 50 is the centre). The point is the part of the
 * image that stays visible when a layout crops it. Click or drag in the image, or use the arrow keys (Shift = 10 %);
 * the numeric sliders of the settings form stay available as the exact alternative.
 */
const props = defineProps<{
  src: string;
  x: number;
  y: number;
  disabled?: boolean;
}>();
const emit = defineEmits<{
  (e: 'update', x: number, y: number): void;
  (e: 'reset'): void;
}>();
const { t } = useI18n();
const hintId = uid('swg-fp-hint');

const centred = computed(() => props.x === 50 && props.y === 50);
const position = computed(() => `${props.x}% ${props.y}%`);

function clamp(value: number): number {
  return Math.max(0, Math.min(100, Math.round(value * 10) / 10));
}

function pick(event: PointerEvent): void {
  const stage = event.currentTarget as HTMLElement;
  const rect = stage.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) return;
  emit(
    'update',
    clamp(((event.clientX - rect.left) / rect.width) * 100),
    clamp(((event.clientY - rect.top) / rect.height) * 100),
  );
}

function onDown(event: PointerEvent): void {
  if (props.disabled === true) return;
  (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
  pick(event);
}

function onMove(event: PointerEvent): void {
  const stage = event.currentTarget as HTMLElement;
  if (props.disabled !== true && stage.hasPointerCapture?.(event.pointerId) === true) pick(event);
}

function onKey(event: KeyboardEvent): void {
  if (props.disabled === true) return;
  const step = event.shiftKey ? 10 : 1;
  const moves: Record<string, [number, number]> = {
    ArrowLeft: [-step, 0],
    ArrowRight: [step, 0],
    ArrowUp: [0, -step],
    ArrowDown: [0, step],
  };
  const move = moves[event.key];
  if (move === undefined) return;
  event.preventDefault();
  emit('update', clamp(props.x + move[0]), clamp(props.y + move[1]));
}
</script>

<template>
  <div class="swg-fp">
    <div
      class="swg-fp__stage"
      :class="{ 'swg-fp__stage--disabled': disabled }"
      :tabindex="disabled ? -1 : 0"
      role="group"
      :aria-label="t('editor.focal.pickerLabel')"
      :aria-describedby="hintId"
      @pointerdown="onDown"
      @pointermove="onMove"
      @keydown="onKey"
    >
      <img class="swg-fp__img" :src="src" alt="" draggable="false" />
      <span class="swg-fp__dot" :style="{ left: `${x}%`, top: `${y}%` }" aria-hidden="true" />
    </div>
    <p :id="hintId" class="swg-fp__hint">{{ t('editor.focal.hint') }}</p>
    <div class="swg-fp__row">
      <figure class="swg-fp__crop">
        <img :src="src" alt="" :style="{ objectPosition: position }" draggable="false" />
        <figcaption>{{ t('editor.focal.crop') }}</figcaption>
      </figure>
      <q-btn
        v-if="!disabled && !centred"
        flat
        dense
        no-caps
        icon="r_filter_center_focus"
        :label="t('editor.focal.reset')"
        @click="emit('reset')"
      />
    </div>
  </div>
</template>

<style scoped>
.swg-fp {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.swg-fp__stage {
  position: relative;
  display: inline-block;
  align-self: flex-start;
  max-inline-size: 100%;
  line-height: 0;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-3);
  cursor: crosshair;
  touch-action: none;
  user-select: none;
}
.swg-fp__stage--disabled {
  cursor: default;
}
.swg-fp__stage:focus-visible {
  outline: 3px solid var(--swg-focus, Highlight);
  outline-offset: 2px;
}
.swg-fp__img {
  display: block;
  max-inline-size: 100%;
  max-block-size: 320px;
  border-radius: var(--swg-radius-sm);
}
/* Physical coordinates on purpose: the focal point is measured from the left and the top of the (never mirrored) image. */
.swg-fp__dot {
  position: absolute;
  inline-size: 22px;
  block-size: 22px;
  margin: -11px 0 0 -11px;
  border: 2px solid #fff;
  border-radius: 50%;
  box-shadow:
    0 0 0 1px rgb(0 0 0 / 55%),
    inset 0 0 0 1px rgb(0 0 0 / 55%);
  pointer-events: none;
}
.swg-fp__dot::after {
  content: '';
  position: absolute;
  inset: 8px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 0 0 1px rgb(0 0 0 / 55%);
}
.swg-fp__hint {
  margin: 0;
  color: var(--swg-muted);
  font-size: 13px;
}
.swg-fp__row {
  display: flex;
  gap: 16px;
  align-items: flex-end;
}
.swg-fp__crop {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0;
  color: var(--swg-muted);
  font-size: 12px;
}
.swg-fp__crop img {
  inline-size: 96px;
  block-size: 96px;
  object-fit: cover;
  border-radius: var(--swg-radius-sm);
  background: var(--swg-surface-3);
}
</style>
