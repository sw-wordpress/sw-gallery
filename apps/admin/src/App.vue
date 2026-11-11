<script setup lang="ts">
import { useThemeSync } from '@/composables/useTheme';
import ShellLayout from '@/layouts/ShellLayout.vue';

// The root element is created by the PHP page; the dark class lives on it (and on Quasar portals).
useThemeSync(() => document.getElementById('swg-admin-root'));
</script>

<template>
  <ShellLayout />
</template>
