<script setup lang="ts">
import { ref, computed, nextTick, watch } from 'vue';
import { onLoad, onReady, onShow, onHide, onUnload } from '@dcloudio/uni-app';
import { getAggregatedTelemetry, getDevice, getLatestTelemetry } from '@/api/device';
import type { Device, MetricField, LatestMetric } from '@/api/types';
import { chartPoints, chartSegments, bucketDuration, telemetryTime } from '@/utils/telemetry-chart';
import { useServerStore } from '@/stores/server';
import { ensureReady } from '@/utils/guard';
import { onWs } from '@/utils/ws';

const ranges = [{ key: '24h', label: '24小时', hours: 24 }, { key: '7d', label: '7天', hours: 168 }, { key: '30d', label: '30天', hours: 720 }];
const id = ref(0); const device = ref<Device | null>(null); const latest = ref<LatestMetric[]>([]);
const metrics = ref<MetricField[]>([]); const metric = ref(''); const range = ref('24h');
const points = ref<ReturnType<typeof chartPoints>>([]); const loading = ref(false); const error = ref('');
const width = ref(300); const height = 270; const selected = ref(-1); const canvasReady = ref(false);
const start = ref(Date.now() - 86400000); const end = ref(Date.now()); const updatedAt = ref('');
const customStart = ref(''); const customEnd = ref('');
const server = useServerStore(); let generation = 0; let left = 0; let active = true; let fetching = false;
let unsubscribe: (() => void) | undefined; let statusUnsubscribe: (() => void) | undefined; let refresh: ReturnType<typeof setTimeout> | undefined;
const currentMetric = computed(() => metrics.value.find(m => m.key === metric.value));
const unit = computed(() => currentMetric.value?.unit || '');
const currentPoint = computed(() => points.value[selected.value] || points.value[points.value.length - 1]);
const lastSaved = computed(() => latest.value.find(m => m.metric_key === metric.value));
const statistics = computed(() => {
  const p = points.value; const count = p.reduce((sum, item) => sum + item.sample_count, 0);
  return { count, mean: count ? p.reduce((sum, item) => sum + item.avg_value * item.sample_count, 0) / count : NaN,
    min: p.length ? Math.min(...p.map(item => item.min_value)) : NaN, max: p.length ? Math.max(...p.map(item => item.max_value)) : NaN };
});
function number(value: number | undefined) { return value !== undefined && Number.isFinite(value) ? Number(value.toFixed(2)).toString() : '—'; }
function time(value: number, short = false) {
  const d = new Date(value); const pad = (v: number) => String(v).padStart(2, '0');
  return `${short && end.value - start.value <= 86400000 ? '' : `${pad(d.getMonth() + 1)}/${pad(d.getDate())} `}${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function selectMetric(event: { detail: { value: string | number } }) {
  const field = metrics.value[Number(event.detail.value)];
  if (field) { metric.value = field.key; void load(); }
}
async function load(quiet = false) {
  if (!metric.value || !active || (quiet && fetching)) return;
  fetching = true;
  const attempt = ++generation; loading.value = !quiet; error.value = '';
  if (!quiet) { selected.value = -1; points.value = []; draw(); }
  try {
    const result = await getAggregatedTelemetry(id.value, metric.value, new Date(start.value).toISOString(), new Date(end.value).toISOString());
    if (attempt !== generation || !active) return;
    points.value = chartPoints(result); updatedAt.value = new Date().toLocaleTimeString();
    await nextTick(); draw();
  } catch (e) { if (attempt === generation) error.value = (e as Error).message; }
  finally { if (attempt === generation) { loading.value = false; fetching = false; } }
}
function chooseRange(key: string, quiet = false) {
  range.value = key;
  if (key === 'custom') return;
  end.value = Date.now(); start.value = end.value - (ranges.find(r => r.key === key)?.hours || 24) * 3600000;
  void load(quiet);
}
function applyCustom() {
  const from = new Date(customStart.value + 'T00:00:00').getTime();
  const to = new Date(customEnd.value + 'T00:00:00').getTime() + 86400000;
  if (!Number.isFinite(from) || !Number.isFinite(to) || to <= from || to - from > 366 * 86400000) { error.value = '请选择起止日期，最长支持 366 天'; return; }
  start.value = from; end.value = to; range.value = 'custom'; void load();
}
function latestWindow() {
  const last = telemetryTime(lastSaved.value?.ts || '');
  if (!Number.isFinite(last)) return;
  range.value = 'saved'; end.value = last + 300000; start.value = end.value - 86400000; void load();
}
function scale() {
  const values = points.value.flatMap(p => [p.min_value, p.max_value]);
  const low = values.length ? Math.min(...values) : 0; const high = values.length ? Math.max(...values) : 1;
  const margin = Math.max((high - low) * 0.16, Math.abs(high) * 0.01, 0.5);
  const min = low - margin, max = high + margin;
  return { x: (stamp: number) => 48 + (stamp - start.value) / Math.max(1, end.value - start.value) * (width.value - 66),
    y: (value: number) => 20 + (1 - (value - min) / (max - min)) * (height - 56), min, max };
}
function draw() {
  if (!canvasReady.value) return;
  const ctx = uni.createCanvasContext('trend'); const w = width.value;
  ctx.clearRect(0, 0, w, height);
  const { x, y, min, max } = scale();
  ctx.setFontSize(10); ctx.setTextAlign('right');
  for (let i = 0; i <= 4; i++) {
    const value = min + (max - min) * i / 4; const yy = y(value);
    ctx.setStrokeStyle('#e9edf4'); ctx.setLineWidth(1); ctx.beginPath(); ctx.moveTo(48, yy); ctx.lineTo(w - 18, yy); ctx.stroke();
    ctx.setFillStyle('#7a8299'); ctx.fillText(number(value), 40, yy + 3);
  }
  ctx.setTextAlign('center'); ctx.setFillStyle('#7a8299');
  for (let i = 0; i < 4; i++) {
    const stamp = start.value + (end.value - start.value) * i / 3;
    ctx.fillText(time(stamp, true), x(stamp), height - 10);
  }
  for (const segment of chartSegments(points.value, bucketDuration(start.value, end.value) * 2.5)) {
    if (segment.length > 1) {
      ctx.beginPath(); segment.forEach((p, i) => i ? ctx.lineTo(x(p.time), y(p.max_value)) : ctx.moveTo(x(p.time), y(p.max_value)));
      [...segment].reverse().forEach(p => ctx.lineTo(x(p.time), y(p.min_value)));
      ctx.closePath(); ctx.setFillStyle('rgba(43,111,227,0.10)'); ctx.fill();
      ctx.setStrokeStyle('#2b6fe3'); ctx.setLineWidth(2.5); ctx.setLineJoin('round'); ctx.setLineCap('round');
      ctx.beginPath(); segment.forEach((p, i) => i ? ctx.lineTo(x(p.time), y(p.avg_value)) : ctx.moveTo(x(p.time), y(p.avg_value))); ctx.stroke();
    } else {
      ctx.setFillStyle('#2b6fe3'); ctx.beginPath(); ctx.arc(x(segment[0].time), y(segment[0].avg_value), 3.5, 0, Math.PI * 2); ctx.fill();
    }
  }
  const point = currentPoint.value;
  if (point) {
    ctx.setStrokeStyle('#b8c9e8'); ctx.setLineWidth(1); ctx.beginPath(); ctx.moveTo(x(point.time), 16); ctx.lineTo(x(point.time), height - 36); ctx.stroke();
    ctx.setFillStyle('#fff'); ctx.beginPath(); ctx.arc(x(point.time), y(point.avg_value), 6, 0, Math.PI * 2); ctx.fill();
    ctx.setFillStyle('#2b6fe3'); ctx.beginPath(); ctx.arc(x(point.time), y(point.avg_value), 3.5, 0, Math.PI * 2); ctx.fill();
  }
  ctx.draw();
}
function inspect(event: unknown) {
  const input = event as { touches?: ArrayLike<{ x?: number; clientX?: number }>; detail?: { x?: number } | number };
  const touch = input.touches?.[0];
  const px = touch?.x ?? (touch?.clientX !== undefined ? touch.clientX - left : typeof input.detail === 'object' ? input.detail?.x : undefined);
  if (px === undefined || !points.value.length) return;
  const target = start.value + (px - 48) / (width.value - 66) * (end.value - start.value);
  selected.value = points.value.reduce((best, p, i, array) => Math.abs(p.time - target) < Math.abs(array[best].time - target) ? i : best, 0); draw();
}
async function initialize() {
  if (!ensureReady()) return;
  const revision = server.connectionRevision;
  try {
    const [d, readings] = await Promise.all([getDevice(id.value), getLatestTelemetry(id.value)]);
    if (!active || revision !== server.connectionRevision) return;
    device.value = d; latest.value = readings;
    const configured = d.metric_fields || [];
    metrics.value = [...configured, ...readings.filter(m => !configured.some(f => f.key === m.metric_key)).map(m => ({ key: m.metric_key, label: m.metric_key }))];
    if (!metric.value) metric.value = metrics.value[0]?.key || '';
    if (metric.value && !metrics.value.some(m => m.key === metric.value)) metrics.value.push({ key: metric.value, label: metric.value });
    await load();
  } catch (e) { error.value = (e as Error).message; }
}
watch(() => server.connectionRevision, () => { active = false; generation++; points.value = []; device.value = null; latest.value = []; metrics.value = []; loading.value = false; error.value = '服务器已切换，请返回设备列表重新进入'; draw(); });
onLoad(q => { id.value = Number(q?.id || 0); metric.value = String(q?.metric || ''); });
onReady(() => {
  width.value = Math.max(220, uni.getSystemInfoSync().windowWidth - 64); canvasReady.value = true;
  uni.createSelectorQuery().select('.canvas').boundingClientRect(result => {
    const rect = result as UniApp.NodeInfo;
    if (rect?.width) { width.value = rect.width; left = rect.left || 0; draw(); }
  }).exec();
  void initialize();
});
function subscribe() {
  unsubscribe?.();
  statusUnsubscribe?.();
  statusUnsubscribe = onWs('device_status', m => { if (Number(m.device_id) === id.value && device.value && active) device.value.is_online = !!m.is_online; });
  unsubscribe = onWs('telemetry', m => {
    if (Number(m.device_id) !== id.value || !active) return;
    if (device.value) device.value.is_online = true;
    if (range.value !== 'custom' && range.value !== 'saved' && !refresh) refresh = setTimeout(() => { refresh = undefined; if (!loading.value) chooseRange(range.value, true); }, 3000);
  });
}
onShow(() => { subscribe(); if (canvasReady.value && active) void initialize(); });
onHide(() => { unsubscribe?.(); statusUnsubscribe?.(); unsubscribe = undefined; if (refresh) clearTimeout(refresh); refresh = undefined; });
onUnload(() => { active = false; generation++; unsubscribe?.(); statusUnsubscribe?.(); if (refresh) clearTimeout(refresh); });
</script>

<template>
  <view class="page">
    <view class="heading">
      <text class="eyebrow">
        {{ device?.name || '设备' }} · 历史遥测
      </text><picker
        :range="metrics.map(m => m.label)"
        :value="Math.max(0, metrics.findIndex(m => m.key === metric))"
        @change="selectMetric"
      >
        <view class="metric">
          {{ currentMetric?.label || metric || '选择指标' }} <text>⌄</text>
        </view>
      </picker>
    </view>
    <view class="status">
      <text
        class="status-dot"
        :class="{ offline: !device?.is_online }"
      /><text>{{ device?.is_online ? '设备在线' : '设备离线' }} · 历史数据可独立查看</text>
    </view>
    <view class="ranges">
      <text
        v-for="r in ranges"
        :key="r.key"
        :class="{ on: range === r.key }"
        @tap="chooseRange(r.key)"
      >
        {{ r.label }}
      </text><text
        :class="{ on: range === 'custom' }"
        @tap="chooseRange('custom')"
      >
        自定义
      </text>
    </view>
    <view
      v-if="range === 'custom'"
      class="dates"
    >
      <picker
        mode="date"
        :value="customStart"
        @change="customStart = $event.detail.value"
      >
        <text>{{ customStart || '起始日期' }}</text>
      </picker><text>至</text><picker
        mode="date"
        :value="customEnd"
        @change="customEnd = $event.detail.value"
      >
        <text>{{ customEnd || '结束日期' }}</text>
      </picker><button
        size="mini"
        @tap="applyCustom"
      >
        查询
      </button>
    </view>
    <view class="chart-surface">
      <view class="reading">
        <text class="value">
          {{ number(currentPoint?.avg_value) }}
        </text><text class="unit">
          {{ unit }}
        </text><text class="reading-label">
          {{ selected >= 0 ? '选中时段均值' : '最后时段均值' }}
        </text>
      </view>
      <text class="caption">
        {{ currentPoint ? time(currentPoint.time) + ' · ' + currentPoint.sample_count + ' 次采样' : '尚无采样' }}
      </text>
      <canvas
        canvas-id="trend"
        class="canvas"
        :style="{ height: height + 'px' }"
        @touchstart="inspect"
        @touchmove.stop="inspect"
        @tap="inspect"
      />
      <view class="legend">
        <text><text class="legend-line" />均值曲线</text><text>浅蓝区域：最低至最高</text>
      </view>
      <text class="caption">
        点击或拖动查看数值 · 缺失采样处断开显示
      </text>
      <text
        v-if="loading"
        class="feedback"
      >
        正在查询已保存的数据…
      </text>
      <view
        v-else-if="error"
        class="feedback error"
      >
        <text>{{ error }}</text><button
          v-if="active"
          size="mini"
          @tap="initialize"
        >
          重试
        </button>
      </view>
      <view
        v-else-if="!points.length"
        class="feedback"
      >
        <text>这个时间段没有已保存的数值采样。</text><text v-if="lastSaved">
          最后一次上报：{{ time(telemetryTime(lastSaved.ts)) }}
        </text><button
          v-if="lastSaved"
          size="mini"
          @tap="latestWindow"
        >
          查看最近有数据的 24 小时
        </button>
      </view>
    </view>
    <view class="statistics">
      <view>
        <text>区间最低</text><text class="stat-value">
          {{ number(statistics.min) }}<text>{{ unit }}</text>
        </text>
      </view><view>
        <text>区间平均</text><text class="stat-value">
          {{ number(statistics.mean) }}<text>{{ unit }}</text>
        </text>
      </view><view>
        <text>区间最高</text><text class="stat-value">
          {{ number(statistics.max) }}<text>{{ unit }}</text>
        </text>
      </view>
    </view>
    <view class="footnote">
      <text>{{ time(start) }} — {{ time(end) }}</text><text>{{ statistics.count }} 次采样 · {{ points.length }} 个汇总点{{ updatedAt ? ' · 查询于 ' + updatedAt : '' }}</text><text>均值按采样数加权；离线不清除历史，数据按服务器保留策略保存（默认 180 天）。</text>
    </view>
    <view
      v-if="points.length"
      class="data-list"
    >
      <text class="list-title">
        最近采样时段
      </text><view
        v-for="p in points.slice(-6).reverse()"
        :key="p.bucket"
        class="data-row"
        @tap="selected = points.indexOf(p); draw()"
      >
        <text>{{ time(p.time) }}</text><text>{{ number(p.avg_value) }} {{ unit }}</text><text>{{ number(p.min_value) }} – {{ number(p.max_value) }}</text>
      </view>
    </view>
  </view>
</template>
<style scoped lang="scss">
.page { padding: 36rpx 32rpx 64rpx; }.eyebrow { color: $hg-muted; font-size: 24rpx; }.metric { font-size: 42rpx; font-weight: 600; margin-top: 12rpx; color: $hg-fg; }.metric text { color: $hg-muted; font-size: 30rpx; margin-left: 14rpx; }.status { display: flex; align-items: center; gap: 10rpx; font-size: 23rpx; color: $hg-muted; margin: 18rpx 0 32rpx; }.status-dot { width: 10rpx; height: 10rpx; border-radius: 50%; background: $hg-ok; }.offline { background: $hg-muted; }.ranges { display: flex; gap: 8rpx; padding: 8rpx; border-radius: 16rpx; background: #e9edf4; margin-bottom: 28rpx; }.ranges > text { flex: 1; text-align: center; font-size: 25rpx; padding: 15rpx 4rpx; color: $hg-muted; border-radius: 12rpx; transition: background .18s, color .18s; }.ranges .on { background: #fff; color: $hg-accent; box-shadow: 0 2rpx 8rpx #1a22330a; }.dates { display: flex; align-items: center; justify-content: space-between; font-size: 24rpx; margin: 22rpx 0; }.dates button { margin: 0; }.chart-surface { background: $hg-card; border-radius: 24rpx; padding: 30rpx 0 26rpx; overflow: hidden; }.reading { display: flex; align-items: baseline; gap: 10rpx; padding: 0 28rpx; }.value { font-size: 64rpx; font-weight: 600; font-variant-numeric: tabular-nums; color: $hg-fg; }.unit { color: $hg-muted; font-size: 26rpx; }.reading-label { margin-left: auto; font-size: 22rpx; color: $hg-muted; }.caption { display: block; color: $hg-muted; padding: 6rpx 28rpx; font-size: 22rpx; }.canvas { width: 100%; margin: 20rpx auto 4rpx; }.legend { display: flex; justify-content: space-between; padding: 8rpx 28rpx; color: $hg-muted; font-size: 21rpx; }.legend-line { display: inline-block; width: 24rpx; height: 4rpx; background: $hg-accent; margin-right: 10rpx; vertical-align: middle; }.feedback { padding: 32rpx 28rpx; text-align: center; color: $hg-muted; font-size: 25rpx; }.feedback text { display: block; margin-bottom: 14rpx; }.feedback button { margin-top: 24rpx; }.error { color: $hg-crit; }.statistics { display: flex; margin: 32rpx 0 24rpx; }.statistics > view { flex: 1; padding: 0 12rpx; border-right: 1rpx solid $hg-line; }.statistics > view:last-child { border: 0; }.statistics text { display: block; font-size: 22rpx; color: $hg-muted; }.statistics .stat-value { font-size: 32rpx; color: $hg-fg; margin-top: 12rpx; font-weight: 600; }.stat-value text { display: inline; font-size: 20rpx; font-weight: 400; margin-left: 5rpx; }.footnote text { display: block; font-size: 22rpx; color: $hg-muted; line-height: 1.9; }.data-list { margin-top: 32rpx; }.list-title { font-size: 26rpx; font-weight: 600; }.data-row { display: grid; grid-template-columns: 1.2fr .8fr 1.4fr; gap: 16rpx; padding: 22rpx 0; border-bottom: 1rpx solid $hg-line; font-size: 24rpx; font-variant-numeric: tabular-nums; }.data-row text { white-space: nowrap; }.data-row text:not(:first-child) { text-align: right; }.data-row text:last-child { color: $hg-muted; }
</style>
