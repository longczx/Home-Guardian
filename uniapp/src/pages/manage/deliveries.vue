<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import request from '@/api/request';
import { ensureReady, toast } from '@/utils/guard';

interface Delivery { id: number; title: string; status: string; attempts: number; last_error?: string; created_at: string }
const rows = ref<Delivery[]>([]);
const page = ref(1);
const total = ref(0);
const busy = ref(false);
const retrying = ref(0);
const labels: Record<string, string> = { pending: '等待重试', sent: '已送达', failed: '失败', skipped: '渠道已禁用或删除' };
async function load(reset = true) {
  if (!ensureReady() || busy.value) return;
  busy.value = true;
  try {
    const nextPage = reset ? 1 : page.value + 1;
    const data = await request.get<{ items: Delivery[]; total: number }>('/notification-deliveries', { page: nextPage });
    rows.value = reset ? data.items : [...rows.value, ...data.items];
    total.value = data.total;
    page.value = nextPage;
  } catch (e) { toast((e as Error).message); }
  finally { busy.value = false; }
}
async function retry(row: Delivery) {
  if (retrying.value) return;
  retrying.value = row.id;
  try {
    await request.post(`/notification-deliveries/${row.id}/retry`);
    toast('已安排重试');
    await load();
  } catch (e) { toast((e as Error).message); }
  finally { retrying.value = 0; }
}
onShow(() => load());
</script>

<template>
  <view class="page">
    <button :loading="busy" @tap="load()">刷新投递记录</button>
    <view v-for="row in rows" :key="row.id" class="row">
      <text class="title">{{ row.title }}</text>
      <text>{{ labels[row.status] || row.status }} · 尝试 {{ row.attempts }} 次</text>
      <text v-if="row.last_error" class="error">{{ row.last_error }}</text>
      <text>{{ row.created_at }}</text>
      <button v-if="row.status === 'failed'" :loading="retrying === row.id" @tap="retry(row)">重试</button>
    </view>
    <view v-if="!busy && !rows.length">暂无投递记录</view>
    <button v-if="rows.length < total" :loading="busy" @tap="load(false)">加载更多</button>
  </view>
</template>

<style scoped>
.page { padding: 24rpx 32rpx; }
.row { display: flex; flex-direction: column; gap: 14rpx; padding: 24rpx; margin: 20rpx 0; background: white; border-radius: 16rpx; }
.title { font-weight: 600; }
.error { color: #c33; word-break: break-all; }
</style>
