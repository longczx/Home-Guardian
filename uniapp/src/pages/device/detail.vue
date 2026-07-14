<script setup lang="ts">
import { ref, computed } from 'vue';
import { onLoad, onShow } from '@dcloudio/uni-app';
import { getDevice, getLatestTelemetry, sendCommand } from '@/api/device';
import type { Device, ControlPoint, LatestMetric } from '@/api/types';
import { toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';

const id = ref(0);
const device = ref<Device | null>(null);
const latest = ref<LatestMetric[]>([]);
const loading = ref(false);

const controls = computed<ControlPoint[]>(() => device.value?.capability?.controls ?? []);

// 主显示：首个遥测指标的最新值（无遥测则不显示 hero 数字）
const hero = computed(() => {
  const f = device.value?.metric_fields?.[0];
  if (!f) return null;
  const m = latest.value.find((x) => x.metric_key === f.key);
  return m ? { value: String(m.value), unit: f.unit || '', label: f.label } : null;
});

function stateVal(ctrl: ControlPoint): unknown {
  const key = ctrl.state_key || ctrl.key;
  return device.value?.state?.[key];
}

function setLocal(ctrl: ControlPoint, v: unknown) {
  if (!device.value) return;
  if (!device.value.state) device.value.state = {};
  device.value.state[ctrl.state_key || ctrl.key] = v;
}

async function load() {
  loading.value = true;
  try {
    const [d, l] = await Promise.all([getDevice(id.value), getLatestTelemetry(id.value)]);
    device.value = d;
    latest.value = l;
  } catch (e) {
    toast((e as Error).message);
  } finally {
    loading.value = false;
  }
}

async function send(ctrl: ControlPoint, value: unknown) {
  const prev = stateVal(ctrl);
  setLocal(ctrl, value);
  try {
    await sendCommand(id.value, { action: ctrl.command, params: { [ctrl.param]: value } });
  } catch (e) {
    setLocal(ctrl, prev);
    toast((e as Error).message);
  }
}

function onSwitch(ctrl: ControlPoint) {
  send(ctrl, !stateVal(ctrl));
}
function onSlider(ctrl: ControlPoint, e: { detail: { value: number } }) {
  send(ctrl, e.detail.value);
}
function onEnum(ctrl: ControlPoint, value: string | number) {
  send(ctrl, value);
}

function goTelemetry() {
  const f = device.value?.metric_fields?.[0];
  uni.navigateTo({
    url: `/pages/device/telemetry?id=${id.value}&metric=${f?.key || ''}&label=${encodeURIComponent(f?.label || '')}&unit=${encodeURIComponent(f?.unit || '')}`,
  });
}

onLoad((q) => {
  id.value = Number(q?.id || 0);
});
onShow(load);
</script>

<template>
  <view class="page">
    <view v-if="device" class="nav">
      <text class="dot" :class="{ gray: !device.is_online }" />
      <text class="st">{{ device.is_online ? '在线' : '离线' }} · {{ device.type }}<text v-if="device.gateway_uid"> · 网关 {{ device.gateway_uid }}</text></text>
    </view>

    <!-- Hero -->
    <view v-if="hero" class="hero">
      <view class="hero-num">
        <text class="num">{{ hero.value }}</text><text class="unit">{{ hero.unit }}</text>
      </view>
      <text class="hero-sub">{{ hero.label }} · {{ device?.is_online ? '实时' : '最后 ' + timeAgo(device?.last_seen ?? null) }}</text>
    </view>

    <!-- 其余遥测指标 -->
    <view v-if="latest.length > 1" class="pills">
      <view v-for="m in latest.slice(0, 6)" :key="m.metric_key" class="pill">
        {{ m.metric_key }}: {{ m.value }}
      </view>
    </view>

    <!-- 动态控制 -->
    <view v-if="controls.length" class="ctl-card">
      <view v-for="ctrl in controls" :key="ctrl.key" class="ctl-row">
        <view class="ctl-head">
          <text class="ctl-label">{{ ctrl.label }}</text>
          <text class="ctl-sub">{{ ctrl.command }} · 经网关下发</text>
        </view>

        <!-- switch -->
        <view
          v-if="ctrl.widget === 'switch'"
          class="sw"
          :class="{ on: !!stateVal(ctrl) }"
          @tap="onSwitch(ctrl)"
        />

        <!-- enum → 分段 -->
        <view v-else-if="ctrl.widget === 'enum'" class="seg">
          <view
            v-for="op in ctrl.options || []"
            :key="String(op.value)"
            class="seg-btn"
            :class="{ on: stateVal(ctrl) === op.value }"
            @tap="onEnum(ctrl, op.value)"
          >{{ op.label }}</view>
        </view>

        <!-- slider -->
        <view v-else-if="ctrl.widget === 'slider'" class="slider-wrap">
          <slider
            :min="ctrl.min ?? 0"
            :max="ctrl.max ?? 100"
            :step="ctrl.step ?? 1"
            :value="Number(stateVal(ctrl) ?? ctrl.min ?? 0)"
            :block-size="20"
            activeColor="#2b6fe3"
            show-value
            @change="onSlider(ctrl, $event)"
          />
        </view>

        <!-- button -->
        <button
          v-else-if="ctrl.widget === 'button'"
          class="mini-btn"
          @tap="send(ctrl, ctrl.default ?? true)"
        >执行</button>

        <text v-else class="ctl-val">{{ stateVal(ctrl) ?? '--' }}{{ ctrl.unit || '' }}</text>
      </view>
    </view>

    <!-- 趋势入口 -->
    <view v-if="device?.metric_fields?.length" class="trend-entry" @tap="goTelemetry">
      <text>查看遥测趋势</text><text class="arrow">›</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 24rpx 32rpx 60rpx;
}
.nav {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-bottom: 20rpx;
}
.dot {
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
  background: $hg-ok;
}
.dot.gray {
  background: $hg-muted;
  opacity: 0.5;
}
.st {
  font-size: 24rpx;
  color: $hg-muted;
}
.hero {
  background: $hg-card;
  border-radius: $hg-radius;
  box-shadow: $hg-shadow;
  padding: 44rpx 32rpx 36rpx;
  text-align: center;
}
.hero-num {
  display: flex;
  align-items: baseline;
  justify-content: center;
}
.num {
  font-size: 96rpx;
  font-weight: 700;
  color: $hg-fg;
  line-height: 1;
}
.unit {
  font-size: 36rpx;
  color: $hg-muted;
  margin-left: 8rpx;
}
.hero-sub {
  display: block;
  margin-top: 16rpx;
  font-size: 24rpx;
  color: $hg-muted;
}
.pills {
  display: flex;
  flex-wrap: wrap;
  gap: 14rpx;
  margin-top: 20rpx;
}
.pill {
  background: $hg-card-2;
  color: $hg-muted;
  font-size: 22rpx;
  padding: 8rpx 20rpx;
  border-radius: 999rpx;
}
.ctl-card {
  margin-top: 24rpx;
  background: $hg-card;
  border-radius: $hg-radius;
  box-shadow: $hg-shadow;
  padding: 8rpx 28rpx;
}
.ctl-row {
  padding: 28rpx 0;
  border-bottom: 1rpx solid $hg-line;
}
.ctl-row:last-child {
  border-bottom: none;
}
.ctl-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.ctl-label {
  font-size: 28rpx;
  font-weight: 600;
  color: $hg-fg;
}
.ctl-sub {
  font-size: 22rpx;
  color: $hg-muted;
}
.sw {
  margin-top: 20rpx;
  width: 88rpx;
  height: 52rpx;
  border-radius: 999rpx;
  background: $hg-line;
  position: relative;
}
.sw::after {
  content: '';
  position: absolute;
  top: 6rpx;
  left: 6rpx;
  width: 40rpx;
  height: 40rpx;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1rpx 4rpx rgba(0, 0, 0, 0.25);
  transition: left 0.18s;
}
.sw.on {
  background: $hg-ok;
}
.sw.on::after {
  left: 42rpx;
}
.seg {
  display: flex;
  gap: 12rpx;
  margin-top: 20rpx;
}
.seg-btn {
  flex: 1;
  text-align: center;
  padding: 16rpx 0;
  border-radius: $hg-radius-s;
  border: 1rpx solid $hg-line;
  background: $hg-card-2;
  color: $hg-muted;
  font-size: 24rpx;
}
.seg-btn.on {
  background: $hg-accent-soft;
  border-color: $hg-accent;
  color: $hg-accent;
  font-weight: 600;
}
.slider-wrap {
  margin-top: 10rpx;
}
.mini-btn {
  margin-top: 20rpx;
  background: $hg-accent;
  color: #fff;
  font-size: 26rpx;
  height: 68rpx;
  line-height: 68rpx;
  border-radius: $hg-radius-s;
}
.ctl-val {
  display: block;
  margin-top: 16rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: $hg-fg;
}
.trend-entry {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 24rpx;
  padding: 30rpx 28rpx;
  background: $hg-card;
  border-radius: $hg-radius;
  box-shadow: $hg-shadow;
  font-size: 28rpx;
  color: $hg-fg;
}
.trend-entry .arrow {
  color: $hg-muted;
  font-size: 36rpx;
}
</style>
