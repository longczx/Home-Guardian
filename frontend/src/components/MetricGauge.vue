<script setup lang="ts">
import { computed } from 'vue'
import { useAppStore } from '@/stores/app'

const props = defineProps<{
  label: string
  value: string | number
  unit?: string
  icon?: string
  color?: string
}>()

const appStore = useAppStore()
</script>

<template>
  <div
    class="p-4 rounded-xl"
    :style="{
      backgroundColor: appStore.cardBg,
      backdropFilter: 'blur(12px)',
      border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
    }"
  >
    <div
      class="text-xs mb-2"
      :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.45)' }"
    >
      {{ props.label }}
    </div>
    <div class="flex items-end gap-1">
      <span
        class="text-2xl font-bold tabular-nums"
        :style="{ color: props.color || (appStore.isDark ? '#fff' : '#1a1a1a') }"
      >
        {{ props.value }}
      </span>
      <span
        v-if="props.unit"
        class="text-sm mb-0.5"
        :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.45)' : 'rgba(0,0,0,0.45)' }"
      >
        {{ props.unit }}
      </span>
    </div>
  </div>
</template>
