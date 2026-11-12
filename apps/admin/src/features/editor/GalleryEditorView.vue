<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import GalleryEditor from './GalleryEditor.vue';

/** Route component of `/galleries/:id`; the key recreates the editor (and its session) when the id changes. */
const route = useRoute();
const id = computed(() => Number.parseInt(String(route.params.id), 10));
</script>

<template>
  <GalleryEditor :key="id" :gallery-id="id" />
</template>
