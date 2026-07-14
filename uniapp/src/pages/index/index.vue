<script setup lang="ts">
import { ref, computed } from 'vue';
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app';
import PageHeader from '@/components/PageHeader.vue';
import { getDevices, sendCommand } from '@/api/device';
import { getAlertLogs as fetchAlerts } from '@/api/alert';
import type { Device, ControlPoint } from '@/api/types';
import { ensureReady, toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';

const devices = ref<Device[]>([]);
const activeAlerts = ref(0);
const room = ref('全部');
const loading = ref(false);

const rooms = computed(() => {
  const set = new Set<string>();
  devices.value.forEach((d) => d.location && set.add(d.location));
  return ['全部', ...Array.from(set)];
});

const filtered = computed(() =>
  room.value === '全部' ? devices.value : devices.value.filter((d) => d.location === room.value),
);

const stats = computed(() => {
  const total = devices.value.length;
  const online = devices.value.filter((d) => d.is_online).length;
  return { total, online, offline: total - online };
});

/** 从设备 capability 提取一个可做快捷开关的控制点（merge 模式第一个 switch） */
function quickSwitch(d: Device): ControlPoint | null {
  const controls = d.capability?.controls ?? [];
  return controls.find((c) => c.widget === 'switch') ?? null;
}

function switchState(d: Device, ctrl: ControlPoint): boolean {
  const key = ctrl.state_key || ctrl.key;
  return !!(d.state && d.state[key]);
}

/** 首个遥测指标的展示值 */
function primaryMetric(d: Device): { text: string; unit: string } | null {
  const f = d.metric_fields?.[0];
  if (!f || !d.state) return null;
  const v = d.state[f.key];
  if (v === undefined || v === null) return null;
  return { text: String(v), unit: f.unit || '' };
}

async function load() {
  if (!ensureReady()) return;
  loading.value = true;
  try {
    const [devPage, alertPage] = await Promise.all([
      getDevices({ per_page: 100 }),
      fetchAlerts({ status: 'triggered', per_page: 1 }),
    ]);
    devices.value = devPage.items;
    activeAlerts.value = alertPage.total;
  } catch (e) {
    toast((e as Error).message);
  } finally {
    loading.value = false;
  }
}

async function toggle(d: Device, ctrl: ControlPoint) {
  const next = !switchState(d, ctrl);
  // 乐观更新
  if (!d.state) d.state = {};
  d.state[ctrl.state_key || ctrl.key] = next;
  try {
    await sendCommand(d.id, { action: ctrl.command, params: { [ctrl.param]: next } });
  } catch (e) {
    d.state[ctrl.state_key || ctrl.key] = !next; // 回滚
    toast((e as Error).message);
  }
}

function openDevice(d: Device) {
  uni.navigateTo({ url: `/pages/device/detail?id=${d.id}` });
}

onShow(load);
onPullDownRefresh(async () => {
  await load();
  uni.stopPullDownRefresh();
});
</script>

<template>
  <view class="page">
    <PageHeader title="我的家" :subtitle="loading ? '刷新中…' : '已连接'" />

    <!-- 状态横幅 -->
    <view class="banner">
      <view class="stat">
        <text class="big">{{ stats.total }}</text><text class="lbl">设备</text>
      </view>
      <view class="sep" />
      <view class="stat">
        <text class="big">{{ stats.online }}</text><text class="lbl">在线</text>
      </view>
      <view class="sep" />
      <view class="stat">
        <text class="big">{{ stats.offline }}</text><text class="lbl">离线</text>
      </view>
      <view v-if="activeAlerts > 0" class="alert-chip" @tap="uni.switchTab({ url: '/pages/alerts/alerts' })">
        <view class="dot" />
        <text>{{ activeAlerts }} 条告警</text>
      </view>
    </view>

    <!-- 房间筛选 -->
    <scroll-view scroll-x class="chips" :show-scrollbar="false">
      <view
        v-for="r in rooms"
        :key="r"
        class="chip"
        :class="{ on: r === room }"
        @tap="room = r"
      >{{ r }}</view>
    </scroll-view>

    <!-- 设备卡网格 -->
    <view class="grid">
      <view
        v-for="d in filtered"
        :key="d.id"
        class="dev"
        :class="{ off: !d.is_online }"
        @tap="openDevice(d)"
      >
        <view class="dev-top">
          <view class="dev-ic">{{ (d.name || '设备').slice(0, 1) }}</view>
          <view class="dev-dot" :class="{ gray: !d.is_online }" />
        </view>
        <view class="dev-mid">
          <text class="dev-name">{{ d.name }}</text>
          <text class="dev-meta">{{ d.location || '未分组' }} · {{ d.is_online ? timeAgo(d.last_seen) : '离线' }}</text>
        </view>

        <!-- 快捷开关 or 遥测值 -->
        <view class="dev-foot">
          <view
            v-if="quickSwitch(d)"
            class="sw"
            :class="{ on: switchState(d, quickSwitch(d)!) }"
            @tap.stop="toggle(d, quickSwitch(d)!)"
          />
          <template v-else-if="primaryMetric(d)">
            <text class="dev-val">{{ primaryMetric(d)!.text }}</text>
            <text class="dev-unit">{{ primaryMetric(d)!.unit }}</text>
          </template>
          <text v-else class="dev-val muted">--</text>
        </view>
      </view>
    </view>

    <view v-if="!loading && !filtered.length" class="empty">该房间暂无设备</view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding-bottom: 40rpx;
}
.banner {
  display: flex;
  align-items: center;
  margin: 8rpx 32rpx 24rpx;
  padding: 28rpx 30rpx;
  border-radius: $hg-radius;
  background: linear-gradient(112deg, $hg-accent, #5d93f2 82%);
  box-shadow: $hg-shadow;
}
.stat {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.big {
  font-size: 44rpx;
  font-weight: 700;
  color: #fff;
  line-height: 1.1;
}
.lbl {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.82);
}
.sep {
  width: 1rpx;
  height: 52rpx;
  background: rgba(255, 255, 255, 0.28);
  margin: 0 36rpx;
}
.alert-chip {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 10rpx;
  background: rgba(255, 255, 255, 0.16);
  border-radius: 999rpx;
  padding: 10rpx 20rpx;
}
.alert-chip text {
  font-size: 22rpx;
  color: #fff;
}
.alert-chip .dot {
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
  background: #ffd666;
}
.chips {
  white-space: nowrap;
  padding: 0 24rpx 20rpx;
}
.chip {
  display: inline-block;
  padding: 12rpx 28rpx;
  margin: 0 8rpx;
  border-radius: 999rpx;
  background: $hg-card;
  border: 1rpx solid $hg-line;
  font-size: 24rpx;
  color: $hg-muted;
}
.chip.on {
  background: $hg-accent;
  border-color: $hg-accent;
  color: #fff;
  font-weight: 600;
}
.grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20rpx;
  padding: 0 32rpx;
}
.dev {
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 26rpx 24rpx;
  box-shadow: $hg-shadow;
  display: flex;
  flex-direction: column;
  gap: 18rpx;
}
.dev.off {
  opacity: 0.62;
}
.dev-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}
.dev-ic {
  width: 72rpx;
  height: 72rpx;
  border-radius: 22rpx;
  background: $hg-accent-soft;
  color: $hg-accent;
  font-size: 32rpx;
  font-weight: 700;
  text-align: center;
  line-height: 72rpx;
}
.dev.off .dev-ic {
  background: $hg-card-2;
  color: $hg-muted;
}
.dev-dot {
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
  background: $hg-ok;
  margin-top: 8rpx;
}
.dev-dot.gray {
  background: $hg-muted;
  opacity: 0.5;
}
.dev-name {
  font-size: 28rpx;
  font-weight: 600;
  color: $hg-fg;
}
.dev-meta {
  display: block;
  font-size: 22rpx;
  color: $hg-muted;
  margin-top: 4rpx;
}
.dev-foot {
  display: flex;
  align-items: baseline;
  min-height: 52rpx;
}
.dev-val {
  font-size: 36rpx;
  font-weight: 700;
  color: $hg-fg;
}
.dev-val.muted {
  color: $hg-muted;
}
.dev-unit {
  font-size: 22rpx;
  color: $hg-muted;
  margin-left: 6rpx;
}
.sw {
  width: 88rpx;
  height: 52rpx;
  border-radius: 999rpx;
  background: $hg-line;
  position: relative;
  transition: background 0.18s;
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
.empty {
  text-align: center;
  color: $hg-muted;
  font-size: 26rpx;
  padding: 80rpx 0;
}
</style>
