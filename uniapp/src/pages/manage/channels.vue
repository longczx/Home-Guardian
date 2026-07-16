<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getChannels, testChannel, deleteChannel, type NotificationChannel } from '@/api/channel';
import { ensureReady, toast } from '@/utils/guard';

const channels = ref<NotificationChannel[]>([]);
const loading = ref(false);
const testing = ref(0);

const TYPE_LABEL: Record<string, string> = {
  email: '邮件',
  webhook: 'Webhook',
  telegram: 'Telegram',
  wechat_work: '企业微信',
  dingtalk: '钉钉',
  in_app: '应用内',
};

async function load() {
  if (!ensureReady()) return;
  loading.value = true;
  try {
    const list = await getChannels({ per_page: 100 });
    channels.value = Array.isArray(list) ? list : [];
  } catch (e) {
    toast((e as Error).message);
  } finally {
    loading.value = false;
  }
}

async function test(c: NotificationChannel) {
  testing.value = c.id;
  try {
    await testChannel(c.id);
    toast('测试消息已发送', 'success');
  } catch (e) {
    toast((e as Error).message);
  } finally {
    testing.value = 0;
  }
}
function add() {
  uni.navigateTo({ url: '/pages/manage/channel-edit' });
}
function edit(c: NotificationChannel) {
  uni.navigateTo({ url: `/pages/manage/channel-edit?id=${c.id}` });
}
function remove(c: NotificationChannel) {
  uni.showModal({
    title: '删除渠道',
    content: `确定删除「${c.name}」？关联的告警规则将失去该通知渠道。`,
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await deleteChannel(c.id);
        channels.value = channels.value.filter((x) => x.id !== c.id);
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
      <view v-for="c in channels" :key="c.id" class="row">
        <view class="mid" @tap="edit(c)">
          <text class="name">{{ c.name }}</text>
          <text class="meta">
            {{ TYPE_LABEL[c.type] || c.type }}
            <text v-if="!c.is_enabled" class="off"> · 已禁用</text>
          </text>
        </view>
        <button class="test" :loading="testing === c.id" @tap="test(c)">测试</button>
        <text class="del" @tap="remove(c)">删除</text>
      </view>
    </view>
    <view v-if="!loading && !channels.length" class="empty">还没有通知渠道，点下方新建</view>
    <view class="fab" @tap="add">＋ 新建渠道</view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 160rpx; }
.list { display: flex; flex-direction: column; gap: 16rpx; }
.row {
  display: flex; align-items: center; gap: 16rpx;
  background: $hg-card; border-radius: $hg-radius; padding: 26rpx 24rpx; box-shadow: $hg-shadow;
}
.mid { flex: 1; min-width: 0; }
.del { font-size: 24rpx; color: $hg-crit; }
.fab {
  position: fixed; left: 32rpx; right: 32rpx; bottom: 40rpx;
  height: 88rpx; line-height: 88rpx; text-align: center;
  background: $hg-accent; color: #fff; font-size: 30rpx; border-radius: $hg-radius-s;
  box-shadow: 0 6rpx 20rpx rgba(43, 111, 227, 0.35);
}
.name { font-size: 30rpx; font-weight: 600; color: $hg-fg; }
.meta { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 4rpx; }
.off { color: $hg-crit; }
.test {
  background: $hg-accent-soft; color: $hg-accent; font-size: 24rpx;
  border-radius: 999rpx; height: 60rpx; line-height: 60rpx; padding: 0 28rpx; margin: 0;
}
.empty { text-align: center; color: $hg-muted; font-size: 26rpx; padding: 80rpx 20rpx; line-height: 1.6; }
</style>
