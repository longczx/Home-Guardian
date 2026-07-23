<script setup lang="ts">
import { ref, computed } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getDevices, deleteDevice } from '@/api/device';
import type { Device } from '@/api/types';
import { ensureReady, toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';

const devices = ref<Device[]>([]);
const loading = ref(false);

// 按"网关 + 其子设备"分组；独立设备（无网关）单独一组
interface Group {
  gateway: Device | null;
  children: Device[];
}
const groups = computed<Group[]>(() => {
  const gateways = devices.value.filter((d) => d.type === 'gateway');
  const childrenOf = (uid: string) => devices.value.filter((d) => d.gateway_uid === uid && d.type !== 'gateway');
  const result: Group[] = gateways.map((gw) => ({ gateway: gw, children: childrenOf(gw.device_uid) }));

  // 独立设备：非网关且没有归属网关（或归属的网关不在列表里）
  const gwUids = new Set(gateways.map((g) => g.device_uid));
  const standalone = devices.value.filter(
    (d) => d.type !== 'gateway' && (!d.gateway_uid || !gwUids.has(d.gateway_uid)),
  );
  if (standalone.length) result.push({ gateway: null, children: standalone });
  return result;
});

async function load() {
  if (!ensureReady()) return;
  loading.value = true;
  try {
    const page = await getDevices({ per_page: 100 });
    devices.value = page.items ?? [];
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
  uni.navigateTo({ url: `/pages/manage/device-edit?id=${d.id}` });
}
function remove(d: Device) {
  const isGw = d.type === 'gateway';
  const childCount = isGw ? devices.value.filter((x) => x.gateway_uid === d.device_uid).length : 0;
  if (isGw && childCount > 0) {
    toast(`该网关下还有 ${childCount} 个子设备，请先删除它们`);
    return;
  }
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
function statusText(d: Device) {
  return d.is_online ? '在线' : '离线 ' + timeAgo(d.last_seen);
}
onShow(load);
</script>

<template>
  <view class="page">
    <view v-for="(g, gi) in groups" :key="gi" class="group">
      <!-- 网关头 -->
      <view v-if="g.gateway" class="gw" @tap="edit(g.gateway)">
        <view class="gw-ic">⇄</view>
        <view class="mid">
          <text class="name">{{ g.gateway.name }}<text class="tag">网关</text></text>
          <text class="meta">
            {{ g.gateway.location || '未分组' }} · 下挂 {{ g.children.length }} 设备 ·
            <text :style="{ color: g.gateway.is_online ? '#2fb56b' : '#7a8299' }">{{ statusText(g.gateway) }}</text>
          </text>
        </view>
        <text class="del" @tap.stop="remove(g.gateway)">删除</text>
      </view>
      <view v-else class="standalone-title">独立设备</view>

      <!-- 子设备 / 独立设备 -->
      <view class="children" :class="{ nested: !!g.gateway }">
        <view v-for="d in g.children" :key="d.id" class="row">
          <view class="ic">{{ (d.name || '设备').slice(0, 1) }}</view>
          <view class="mid" @tap="edit(d)">
            <text class="name">{{ d.name }}</text>
            <text class="meta">
              {{ d.type }} · {{ d.location || '未分组' }} ·
              <text :style="{ color: d.is_online ? '#2fb56b' : '#7a8299' }">{{ statusText(d) }}</text>
            </text>
          </view>
          <text class="del" @tap="remove(d)">删除</text>
        </view>
        <view v-if="g.gateway && !g.children.length" class="empty-child">网关下暂无子设备</view>
      </view>
    </view>

    <view v-if="!loading && !devices.length" class="empty">还没有设备，点右下角添加</view>
    <view class="fab" @tap="add">＋ 添加设备</view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 160rpx; }
.group { margin-bottom: 24rpx; }
.gw {
  display: flex; align-items: center; gap: 20rpx;
  background: $hg-card; border-radius: $hg-radius; padding: 24rpx;
  box-shadow: $hg-shadow;
}
.gw-ic {
  width: 68rpx; height: 68rpx; border-radius: 20rpx; flex: none;
  background: #eaf0fb; color: $hg-accent; font-size: 34rpx; font-weight: 700;
  text-align: center; line-height: 68rpx;
}
.tag {
  font-size: 20rpx; color: $hg-accent; background: $hg-accent-soft;
  border-radius: 6rpx; padding: 2rpx 10rpx; margin-left: 12rpx; font-weight: 500;
}
.standalone-title { font-size: 24rpx; color: $hg-muted; padding: 8rpx 4rpx 12rpx; }
.children { display: flex; flex-direction: column; gap: 12rpx; }
.children.nested { margin: 12rpx 0 0 28rpx; padding-left: 16rpx; border-left: 2rpx solid $hg-line; }
.row {
  display: flex; align-items: center; gap: 18rpx;
  background: $hg-card; border-radius: $hg-radius-s; padding: 20rpx;
  box-shadow: $hg-shadow;
}
.ic {
  width: 60rpx; height: 60rpx; border-radius: 16rpx; flex: none;
  background: $hg-accent-soft; color: $hg-accent; font-size: 28rpx; font-weight: 700;
  text-align: center; line-height: 60rpx;
}
.mid { flex: 1; min-width: 0; }
.name { font-size: 30rpx; font-weight: 600; color: $hg-fg; }
.meta { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 4rpx; }
.del { font-size: 24rpx; color: $hg-crit; }
.empty-child { font-size: 22rpx; color: $hg-muted; padding: 12rpx 0 0 8rpx; }
.empty { text-align: center; color: $hg-muted; font-size: 26rpx; padding: 100rpx 0; }
.fab {
  position: fixed; left: 32rpx; right: 32rpx; bottom: 40rpx;
  height: 88rpx; line-height: 88rpx; text-align: center;
  background: $hg-accent; color: #fff; font-size: 30rpx; border-radius: $hg-radius-s;
  box-shadow: 0 6rpx 20rpx rgba(43, 111, 227, 0.35);
}
</style>
