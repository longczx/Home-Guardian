<script setup lang="ts">
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { onShow, onHide, onPullDownRefresh } from '@dcloudio/uni-app';
import PageHeader from '@/components/PageHeader.vue';
import { getAlertLogs, acknowledgeAlert, resolveAlert } from '@/api/alert';
import type { AlertLog } from '@/api/types';
import { ensureReady, toast } from '@/utils/guard';
import { timeAgo, severityColor } from '@/utils/format';
import { onWs } from '@/utils/ws';

const { t } = useI18n();
const FILTERS = computed(() => [
  { key: '', label: t('alert.all') },
  { key: 'triggered', label: t('alert.triggered') },
  { key: 'acknowledged', label: t('alert.acknowledged') },
  { key: 'resolved', label: t('alert.resolved') },
]);

function sevLabel(sev: string | null): string {
  return t(sev === 'critical' ? 'alert.sevCritical' : sev === 'info' ? 'alert.sevInfo' : 'alert.sevWarning');
}

const logs = ref<AlertLog[]>([]);
const filter = ref('');
const loading = ref(false);

const untreated = computed(() => logs.value.filter((l) => l.status === 'triggered').length);

async function load() {
  if (!ensureReady()) return;
  loading.value = true;
  try {
    const params: Record<string, string | number> = { per_page: 50 };
    if (filter.value) params.status = filter.value;
    const page = await getAlertLogs(params);
    logs.value = page.items;
  } catch (e) {
    toast((e as Error).message);
  } finally {
    loading.value = false;
  }
}

function setFilter(k: string) {
  filter.value = k;
  load();
}

async function onAck(l: AlertLog) {
  try {
    await acknowledgeAlert(l.id);
    l.status = 'acknowledged';
    l.acknowledged_at = new Date().toISOString();
  } catch (e) {
    toast((e as Error).message);
  }
}

async function onResolve(l: AlertLog) {
  try {
    await resolveAlert(l.id);
    l.status = 'resolved';
    l.resolved_at = new Date().toISOString();
  } catch (e) {
    toast((e as Error).message);
  }
}

// 实时：新告警/告警恢复时刷新列表
let unsubs: Array<() => void> = [];
onShow(() => {
  load();
  unsubs = [
    onWs('alert', load),
    onWs('alert_resolved', load),
  ];
});
onHide(() => { unsubs.forEach((u) => u()); unsubs = []; });
onPullDownRefresh(async () => {
  await load();
  uni.stopPullDownRefresh();
});
</script>

<template>
  <view class="page">
    <PageHeader :title="t('alert.title')" :subtitle="t('alert.untreated', { n: untreated })" />

    <scroll-view scroll-x class="chips" :show-scrollbar="false">
      <view
        v-for="f in FILTERS"
        :key="f.key"
        class="chip"
        :class="{ on: f.key === filter }"
        @tap="setFilter(f.key)"
      >{{ f.label }}</view>
    </scroll-view>

    <view class="list">
      <view
        v-for="l in logs"
        :key="l.id"
        class="alert"
        :class="{ resolved: l.status === 'resolved' }"
      >
        <view class="stripe" :style="{ background: l.status === 'resolved' ? '#2fb56b' : severityColor(l.severity) }" />
        <view class="body">
          <view class="head">
            <text
              v-if="l.status === 'resolved'"
              class="sev done"
            >{{ t('alert.sevDone') }}</text>
            <text
              v-else
              class="sev"
              :style="{ background: severityColor(l.severity) }"
            >{{ sevLabel(l.severity) }}</text>
            <text class="title">{{ l.rule?.name || '告警' }}</text>
          </view>
          <text class="desc">
            {{ l.device?.name || '设备' }}<text v-if="l.device?.location"> · {{ l.device.location }}</text>
            <text v-if="l.message"> · {{ l.message }}</text>
          </text>
          <view class="foot">
            <text class="time">
              {{ timeAgo(l.triggered_at) }} {{ t('alert.triggeredAt') }}<text v-if="l.resolved_at"> · {{ t('alert.recovered') }}</text>
            </text>
            <view class="acts">
              <text v-if="l.status === 'triggered'" class="act solid" @tap="onAck(l)">{{ t('alert.ack') }}</text>
              <text v-if="l.status !== 'resolved'" class="act" @tap="onResolve(l)">{{ t('alert.resolve') }}</text>
            </view>
          </view>
        </view>
      </view>
    </view>

    <view v-if="!loading && !logs.length" class="empty">{{ t('alert.empty') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding-bottom: 40rpx;
}
.chips {
  white-space: nowrap;
  padding: 8rpx 24rpx 20rpx;
}
.chip {
  display: inline-block;
  padding: 12rpx 28rpx;
  margin: 0 8rpx;
  border-radius: 999rpx;
  background: $hg-card;
  border: 1rpx solid $hg-line;
  font-size: 24rpx;
  color: $hg-muted;
}
.chip.on {
  background: $hg-accent;
  border-color: $hg-accent;
  color: #fff;
  font-weight: 600;
}
.list {
  padding: 0 32rpx;
  display: flex;
  flex-direction: column;
  gap: 18rpx;
}
.alert {
  display: flex;
  overflow: hidden;
  background: $hg-card;
  border-radius: $hg-radius-s;
  box-shadow: $hg-shadow;
}
.alert.resolved {
  opacity: 0.6;
}
.stripe {
  width: 8rpx;
  flex: none;
}
.body {
  flex: 1;
  padding: 24rpx 26rpx;
  min-width: 0;
}
.head {
  display: flex;
  align-items: center;
  gap: 14rpx;
}
.sev {
  font-size: 20rpx;
  font-weight: 700;
  color: #fff;
  padding: 4rpx 14rpx;
  border-radius: 999rpx;
}
.sev.done {
  background: transparent;
  color: $hg-ok;
  border: 1rpx solid $hg-ok;
}
.title {
  font-size: 28rpx;
  font-weight: 600;
  color: $hg-fg;
}
.desc {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
  margin-top: 8rpx;
  line-height: 1.5;
}
.foot {
  display: flex;
  align-items: center;
  margin-top: 16rpx;
}
.time {
  font-size: 22rpx;
  color: $hg-muted;
}
.acts {
  margin-left: auto;
  display: flex;
  gap: 14rpx;
}
.act {
  font-size: 24rpx;
  color: $hg-accent;
  border: 1rpx solid $hg-accent;
  border-radius: 999rpx;
  padding: 8rpx 24rpx;
}
.act.solid {
  background: $hg-accent;
  color: #fff;
}
.empty {
  text-align: center;
  color: $hg-muted;
  font-size: 26rpx;
  padding: 100rpx 0;
}
</style>
