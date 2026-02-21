<script setup lang="ts">
import { ref, computed } from 'vue'
import { NDatePicker, NSpace, NButton } from 'naive-ui'

const emit = defineEmits<{
  change: [range: { start: string; end: string }]
}>()

const presets = [
  { label: '1小时', hours: 1 },
  { label: '6小时', hours: 6 },
  { label: '24小时', hours: 24 },
  { label: '7天', hours: 168 },
  { label: '30天', hours: 720 },
]

const activePreset = ref(24)
const customRange = ref<[number, number] | null>(null)

function selectPreset(hours: number) {
  activePreset.value = hours
  customRange.value = null
  const end = new Date()
  const start = new Date(end.getTime() - hours * 3600 * 1000)
  emit('change', {
    start: start.toISOString(),
    end: end.toISOString(),
  })
}

function handleCustomRange(value: [number, number] | null) {
  if (value) {
    activePreset.value = 0
    customRange.value = value
    emit('change', {
      start: new Date(value[0]).toISOString(),
      end: new Date(value[1]).toISOString(),
    })
  }
}

// Emit default range on mount
selectPreset(24)
</script>

<template>
  <NSpace align="center" :size="8">
    <NButton
      v-for="preset in presets"
      :key="preset.hours"
      :type="activePreset === preset.hours ? 'primary' : 'default'"
      size="small"
      secondary
      @click="selectPreset(preset.hours)"
    >
      {{ preset.label }}
    </NButton>
    <NDatePicker
      type="datetimerange"
      :value="customRange"
      @update:value="handleCustomRange"
      size="small"
      clearable
      style="width: 340px"
    />
  </NSpace>
</template>
