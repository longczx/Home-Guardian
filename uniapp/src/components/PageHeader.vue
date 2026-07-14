<script setup lang="ts">
import { ref } from 'vue';

// 自定义导航栏（tab 页用）：占位状态栏高度，左标题右插槽。
defineProps<{ title: string; subtitle?: string }>();

const statusBarHeight = ref(0);
const info = uni.getSystemInfoSync();
statusBarHeight.value = info.statusBarHeight || 0;
</script>

<template>
  <view class="hdr" :style="{ paddingTop: statusBarHeight + 'px' }">
    <view class="hdr-inner">
      <view class="hdr-text">
        <text class="hdr-title">{{ title }}</text>
        <text v-if="subtitle" class="hdr-sub">{{ subtitle }}</text>
      </view>
      <view class="hdr-actions">
        <slot name="actions" />
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.hdr {
  background: $hg-bg;
}
.hdr-inner {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  padding: 16rpx 32rpx 20rpx;
}
.hdr-title {
  font-size: 40rpx;
  font-weight: 700;
  color: $hg-fg;
}
.hdr-sub {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
  margin-top: 4rpx;
}
.hdr-actions {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
</style>
