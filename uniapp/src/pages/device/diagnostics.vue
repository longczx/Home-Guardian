<script setup lang="ts">
import { ref } from 'vue';
import { onLoad, onPullDownRefresh } from '@dcloudio/uni-app';
import { getDiagnostics, probeDiagnostics, statusLabel } from '@/api/operations';
import type { Diagnostic } from '@/api/operations';
import { getCommandResult } from '@/api/device';
import { withCommandResult } from '@/utils/command-result';
import { toast } from '@/utils/guard';
import { useAuthStore } from '@/stores/auth';
const auth = useAuthStore();
const id = ref(0); const data = ref<Diagnostic | null>(null); const busy = ref(false); const error = ref('');
async function load() {
  try { data.value = await getDiagnostics(id.value); error.value = ''; }
  catch (e) { error.value = (e as Error).message; }
  finally { uni.stopPullDownRefresh(); }
}
async function probe() {
  if (busy.value) return;
  busy.value = true;
  try { await withCommandResult(() => probeDiagnostics(id.value), 65000, getCommandResult); await load(); }
  catch (e) { toast((e as Error).message); }
  finally { busy.value = false; }
}
onLoad((q) => { id.value = Number(q?.id); load(); });
onPullDownRefresh(load);
</script>
<template>
  <view class="page">
    <text v-if="error">{{ error }}</text>
    <view v-if="data" class="card">
      <text class="title">{{ data.device.name }}</text>
      <text>连接：{{ data.device.is_online ? '在线' : '离线' }}</text>
      <text>最近联系：{{ data.device.last_seen || '暂无记录' }}</text>
      <text>最近数据：{{ data.last_telemetry_at || '暂无记录' }}</text>
      <text v-if="data.gateway">所属网关：{{ data.gateway.name }} · {{ data.gateway.is_online ? '在线' : '离线' }}</text>
      <text>固件：{{ data.info.firmware || data.device.firmware_version || '暂未读取' }}</text>
      <text v-if="data.info.wifi_rssi != null">Wi-Fi 信号：{{ data.info.wifi_rssi }} dBm</text>
      <text v-if="data.info.uptime_s != null">运行时长：{{ data.info.uptime_s }} 秒</text>
      <text v-if="data.info.free_heap != null">可用内存：{{ data.info.free_heap }} 字节</text>
      <text v-if="data.info.module_count != null">挂载模块：{{ data.info.module_count }} 个</text>
      <text>信息读取时间：{{ data.info_at || '暂未读取' }}</text>
      <text v-if="data.state">状态上报：{{ data.state.reported_at || '仅有期望状态，尚未收到设备上报' }}</text>
      <button v-if="data.device.type === 'gateway' && auth.canManage" :disabled="busy || !data.device.is_online" @tap="probe">{{ busy ? '等待设备回复…' : '读取网关信息' }}</button>
      <text v-else-if="data.device.type !== 'gateway'">信号和运行信息请在所属网关的诊断页查看。</text>
      <text v-if="!data.device.is_online">请检查供电、Wi-Fi 及网关连接；这里显示的是最后已知数据。</text>
    </view>
    <view v-if="data" class="card">
      <text class="title">最近 10 条指令</text>
      <text v-if="!data.commands.length">暂无指令记录</text>
      <view v-for="c in data.commands" :key="c.id" class="row"><text>{{ c.action }} · {{ statusLabel(c.status) }}</text><text>{{ c.sent_at }}</text></view>
      <text>红外回执表示完成发送，空调实际状态仍需核对。</text>
    </view>
    <button @tap="load">刷新</button>
  </view>
</template>
<style scoped lang="scss">
.page { padding: 24rpx; }.card { background: $hg-card; padding: 28rpx; border-radius: 20rpx; margin-bottom: 24rpx; }
text { display: block; margin-bottom: 14rpx; font-size: 25rpx; word-break: break-all; }.title { font-size: 32rpx; font-weight: 600; }.row { border-top: 1px solid $hg-line; padding-top: 16rpx; }
</style>
