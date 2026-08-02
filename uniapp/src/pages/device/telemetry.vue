<script setup lang="ts">
import { ref, computed } from 'vue';
import { onLoad, onReady, onUnload } from '@dcloudio/uni-app';
import { getAggregatedTelemetry } from '@/api/device';
import type { AggregatedPoint } from '@/api/types';
import { toast } from '@/utils/guard';
import { onWs } from '@/utils/ws';

const RANGES = [
  { key: '24h', label: '24小时', hours: 24 },
  { key: '7d', label: '7天', hours: 24 * 7 },
  { key: '30d', label: '30天', hours: 24 * 30 },
];

const deviceId = ref(0);
const metric = ref('');
const label = ref('');
const unit = ref('');
const range = ref('24h');
const points = ref<AggregatedPoint[]>([]);
const canvasW = ref(0);
const canvasH = ref(240);

function isoAgo(hours: number): string {
  return new Date(Date.now() - hours * 3600 * 1000).toISOString();
}

// 汇总统计（NUMERIC 经 PDO 是字符串，统一 Number() 兜底）
const stat = computed(() => {
  const avg = points.value.map((p) => Number(p.avg_value)).filter(Number.isFinite);
  const maxs = points.value.map((p) => Number(p.max_value)).filter(Number.isFinite);
  const mins = points.value.map((p) => Number(p.min_value)).filter(Number.isFinite);
  return {
    latest: avg.length ? avg[avg.length - 1].toFixed(1) : '-',
    peak: maxs.length ? Math.max(...maxs).toFixed(1) : '-',
    valley: mins.length ? Math.min(...mins).toFixed(1) : '-',
  };
});

async function load() {
  if (!metric.value) return;
  const r = RANGES.find((x) => x.key === range.value)!;
  try {
    points.value = await getAggregatedTelemetry(deviceId.value, metric.value, isoAgo(r.hours), new Date().toISOString());
    draw();
  } catch (e) {
    toast((e as Error).message);
  }
}

function setRange(k: string) {
  range.value = k;
  load();
}

