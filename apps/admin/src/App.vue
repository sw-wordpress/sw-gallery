<script setup lang="ts">
import { onBeforeUnmount } from 'vue';
import { attachHeartbeatNonce, getApi } from '@/api';
import { useThemeSync } from '@/composables/useTheme';
import ShellLayout from '@/layouts/ShellLayout.vue';

// The root element is created by the PHP page; the dark class lives on it (and on Quasar portals).
useThemeSync(() => document.getElementById('swg-admin-root'));

// WordPress' Heartbeat (every ~60 s) carries a fresh REST nonce; the client's reactive refresh covers the rest.
const detachHeartbeat = attachHeartbeatNonce((nonce) => getApi().setNonce(nonce));
onBeforeUnmount(detachHeartbeat);
</script>

<template>
  <ShellLayout />
</template>
