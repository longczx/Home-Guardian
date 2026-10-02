<script setup lang="ts">
import { switchValue } from '@/utils/events';
import { ref, computed } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import {
  getAutomation, createAutomation, updateAutomation, type AutomationInput, type AutomationAction,
} from '@/api/automation';
import { modes, previewAutomation, type RulePreview } from '@/api/experience';
import { getDevices } from '@/api/device';
import { getChannels, type NotificationChannel } from '@/api/channel';
import { CONDITIONS } from '@/api/alertRule';
import type { Device } from '@/api/types';
import { toast } from '@/utils/guard';

const editId = ref(0);
const saving = ref(false);
const devices = ref<Device[]>([]);
const channels = ref<NotificationChannel[]>([]);

const policyModes = ref<string[]>([]);
const conditionLogic = ref('all');
const conditions = ref<{ device_id: number; metric_key: string; condition: string; value: number; max_age_sec: number }[]>([]);
const maxAge = ref(900); const windowStart = ref(''); const windowEnd = ref('');
const preview = ref<RulePreview | null>(null);
const originalConfig = ref<Record<string, unknown>>({});
const actionDrafts = ref<AutomationAction[]>([]); const selectedAction = ref(0);
function toggleMode(mode: string) { policyModes.value = policyModes.value.includes(mode) ? policyModes.value.filter((m) => m !== mode) : [...policyModes.value, mode]; }
function addCondition() {
  if (!devices.value.length || conditions.value.length >= 10) return toast('请先添加设备，组合条件最多 10 项');
  uni.showActionSheet({ itemList: devices.value.map((d) => d.name), success: (r) => { const d = devices.value[r.tapIndex]; conditions.value.push({ device_id: d.id, metric_key: d.metric_fields?.[0]?.key || '', condition: 'EQUALS', value: 0, max_age_sec: 900 }); } });
}
function chooseExtraCondition(index: number) { uni.showActionSheet({ itemList: CONDITIONS.map((c) => c.label), success: (r) => { conditions.value[index].condition = CONDITIONS[r.tapIndex].value; } }); }
async function inspect() { if (!editId.value) return toast('先保存规则后可试运行'); try { preview.value = await previewAutomation(editId.value); } catch (e) { toast((e as Error).message); } }
function selectAction(index: number) {
  const payload = buildPayload(); if (!payload) return;
  actionDrafts.value = payload.actions; selectedAction.value = index;
  const action = actionDrafts.value[index];
  f.value.actionType = action.type; f.value.actDeviceId = action.device_id; f.value.actName = action.payload?.action || ''; f.value.actionParams = JSON.stringify(action.payload?.params ?? {}); f.value.channelIds = action.channel_ids || [];
}
function addAction() {
  const payload = buildPayload(); if (!payload) return;
  actionDrafts.value = [...payload.actions, { type: 'device_command' }]; selectedAction.value = actionDrafts.value.length - 1;
  f.value.actionType = 'device_command'; f.value.actDeviceId = undefined; f.value.actName = ''; f.value.actionParams = '{}'; f.value.channelIds = [];
}
function removeAction(index: number) {
  if (actionDrafts.value.length <= 1) return toast('至少保留一个动作');
  actionDrafts.value.splice(index, 1); selectedAction.value = 0;
  const action = actionDrafts.value[0]; f.value.actionType = action.type; f.value.actDeviceId = action.device_id; f.value.actName = action.payload?.action || ''; f.value.actionParams = JSON.stringify(action.payload?.params ?? {}); f.value.channelIds = action.channel_ids || [];
}
// One trigger can carry several independent conditions and actions.
const f = ref({
  name: '',
  description: '',
  triggerType: 'telemetry' as 'telemetry' | 'schedule',
  // telemetry
  deviceId: undefined as number | undefined,
  metricKey: '',
  condition: 'GREATER_THAN',
  value: 0,
  durationSec: 0,
  cooldownSec: 0,
  // schedule
  cronMode: 'daily' as 'daily' | 'hourly' | 'weekday' | 'custom',
  cronTime: '22:00', // HH:MM，用于 daily/weekday
  cron: '0 22 * * *', // custom 模式的原始表达式
  // action
  actionType: 'device_command' as 'device_command' | 'notify',
  actDeviceId: undefined as number | undefined,
  actionParams: '{}',
  actName: '',        // 指令 action 名，如 turn_on
  channelIds: [] as number[],
  isEnabled: true,
});

