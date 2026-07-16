<script setup lang="ts">
import { ref, computed } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import {
  getAutomation, createAutomation, updateAutomation, type AutomationInput,
} from '@/api/automation';
import { getDevices } from '@/api/device';
import { getChannels, type NotificationChannel } from '@/api/channel';
import { CONDITIONS } from '@/api/alertRule';
import type { Device } from '@/api/types';
import { toast } from '@/utils/guard';

const editId = ref(0);
const saving = ref(false);
const devices = ref<Device[]>([]);
const channels = ref<NotificationChannel[]>([]);

// 简化模型：单触发 + 单动作
const f = ref({
  name: '',
  description: '',
  triggerType: 'telemetry' as 'telemetry' | 'schedule',
  // telemetry
  deviceId: undefined as number | undefined,
  metricKey: '',
  condition: 'GREATER_THAN',
  value: 0,
  // schedule
  cron: '0 22 * * *',
  // action
  actionType: 'device_command' as 'device_command' | 'notify',
  actDeviceId: undefined as number | undefined,
  actName: '',        // 指令 action 名，如 turn_on
  channelIds: [] as number[],
  isEnabled: true,
});

const triggerDevice = computed(() => devices.value.find((d) => d.id === f.value.deviceId));
const actionDevice = computed(() => devices.value.find((d) => d.id === f.value.actDeviceId));
const conditionLabel = computed(() => CONDITIONS.find((c) => c.value === f.value.condition)?.label ?? '请选择');
const metricOptions = computed(() => triggerDevice.value?.metric_fields ?? []);

async function loadRefs() {
  try {
    const [dev, ch] = await Promise.all([getDevices({ per_page: 100 }), getChannels({ per_page: 100 })]);
    devices.value = dev.items ?? [];
    channels.value = Array.isArray(ch) ? ch : [];
  } catch (e) {
    toast((e as Error).message);
  }
}

function chooseDevice(target: 'trigger' | 'action') {
  if (!devices.value.length) return toast('暂无设备');
  uni.showActionSheet({
    itemList: devices.value.map((d) => d.name),
    success: (r) => {
      const id = devices.value[r.tapIndex]?.id;
      if (target === 'trigger') f.value.deviceId = id;
      else f.value.actDeviceId = id;
    },
  });
}
function chooseCondition() {
  uni.showActionSheet({
    itemList: CONDITIONS.map((c) => c.label),
    success: (r) => { f.value.condition = CONDITIONS[r.tapIndex].value; },
  });
}
function toggleChannel(id: number) {
  f.value.channelIds = f.value.channelIds.includes(id)
    ? f.value.channelIds.filter((x) => x !== id)
    : [...f.value.channelIds, id];
}

function buildPayload(): AutomationInput | null {
  if (!f.value.name.trim()) { toast('请填写名称'); return null; }

  let trigger_config: Record<string, unknown>;
  if (f.value.triggerType === 'telemetry') {
    if (!f.value.deviceId) { toast('请选择触发设备'); return null; }
    if (!f.value.metricKey.trim()) { toast('请填写触发指标'); return null; }
    trigger_config = {
      device_id: f.value.deviceId,
      metric_key: f.value.metricKey.trim(),
      condition: f.value.condition,
      value: Number(f.value.value),
    };
  } else {
    if (!f.value.cron.trim()) { toast('请填写 cron 表达式'); return null; }
    trigger_config = { cron: f.value.cron.trim(), timezone: 'Asia/Shanghai' };
  }

  let action;
  if (f.value.actionType === 'device_command') {
    if (!f.value.actDeviceId) { toast('请选择执行设备'); return null; }
    if (!f.value.actName.trim()) { toast('请填写指令名'); return null; }
    action = { type: 'device_command' as const, device_id: f.value.actDeviceId, payload: { action: f.value.actName.trim() } };
  } else {
    if (!f.value.channelIds.length) { toast('请选择通知渠道'); return null; }
    action = { type: 'notify' as const, channel_ids: f.value.channelIds };
  }

  return {
    name: f.value.name.trim(),
    description: f.value.description.trim() || undefined,
    trigger_type: f.value.triggerType,
    trigger_config,
    actions: [action],
    is_enabled: f.value.isEnabled,
  };
}

