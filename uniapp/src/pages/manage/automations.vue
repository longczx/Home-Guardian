<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getAutomations, setAutomationEnabled, deleteAutomation, type Automation } from '@/api/automation';
import { ensureReady, toast } from '@/utils/guard';
import { timeAgo } from '@/utils/format';

const items = ref<Automation[]>([]);
const loading = ref(false);

async function load() {
  if (!ensureReady()) return;
  loading.value = true;
  try {
    items.value = (await getAutomations({ per_page: 100 })).items;
  } catch (e) {
    toast((e as Error).message);
  } finally {
    loading.value = false;
  }
}

async function toggle(a: Automation, e: { detail: { value: boolean } }) {
  const next = e.detail.value;
  try {
    await setAutomationEnabled(a.id, next);
    a.is_enabled = next;
  } catch (err) {
    toast((err as Error).message);
  }
}

function remove(a: Automation) {
  uni.showModal({
    title: '删除自动化',
    content: `确定删除「${a.name}」？`,
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await deleteAutomation(a.id);
        items.value = items.value.filter((x) => x.id !== a.id);
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
    <view class="tip">自动化规则的新建/编辑请在后台完成；App 端可启停与删除。</view>
    <view class="list">
      <view v-for="a in items" :key="a.id" class="row">
        <view class="mid">
          <text class="name">{{ a.name }}</text>
          <text class="meta">
            {{ a.trigger_type === 'schedule' ? '定时触发' : '遥测触发' }}
            <text v-if="a.last_triggered_at"> · 上次 {{ timeAgo(a.last_triggered_at) }}</text>
          </text>
        </view>
        <switch :checked="a.is_enabled" color="#2b6fe3" @change="toggle(a, $event)" />
        <text class="del" @tap="remove(a)">删除</text>
      </view>
    </view>
    <view v-if="!loading && !items.length" class="empty">还没有自动化规则</view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx; }
.tip { font-size: 22rpx; color: $hg-muted; margin-bottom: 16rpx; line-height: 1.5; }
.list { display: flex; flex-direction: column; gap: 16rpx; }
.row {
  display: flex; align-items: center; gap: 16rpx;
  background: $hg-card; border-radius: $hg-radius; padding: 24rpx; box-shadow: $hg-shadow;
}
.mid { flex: 1; min-width: 0; }
.name { font-size: 30rpx; font-weight: 600; color: $hg-fg; }
.meta { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 4rpx; }
.del { font-size: 24rpx; color: $hg-crit; }
.empty { text-align: center; color: $hg-muted; font-size: 26rpx; padding: 100rpx 0; }
</style>