const triggerDevice = computed(() => devices.value.find((d) => d.id === f.value.deviceId));
const actionDevice = computed(() => devices.value.find((d) => d.id === f.value.actDeviceId));
// 执行设备的可用指令（来自 capability.controls）：优先选择，避免手打错指令名
const actCommands = computed(() => {
  const ctrls = actionDevice.value?.capability?.controls ?? [];
  const seen = new Set<string>();
  return ctrls
    .filter((c) => c.command && !seen.has(c.command) && seen.add(c.command))
    .map((c) => ({ command: c.command, label: c.label || c.command }));
});
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
// 由模式 + 时间生成 cron；custom 模式用原始输入
function effectiveCron(): string {
  if (f.value.cronMode === 'custom') return f.value.cron.trim();
  if (f.value.cronMode === 'hourly') return '0 * * * *';
  const [hh, mm] = f.value.cronTime.split(':');
  const h = Number(hh) || 0;
  const m = Number(mm) || 0;
  return f.value.cronMode === 'weekday' ? `${m} ${h} * * 1-5` : `${m} ${h} * * *`;
}
const cronPreview = computed(() => effectiveCron());

function chooseActCommand() {
  if (!actCommands.value.length) return;
  uni.showActionSheet({
    itemList: actCommands.value.map((c) => `${c.label} (${c.command})`),
    success: (r) => { f.value.actName = actCommands.value[r.tapIndex].command; },
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
      duration_sec: Number(f.value.durationSec),
      cooldown_sec: Number(f.value.cooldownSec),
    };
  } else {
    const cron = effectiveCron();
    if (!cron) { toast('请填写 cron 表达式'); return null; }
    trigger_config = { cron, timezone: 'Asia/Shanghai' };
  }

  trigger_config = { ...originalConfig.value, ...trigger_config, modes: policyModes.value, conditions: conditions.value, condition_logic: conditionLogic.value, max_age_sec: Number(maxAge.value), time_window: windowStart.value && windowEnd.value ? { start: windowStart.value, end: windowEnd.value } : undefined };
  let action;
  if (f.value.actionType === 'device_command') {
    if (!f.value.actDeviceId) { toast('请选择执行设备'); return null; }
    if (!f.value.actName.trim()) { toast('请填写指令名'); return null; }
    let params: Record<string, unknown>;
    try {
      params = JSON.parse(f.value.actionParams);
      if (!params || typeof params !== 'object' || Array.isArray(params)) throw new Error();
    } catch { toast('动作参数必须是有效 JSON 对象'); return null; }
    action = { type: 'device_command' as const, device_id: f.value.actDeviceId, payload: { action: f.value.actName.trim(), params } };
  } else {
    if (!f.value.channelIds.length) { toast('请选择通知渠道'); return null; }
    action = { type: 'notify' as const, channel_ids: f.value.channelIds };
  }

  return {
    name: f.value.name.trim(),
    description: f.value.description.trim() || undefined,
    trigger_type: f.value.triggerType,
    trigger_config,
    actions: actionDrafts.value.length ? actionDrafts.value.map((a, i) => i === selectedAction.value ? action : a) : [action],
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
      originalConfig.value = tc; policyModes.value = (tc.modes as string[]) || []; conditionLogic.value = (tc.condition_logic as string) || 'all';
      conditions.value = (tc.conditions as typeof conditions.value) || []; maxAge.value = Number(tc.max_age_sec ?? 900);
      const window = tc.time_window as { start: string; end: string } | undefined; windowStart.value = window?.start || ''; windowEnd.value = window?.end || '';
      actionDrafts.value = a.actions || [];
      f.value = {
        name: a.name,
        description: a.description || '',
        triggerType: a.trigger_type,
        deviceId: (tc.device_id as number) || undefined,
        metricKey: (tc.metric_key as string) || '',
        condition: (tc.condition as string) || 'GREATER_THAN',
        value: (tc.value as number) ?? 0,
        durationSec: Number(tc.duration_sec ?? 0),
        cooldownSec: Number(tc.cooldown_sec ?? 0),
        cronMode: tc.cron ? 'custom' : 'daily',
        cronTime: '22:00',
        cron: (tc.cron as string) || '0 22 * * *',
        actionType: act?.type === 'notify' ? 'notify' : 'device_command',
        actDeviceId: act?.device_id,
        actName: act?.payload?.action || '',
        actionParams: JSON.stringify(act?.payload?.params ?? {}),
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

    <view class="card"><text class="ct">执行限制与组合条件</text>
      <view class="field"><text class="label">适用家庭模式（不选则不限）</text><view class="chips"><text v-for="mode in modes" :key="mode.value" class="chip" :class="{ on: policyModes.includes(mode.value) }" @tap="toggleMode(mode.value)">{{ mode.label }}</text></view></view>
      <view class="field"><text class="label">触发数据有效期（秒）</text><input v-model.number="maxAge" type="number" class="input" /><text class="hint">过期数据不用于试运行判断；组合条件分别判断有效期。</text></view>
      <view class="field"><text class="label">允许执行时间（北京时间，可跨午夜；留空则不限）</text><input v-model="windowStart" class="input" placeholder="开始 HH:MM" /><input v-model="windowEnd" class="input" placeholder="结束 HH:MM" /></view>
      <view class="chips"><text class="chip" :class="{ on: conditionLogic === 'all' }" @tap="conditionLogic = 'all'">全部条件满足</text><text class="chip" :class="{ on: conditionLogic === 'any' }" @tap="conditionLogic = 'any'">任一条件满足</text></view>
      <view v-for="(condition, index) in conditions" :key="index" class="field"><text class="label">{{ devices.find((d) => d.id === condition.device_id)?.name }} <text @tap="conditions.splice(index, 1)">删除</text></text><input v-model="condition.metric_key" class="input" placeholder="指标，如 temperature / door_open" /><view class="picker" @tap="chooseExtraCondition(index)">{{ CONDITIONS.find((c) => c.value === condition.condition)?.label }}</view><input v-model.number="condition.value" class="input" type="number" placeholder="比较值（布尔：1 是，0 否）" /><input v-model.number="condition.max_age_sec" class="input" type="number" placeholder="有效期秒数" /></view>
      <button size="mini" @tap="addCondition">增加组合条件</button>
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
        <view class="field"><text class="label">持续满足（秒）</text>
          <input v-model.number="f.durationSec" type="number" class="input" />
        </view>
        <view class="field"><text class="label">两次触发间隔（秒）</text>
          <input v-model.number="f.cooldownSec" type="number" class="input" />
        </view>
        <text class="hint">持续满足只执行一次，条件恢复后可再次触发。</text>
      </template>

      <template v-else>
        <view class="field"><text class="label">频率</text>
          <view class="seg wrap">
            <view class="seg-btn" :class="{ on: f.cronMode === 'daily' }" @tap="f.cronMode = 'daily'">每天</view>
            <view class="seg-btn" :class="{ on: f.cronMode === 'weekday' }" @tap="f.cronMode = 'weekday'">工作日</view>
            <view class="seg-btn" :class="{ on: f.cronMode === 'hourly' }" @tap="f.cronMode = 'hourly'">每小时</view>
            <view class="seg-btn" :class="{ on: f.cronMode === 'custom' }" @tap="f.cronMode = 'custom'">自定义</view>
          </view>
        </view>
        <view v-if="f.cronMode === 'daily' || f.cronMode === 'weekday'" class="field">
          <text class="label">时间</text>
          <picker mode="time" :value="f.cronTime" @change="f.cronTime = $event.detail.value">
            <view class="picker">{{ f.cronTime }} ›</view>
          </picker>
        </view>
        <view v-else-if="f.cronMode === 'custom'" class="field">
          <text class="label">Cron 表达式</text>
          <input v-model="f.cron" class="input" placeholder="0 22 * * *" placeholder-class="ph" />
          <text class="hint">格式：分 时 日 月 周</text>
        </view>
        <text class="hint" style="padding-left:0;">将执行：<text style="font-family:monospace;">{{ cronPreview }}</text></text>
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
        <view class="field"><text class="label">指令</text>
          <view v-if="actCommands.length" class="picker" @tap="chooseActCommand">
            {{ f.actName || '请选择指令 ›' }}
          </view>
          <input v-else v-model="f.actName" class="input" placeholder="如 turn_on / turn_off" placeholder-class="ph" />
        </view>
        <view class="field"><text class="label">动作参数（JSON）</text>
          <input v-model="f.actionParams" class="input" placeholder='例如 {"power":true,"temp":26}' />
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
        <switch :checked="f.isEnabled" color="#2b6fe3" @change="f.isEnabled = switchValue($event)" />
      </view>
    </view>

    <view class="card"><text class="ct">动作列表</text><view v-for="(action, index) in actionDrafts" :key="index" class="field"><text @tap="selectAction(index)">{{ selectedAction === index ? '正在编辑 ' : '点击编辑 ' }}动作 {{ index + 1 }}：{{ action.type === 'notify' ? '通知' : action.payload?.action || '待填写' }}</text><text class="hint" @tap="removeAction(index)">删除动作</text></view><button size="mini" @tap="addAction">添加动作（先填写当前动作）</button><text class="hint">按顺序提交；每个动作独立记录回执结果。人工控制后暂停该设备自动化 30 分钟。</text></view>
    <view v-if="editId" class="card"><button @tap="inspect">试运行已保存版本（不发送指令）</button><text v-if="preview" class="hint">{{ preview.eligible ? '当前快照满足条件' : '当前快照不满足条件' }}。{{ preview.note }}</text><text v-for="(check, i) in [...(preview?.checks || []), ...(preview?.conditions || [])]" :key="i" class="hint">{{ check.passed ? '✓' : '×' }} {{ check.label }}：{{ check.reason }}</text></view>
    <button class="btn btn-primary" :loading="saving" @tap="save">保存</button>
    <text class="foot-hint">支持数值和布尔指标（1/0）；只有条件再次恢复并满足时才重新触发。</text>
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
.seg.wrap { flex-wrap: wrap; }
.seg.wrap .seg-btn { flex: 1 1 40%; }
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
