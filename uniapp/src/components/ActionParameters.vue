<script setup lang="ts">
import type { ControlPoint } from '@/api/types';
import { switchValue } from '@/utils/events';
function inputValue(event: unknown): string { return String((event as { detail?: { value?: unknown } }).detail?.value ?? ''); }
const props = defineProps<{ controls: ControlPoint[]; modelValue: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();
function change(c: ControlPoint, value: unknown) { emit('update:modelValue', { ...props.modelValue, [c.param]: value }); }
function number(c: ControlPoint, value: string) { change(c, value === '' ? '' : Number(value)); }
</script>
<template>
  <view
    v-for="c in controls"
    :key="c.param"
    class="field"
  >
    <text>{{ c.label || c.param }} {{ c.unit || '' }}</text>
    <switch
      v-if="c.value_type === 'bool'"
      :checked="modelValue[c.param] === true"
      @change="change(c, switchValue($event))"
    />
    <view v-else-if="c.value_type === 'enum'">
      <button
        v-for="o in c.options || []"
        :key="String(o.value)"
        size="mini"
        :class="{ selected: modelValue[c.param] === o.value }"
        @tap="change(c, o.value)"
      >
        {{ o.label }}
      </button>
    </view>
    <input
      v-else-if="c.value_type === 'int' || c.value_type === 'float'"
      type="digit"
      :value="String(modelValue[c.param] ?? '')"
      :placeholder="`${c.min ?? ''} ~ ${c.max ?? ''}`"
      @input="number(c, inputValue($event))"
    >
    <input
      v-else
      :value="String(modelValue[c.param] ?? '')"
      @input="change(c, inputValue($event))"
    >
  </view>
</template>
<style scoped>.field { padding: 12px 0; } text { display: block; margin-bottom: 8px; } input { border-bottom: 1px solid #ddd; padding: 8px 0; }.selected { background: #2b6fe3; color: white; }</style>
