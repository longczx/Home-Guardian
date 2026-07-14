<script setup lang="ts">
import { ref } from 'vue';
import { onLoad, onReady } from '@dcloudio/uni-app';
import { getAggregatedTelemetry } from '@/api/device';
import type { AggregatedPoint } from '@/api/types';
import { toast } from '@/utils/guard';

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

// 轻量 canvas 折线（PR1 自绘；uCharts 可后续替换）
function draw() {
  const data = points.value.map((p) => p.avg_value).filter((v) => typeof v === 'number');
  const ctx = uni.createCanvasContext('trend');
  const w = canvasW.value;
  const h = canvasH.value;
  const pad = 12;
  ctx.clearRect(0, 0, w, h);
  if (data.length < 2) {
    ctx.setFillStyle('#c9d6f2');
    ctx.setFontSize(13);
    ctx.fillText('暂无足够数据', w / 2 - 40, h / 2);
    ctx.draw();
    return;
  }
  const min = Math.min(...data);
  const max = Math.max(...data);
  const span = max - min || 1;
  const x = (i: number) => pad + (i / (data.length - 1)) * (w - pad * 2);
  const y = (v: number) => h - pad - ((v - min) / span) * (h - pad * 2 - 8);

  // 网格
  ctx.setStrokeStyle('rgba(201,214,242,0.18)');
  ctx.setLineWidth(1);
  [0.25, 0.5, 0.75].forEach((p) => {
    ctx.beginPath();
    ctx.moveTo(pad, h * p);
    ctx.lineTo(w - pad, h * p);
    ctx.stroke();
  });

  // 折线
  ctx.setStrokeStyle('#6ea8ff');
  ctx.setLineWidth(2);
  ctx.beginPath();
  data.forEach((v, i) => (i === 0 ? ctx.moveTo(x(i), y(v)) : ctx.lineTo(x(i), y(v))));
  ctx.stroke();

  // 终点
  const li = data.length - 1;
  ctx.setFillStyle('#6ea8ff');
  ctx.beginPath();
  ctx.arc(x(li), y(data[li]), 4, 0, Math.PI * 2);
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
        <text class="sv">{{ points[points.length - 1]?.avg_value?.toFixed?.(1) ?? '-' }}{{ unit }}</text>
        <text class="sl">最新均值</text>
      </view>
      <view class="sm">
        <text class="sv">{{ Math.max(...points.map((p) => p.max_value)).toFixed(1) }}{{ unit }}</text>
        <text class="sl">峰值</text>
      </view>
      <view class="sm">
        <text class="sv">{{ Math.min(...points.map((p) => p.min_value)).toFixed(1) }}{{ unit }}</text>
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
