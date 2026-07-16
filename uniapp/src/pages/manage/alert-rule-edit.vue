<script setup lang="ts">
import { ref, computed } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { getAlertRule, createAlertRule, updateAlertRule, CONDITIONS, SEVERITIES, type AlertRuleInput } from '@/api/alertRule';
import { getDevices } from '@/api/device';
import { getChannels, type NotificationChannel } from '@/api/channel';
import type { Device } from '@/api/types';
import { toast } from '@/utils/guard';

const editId = ref(0);
const saving = ref(false);
const devices = ref<Device[]>([]);
const channels = ref<NotificationChannel[]>([]);

const form = ref<AlertRuleInput>({
  name: '',
  device_id: undefined,
  trigger_type: 'telemetry',
  telemetry_key: '',
  condition: 'GREATER_THAN',
  threshold_value: [0],
  trigger_duration_sec: 0,
  offline_timeout_sec: 120,
  severity: 'warning',
  notify_cooldown_sec: 600,
  notify_on_recovery: true,
  notification_channel_ids: [],
  is_enabled: true,
});

// 阈值以字符串编辑（区间用逗号分隔），提交时转数值数组
const thresholdText = ref('0');
const isBetween = computed(() => form.value.condition === 'BETWEEN' || form.value.condition === 'NOT_BETWEEN');
const isOffline = computed(() => form.value.trigger_type === 'offline');

const currentDevice = computed(() => devices.value.find((d) => d.id === form.value.device_id));
const metricOptions = computed(() => currentDevice.value?.metric_fields ?? []);
const conditionLabel = computed(() => CONDITIONS.find((c) => c.value === form.value.condition)?.label ?? '请选择');
const severityLabel = computed(() => SEVERITIES.find((s) => s.value === form.value.severity)?.label ?? '警告');

// 用 actionSheet 选择，规避 uni <picker> 在 range 由空变有时的渲染崩溃
function chooseDevice() {
  if (!devices.value.length) return toast('暂无设备');
  uni.showActionSheet({
    itemList: devices.value.map((d) => d.name),
    success: (r) => { form.value.device_id = devices.value[r.tapIndex]?.id; },
  });
}
function chooseCondition() {
  uni.showActionSheet({
    itemList: CONDITIONS.map((c) => c.label),
    success: (r) => { form.value.condition = CONDITIONS[r.tapIndex].value; },
  });
}
function chooseSeverity() {
  uni.showActionSheet({
    itemList: SEVERITIES.map((s) => s.label),
    success: (r) => { form.value.severity = SEVERITIES[r.tapIndex].value as AlertRuleInput['severity']; },
  });
}

async function loadRefs() {
  try {
    const [dev, ch] = await Promise.all([getDevices({ per_page: 100 }), getChannels({ per_page: 100 })]);
    devices.value = dev.items ?? [];
    channels.value = Array.isArray(ch) ? ch : [];
  } catch (e) {
    toast((e as Error).message);
  }
}

function pickMetric(key: string) {
  form.value.telemetry_key = key;
}
function toggleChannel(id: number) {
  const list = form.value.notification_channel_ids ?? [];
  form.value.notification_channel_ids = list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
}

async function save() {
  if (!form.value.name?.trim()) return toast('请填写规则名称');
  if (!form.value.device_id) return toast('请选择设备');

  const payload: AlertRuleInput = { ...form.value };
  if (isOffline.value) {
    payload.telemetry_key = null;
    payload.condition = null;
    payload.threshold_value = null;
  } else {
    if (!payload.telemetry_key) return toast('请选择/填写遥测指标');
    const nums = thresholdText.value.split(',').map((s) => Number(s.trim())).filter((n) => !Number.isNaN(n));
    if (isBetween.value && nums.length < 2) return toast('区间条件需填两个阈值，用逗号分隔');
    if (!nums.length) return toast('请填写阈值');
    payload.threshold_value = nums;
  }

  saving.value = true;
  try {
    if (editId.value) {
      await updateAlertRule(editId.value, payload);
    } else {
      await createAlertRule(payload);
    }
    toast('已保存', 'success');
    setTimeout(() => uni.navigateBack(), 500);
  } catch (e) {
    toast((e as Error).message);
  } finally {
    saving.value = false;
  }
}

onLoad(async (q) => {
  await loadRefs();
  if (q?.id) {
    editId.value = Number(q.id);
    try {
      const r = await getAlertRule(editId.value);
      form.value = {
        name: r.name,
        device_id: r.device_id,
        trigger_type: r.trigger_type,
        telemetry_key: r.telemetry_key ?? '',
        condition: r.condition ?? 'GREATER_THAN',
        threshold_value: r.threshold_value ?? [0],
        trigger_duration_sec: r.trigger_duration_sec,
        offline_timeout_sec: r.offline_timeout_sec,
        severity: r.severity,
        notify_cooldown_sec: r.notify_cooldown_sec,
        notify_on_recovery: r.notify_on_recovery,
        notification_channel_ids: r.notification_channel_ids ?? [],
        is_enabled: r.is_enabled,
      };
      thresholdText.value = (r.threshold_value ?? [0]).join(',');
    } catch (e) {
      toast((e as Error).message);
    }
  }
});
</script>

