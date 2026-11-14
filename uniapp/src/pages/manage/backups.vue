<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app';
import { getBackups, statusLabel, type Backup, type BackupHealth } from '@/api/operations';
import { ensureReady, toast } from '@/utils/guard';
import { useServerStore } from '@/stores/server';
const items = ref<Backup[]>([]); const health = ref<BackupHealth | null>(null); const error = ref(''); const loading = ref(false); const selected = ref('');
const currentCommit = ref('unknown');
const server = useServerStore(); let generation = 0;
const chosen = computed(() => items.value.find(item => item.id === selected.value));
const archive = computed(() => chosen.value && /^\d{8}T\d{6}Z-[a-f0-9]{8}$/.test(chosen.value.id) ? `/root/backups/home-guardian/managed/${chosen.value.id}.zip` : '');
function command(action: 'verify' | 'drill' | 'restore' | 'record-copy') {
  if (!archive.value || chosen.value?.status !== 'success') return '';
  return `cd /opt/home-guardian\npython3 scripts/backup.py --backup-dir /root/backups/home-guardian/managed ${action} ${archive.value}` + (action === 'restore' ? ` --confirm ${chosen.value.id}` : action === 'record-copy' ? " --label '请填写实际异机存储标签'" : '');
}
function copy(action: 'verify' | 'drill' | 'restore' | 'record-copy') {
  const text = command(action); if (!text) return;
  uni.setClipboardData({ data: text, success: () => toast('命令已复制，请在服务器 SSH 终端执行'), fail: () => toast('复制失败，可手动记录下方命令') });
}
function format(value?: string) { return value ? new Date(value).toLocaleString() : '尚未完成'; }
async function load() {
  if (!ensureReady()) return; const attempt = ++generation; loading.value = true;
  try { const result = await getBackups(); if (attempt !== generation) return; items.value = result.items; currentCommit.value = result.current_commit || 'unknown'; health.value = result.health || null; error.value = ''; if (!items.value.some(item => item.id === selected.value)) selected.value = result.health?.last_success?.id || ''; }
  catch (e) { if (attempt === generation) error.value = (e as Error).message; }
  finally { if (attempt === generation) loading.value = false; uni.stopPullDownRefresh(); }
}
watch(() => server.connectionRevision, () => { generation++; items.value = []; health.value = null; selected.value = ''; error.value = '服务器已切换，请重新加载'; });
onShow(load); onPullDownRefresh(load);
</script>
<template>
  <view class="page">
    <text class="title">
      备份健康
    </text><text class="hint">
      备份包含整个平台的账户与配置凭证，仅家庭所有者可在单家庭实例查看。
    </text>
    <view
      v-if="health"
      class="section"
    >
      <text class="title">
        {{ health.needs_attention ? '需要关注' : '最近备份正常' }}
      </text><text>最近成功：{{ format(health.last_success?.created_at) }}</text><text>距今 {{ health.age_hours ?? '—' }} 小时 · 最近连续失败 {{ health.consecutive_failures }} 次</text><text
        v-for="warning in health.warnings"
        :key="warning"
        class="warning"
      >
        {{ warning }}
      </text><text class="hint">
        超过 36 小时无成功备份或校验、演练、副本待完善时，会在首页提醒；已有通知渠道每天最多提醒一次。
      </text>
    </view>
    <text
      v-if="error"
      class="error"
    >
      {{ error }}
    </text><button
      :loading="loading"
      @tap="load"
    >
      刷新状态
    </button>
    <text class="title list-title">
      备份记录 · 选择一份查看恢复准备
    </text><text
      v-if="!loading && !items.length"
      class="hint"
    >
      暂无备份记录，请管理员配置服务器备份任务。
    </text>
    <view
      v-for="item in items"
      :key="item.id"
      class="section selectable"
      :class="{ chosen: selected === item.id }"
      @tap="selected = item.id"
    >
      <text>{{ selected === item.id ? '✓ ' : '' }}{{ statusLabel(item.status) }} · {{ format(item.created_at) }}</text><text class="hint">
        {{ item.id }} · {{ item.size_bytes ? (item.size_bytes / 1048576).toFixed(2) + ' MB' : '大小待确认' }}{{ item.commit ? ' · 版本 ' + item.commit.slice(0, 8) : '' }}
      </text><text
        v-if="item.error"
        class="error"
      >
        {{ item.error }}
      </text>
    </view>
    <view
      v-if="chosen"
      class="section"
    >
      <text class="title">
        这份备份是否可恢复？
      </text><text v-if="chosen.commit && currentCommit !== 'unknown' && chosen.commit !== currentCommit" class="warning">备份来自其他代码版本。恢复前先部署对应版本（{{ chosen.commit.slice(0, 8) }}），不要将旧数据库直接覆盖到不同版本的应用。</text><text>文件完整性校验：{{ format(chosen.integrity_verified_at) }}</text><text>数据库隔离恢复演练：{{ format(chosen.database_restore_verified_at) }}</text><text>Redis 副本恢复演练：{{ format(chosen.redis_restore_verified_at) }}</text><text class="hint">
        文件校验不等于恢复演练。EMQX 文件仅校验完整性，完整服务恢复仍需在维护窗口验证。
      </text><text>异机副本：{{ chosen.external_copy_at ? (chosen.external_copy_label || '已登记') + ' · ' + format(chosen.external_copy_at) : '未登记' }}</text><text class="hint">
        异机副本为管理员登记，系统不会自动上传或核验远程文件。不要只把副本放在同一服务器。
      </text><text
        v-if="chosen.drill_error"
        class="error"
      >
        {{ chosen.drill_error }} · {{ format(chosen.drill_failed_at) }}
      </text>
      <template v-if="chosen.status === 'success'">
        <button
          size="mini"
          @tap="copy('verify')"
        >
          复制文件校验命令
        </button><button
          size="mini"
          @tap="copy('drill')"
        >
          复制隔离恢复演练命令
        </button><button
          size="mini"
          @tap="copy('record-copy')"
        >
          完成异机复制后，登记副本
        </button>
      </template>
    </view>
    <view
      v-if="chosen?.status === 'success'"
      class="section"
    >
      <text class="title">
        管理员恢复步骤
      </text><text>1. 在另一台机器另存备份；在服务器核对编号，准备与备份对应的代码版本。</text><text>2. 执行文件校验与隔离恢复演练，刷新本页检查结果。</text><text>3. 安排维护窗口，告知家庭成员暂停操作。</text><text>4. 通过 SSH 执行下面的恢复命令；这会替换平台数据并短暂停止服务，执行前自动保留当前数据。</text><text
        selectable
        class="code"
      >
        {{ command('restore') }}
      </text><button @tap="copy('restore')">
        复制所选备份的恢复命令
      </button><text>5. 恢复完成后重新登录；检查页面、MQTT、设备上线和遥测。历史控制指令不会重放。</text><text class="hint">
        命令不会在 App 内执行，服务器目录为当前部署的 /opt/home-guardian。新机器恢复前需先准备匹配的服务版本与配置。
      </text>
    </view>
  </view>
</template>
<style scoped lang="scss">.page { padding: 28rpx; }.title { display: block; font-size: 30rpx; font-weight: 600; }.hint { display: block; color: $hg-muted; font-size: 24rpx; line-height: 1.8; margin: 16rpx 0; }.section { background: $hg-card; border-radius: 20rpx; padding: 28rpx; margin: 24rpx 0; }.section > text { display: block; font-size: 25rpx; line-height: 1.8; margin-bottom: 14rpx; word-break: break-all; }.section .title { font-size: 30rpx; }.section .hint { font-size: 24rpx; }.list-title { margin-top: 36rpx; }.chosen { box-shadow: inset 0 0 0 2rpx $hg-accent; }.warning { color: $hg-warn; }.error { color: $hg-crit; }.code { white-space: pre-wrap; padding: 20rpx; background: $hg-card-2; font-family: monospace; }button { margin-top: 20rpx; }</style>