async function save() {
  const payload = buildPayload();
  if (!payload) return;
  saving.value = true;
  try {
    if (editId.value) await updateAutomation(editId.value, payload);
    else await createAutomation(payload);
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
      const a = await getAutomation(editId.value);
      const tc = a.trigger_config || {};
      const act = a.actions?.[0];
      f.value = {
        name: a.name,
        description: a.description || '',
        triggerType: a.trigger_type,
        deviceId: (tc.device_id as number) || undefined,
        metricKey: (tc.metric_key as string) || '',
        condition: (tc.condition as string) || 'GREATER_THAN',
        value: (tc.value as number) ?? 0,
        cron: (tc.cron as string) || '0 22 * * *',
        actionType: act?.type === 'notify' ? 'notify' : 'device_command',
        actDeviceId: act?.device_id,
        actName: act?.payload?.action || '',
        channelIds: act?.channel_ids || [],
        isEnabled: a.is_enabled,
      };
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
        <text class="label">名称</text>
        <input v-model="f.name" class="input" placeholder="如 夜间自动关灯" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">描述（可选）</text>
        <input v-model="f.description" class="input" placeholder="" placeholder-class="ph" />
      </view>
    </view>

    <!-- 触发 -->
    <view class="card">
      <text class="ct">触发条件</text>
      <view class="seg">
        <view class="seg-btn" :class="{ on: f.triggerType === 'telemetry' }" @tap="f.triggerType = 'telemetry'">遥测条件</view>
        <view class="seg-btn" :class="{ on: f.triggerType === 'schedule' }" @tap="f.triggerType = 'schedule'">定时</view>
      </view>

      <template v-if="f.triggerType === 'telemetry'">
        <view class="field"><text class="label">设备</text>
          <view class="picker" @tap="chooseDevice('trigger')">{{ triggerDevice ? triggerDevice.name : '请选择设备 ›' }}</view>
        </view>
        <view class="field"><text class="label">指标</text>
          <input v-model="f.metricKey" class="input" placeholder="如 temperature" placeholder-class="ph" />
          <view v-if="metricOptions.length" class="chips">
            <text v-for="m in metricOptions" :key="m.key" class="chip" :class="{ on: f.metricKey === m.key }" @tap="f.metricKey = m.key">{{ m.label }}</text>
          </view>
        </view>
        <view class="field"><text class="label">条件</text>
          <view class="picker" @tap="chooseCondition">{{ conditionLabel }} ›</view>
        </view>
        <view class="field"><text class="label">阈值</text>
          <input v-model.number="f.value" type="number" class="input" placeholder="如 30" placeholder-class="ph" />
        </view>
      </template>

      <template v-else>
        <view class="field"><text class="label">Cron 表达式</text>
          <input v-model="f.cron" class="input" placeholder="0 22 * * *（每晚 22:00）" placeholder-class="ph" />
          <text class="hint">格式：分 时 日 月 周。示例 0 22 * * * = 每天 22:00</text>
        </view>
      </template>
    </view>

    <!-- 动作 -->
    <view class="card">
      <text class="ct">执行动作</text>
      <view class="seg">
        <view class="seg-btn" :class="{ on: f.actionType === 'device_command' }" @tap="f.actionType = 'device_command'">控制设备</view>
        <view class="seg-btn" :class="{ on: f.actionType === 'notify' }" @tap="f.actionType = 'notify'">发送通知</view>
      </view>

      <template v-if="f.actionType === 'device_command'">
        <view class="field"><text class="label">目标设备</text>
          <view class="picker" @tap="chooseDevice('action')">{{ actionDevice ? actionDevice.name : '请选择设备 ›' }}</view>
        </view>
        <view class="field"><text class="label">指令名</text>
          <input v-model="f.actName" class="input" placeholder="如 turn_on / turn_off" placeholder-class="ph" />
        </view>
      </template>

      <template v-else>
        <view class="field"><text class="label">通知渠道</text>
          <view v-if="channels.length" class="chips">
            <text v-for="c in channels" :key="c.id" class="chip" :class="{ on: f.channelIds.includes(c.id) }" @tap="toggleChannel(c.id)">{{ c.name }}</text>
          </view>
          <text v-else class="hint">暂无渠道，可先在「通知渠道」创建</text>
        </view>
      </template>
    </view>

    <view class="card">
      <view class="field row-between">
        <text class="label nomb">启用</text>
        <switch :checked="f.isEnabled" color="#2b6fe3" @change="f.isEnabled = $event.detail.value" />
      </view>
    </view>

    <button class="btn btn-primary" :loading="saving" @tap="save">保存</button>
    <text class="foot-hint">多动作/复杂规则请在后台配置。</text>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 60rpx; }
.card { background: $hg-card; border-radius: $hg-radius; padding: 8rpx 28rpx; box-shadow: $hg-shadow; margin-bottom: 20rpx; }
.ct { display: block; font-size: 26rpx; font-weight: 600; color: $hg-fg; padding: 22rpx 0 4rpx; }
.field { padding: 24rpx 0; border-bottom: 1rpx solid $hg-line; }
.field:last-child { border-bottom: none; }
.row-between { display: flex; align-items: center; justify-content: space-between; }
.label { display: block; font-size: 24rpx; color: $hg-muted; margin-bottom: 12rpx; }
.label.nomb { margin-bottom: 0; }
.input { font-size: 30rpx; color: $hg-fg; }
.ph { color: #b6bccb; }
.picker { font-size: 30rpx; color: $hg-fg; }
.seg { display: flex; gap: 12rpx; margin: 12rpx 0; }
.seg-btn { flex: 1; text-align: center; padding: 16rpx 0; border-radius: $hg-radius-s; border: 1rpx solid $hg-line; background: $hg-card-2; color: $hg-muted; font-size: 26rpx; }
.seg-btn.on { background: $hg-accent-soft; border-color: $hg-accent; color: $hg-accent; font-weight: 600; }
.chips { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 12rpx; }
.chip { padding: 10rpx 22rpx; border-radius: 999rpx; border: 1rpx solid $hg-line; background: $hg-card-2; color: $hg-muted; font-size: 24rpx; }
.chip.on { background: $hg-accent-soft; border-color: $hg-accent; color: $hg-accent; font-weight: 600; }
.hint { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 8rpx; }
.btn { border-radius: $hg-radius-s; height: 90rpx; line-height: 90rpx; font-size: 32rpx; }
.btn-primary { background: $hg-accent; color: #fff; }
.foot-hint { display: block; text-align: center; font-size: 22rpx; color: $hg-muted; margin-top: 20rpx; }
</style>
