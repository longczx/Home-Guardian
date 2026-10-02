<script setup lang="ts">
import { ref } from 'vue';
import { onShow, onHide, onPullDownRefresh } from '@dcloudio/uni-app';
import { getAutomationRuns, statusLabel } from '@/api/operations';
import type { AutomationRun } from '@/api/operations';
const items = ref<AutomationRun[]>([]); const page = ref(1); const total = ref(0); const error = ref(''); const busy = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;
async function load(reset = false) {
  if (busy.value) return; busy.value = true;
  try { if (reset) page.value = 1; const data = await getAutomationRuns(page.value);
    items.value = data.items; total.value = data.total; error.value = '';
  } catch (e) { error.value = (e as Error).message; }
  finally { busy.value = false; uni.stopPullDownRefresh(); }
}
function turn(n: number) { page.value += n; load(); }
onShow(() => { load(true); timer = setInterval(() => { if (items.value.some((r) => r.status === 'running')) load(); }, 10000); });
onHide(() => clearInterval(timer));
onPullDownRefresh(() => load(true));
</script>
<template>
  <view class="page">
    <text class="hint">记录新版本上线后的触发。设备回执及通知投递完成后才判定成功。</text>
    <text v-if="error">{{ error }}</text><text v-if="!items.length && !error">暂无执行记录</text>
    <view v-for="run in items" :key="run.id" class="card">
      <text class="title">{{ run.automation_name }} · {{ statusLabel(run.status) }}</text>
      <text>{{ run.started_at }} · {{ run.trigger_type === 'schedule' ? '定时触发' : '数据条件触发' }}</text>
      <text v-if="run.trigger_type === 'telemetry'">{{ run.trigger_context.metric_key }}：{{ run.trigger_context.observed_value }}；阈值 {{ run.trigger_context.value }}</text>
      <text v-else>计划：{{ run.trigger_context.cron }}</text>
      <view v-for="action in run.action_results" :key="action.index" class="row">
        <text>动作 {{ action.index + 1 }}：{{ action.type === 'notify' ? '通知' : action.action || '设备控制' }} · {{ statusLabel(action.status) }}</text>
        <text v-if="action.command_status">{{ statusLabel(action.command_status) }}</text>
        <text v-if="action.error" class="error">{{ action.error }}</text>
        <text v-for="d in action.deliveries" :key="d.channel_id">渠道 {{ d.channel_id }}：{{ statusLabel(d.status) }}，尝试 {{ d.attempts }} 次</text>
      </view>
    </view>
    <view class="buttons"><button :disabled="page <= 1 || busy" @tap="turn(-1)">上一页</button><text>{{ page }}</text><button :disabled="page * 20 >= total || busy" @tap="turn(1)">下一页</button></view>
  </view>
</template>
<style scoped lang="scss">
.page { padding: 24rpx; }.card { background: $hg-card; padding: 28rpx; margin: 24rpx 0; border-radius: 20rpx; }text { display: block; font-size: 25rpx; margin-bottom: 14rpx; word-break: break-all; }.title { font-weight: 600; font-size: 30rpx; }.hint { color: $hg-muted; }.row { border-top: 1px solid $hg-line; padding-top: 16rpx; }.error { color: #b42318; }.buttons { display: flex; align-items: center; }
</style>
