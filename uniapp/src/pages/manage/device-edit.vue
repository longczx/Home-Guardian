<script setup lang="ts">
import { ref, computed } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { getDevice, updateDevice } from '@/api/device';
import { getMetricDefinitions, createMetricDefinition, type MetricDefinition } from '@/api/metricDefinition';
import { getCapabilityTemplates, type CapabilityTemplate } from '@/api/capabilityTemplate';
import type { Device, Capability } from '@/api/types';
import { toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';

const id = ref(0);
const device = ref<Device | null>(null);
const name = ref('');
const location = ref('');
const type = ref('');
const saving = ref(false);

// 设备类型：决定 App 里的归类与图标；ac/switch/light/curtain 为执行器类，
// 配合「能力模板」才会渲染出控制卡。gateway 另有含义（见 chooseType 提示）。
const TYPES = [
  { value: 'sensor', label: '传感器' },
  { value: 'ac', label: '空调' },
  { value: 'switch', label: '开关' },
  { value: 'light', label: '灯' },
  { value: 'curtain', label: '窗帘' },
  { value: 'gateway', label: '网关' },
];
const typeLabel = computed(() => TYPES.find((t) => t.value === type.value)?.label ?? type.value ?? '未设置');

// 用 actionSheet 选择，与本项目其他表单一致（规避 uni <picker> 渲染崩溃）
function chooseType() {
  uni.showActionSheet({
    itemList: TYPES.map((t) => t.label),
    success: (r) => {
      const next = TYPES[r.tapIndex]?.value;
      if (!next) return;
      // 网关是"代其下子设备收发 MQTT"的角色，且后端按 type==='gateway'
      // 才会在它离线时批量下线子设备，误改会让子设备一直卡在在线
      if (type.value === 'gateway' && next !== 'gateway') {
        uni.showModal({
          title: '确认改类型？',
          content: '该设备当前是网关。改为其他类型后，它离线时其下子设备将不再自动下线。',
          success: (m) => { if (m.confirm) type.value = next; },
        });
        return;
      }
      type.value = next;
    },
  });
}

// 能力模板：给执行器套上后详情页才会渲染控制卡（电源/模式/温度…）
const templates = ref<CapabilityTemplate[]>([]);
const capability = ref<Capability | null>(null);
const capabilityLabel = computed(() => {
  if (!capability.value) return '无（仅遥测展示）';
  const hit = templates.value.find(
    (t) => t.control_mode === capability.value?.control_mode
      && t.controls?.length === capability.value?.controls?.length,
  );
  return hit ? hit.name : `自定义（${capability.value.controls?.length ?? 0} 个控件）`;
});

function chooseCapability() {  const items = ['无（仅遥测展示）', ...templates.value.map((t) => t.name)];
  uni.showActionSheet({
    itemList: items,
    success: (r) => {
      if (r.tapIndex === 0) {
        capability.value = null;
        return;
      }
      const t = templates.value[r.tapIndex - 1];
      if (!t) return;
      capability.value = { control_mode: t.control_mode, controls: t.controls };
      // 套模板时顺带对齐类型，省得用户再选一次
      if (t.device_category && type.value !== 'gateway') {
        type.value = t.device_category;
      }
    },
  });
}

// 遥测字段字典
const defs = ref<MetricDefinition[]>([]);// 已选字段：key → {label, unit}（字典项与自定义项统一存这里）
const selected = ref<Record<string, { label: string; unit: string }>>({});

// 自定义添加表单
const showCustom = ref(false);
const cf = ref({ key: '', label: '', unit: '', saveToDict: false });

// 已选中但不在字典里的（历史/自定义字段）
const customSelected = computed(() => {
  const dictKeys = new Set(defs.value.map((d) => d.metric_key));
  return Object.keys(selected.value).filter((k) => !dictKeys.has(k));
});

function isSelected(key: string) {
  return Object.prototype.hasOwnProperty.call(selected.value, key);
}

function toggleDef(d: MetricDefinition) {
  if (isSelected(d.metric_key)) {
    removeKey(d.metric_key);
  } else {
    selected.value = { ...selected.value, [d.metric_key]: { label: d.label, unit: d.unit || '' } };
  }
}

function removeKey(key: string) {
  const next = { ...selected.value };
  delete next[key];
  selected.value = next;
}

async function addCustom() {
  const key = cf.value.key.trim();
  if (!key) return toast('请填写字段 key');
  const label = cf.value.label.trim() || key;
  const unit = cf.value.unit.trim();

  // 存入字典（失败不阻断，仅提示；重复 key 视为已存在照常选中）
  if (cf.value.saveToDict) {
    try {
      const created = await createMetricDefinition({ metric_key: key, label, unit });
      defs.value = [...defs.value, created];
    } catch (e) {
      toast('未能存入字典：' + (e as Error).message);
    }
  }

  selected.value = { ...selected.value, [key]: { label, unit } };
  cf.value = { key: '', label: '', unit: '', saveToDict: false };
  showCustom.value = false;
}

async function load() {
  try {
    const [d, list] = await Promise.all([getDevice(id.value), getMetricDefinitions().catch(() => [])]);
    device.value = d;
    name.value = d.name;
    location.value = d.location || '';
    type.value = d.type || '';
    capability.value = d.capability || null;
    defs.value = Array.isArray(list) ? list : [];
    // 模板列表拿不到不影响其余编辑，失败静默降级
    templates.value = await getCapabilityTemplates().catch(() => []);

    const sel: Record<string, { label: string; unit: string }> = {};
    (d.metric_fields || []).forEach((m) => {
      sel[m.key] = { label: m.label || m.key, unit: m.unit || '' };
    });
    selected.value = sel;
  } catch (e) {
    toast((e as Error).message);
  }
}

async function save() {
  if (!name.value.trim()) return toast('名称不能为空');
  const mf = Object.entries(selected.value).map(([key, v]) => ({
    key,
    label: v.label || key,
    unit: v.unit || '',
  }));
  saving.value = true;
  try {
    await updateDevice(id.value, {
      name: name.value.trim(),
      location: location.value.trim(),
      type: type.value || undefined,
      capability: capability.value,
      metric_fields: mf,
    });
    toast('已保存', 'success');
    setTimeout(() => uni.navigateBack(), 500);
  } catch (e) {
    toast((e as Error).message);
  } finally {
    saving.value = false;
  }
}

function openModules() {
  uni.navigateTo({ url: `/pages/manage/gateway-modules?id=${id.value}` });
}

onLoad((q) => {
  id.value = Number(q?.id || 0);
  load();
});</script>

<template>
  <view class="page">
    <!-- 只读信息 -->
    <view v-if="device" class="card info">
      <view class="irow"><text class="ik">设备标识</text><text class="iv">{{ device.device_uid }}</text></view>
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
      <view class="field">
        <text class="label">设备类型</text>
        <view class="picker" @tap="chooseType">{{ typeLabel }} ›</view>
      </view>
      <view class="field">
        <text class="label">控制能力（执行器需要）</text>
        <view class="picker" @tap="chooseCapability">{{ capabilityLabel }} ›</view>
      </view>
      <!-- 网关才有子设备可热插拔 -->
      <view v-if="type === 'gateway'" class="field">
        <text class="label">子设备</text>
        <view class="picker" @tap="openModules">管理挂载的传感器 / 执行器 ›</view>
      </view>
    </view>

    <!-- 遥测指标：从字典勾选 -->
    <view class="card">
      <view class="mhead">
        <text class="ct">遥测指标</text>
      </view>
      <text class="hint">从字典勾选设备上报的字段，key 自动对齐——避免手打拼错导致图表不显示、告警不触发。</text>

      <view v-if="defs.length" class="chips">
        <text
          v-for="d in defs"
          :key="d.metric_key"
          class="chip"
          :class="{ on: isSelected(d.metric_key) }"
          @tap="toggleDef(d)"
        >{{ d.icon }} {{ d.label }}<text v-if="d.unit" class="cu"> · {{ d.unit }}</text></text>
      </view>
      <text v-else class="empty">字典为空，可在下方自定义添加</text>

      <!-- 已选的自定义字段（不在字典中） -->
      <view v-if="customSelected.length" class="custom-list">
        <text class="sub">自定义字段</text>
        <view v-for="k in customSelected" :key="k" class="crow">
          <text class="ck">{{ selected[k].label }} · {{ k }}<text v-if="selected[k].unit"> · {{ selected[k].unit }}</text></text>
          <text class="mdel" @tap="removeKey(k)">✕</text>
        </view>
      </view>

      <!-- 自定义添加 -->
      <view class="add-wrap">
        <text class="add" @tap="showCustom = !showCustom">{{ showCustom ? '收起' : '＋ 自定义字段' }}</text>
        <view v-if="showCustom" class="cform">
          <input v-model="cf.key" class="min" placeholder="key（须与设备上报一致，如 temperature）" placeholder-class="ph" />
          <input v-model="cf.label" class="min" placeholder="显示名称，如 温度" placeholder-class="ph" />
          <input v-model="cf.unit" class="min" placeholder="单位（可空），如 °C" placeholder-class="ph" />
          <view class="save-dict" @tap="cf.saveToDict = !cf.saveToDict">
            <text class="cbox">{{ cf.saveToDict ? '☑' : '☐' }}</text>
            <text class="cbox-txt">同时存入字典，下次可直接勾选</text>
          </view>
          <button class="mini-btn" @tap="addCustom">添加</button>
        </view>
      </view>
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
.picker { font-size: 30rpx; color: $hg-fg; }
.ph { color: #b6bccb; }
.mhead { display: flex; align-items: center; justify-content: space-between; padding: 22rpx 0 4rpx; }
.ct { font-size: 26rpx; font-weight: 600; color: $hg-fg; }
.hint { display: block; font-size: 22rpx; color: $hg-muted; padding-bottom: 16rpx; line-height: 1.5; }
.chips { display: flex; flex-wrap: wrap; gap: 14rpx; padding-bottom: 6rpx; }
.chip {
  padding: 12rpx 24rpx; border-radius: 999rpx; border: 1rpx solid $hg-line;
  background: $hg-card-2; color: $hg-muted; font-size: 24rpx;
}
.chip.on { background: $hg-accent-soft; border-color: $hg-accent; color: $hg-accent; font-weight: 600; }
.cu { font-size: 22rpx; opacity: 0.8; }
.empty { display: block; text-align: center; color: $hg-muted; font-size: 24rpx; padding: 16rpx 0; }
.custom-list { margin-top: 18rpx; }
.sub { display: block; font-size: 22rpx; color: $hg-muted; margin-bottom: 8rpx; }
.crow { display: flex; align-items: center; justify-content: space-between; padding: 14rpx 0; border-top: 1rpx solid $hg-line; }
.ck { font-size: 26rpx; color: $hg-fg; }
.mdel { flex: none; color: $hg-crit; font-size: 28rpx; padding: 0 6rpx; }
.add-wrap { padding: 18rpx 0 8rpx; }
.add { font-size: 26rpx; color: $hg-accent; }
.cform { margin-top: 16rpx; display: flex; flex-direction: column; gap: 14rpx; }
.min { font-size: 26rpx; color: $hg-fg; background: $hg-card-2; border-radius: 10rpx; padding: 16rpx; }
.save-dict { display: flex; align-items: center; gap: 10rpx; }
.cbox { font-size: 30rpx; color: $hg-accent; }
.cbox-txt { font-size: 24rpx; color: $hg-muted; }
.mini-btn { background: $hg-accent; color: #fff; font-size: 26rpx; height: 72rpx; line-height: 72rpx; border-radius: $hg-radius-s; }
.btn { border-radius: $hg-radius-s; height: 90rpx; line-height: 90rpx; font-size: 32rpx; }
.btn-primary { background: $hg-accent; color: #fff; }
</style>
