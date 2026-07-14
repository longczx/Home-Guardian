<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getPushSettings, updatePushSettings } from '@/api/push';
import { registerPush } from '@/utils/push';
import { toast } from '@/utils/guard';

const enabled = ref(true);
const minSeverity = ref('warning');
const registered = ref(false);

const LEVELS = [
  { value: 'info', label: '全部（含提醒）' },
  { value: 'warning', label: '警告及以上' },
  { value: 'critical', label: '仅严重' },
];

async function load() {
  try {
    const s = await getPushSettings();
    enabled.value = s.push_enabled;
    minSeverity.value = s.min_severity;
    registered.value = s.registered;
  } catch (e) {
    toast((e as Error).message);
  }
}

async function onToggle(e: { detail: { value: boolean } }) {
  enabled.value = e.detail.value;
  try {
    await updatePushSettings({ push_enabled: enabled.value });
    if (enabled.value) registerPush(); // 重新登记 cid
  } catch (err) {
    toast((err as Error).message);
  }
}

async function pickLevel(v: string) {
  minSeverity.value = v;
  try {
    await updatePushSettings({ min_severity: v });
  } catch (e) {
    toast((e as Error).message);
  }
}

onShow(load);
</script>

<template>
  <view class="page">
    <view class="card">
      <view class="row">
        <view>
          <text class="t">接收告警推送</text>
          <text class="s">关闭后该账号所有设备不再收到推送</text>
        </view>
        <switch :checked="enabled" color="#2b6fe3" @change="onToggle" />
      </view>
    </view>

    <view v-if="enabled" class="card">
      <text class="ct">推送级别</text>
      <view
        v-for="l in LEVELS"
        :key="l.value"
        class="opt"
        @tap="pickLevel(l.value)"
      >
        <text class="opt-label">{{ l.label }}</text>
        <text v-if="minSeverity === l.value" class="check">✓</text>
      </view>
    </view>

    <view class="tip">
      <text v-if="!registered">当前设备尚未登记推送（仅 App 端支持，H5/小程序不接收）。</text>
      <text v-else>App 端在锁屏 / 后台也能收到严重告警通知。</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx; }
.card {
  background: $hg-card; border-radius: $hg-radius; padding: 8rpx 28rpx;
  box-shadow: $hg-shadow; margin-bottom: 20rpx;
}
.row { display: flex; align-items: center; justify-content: space-between; padding: 28rpx 0; }
.t { font-size: 30rpx; color: $hg-fg; font-weight: 600; }
.s { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 4rpx; }
.ct { display: block; font-size: 24rpx; color: $hg-muted; padding: 24rpx 0 8rpx; }
.opt {
  display: flex; align-items: center; justify-content: space-between;
  padding: 28rpx 0; border-top: 1rpx solid $hg-line;
}
.opt-label { font-size: 30rpx; color: $hg-fg; }
.check { color: $hg-accent; font-size: 32rpx; font-weight: 700; }
.tip { font-size: 22rpx; color: $hg-muted; padding: 8rpx 8rpx; line-height: 1.6; }
</style>
