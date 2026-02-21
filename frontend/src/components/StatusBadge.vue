<script setup lang="ts">
import { computed } from 'vue'
import { NTag } from 'naive-ui'

const props = defineProps<{
  status: string
}>()

const typeMap: Record<string, 'success' | 'error' | 'warning' | 'info'> = {
  online: 'success',
  offline: 'error',
  inactive: 'warning',
}

const labelMap: Record<string, string> = {
  online: '在线',
  offline: '离线',
  inactive: '未激活',
}
</script>

<template>
  <NTag
    :type="typeMap[props.status] || 'info'"
    size="small"
    round
    :bordered="false"
  >
    <template #icon>
      <div
        class="w-2 h-2 rounded-full mr-1"
        :class="{
          'bg-green-400': props.status === 'online',
          'bg-red-400': props.status === 'offline',
          'bg-yellow-400': props.status === 'inactive',
        }"
      />
    </template>
    {{ labelMap[props.status] || props.status }}
  </NTag>
</template>
