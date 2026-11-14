<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getApi } from '@/api';
import { getConfig } from '@/config';
import { useAppStore } from '@/stores/app';

/**
 * The login session is gone (FU: M2-17). A banner, not a replacement screen: the current view stays mounted, so
 * staged editor changes and the unsaved-changes guard survive. The login link opens in a new tab (the bootstrap's
 * `wp_login_url`, never user input); afterwards "Continue" fetches a fresh nonce and clears the banner.
 */
const { t } = useI18n();
const app = useAppStore();
const loginUrl = getConfig().loginUrl;
const checking = ref(false);
const stillExpired = ref(false);

async function resume(): Promise<void> {
  checking.value = true;
  stillExpired.value = false;
  try {
    if (await getApi().renewSession()) {
      app.setSessionExpired(false);
      app.announce(t('session.resumed'));
    } else {
      stillExpired.value = true;
      app.setSessionExpired(true);
    }
  } finally {
    checking.value = false;
  }
}
</script>

<template>
  <div class="swg-session" role="alert">
    <q-icon name="r_lock_clock" size="24px" aria-hidden="true" />
    <div class="swg-session__text">
      <strong>{{ t('session.title') }}</strong>
      <span>{{ t('session.text') }}</span>
      <span v-if="stillExpired" class="swg-session__again">{{ t('session.stillExpired') }}</span>
    </div>
    <div class="swg-session__actions">
      <a
        v-if="loginUrl !== ''"
        class="swg-session__login"
        :href="loginUrl"
        target="_blank"
        rel="noopener noreferrer"
        ><q-icon name="r_login" size="20px" aria-hidden="true" />{{ t('session.login') }}</a
      >
      <q-btn
        outline
        no-caps
        color="primary"
        icon="r_refresh"
        :loading="checking"
        :label="t('session.resume')"
        @click="resume"
      />
    </div>
  </div>
</template>

<style scoped>
.swg-session {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
  margin-block-end: 16px;
  padding: 12px 16px;
  border: 1px solid var(--swg-border);
  border-inline-start: 4px solid var(--swg-danger);
  border-radius: 12px;
  background: var(--swg-surface);
  color: var(--swg-text);
}
.swg-session__text {
  display: flex;
  flex: 1 1 280px;
  flex-direction: column;
  gap: 2px;
}
.swg-session__again {
  color: var(--swg-muted);
}
.swg-session__login {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-block-size: 36px;
  padding: 0 16px;
  border-radius: 8px;
  background: var(--swg-primary);
  color: #fff !important;
  font-weight: 600;
  text-decoration: none !important;
}
.swg-session__actions {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
