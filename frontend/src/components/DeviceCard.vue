<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { NIcon } from 'naive-ui'
import { HardwareChipOutline } from '@vicons/ionicons5'
import StatusBadge from './StatusBadge.vue'
import { useAppStore } from '@/stores/app'
import type { Device } from '@/stores/device'

const props = defineProps<{
  device: Device
}>()

const router = useRouter()
const appStore = useAppStore()

const telemetryEntries = computed(() => {
  if (!props.device.latestTelemetry) return []
  return Object.entries(props.device.latestTelemetry)
    .filter(([k]) => !['device_id', 'timestamp'].includes(k))
    .slice(0, 4)
})

function goToDetail() {
  router.push({ name: 'device-detail', params: { id: props.device.id } })
}
</script>

<template>
  <div
    class="p-4 rounded-xl cursor-pointer transition-all hover:scale-[1.02]"
    :style="{
      backgroundColor: appStore.cardBg,
      backdropFilter: 'blur(12px)',
      border: appStore.isDark ? '1px solid rgba(255,255,255,0.06)' : '1px solid rgba(0,0,0,0.06)',
    }"
    @click="goToDetail"
  >
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <div
          class="w-8 h-8 rounded-lg flex items-center justify-center"
          :style="{
            backgroundColor: appStore.isDark ? 'rgba(24,160,88,0.15)' : 'rgba(24,160,88,0.1)',
          }"
        >
          <NIcon :size="18" color="#18a058">
            <HardwareChipOutline />
          </NIcon>
        </div>
        <div>
          <div
            class="font-medium text-sm"
            :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
          >
            {{ device.name }}
          </div>
          <div
            class="text-xs"
            :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.38)' : 'rgba(0,0,0,0.38)' }"
          >
            {{ device.location || device.type }}
          </div>
        </div>
      </div>
      <StatusBadge :status="device.status" />
    </div>

    <div v-if="telemetryEntries.length" class="grid grid-cols-2 gap-2">
      <div
        v-for="[key, val] in telemetryEntries"
        :key="key"
        class="text-xs rounded-lg px-2 py-1.5"
        :style="{
          backgroundColor: appStore.isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.03)',
        }"
      >
        <span :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.38)' : 'rgba(0,0,0,0.38)' }">
          {{ key }}
        </span>
        <span
          class="ml-1 font-medium"
          :style="{ color: appStore.isDark ? '#fff' : '#1a1a1a' }"
        >
          {{ val }}
        </span>
      </div>
    </div>

    <div
      v-else
      class="text-xs"
      :style="{ color: appStore.isDark ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.25)' }"
    >
      暂无遥测数据
    </div>
  </div>
</template>
