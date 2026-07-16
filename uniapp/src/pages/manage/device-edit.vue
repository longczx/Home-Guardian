<script setup lang="ts">
import { ref } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { getDevice, updateDevice } from '@/api/device';
import type { Device } from '@/api/types';
import { toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';

const id = ref(0);
const device = ref<Device | null>(null);
const name = ref('');
const location = ref('');
const metrics = ref<{ key: string; label: string; unit: string }[]>([]);
const saving = ref(false);

async function load() {
  try {
    const d = await getDevice(id.value);
    device.value = d;
    name.value = d.name;
    location.value = d.location || '';
    metrics.value = (d.metric_fields || []).map((m) => ({
      key: m.key, label: m.label || m.key, unit: m.unit || '',
    }));
  } catch (e) {
    toast((e as Error).message);
  }
}

function addMetric() {
  metrics.value.push({ key: '', label: '', unit: '' });
}
function removeMetric(i: number) {
  metrics.value.splice(i, 1);
}

async function save() {
  if (!name.value.trim()) return toast('名称不能为空');
  // 过滤空 key 的指标行
  const mf = metrics.value
    .filter((m) => m.key.trim())
    .map((m) => ({ key: m.key.trim(), label: m.label.trim() || m.key.trim(), unit: m.unit.trim() }));
  saving.value = true;
  try {
    await updateDevice(id.value, { name: name.value.trim(), location: location.value.trim(), metric_fields: mf });
    toast('已保存', 'success');
    setTimeout(() => uni.navigateBack(), 500);
  } catch (e) {
    toast((e as Error).message);
  } finally {
    saving.value = false;
  }
}

onLoad((q) => {
  id.value = Number(q?.id || 0);
  load();
});
</script>

<template>
  <view class="page">
    <!-- 只读信息 -->
    <view v-if="device" class="card info">
      <view class="irow"><text class="ik">设备标识</text><text class="iv">{{ device.device_uid }}</text></view>
      <view class="irow"><text class="ik">类型</text><text class="iv">{{ device.type }}</text></view>
      <view class="irow"><text class="ik">状态</text>
        <text class="iv" :style="{ color: device.is_online ? '#2fb56b' : '#7a8299' }">
          {{ device.is_online ? '在线' : '离线 ' + timeAgo(device.last_seen) }}
        </text>
      </view>
      <view v-if="device.gateway_uid" class="irow"><text class="ik">所属网关</text><text class="iv">{{ device.gateway_uid }}</text></view>
      <view v-if="device.firmware_version" class="irow"><text class="ik">固件</text><text class="iv">{{ device.firmware_version }}</text></view>
    </view>

    <!-- 可编辑 -->
    <view class="card">
      <view class="field">
        <text class="label">设备名称</text>
        <input v-model="name" class="input" placeholder="设备名称" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">位置 / 房间</text>
        <input v-model="location" class="input" placeholder="如 客厅" placeholder-class="ph" />
      </view>
    </view>

    <!-- 遥测指标 -->
    <view class="card">
      <view class="mhead">
        <text class="ct">遥测指标</text>
        <text class="add" @tap="addMetric">＋ 添加</text>
      </view>
      <text class="hint">定义该设备上报的遥测字段（key 需与设备上报一致，如 temperature）。</text>
      <view v-for="(m, i) in metrics" :key="i" class="mrow">
        <input v-model="m.key" class="min" placeholder="key" placeholder-class="ph" />
        <input v-model="m.label" class="min" placeholder="名称" placeholder-class="ph" />
        <input v-model="m.unit" class="min unit" placeholder="单位" placeholder-class="ph" />
        <text class="mdel" @tap="removeMetric(i)">✕</text>
      </view>
      <text v-if="!metrics.length" class="empty">暂无指标</text>
    </view>

    <button class="btn btn-primary" :loading="saving" @tap="save">保存</button>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 60rpx; }
.card { background: $hg-card; border-radius: $hg-radius; padding: 8rpx 28rpx; box-shadow: $hg-shadow; margin-bottom: 20rpx; }
.info { padding: 20rpx 28rpx; }
.irow { display: flex; justify-content: space-between; padding: 12rpx 0; }
.ik { font-size: 24rpx; color: $hg-muted; }
.iv { font-size: 26rpx; color: $hg-fg; max-width: 60%; text-align: right; word-break: break-all; }
.field { padding: 26rpx 0; border-bottom: 1rpx solid $hg-line; }
.field:last-child { border-bottom: none; }
.label { display: block; font-size: 24rpx; color: $hg-muted; margin-bottom: 12rpx; }
.input { font-size: 30rpx; color: $hg-fg; }
.ph { color: #b6bccb; }
.mhead { display: flex; align-items: center; justify-content: space-between; padding: 22rpx 0 4rpx; }
.ct { font-size: 26rpx; font-weight: 600; color: $hg-fg; }
.add { font-size: 26rpx; color: $hg-accent; }
.hint { display: block; font-size: 22rpx; color: $hg-muted; padding-bottom: 12rpx; }
.mrow { display: flex; align-items: center; gap: 12rpx; padding: 14rpx 0; border-top: 1rpx solid $hg-line; }
.min { flex: 1; min-width: 0; font-size: 26rpx; color: $hg-fg; background: $hg-card-2; border-radius: 10rpx; padding: 12rpx 16rpx; }
.min.unit { flex: 0 0 120rpx; }
.mdel { flex: none; color: $hg-crit; font-size: 28rpx; padding: 0 6rpx; }
.empty { display: block; text-align: center; color: $hg-muted; font-size: 24rpx; padding: 20rpx 0; }
.btn { border-radius: $hg-radius-s; height: 90rpx; line-height: 90rpx; font-size: 32rpx; }
.btn-primary { background: $hg-accent; color: #fff; }
</style>