// 桶时间戳 → 轴标签（24h 显示 时:分；更长显示 月/日）
function fmtTime(bucket: string): string {
  const d = new Date(String(bucket).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  const p = (n: number) => String(n).padStart(2, '0');
  return range.value === '24h'
    ? `${p(d.getHours())}:${p(d.getMinutes())}`
    : `${p(d.getMonth() + 1)}/${p(d.getDate())}`;
}

// 轻量 canvas 折线：带 Y 轴刻度 + 网格 + X 轴时间标签 + 面积填充
function draw() {
  // NUMERIC 经 PDO 会是字符串，统一 Number() 兜底，别再被 typeof 过滤掉
  const pts = points.value.filter((p) => Number.isFinite(Number(p.avg_value)));
  const data = pts.map((p) => Number(p.avg_value));
  const ctx = uni.createCanvasContext('trend');
  const w = canvasW.value;
  const h = canvasH.value;
  ctx.clearRect(0, 0, w, h);

  if (data.length < 2) {
    ctx.setFillStyle('rgba(255,255,255,0.55)');
    ctx.setFontSize(13);
    ctx.setTextAlign('center');
    ctx.fillText('暂无足够数据', w / 2, h / 2);
    ctx.draw();
    return;
  }

  const padL = 46, padR = 14, padT = 14, padB = 28;
  const plotW = w - padL - padR;
  const plotH = h - padT - padB;

  let min = Math.min(...data);
  let max = Math.max(...data);
  if (min === max) { min -= 1; max += 1; }
  const room = (max - min) * 0.12; // 上下留白，曲线不贴边
  min -= room; max += room;
  const span = max - min || 1;

  const x = (i: number) => padL + (i / (data.length - 1)) * plotW;
  const y = (v: number) => padT + (1 - (v - min) / span) * plotH;

  // Y 轴：4 档网格线 + 数值刻度
  const yTicks = 4;
  ctx.setFontSize(10);
  ctx.setTextAlign('right');
  for (let i = 0; i <= yTicks; i++) {
    const val = min + (span * i) / yTicks;
    const yy = y(val);
    ctx.setStrokeStyle('rgba(255,255,255,0.08)');
    ctx.setLineWidth(1);
    ctx.beginPath();
    ctx.moveTo(padL, yy);
    ctx.lineTo(w - padR, yy);
    ctx.stroke();
    ctx.setFillStyle('rgba(255,255,255,0.5)');
    ctx.fillText(val.toFixed(1), padL - 6, yy + 3);
  }

  // X 轴：约 4 个时间刻度
  ctx.setTextAlign('center');
  ctx.setFillStyle('rgba(255,255,255,0.5)');
  const xTicks = Math.min(4, data.length - 1);
  for (let i = 0; i <= xTicks; i++) {
    const idx = Math.round((i / xTicks) * (data.length - 1));
    ctx.fillText(fmtTime(pts[idx].bucket), x(idx), h - 9);
  }

  // 面积填充
  ctx.beginPath();
  ctx.moveTo(x(0), y(data[0]));
  data.forEach((v, i) => ctx.lineTo(x(i), y(v)));
  ctx.lineTo(x(data.length - 1), padT + plotH);
  ctx.lineTo(x(0), padT + plotH);
  ctx.closePath();
  ctx.setFillStyle('rgba(110,168,255,0.14)');
  ctx.fill();

  // 折线
  ctx.setStrokeStyle('#6ea8ff');
  ctx.setLineWidth(2);
  ctx.beginPath();
  data.forEach((v, i) => (i === 0 ? ctx.moveTo(x(i), y(v)) : ctx.lineTo(x(i), y(v))));
  ctx.stroke();

  // 终点高亮
  const li = data.length - 1;
  ctx.setFillStyle('#6ea8ff');
  ctx.beginPath();
  ctx.arc(x(li), y(data[li]), 3.5, 0, Math.PI * 2);
  ctx.fill();

  ctx.draw();
}

onLoad((q) => {
  deviceId.value = Number(q?.id || 0);
  metric.value = String(q?.metric || '');
  label.value = decodeURIComponent(String(q?.label || ''));
  unit.value = decodeURIComponent(String(q?.unit || ''));
});

onReady(() => {
  uni.getSystemInfo({
    success: (info) => {
      canvasW.value = info.windowWidth - 32 - 28; // 页边距 + 卡内边距
      load();
    },
  });
});

// 实时：本设备该指标有新遥测时，追加一个数据点即时重绘
const unsub = onWs('telemetry', (m) => {
  if (Number(m.device_id) !== deviceId.value || !m.data || typeof m.data !== 'object') return;
  const v = (m.data as Record<string, unknown>)[metric.value];
  if (v === undefined || v === null || Number.isNaN(Number(v))) return;
  const val = Number(v);
  points.value.push({ bucket: String(m.ts ?? ''), avg_value: val, min_value: val, max_value: val, sample_count: 1 });
  if (points.value.length > 240) points.value.shift(); // 限长防内存膨胀
  draw();
});
onUnload(() => unsub());
</script>

<template>
  <view class="page">
    <view class="chart-card">
      <view class="chart-head">
        <text class="metric">{{ label || metric }}<text v-if="unit" class="unit"> / {{ unit }}</text></text>
        <view class="ranges">
          <text
            v-for="r in RANGES"
            :key="r.key"
            class="rng"
            :class="{ on: r.key === range }"
            @tap="setRange(r.key)"
          >{{ r.label }}</text>
        </view>
      </view>
      <canvas
        canvas-id="trend"
        class="canvas"
        :style="{ width: canvasW + 'px', height: canvasH + 'px' }"
      />
    </view>

    <view class="summary" v-if="points.length">
      <view class="sm">
        <text class="sv">{{ stat.latest }}{{ unit }}</text>
        <text class="sl">最新均值</text>
      </view>
      <view class="sm">
        <text class="sv">{{ stat.peak }}{{ unit }}</text>
        <text class="sl">峰值</text>
      </view>
      <view class="sm">
        <text class="sv">{{ stat.valley }}{{ unit }}</text>
        <text class="sl">谷值</text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 24rpx 32rpx;
}
.chart-card {
  background: $hg-chart-bg;
  border-radius: $hg-radius;
  padding: 28rpx 28rpx 20rpx;
  box-shadow: $hg-shadow;
}
.chart-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12rpx;
}
.metric {
  color: #fff;
  font-size: 28rpx;
  font-weight: 600;
}
.unit {
  color: $hg-chart-fg;
  font-weight: 400;
  font-size: 22rpx;
}
.ranges {
  display: flex;
  gap: 6rpx;
}
.rng {
  font-size: 22rpx;
  color: $hg-chart-fg;
  opacity: 0.6;
  padding: 6rpx 16rpx;
  border-radius: 10rpx;
}
.rng.on {
  opacity: 1;
  background: rgba(255, 255, 255, 0.14);
}
.canvas {
  display: block;
}
.summary {
  display: flex;
  gap: 20rpx;
  margin-top: 24rpx;
}
.sm {
  flex: 1;
  background: $hg-card;
  border-radius: $hg-radius-s;
  box-shadow: $hg-shadow;
  padding: 24rpx 0;
  text-align: center;
}
.sv {
  font-size: 32rpx;
  font-weight: 700;
  color: $hg-fg;
}
.sl {
  display: block;
  font-size: 22rpx;
  color: $hg-muted;
  margin-top: 6rpx;
}
</style>
