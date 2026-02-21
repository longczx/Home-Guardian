<script setup lang="ts">
import { usePermission } from '@/composables/usePermission'

const props = defineProps<{
  permission: string
}>()

const { hasPermission } = usePermission()
</script>

<template>
  <template v-if="hasPermission(props.permission)">
    <slot />
  </template>
</template>
