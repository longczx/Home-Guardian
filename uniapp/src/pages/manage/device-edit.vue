<script setup lang="ts">
import { ref } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { updateDevice } from '@/api/device';
import { toast } from '@/utils/guard';

const id = ref(0);
const name = ref('');
const location = ref('');
const saving = ref(false);

async function save() {
  if (!name.value.trim()) {
    toast('名称不能为空');
    return;
  }
  saving.value = true;
  try {
    await updateDevice(id.value, { name: name.value.trim(), location: location.value.trim() });
    toast('已保存', 'success');
    setTimeout(() => uni.navigateBack(), 500);
  } catch (e) {
    toast((e as Error).message);
  } finally {
    saving.value = false;
  }
}

onLoad((q) => {
  id.value = Number(q?.id || 0);
  name.value = decodeURIComponent(String(q?.name || ''));
  location.value = decodeURIComponent(String(q?.location || ''));
});
</script>

<template>
  <view class="page">
    <view class="card">
      <view class="field">
        <text class="label">设备名称</text>
        <input v-model="name" class="input" placeholder="设备名称" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">位置 / 房间</text>
        <input v-model="location" class="input" placeholder="如 客厅" placeholder-class="ph" />
      </view>
    </view>
    <button class="btn btn-primary" :loading="saving" @tap="save">保存</button>
  </view>
</template>

<style lang="scss" scoped>
.page {
  padding: 32rpx;
}
.card {
  background: $hg-card;
  border-radius: $hg-radius;
  padding: 8rpx 28rpx;
  box-shadow: $hg-shadow;
}
.field {
  padding: 28rpx 0;
  border-bottom: 1rpx solid $hg-line;
}
.field:last-child {
  border-bottom: none;
}
.label {
  display: block;
  font-size: 24rpx;
  color: $hg-muted;
  margin-bottom: 12rpx;
}
.input {
  font-size: 30rpx;
  color: $hg-fg;
}
.ph {
  color: #b6bccb;
}
.btn {
  margin-top: 48rpx;
  border-radius: $hg-radius-s;
  height: 88rpx;
  line-height: 88rpx;
  font-size: 30rpx;
}
.btn-primary {
  background: $hg-accent;
  color: #fff;
}
</style>
