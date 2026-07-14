<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app';
import PageHeader from '@/components/PageHeader.vue';
import { useAuthStore } from '@/stores/auth';
import { ensureReady, toast } from '@/utils/guard';

const auth = useAuthStore();

// 管理 Tab 仅 owner/admin 可用；member 落到此页给出说明（Tab 由 me 页角色提示引导）
onShow(() => {
  ensureReady();
});

const items = [
  { key: 'devices', title: '设备管理', sub: '添加（扫码配网）· 编辑 · 删除' },
  { key: 'alert-rules', title: '告警规则', sub: '遥测/离线 · 分级 · 冷却 · 恢复通知' },
  { key: 'channels', title: '通知渠道', sub: '邮件 / Webhook · 一键测试' },
  { key: 'automations', title: '自动化', sub: '查看 · 启停 · 删除' },
];

function open() {
  toast('该管理功能将在 PR2 提供');
}
</script>

<template>
  <view class="page">
    <PageHeader title="管理" :subtitle="auth.canManage ? '设备与规则管理' : '仅管理员可用'" />

    <view v-if="auth.canManage" class="menu">
      <view v-for="it in items" :key="it.key" class="item" @tap="open">
        <view class="ic">{{ it.title.slice(0, 1) }}</view>
        <view class="mid">
          <text class="t">{{ it.title }}</text>
          <text class="s">{{ it.sub }}</text>
        </view>
        <text class="arrow">›</text>
      </view>
    </view>

    <view v-else class="notice">
      <text>你当前是家庭成员，暂无管理权限。</text>
      <text class="sub">如需管理设备或告警规则，请让户主把你设为管理员。</text>
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
