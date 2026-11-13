<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { onShow, onHide, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app';
import RoomPicker from '@/components/RoomPicker.vue';
import { useServerStore } from '@/stores/server';
import PageHeader from '@/components/PageHeader.vue';
import { getAlertLogs, acknowledgeAlert, resolveAlert } from '@/api/alert';
import { handleAlert } from '@/api/experience';
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

const untreated = ref(0);
const page = ref(0); const lastPage = ref(1); const total = ref(0); const error = ref('');
const location = ref(''); const query = ref(''); const days = ref(0);
const server = useServerStore(); let generation = 0;
async function load(more = false) {
  if (!ensureReady() || (more && (loading.value || page.value >= lastPage.value))) return;
  const attempt = ++generation;
  const nextPage = more ? page.value + 1 : 1;
  loading.value = true; error.value = '';
  try {
    const params: Record<string, string | number> = { per_page: 30, page: nextPage };
    if (filter.value) params.status = filter.value;
    if (location.value) params.location = location.value;
    if (query.value.trim()) params.q = query.value.trim();
    if (days.value) params.start = new Date(Date.now() - days.value * 86400000).toISOString();
    const [result, pending] = await Promise.all([getAlertLogs(params), getAlertLogs({ status: 'triggered', per_page: 1 })]);
    if (attempt !== generation) return;
    logs.value = [...new Map((more ? [...logs.value, ...result.items] : result.items).map(l => [l.id, l])).values()];
    page.value = result.current_page; lastPage.value = result.last_page; total.value = result.total;
    untreated.value = pending.total;
  } catch (e) { if (attempt === generation) error.value = (e as Error).message; }
  finally { if (attempt === generation) loading.value = false; }
}
watch([location, days], () => { void load(); });
watch(() => server.connectionRevision, () => { generation++; logs.value = []; untreated.value = 0; page.value = 0; loading.value = false; error.value = ''; });
onReachBottom(() => { void load(true); });

function setFilter(k: string) {
  filter.value = k;
  load();
}

async function onAck(l: AlertLog) {
  try {
    await acknowledgeAlert(l.id);
    l.status = 'acknowledged';
    l.acknowledged_at = new Date().toISOString();
    await load();
  } catch (e) {
    toast((e as Error).message);
  }
}

async function onResolve(l: AlertLog) {
  try {
    await resolveAlert(l.id);
    l.status = 'resolved';
    l.resolved_at = new Date().toISOString();
    await load();
  } catch (e) {
    toast((e as Error).message);
  }
}

function handling(l: AlertLog) {
  uni.showModal({ title: '记录处理情况', editable: true, placeholderText: '如：已检查门窗，等待温度恢复', content: l.handling_note || '', success: async (r) => {
    if (!r.confirm) return;
    try { await handleAlert(l.id, r.content || ''); await load(); toast('已记录，实际恢复以传感器为准'); } catch (e) { toast((e as Error).message); }
  } });
}
// 实时：新告警/告警恢复时刷新列表
let unsubs: Array<() => void> = [];
onShow(() => {
  load();
  unsubs = [
    onWs('alert', () => { void load(); }),
    onWs('alert_resolved', () => { void load(); }),
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
    <PageHeader
      :title="t('alert.title')"
      :subtitle="t('alert.untreated', { n: untreated })"
    />

    <scroll-view
      scroll-x
      class="chips"
      :show-scrollbar="false"
    >
      <view
        v-for="f in FILTERS"
        :key="f.key"
        class="chip"
        :class="{ on: f.key === filter }"
        @tap="setFilter(f.key)"
      >
        {{ f.label }}
      </view>
    </scroll-view>

    <view class="filters">
      <input
        v-model="query"
        placeholder="搜索设备名称或设备编号"
        confirm-type="search"
        @confirm="load()"
      ><button
        size="mini"
        @tap="load()"
      >
        搜索
      </button>
      <RoomPicker
        v-model="location"
        empty-label="全部房间"
      />
      <view class="periods">
        <text
          v-for="n in [0, 1, 7, 30]"
          :key="n"
          :class="{ selected: days === n }"
          @tap="days = n"
        >
          {{ n ? '近 ' + n + ' 天' : '全部时间' }}
        </text>
      </view>
      <text>当前筛选共 {{ total }} 条 · 顶部待处理数为全部可访问告警</text>
    </view>
    <text
      v-if="error"
      class="load-error"
      @tap="load()"
    >
      {{ error }} · 点击重试
    </text>
    <view class="list">
      <view
        v-for="l in logs"
        :key="l.id"
        class="alert"
        :class="{ resolved: l.status === 'resolved' }"
      >
        <view
          class="stripe"
          :style="{ background: l.status === 'resolved' ? '#2fb56b' : severityColor(l.severity) }"
        />
        <view class="body">
          <view class="head">
            <text
              v-if="l.status === 'resolved'"
              class="sev done"
            >
              {{ t('alert.sevDone') }}
            </text>
            <text
              v-else
              class="sev"
              :style="{ background: severityColor(l.severity) }"
            >
              {{ sevLabel(l.severity) }}
            </text>
            <text class="title">
              {{ l.rule?.name || '告警' }}
            </text>
          </view>
          <text class="desc">
            {{ l.device?.name || '设备' }}<text v-if="l.device?.location">
              · {{ l.device.location }}
            </text>
            <text v-if="l.message">
              · {{ l.message }}
            </text>
          </text>
          <text
            v-if="l.handling_note"
            class="desc"
          >
            处理：{{ l.handling_note }}
          </text><text class="desc">
            {{ l.recovered_at ? "传感器已恢复：" + l.recovered_at : "尚无传感器恢复记录" }}
          </text>
          <view class="foot">
            <text class="time">
              {{ timeAgo(l.triggered_at) }} {{ t('alert.triggeredAt') }}<text v-if="l.resolved_at">
                · {{ l.recovered_at ? '传感器已恢复' : '人工标记完成' }}
              </text>
            </text>
            <view class="acts">
              <text
                class="act"
                @tap="handling(l)"
              >
                处理备注
              </text>
              <text
                v-if="l.status === 'triggered'"
                class="act solid"
                @tap="onAck(l)"
              >
                {{ t('alert.ack') }}
              </text>
              <text
                v-if="l.status !== 'resolved'"
                class="act"
                @tap="onResolve(l)"
              >
                {{ t('alert.resolve') }}
              </text>
            </view>
          </view>
        </view>
      </view>
    </view>

    <button
      v-if="page < lastPage"
      :disabled="loading"
      :loading="loading"
      @tap="load(true)"
    >
      加载更多告警
    </button>
    <text
      v-else-if="logs.length"
      class="list-end"
    >
      已显示全部 {{ total }} 条
    </text>
    <view
      v-if="!loading && !error && !logs.length"
      class="empty"
    >
      {{ t('alert.empty') }}
    </view>
  </view>
</template>

<style lang="scss" scoped>
.filters { margin: 8rpx 24rpx 24rpx; padding: 24rpx; background: $hg-card; border-radius: 20rpx; font-size: 24rpx; color: $hg-muted; }.filters input { color: $hg-fg; padding: 20rpx 0; }.periods { display: flex; gap: 24rpx; margin-bottom: 24rpx; }.selected { color: $hg-accent; }.load-error, .list-end { display: block; padding: 24rpx; text-align: center; color: $hg-muted; }
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
