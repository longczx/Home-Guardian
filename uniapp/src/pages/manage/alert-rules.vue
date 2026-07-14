<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getAlertRules, deleteAlertRule, type AlertRule } from '@/api/alertRule';
import { ensureReady, toast } from '@/utils/guard';
import { severityLabel, severityColor } from '@/utils/format';

const rules = ref<AlertRule[]>([]);
const loading = ref(false);

async function load() {
  if (!ensureReady()) return;
  loading.value = true;
  try {
    rules.value = (await getAlertRules({ per_page: 100 })).items;
  } catch (e) {
    toast((e as Error).message);
  } finally {
    loading.value = false;
  }
}

function add() {
  uni.navigateTo({ url: '/pages/manage/alert-rule-edit' });
}
function edit(r: AlertRule) {
  uni.navigateTo({ url: `/pages/manage/alert-rule-edit?id=${r.id}` });
}
function remove(r: AlertRule) {
  uni.showModal({
    title: '删除规则',
    content: `确定删除「${r.name}」？`,
    success: async (res) => {
      if (!res.confirm) return;
      try {
        await deleteAlertRule(r.id);
        rules.value = rules.value.filter((x) => x.id !== r.id);
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
      <view v-for="r in rules" :key="r.id" class="row">
        <view class="sev" :style="{ background: severityColor(r.severity) }">{{ severityLabel(r.severity) }}</view>
        <view class="mid" @tap="edit(r)">
          <text class="name">{{ r.name }}</text>
          <text class="meta">
            {{ r.trigger_type === 'offline' ? '设备离线' : (r.telemetry_key + ' ' + (r.condition || '')) }}
            <text v-if="!r.is_enabled" class="off"> · 已禁用</text>
          </text>
        </view>
        <text class="del" @tap="remove(r)">删除</text>
      </view>
    </view>
    <view v-if="!loading && !rules.length" class="empty">还没有告警规则</view>
    <view class="fab" @tap="add">＋ 新建规则</view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 160rpx; }
.list { display: flex; flex-direction: column; gap: 16rpx; }
.row {
  display: flex; align-items: center; gap: 20rpx;
  background: $hg-card; border-radius: $hg-radius; padding: 24rpx; box-shadow: $hg-shadow;
}
.sev {
  flex: none; color: #fff; font-size: 20rpx; font-weight: 700;
  padding: 6rpx 14rpx; border-radius: 999rpx;
}
.mid { flex: 1; min-width: 0; }
.name { font-size: 30rpx; font-weight: 600; color: $hg-fg; }
.meta { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 4rpx; }
.off { color: $hg-crit; }
.del { font-size: 24rpx; color: $hg-crit; }
.empty { text-align: center; color: $hg-muted; font-size: 26rpx; padding: 100rpx 0; }
.fab {
  position: fixed; left: 32rpx; right: 32rpx; bottom: 40rpx;
  height: 88rpx; line-height: 88rpx; text-align: center;
  background: $hg-accent; color: #fff; font-size: 30rpx; border-radius: $hg-radius-s;
  box-shadow: 0 6rpx 20rpx rgba(43, 111, 227, 0.35);
}
</style>
