<script setup lang="ts">
import { ref } from 'vue';
import { onShow, onPullDownRefresh } from '@dcloudio/uni-app';
import { getBackups, statusLabel } from '@/api/operations';
import type { Backup } from '@/api/operations';
const items = ref<Backup[]>([]); const error = ref('');
async function load() {
  try { items.value = (await getBackups()).items; error.value = ''; }
  catch (e) { error.value = (e as Error).message; }
  finally { uni.stopPullDownRefresh(); }
}
onShow(load); onPullDownRefresh(load);
</script>
<template>
  <view class="page">
    <view class="card"><text class="title">备份与恢复</text><text>服务器定期备份数据库、队列数据和部署配置。这里显示最近的备份结果。</text><text>恢复会替换整个平台的数据并短暂停止服务，须由服务器管理员按运维文档执行。恢复前会自动保留当前数据。</text><text>备份文件包含账户及配置凭证，仅保存在服务器受限目录，请另存一份到独立存储。</text></view>
    <text v-if="error">{{ error }}</text><text v-else-if="!items.length">暂无备份记录，请先配置服务器备份任务。</text>
    <view v-for="item in items" :key="item.id" class="card"><text class="title">{{ statusLabel(item.status) }}</text><text>{{ item.created_at }}</text><text>编号：{{ item.id }}</text><text v-if="item.size_bytes">大小：{{ (item.size_bytes / 1048576).toFixed(2) }} MB</text><text v-if="item.commit">代码版本：{{ item.commit.slice(0, 8) }}</text><text v-if="item.error">{{ item.error }}</text></view>
    <button @tap="load">刷新</button>
  </view>
</template>
<style scoped lang="scss">
.page { padding: 24rpx; }.card { background: $hg-card; padding: 28rpx; border-radius: 20rpx; margin-bottom: 24rpx; }text { display: block; font-size: 25rpx; line-height: 1.7; margin-bottom: 12rpx; word-break: break-all; }.title { font-size: 32rpx; font-weight: 600; }
</style>
