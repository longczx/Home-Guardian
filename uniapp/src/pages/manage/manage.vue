<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { onShow } from '@dcloudio/uni-app';
import PageHeader from '@/components/PageHeader.vue';
import { useAuthStore } from '@/stores/auth';
import { ensureReady } from '@/utils/guard';

const { t } = useI18n();
const auth = useAuthStore();

// 管理 Tab 仅 owner/admin 可用；member 落到此页给出说明（Tab 由 me 页角色提示引导）
onShow(() => {
  ensureReady();
});

const items = computed(() => [
  { key: 'devices', title: t('manage.devices'), sub: t('manage.devicesSub'), url: '/pages/manage/devices' },
  { key: 'alert-rules', title: t('manage.rules'), sub: t('manage.rulesSub'), url: '/pages/manage/alert-rules' },
  { key: 'channels', title: t('manage.channels'), sub: t('manage.channelsSub'), url: '/pages/manage/channels' },
  { key: 'automations', title: t('manage.automations'), sub: t('manage.automationsSub'), url: '/pages/manage/automations' },
]);

function open(url: string) {
  uni.navigateTo({ url });
}
</script>

<template>
  <view class="page">
    <PageHeader :title="t('manage.title')" :subtitle="auth.canManage ? t('manage.subtitle') : t('manage.memberOnly')" />

    <view v-if="auth.canManage" class="menu">
      <view v-for="it in items" :key="it.key" class="item" @tap="open(it.url)">
        <view class="ic">{{ it.title.slice(0, 1) }}</view>
        <view class="mid">
          <text class="t">{{ it.title }}</text>
          <text class="s">{{ it.sub }}</text>
        </view>
        <text class="arrow">›</text>
      </view>
    </view>

    <view v-else class="notice">
      <text>{{ t('manage.noPermTitle') }}</text>
      <text class="sub">{{ t('manage.noPermSub') }}</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
}
.menu {
  margin: 8rpx 32rpx 0;
  background: $hg-card;
  border-radius: $hg-radius;
  box-shadow: $hg-shadow;
  overflow: hidden;
}
.item {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 30rpx 28rpx;
  border-bottom: 1rpx solid $hg-line;
}
.item:last-child {
  border-bottom: none;
}
.ic {
  width: 68rpx;
  height: 68rpx;
  border-radius: 20rpx;
  background: $hg-accent-soft;
  color: $hg-accent;
  font-size: 30rpx;
  font-weight: 700;
  text-align: center;
  line-height: 68rpx;
  flex: none;
}
.mid {
  flex: 1;
}
.t {
  font-size: 30rpx;
  font-weight: 600;
  color: $hg-fg;
}
.s {
  display: block;
  font-size: 22rpx;
  color: $hg-muted;
  margin-top: 4rpx;
}
.arrow {
  color: $hg-muted;
  font-size: 36rpx;
}
.notice {
  margin: 60rpx 48rpx;
  text-align: center;
  color: $hg-fg;
  font-size: 28rpx;
}
.notice .sub {
  display: block;
  margin-top: 16rpx;
  font-size: 24rpx;
  color: $hg-muted;
  line-height: 1.6;
}
</style>
