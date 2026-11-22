<script setup>
import DefaultTheme from 'vitepress/theme'
const { Layout } = DefaultTheme
</script>

<template>
  <Layout>
    <template #layout-bottom>
      <footer class="sw-gallery-docs-footer">
        Open source by Stefan Warnat – free to use, free to change, better together.
      </footer>
    </template>
  </Layout>
</template>
