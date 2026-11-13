<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { getHomeOverview } from '@/api/experience';
const props = defineProps<{ modelValue: string; emptyLabel?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const rooms = ref<string[]>([]);
const error = ref('');
const loading = ref(false);
const options = computed(() => ['', ...new Set([...rooms.value, ...(props.modelValue ? [props.modelValue] : [])])]);
const labels = computed(() => options.value.map(value => value || props.emptyLabel || '暂不分配房间'));
async function load() {
  loading.value = true;
  try {
    const overview = await getHomeOverview();
    rooms.value = [...new Set([...overview.rooms, ...overview.devices.map(d => d.location).filter((s): s is string => !!s)])];
    error.value = '';
  } catch { error.value = '房间加载失败，点击重试'; }
  finally { loading.value = false; }
}
function select(event: { detail: { value: string | number } }) {
  const value = options.value[Number(event.detail.value)];
  if (value !== undefined) emit('update:modelValue', value);
}
onMounted(load);
</script>
<template>
  <view>
    <text
      v-if="error"
      class="hint"
      @tap="load"
    >
      {{ error }}
    </text>
    <picker
      v-else
      :range="labels"
      :value="Math.max(0, options.indexOf(modelValue))"
      :disabled="loading"
      @change="select"
    >
      <view class="room-picker">
        {{ loading ? '正在读取房间…' : modelValue || emptyLabel || '暂不分配房间' }} <text>›</text>
      </view>
    </picker>
    <text
      v-if="!loading && !error && !rooms.length"
      class="hint"
    >
      暂无房间，可先到「管理 → 房间管理」添加。
    </text>
  </view>
</template>
<style scoped lang="scss">
.room-picker { display: flex; justify-content: space-between; padding: 22rpx 0; color: $hg-fg; font-size: 28rpx; }.hint { color: $hg-muted; font-size: 24rpx; }
</style>
