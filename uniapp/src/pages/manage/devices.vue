<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getDevices, deleteDevice } from '@/api/device';
import type { Device } from '@/api/types';
import { ensureReady, toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';

const devices = ref<Device[]>([]);
const loading = ref(false);

async function load() {
  if (!ensureReady()) return;
  loading.value = true;
  try {
    const page = await getDevices({ per_page: 100 });
    devices.value = page.items;
  } catch (e) {
    toast((e as Error).message);
  } finally {
    loading.value = false;
  }
}

function add() {
  uni.navigateTo({ url: '/pages/manage/device-add' });
}
function edit(d: Device) {
  uni.navigateTo({ url: `/pages/manage/device-edit?id=${d.id}&name=${encodeURIComponent(d.name)}&location=${encodeURIComponent(d.location || '')}` });
}
function remove(d: Device) {
  uni.showModal({
    title: '删除设备',
    content: `确定删除「${d.name}」？该设备的遥测与规则关联将一并移除。`,
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await deleteDevice(d.id);
        devices.value = devices.value.filter((x) => x.id !== d.id);
        toast('已删除', 'success');
      } catch (e) {
        toast((e as Error).message);
      }
    },
  });
}
onShow(load);
</script>

<template>
  <view class="page">
    <view class="list">
      <view v-for="d in devices" :key="d.id" class="row">
        <view class="ic">{{ (d.name || '设备').slice(0, 1) }}</view>
        <view class="mid" @tap="edit(d)">
          <text class="name">{{ d.name }}</text>
          <text class="meta">
            {{ d.type }} · {{ d.location || '未分组' }} ·
            <text :style="{ color: d.is_online ? '#2fb56b' : '#7a8299' }">{{ d.is_online ? '在线' : '离线 ' + timeAgo(d.last_seen) }}</text>
          </text>
        </view>
        <text class="del" @tap="remove(d)">删除</text>
      </view>
    </view>
    <view v-if="!loading && !devices.length" class="empty">还没有设备，点右下角添加</view>

    <view class="fab" @tap="add">＋ 添加设备</view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 24rpx 32rpx 160rpx;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.row {
  display: flex;
  align-items: center;
  gap: 20rpx;
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 24rpx 24rpx;
  box-shadow: $hg-shadow;
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
  min-width: 0;
}
.name {
  font-size: 30rpx;
  font-weight: 600;
  color: $hg-fg;
}
.meta {
  display: block;
  font-size: 22rpx;
  color: $hg-muted;
  margin-top: 4rpx;
}
.del {
  font-size: 24rpx;
  color: $hg-crit;
}
.empty {
  text-align: center;
  color: $hg-muted;
  font-size: 26rpx;
  padding: 100rpx 0;
}
.fab {
  position: fixed;
  left: 32rpx;
  right: 32rpx;
  bottom: 40rpx;
  height: 88rpx;
  line-height: 88rpx;
  text-align: center;
  background: $hg-accent;
  color: #fff;
  font-size: 30rpx;
  border-radius: $hg-radius-s;
  box-shadow: 0 6rpx 20rpx rgba(43, 111, 227, 0.35);
}
</style>
