<script setup lang="ts">
import { darkTheme, type GlobalThemeOverrides } from 'naive-ui'
import {
  NConfigProvider,
  NDialogProvider,
  NMessageProvider,
  NNotificationProvider,
  NLoadingBarProvider,
} from 'naive-ui'
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()

const themeOverrides: GlobalThemeOverrides = {
  common: {
    borderRadius: '8px',
    borderRadiusSmall: '6px',
    primaryColor: '#18a058',
    primaryColorHover: '#36ad6a',
    primaryColorPressed: '#0c7a43',
    primaryColorSuppl: '#36ad6a',
  },
  Card: {
    borderRadius: '12px',
  },
}
</script>

<template>
  <NConfigProvider
    :theme="appStore.isDark ? darkTheme : undefined"
    :theme-overrides="themeOverrides"
    inline-theme-disabled
  >
    <NLoadingBarProvider>
      <NDialogProvider>
        <NMessageProvider>
          <NNotificationProvider>
            <RouterView />
          </NNotificationProvider>
        </NMessageProvider>
      </NDialogProvider>
    </NLoadingBarProvider>
  </NConfigProvider>
</template>
