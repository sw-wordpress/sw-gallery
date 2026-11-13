<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { parseHeightMessage } from './srcdoc';

/**
 * The ONLY place where server-rendered gallery markup becomes visible: a sandboxed iframe with a
 * `srcdoc` document (see srcdoc.ts for the CSP and the asset validation). `sandbox` carries
 * `allow-scripts` only: no `allow-same-origin` (opaque origin: no access to the admin page,
 * cookies or the REST nonce), no popups, no top navigation, no forms. The parent never reads the
 * frame's DOM; it only accepts height reports that pass `parseHeightMessage`.
 */
const props = defineProps<{
  srcdoc: string;
  /** Per-render token the frame must echo in its height messages. */
  token: string;
  title: string;
  minHeight: number;
  maxHeight: number;
}>();

const frame = ref<HTMLIFrameElement | null>(null);
const height = ref(props.minHeight);

function clamp(value: number): number {
  return Math.min(Math.max(value, props.minHeight), props.maxHeight);
}

function onMessage(event: MessageEvent): void {
  const reported = parseHeightMessage(
    { origin: event.origin, source: event.source, data: event.data },
    frame.value?.contentWindow,
    props.token,
  );
  if (reported !== null) height.value = clamp(reported);
}

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));
// The parent recreates the frame (new key) per render; reset until the new document reports.
watch(
  () => props.srcdoc,
  () => {
    height.value = props.minHeight;
  },
);
</script>

<template>
  <iframe
    ref="frame"
    class="swg-pvf"
    sandbox="allow-scripts"
    referrerpolicy="no-referrer"
    :srcdoc="srcdoc"
    :title="title"
    :style="{ blockSize: `${height}px` }"
  />
</template>

<style scoped>
.swg-pvf {
  display: block;
  inline-size: 100%;
  border: 0;
  background: transparent;
}
</style>
