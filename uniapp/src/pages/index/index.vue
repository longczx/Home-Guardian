<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { onShow, onHide, onPullDownRefresh } from '@dcloudio/uni-app';
import PageHeader from '@/components/PageHeader.vue';
import { sendCommand, getCommandResult } from '@/api/device';
import { getHomeOverview, modes, type HomeMode } from '@/api/experience';
import { withCommandResult } from '@/utils/command-result';
import { useAuthStore } from '@/stores/auth';
import type { Device, ControlPoint } from '@/api/types';
import { ensureReady, toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';
import { onWs, wsConnected } from '@/utils/ws';
import { setOverride } from '@/api/experience';
import { useServerStore } from '@/stores/server';

const { t } = useI18n();
const auth = useAuthStore();
const mode = ref<HomeMode>('home');
const onlyFavorites = ref(false);
const pendingDevices = ref<number[]>([]);
function openMode() { uni.navigateTo({ url: '/pages/manage/home-mode' }); }
function openAlerts() { uni.switchTab({ url: '/pages/alerts/alerts' }); }
const ALL = '\u0000all'; // 房间「全部」的内部标识，避免与真实房间名冲突
const devices = ref<Device[]>([]);
const activeAlerts = ref(0);
const room = ref(ALL);
const configuredRooms = ref<string[]>([]);
const loading = ref(false);
const loadError = ref(''); const syncedAt = ref(0); const now = ref(Date.now());
const backupHealth = ref<import('@/api/operations').BackupHealth | null>(null);
const gatewayCount = ref(0); const server = useServerStore(); let loadGeneration = 0;
let clock: ReturnType<typeof setInterval> | null = null;
const connectionLabel = computed(() => loading.value ? '正在同步…' : loadError.value ? '同步失败 · 显示上次数据' : !syncedAt.value ? '尚未同步' : `${wsConnected.value ? '实时连接正常' : '实时连接中断 · 可下拉刷新'} · 同步于 ${new Date(syncedAt.value).toLocaleTimeString()}`);
function holdMinutes(d: Device) { return Math.max(0, Math.ceil((Date.parse(d.manual_override_until || '') - Math.max(now.value, Date.now())) / 60000)) || 0; }
async function resume(d: Device) {
  try { await setOverride(d.id, 0); d.manual_override_until = null; toast('已恢复自动化', 'success'); }
  catch (e) { toast((e as Error).message); }
}
function addDevice(module = false) { uni.navigateTo({ url: '/pages/manage/device-add' + (module ? '?mode=module' : '') }); }
watch(() => server.connectionRevision, () => { loadGeneration++; backupHealth.value = null; devices.value = []; configuredRooms.value = []; gatewayCount.value = 0; syncedAt.value = 0; loadError.value = ''; pendingDevices.value = []; });

const rooms = computed(() => {
  const set = new Set<string>();
  devices.value.forEach((d) => d.location && set.add(d.location));
  return [ALL, ...Array.from(new Set([...configuredRooms.value, ...Array.from(set)]))];
});

function roomLabel(r: string): string {
  return r === ALL ? t('home.allRooms') : r;
}

const filtered = computed(() =>
  devices.value.filter((d) => (room.value === ALL || d.location === room.value) && (!onlyFavorites.value || d.is_favorite)).sort((a, b) => Number(!!b.is_favorite) - Number(!!a.is_favorite)),
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
  if (!f) return null;
  const reading = d.latest_metrics?.find((m) => m.metric_key === f.key);
  if (!reading?.fresh) return { text: '数据过期', unit: '' };
  const v = reading.value;
  if (v === undefined || v === null) return null;
  return { text: String(v), unit: f.unit || '' };
}

async function load() {
  if (!ensureReady()) return;
  const attempt = ++loadGeneration;
  loading.value = true;
  try {
    const overview = await getHomeOverview();
    if (attempt !== loadGeneration) return;
    loadError.value = ''; syncedAt.value = Date.now();
    gatewayCount.value = overview.devices.filter(d => d.type === 'gateway').length;
    devices.value = overview.devices.filter((d) => d.type !== 'gateway');
    backupHealth.value = overview.backup_health || null;
    mode.value = overview.mode;
    configuredRooms.value = overview.rooms;
    activeAlerts.value = overview.active_alerts;
  } catch (e) {
    if (attempt === loadGeneration) loadError.value = (e as Error).message;
  } finally {
    if (attempt === loadGeneration) loading.value = false;
  }
}

async function toggle(d: Device, ctrl: ControlPoint) {
  if (pendingDevices.value.includes(d.id) || !d.is_online) return;
  pendingDevices.value.push(d.id);
  const next = !switchState(d, ctrl);
  // 乐观更新
  if (!d.state) d.state = {};
  d.state[ctrl.state_key || ctrl.key] = next;
  try {
    await withCommandResult(async () => {
      const command = await sendCommand(d.id, { action: ctrl.command, params: { [ctrl.param]: next } });
      d.manual_override_until = new Date(Date.now() + 30 * 60000).toISOString();
      return command;
    }, 65000, getCommandResult);
    toast(d.type === 'ac' ? '红外已发送；自动化暂停 30 分钟，实际状态请核对' : '设备已确认；自动化暂停 30 分钟');
    await load();
  } catch (e) {
    d.state[ctrl.state_key || ctrl.key] = !next; // 回滚
    toast((e as Error).message);
  } finally { pendingDevices.value = pendingDevices.value.filter((id) => id !== d.id); }
}

function openDevice(d: Device) {
  uni.navigateTo({ url: `/pages/device/detail?id=${d.id}` });
}

// 实时更新：设备在线状态 / 执行器状态 / 遥测值 / 新告警
let unsubs: Array<() => void> = [];
function subscribe() {
  const find = (id: unknown) => devices.value.find((d) => d.id === Number(id));
  unsubs = [
    onWs('device_status', (m) => {
      const d = find(m.device_id);
      if (d) d.is_online = !!m.is_online;
    }),
    onWs('device_state', (m) => {
      const d = find(m.device_id);
      if (d && m.state && typeof m.state === 'object') {
        d.state = { ...(d.state || {}), ...(m.state as Record<string, unknown>) };
      }
    }),
    onWs('telemetry', (m) => {
      const d = find(m.device_id);
      if (d && m.data && typeof m.data === 'object') {
        d.latest_metrics = Object.entries(m.data as Record<string, unknown>).filter(([key]) => key !== 'timestamp' && key !== 'request_id').map(([metric_key, value]) => ({ metric_key, value, ts: new Date().toISOString(), fresh: true }));
        d.data_stale = false;
        d.state = { ...(d.state || {}), ...(m.data as Record<string, unknown>) };
        d.is_online = true;
      }
    }),
    onWs('alert', () => { activeAlerts.value += 1; }),
    onWs('alert_resolved', () => { if (activeAlerts.value > 0) activeAlerts.value -= 1; }),
  ];
}
function unsubscribe() {
  unsubs.forEach((u) => u());
  unsubs = [];
}

onShow(() => { load(); subscribe(); now.value = Date.now(); if (!clock) clock = setInterval(() => { now.value = Date.now(); }, 1000); });
onHide(() => { unsubscribe(); if (clock) clearInterval(clock); clock = null; });
onPullDownRefresh(async () => {
  await load();
  uni.stopPullDownRefresh();
});
</script>

<template>
  <view class="page">
    <PageHeader
      :title="t('home.title')"
      :subtitle="connectionLabel"
    />

    <view
      v-if="loadError"
      class="sync-error"
      @tap="load"
    >
      {{ loadError }} · 点击重试
    </view>
    <view v-if="backupHealth?.needs_attention" class="backup-warning" @tap="uni.navigateTo({ url: '/pages/manage/backups' })">备份需要关注 · {{ backupHealth.warnings[0] }} ›</view>
    <view class="mode-line">
      <text @tap="auth.canManage && openMode()">
        家庭模式：{{ modes.find((m) => m.value === mode)?.label }} {{ auth.canManage ? '›' : '' }}
      </text><text @tap="onlyFavorites = !onlyFavorites">
        {{ onlyFavorites ? '查看全部' : '只看常用' }}
      </text>
    </view>
    <!-- 状态横幅 -->
    <view class="banner">
      <view class="stat">
        <text class="big">
          {{ stats.total }}
        </text><text class="lbl">
          {{ t('home.devices') }}
        </text>
      </view>
      <view class="sep" />
      <view class="stat">
        <text class="big">
          {{ stats.online }}
        </text><text class="lbl">
          {{ t('home.online') }}
        </text>
      </view>
      <view class="sep" />
      <view class="stat">
        <text class="big">
          {{ stats.offline }}
        </text><text class="lbl">
          {{ t('home.offline') }}
        </text>
      </view>
      <view
        v-if="activeAlerts > 0"
        class="alert-chip"
        @tap="openAlerts"
      >
        <view class="dot" />
        <text>{{ t('home.activeAlerts', { n: activeAlerts }) }}</text>
      </view>
    </view>

    <!-- 房间筛选 -->
    <scroll-view
      scroll-x
      class="chips"
      :show-scrollbar="false"
    >
      <view
        v-for="r in rooms"
        :key="r"
        class="chip"
        :class="{ on: r === room }"
        @tap="room = r"
      >
        {{ roomLabel(r) }}
      </view>
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
          <view class="dev-ic">
            {{ (d.name || '设备').slice(0, 1) }}
          </view>
          <view
            class="dev-dot"
            :class="{ gray: !d.is_online }"
          />
        </view>
        <view class="dev-mid">
          <text class="dev-name">
            {{ d.is_favorite ? '★ ' : '' }}{{ d.name }}
          </text>
          <text class="dev-meta">
            {{ d.location || '未分组' }} · {{ d.is_online ? timeAgo(d.last_seen) : '离线' }}
          </text>
        </view>

        <text
          v-if="d.capability"
          class="dev-meta"
        >
          {{ d.type === 'ac' ? '期望状态 · 实际未验证' : d.state_source === 'device_report' ? '有设备上报' : '期望状态' }}{{ pendingDevices.includes(d.id) ? ' · 等待回执' : '' }}
        </text>
        <view
          v-if="holdMinutes(d)"
          class="hold"
          @tap.stop="resume(d)"
        >
          自动化暂停 · 剩余 {{ holdMinutes(d) }} 分钟 <text>恢复自动化 ›</text>
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
            <text class="dev-val">
              {{ primaryMetric(d)!.text }}
            </text>
            <text class="dev-unit">
              {{ primaryMetric(d)!.unit }}
            </text>
          </template>
          <text
            v-else
            class="dev-val muted"
          >
            --
          </text>
        </view>
      </view>
    </view>

    <view
      v-if="!loading && !filtered.length"
      class="empty"
    >
      <template v-if="loadError">
        <text>暂时无法读取设备</text><button
          size="mini"
          @tap="load"
        >
          重新同步
        </button>
      </template>
      <template v-else-if="!devices.length && gatewayCount">
        <text>网关已添加，下一步接入模块</text><text>给网关添加温湿度、声音或红外模块后，会在这里显示。</text><button
          v-if="auth.canManage"
          @tap="addDevice(true)"
        >
          给已有网关添加模块
        </button>
      </template>
      <template v-else-if="!devices.length">
        <text>从添加第一台 ESP32 网关开始</text><button
          v-if="auth.canManage"
          @tap="addDevice()"
        >
          新增 ESP32 网关
        </button><text v-else>
          请联系家庭管理员添加设备。
        </text>
      </template>
      <template v-else>
        <text>{{ onlyFavorites ? '当前筛选下没有常用设备' : '这个房间还没有设备' }}</text><button
          size="mini"
          @tap="room = ALL; onlyFavorites = false"
        >
          查看全部设备
        </button>
      </template>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.backup-warning { margin: 16rpx 32rpx; padding: 22rpx; background: #fff5e8; border-radius: 14rpx; color: #9a6100; font-size: 25rpx; }
.sync-error { margin: 16rpx 32rpx; color: $hg-crit; font-size: 25rpx; }.hold { color: $hg-warn; font-size: 22rpx; margin: 12rpx 0; }.hold text { display: block; color: $hg-accent; padding: 10rpx 0; }.empty text { display: block; margin-bottom: 18rpx; }
.mode-line { display: flex; justify-content: space-between; margin: 0 32rpx 20rpx; font-size: 26rpx; color: $hg-accent; }
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
