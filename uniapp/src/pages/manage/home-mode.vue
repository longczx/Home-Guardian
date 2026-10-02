<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { getHomeOverview, setHomeMode, modes, type HomeMode } from '@/api/experience';
import { getAutomations, type Automation } from '@/api/automation';
import { toast } from '@/utils/guard';
const current = ref<HomeMode>('home'); const rules = ref<Automation[]>([]); const busy = ref(false); const error = ref('');
async function load() { try { const [overview, page] = await Promise.all([getHomeOverview(), getAutomations({ per_page: 100 })]); current.value = overview.mode; rules.value = page.items; error.value = ''; } catch (e) { error.value = (e as Error).message; } }
function applies(rule: Automation, mode: HomeMode) { const allowed = rule.trigger_config.modes as string[] | undefined; return !allowed?.length || allowed.includes(mode); }
function change(mode: HomeMode) {
  if (busy.value || mode === current.value) return;
  const eligible = rules.value.filter((r) => r.is_enabled && applies(r, mode)).map((r) => r.name);
  uni.showModal({ title: '切换为' + modes.find((m) => m.value === mode)?.label, content: `后续触发时适用的规则：${eligible.join('、') || '无'}。本次切换不会立即发送设备指令。`, success: async (r) => {
    if (!r.confirm) return; busy.value = true;
    try { await setHomeMode(mode); current.value = mode; toast('模式已切换', 'success'); } catch (e) { toast((e as Error).message); } finally { busy.value = false; }
  } });
}
onShow(load);
</script>
<template>
  <view class="page">
    <text class="hint">
      家庭模式决定规则是否适用。需要管理员及全部房间权限；规则还会检查数据、时间窗口和人工接管。
    </text><text v-if="error">
      {{ error }}
    </text><view class="modes">
      <button
        v-for="mode in modes"
        :key="mode.value"
        :disabled="busy"
        :class="{ active: current === mode.value }"
        @tap="change(mode.value)"
      >
        {{ mode.label }}{{ current === mode.value ? ' ✓' : '' }}
      </button>
    </view><view
      v-for="rule in rules"
      :key="rule.id"
      class="card"
    >
      <text>{{ rule.name }} · {{ rule.is_enabled ? '已启用' : '已停用' }}</text><text class="hint">
        适用：{{ modes.filter((m) => applies(rule, m.value)).map((m) => m.label).join('、') }}
      </text>
    </view>
  </view>
</template>
<style scoped lang="scss">.page { padding: 28rpx; }.hint { display: block; color: $hg-muted; font-size: 25rpx; line-height: 1.7; }.modes { display: flex; margin: 24rpx 0; }.active { color: $hg-accent; }.card { padding: 24rpx; background: $hg-card; margin-bottom: 16rpx; border-radius: 18rpx; }</style>
