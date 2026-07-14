<script setup lang="ts">
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { onShow } from '@dcloudio/uni-app';
import { getPushSettings, updatePushSettings } from '@/api/push';
import { registerPush } from '@/utils/push';
import { toast } from '@/utils/guard';

const { t } = useI18n();
const enabled = ref(true);
const minSeverity = ref('warning');
const registered = ref(false);

const LEVELS = computed(() => [
  { value: 'info', label: t('push.levelInfo') },
  { value: 'warning', label: t('push.levelWarning') },
  { value: 'critical', label: t('push.levelCritical') },
]);

async function load() {
  try {
    const s = await getPushSettings();
    enabled.value = s.push_enabled;
    minSeverity.value = s.min_severity;
    registered.value = s.registered;
  } catch (e) {
    toast((e as Error).message);
  }
}

async function onToggle(e: { detail: { value: boolean } }) {
  enabled.value = e.detail.value;
  try {
    await updatePushSettings({ push_enabled: enabled.value });
    if (enabled.value) registerPush(); // 重新登记 cid
  } catch (err) {
    toast((err as Error).message);
  }
}

async function pickLevel(v: string) {
  minSeverity.value = v;
  try {
    await updatePushSettings({ min_severity: v });
  } catch (e) {
    toast((e as Error).message);
  }
}

onShow(load);
</script>

<template>
  <view class="page">
    <view class="card">
      <view class="row">
        <view>
          <text class="t">{{ t('push.receive') }}</text>
          <text class="s">{{ t('push.receiveSub') }}</text>
        </view>
        <switch :checked="enabled" color="#2b6fe3" @change="onToggle" />
      </view>
    </view>

    <view v-if="enabled" class="card">
      <text class="ct">{{ t('push.level') }}</text>
      <view
        v-for="l in LEVELS"
        :key="l.value"
        class="opt"
        @tap="pickLevel(l.value)"
      >
        <text class="opt-label">{{ l.label }}</text>
        <text v-if="minSeverity === l.value" class="check">✓</text>
      </view>
    </view>

    <view class="tip">
      <text v-if="!registered">{{ t('push.notRegistered') }}</text>
      <text v-else>{{ t('push.registered') }}</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx; }
.card {
  background: $hg-card; border-radius: $hg-radius; padding: 8rpx 28rpx;
  box-shadow: $hg-shadow; margin-bottom: 20rpx;
}
.row { display: flex; align-items: center; justify-content: space-between; padding: 28rpx 0; }
.t { font-size: 30rpx; color: $hg-fg; font-weight: 600; }
.s { display: block; font-size: 22rpx; color: $hg-muted; margin-top: 4rpx; }
.ct { display: block; font-size: 24rpx; color: $hg-muted; padding: 24rpx 0 8rpx; }
.opt {
  display: flex; align-items: center; justify-content: space-between;
  padding: 28rpx 0; border-top: 1rpx solid $hg-line;
}
.opt-label { font-size: 30rpx; color: $hg-fg; }
.check { color: $hg-accent; font-size: 32rpx; font-weight: 700; }
.tip { font-size: 22rpx; color: $hg-muted; padding: 8rpx 8rpx; line-height: 1.6; }
</style>