<template>
  <view class="page">
    <view class="card">
      <view class="field">
        <text class="label">规则名称</text>
        <input v-model="form.name" class="input" placeholder="如 客厅温度过高" placeholder-class="ph" />
      </view>

      <view class="field">
        <text class="label">关联设备</text>
        <view class="picker" @tap="chooseDevice">{{ currentDevice ? currentDevice.name : '请选择设备 ›' }}</view>
      </view>

      <view class="field">
        <text class="label">触发类型</text>
        <view class="seg">
          <view class="seg-btn" :class="{ on: !isOffline }" @tap="form.trigger_type = 'telemetry'">遥测阈值</view>
          <view class="seg-btn" :class="{ on: isOffline }" @tap="form.trigger_type = 'offline'">设备离线</view>
        </view>
      </view>

      <view class="field">
        <text class="label">告警级别</text>
        <view class="picker" @tap="chooseSeverity">{{ severityLabel }} ›</view>
      </view>
    </view>

    <!-- 遥测型字段 -->
    <view v-if="!isOffline" class="card">
      <view class="field">
        <text class="label">遥测指标</text>
        <input v-model="form.telemetry_key" class="input" placeholder="如 temperature" placeholder-class="ph" />
        <view v-if="metricOptions.length" class="metric-chips">
          <text
            v-for="m in metricOptions"
            :key="m.key"
            class="mchip"
            :class="{ on: form.telemetry_key === m.key }"
            @tap="pickMetric(m.key)"
          >{{ m.label }}</text>
        </view>
      </view>
      <view class="field">
        <text class="label">比较条件</text>
        <view class="picker" @tap="chooseCondition">{{ conditionLabel }} ›</view>
      </view>
      <view class="field">
        <text class="label">阈值{{ isBetween ? '（两个，逗号分隔）' : '' }}</text>
        <input v-model="thresholdText" class="input" :placeholder="isBetween ? '如 16,30' : '如 30'" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">持续时间（秒，0=立即）</text>
        <input v-model.number="form.trigger_duration_sec" type="number" class="input" placeholder="0" placeholder-class="ph" />
      </view>
    </view>

    <!-- 离线型字段 -->
    <view v-else class="card">
      <view class="field">
        <text class="label">离线超时（秒）</text>
        <input v-model.number="form.offline_timeout_sec" type="number" class="input" placeholder="120" placeholder-class="ph" />
      </view>
    </view>

    <!-- 通知设置 -->
    <view class="card">
      <view class="field">
        <text class="label">通知冷却（秒）</text>
        <input v-model.number="form.notify_cooldown_sec" type="number" class="input" placeholder="600" placeholder-class="ph" />
      </view>
      <view class="field row-between">
        <text class="label nomb">恢复时发通知</text>
        <switch :checked="form.notify_on_recovery" color="#2b6fe3" @change="form.notify_on_recovery = $event.detail.value" />
      </view>
      <view class="field">
        <text class="label">通知渠道</text>
        <view v-if="channels.length" class="metric-chips">
          <text
            v-for="c in channels"
            :key="c.id"
            class="mchip"
            :class="{ on: (form.notification_channel_ids ?? []).includes(c.id) }"
            @tap="toggleChannel(c.id)"
          >{{ c.name }}</text>
        </view>
        <text v-else class="hint">暂无通知渠道，可先在「通知渠道」创建</text>
      </view>
      <view class="field row-between">
        <text class="label nomb">启用规则</text>
        <switch :checked="form.is_enabled" color="#2b6fe3" @change="form.is_enabled = $event.detail.value" />
      </view>
    </view>

    <button class="btn btn-primary" :loading="saving" @tap="save">保存</button>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 60rpx; }
.card {
  background: $hg-card; border-radius: $hg-radius; padding: 8rpx 28rpx;
  box-shadow: $hg-shadow; margin-bottom: 20rpx;
}
.field { padding: 26rpx 0; border-bottom: 1rpx solid $hg-line; }
.field:last-child { border-bottom: none; }
.row-between { display: flex; align-items: center; justify-content: space-between; }
.label { display: block; font-size: 24rpx; color: $hg-muted; margin-bottom: 12rpx; }
.label.nomb { margin-bottom: 0; }
.input { font-size: 30rpx; color: $hg-fg; }
.ph { color: #b6bccb; }
.picker { font-size: 30rpx; color: $hg-fg; }
.seg { display: flex; gap: 12rpx; }
.seg-btn {
  flex: 1; text-align: center; padding: 16rpx 0; border-radius: $hg-radius-s;
  border: 1rpx solid $hg-line; background: $hg-card-2; color: $hg-muted; font-size: 26rpx;
}
.seg-btn.on { background: $hg-accent-soft; border-color: $hg-accent; color: $hg-accent; font-weight: 600; }
.metric-chips { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 8rpx; }
.mchip {
  padding: 10rpx 22rpx; border-radius: 999rpx; border: 1rpx solid $hg-line;
  background: $hg-card-2; color: $hg-muted; font-size: 24rpx;
}
.mchip.on { background: $hg-accent-soft; border-color: $hg-accent; color: $hg-accent; font-weight: 600; }
.hint { font-size: 24rpx; color: $hg-muted; }
.btn { border-radius: $hg-radius-s; height: 90rpx; line-height: 90rpx; font-size: 32rpx; }
.btn-primary { background: $hg-accent; color: #fff; }
</style>
