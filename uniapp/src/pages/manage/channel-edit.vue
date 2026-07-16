<script setup lang="ts">
import { ref, computed } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import { getChannel, createChannel, updateChannel, CHANNEL_TYPES, type ChannelInput } from '@/api/channel';
import { toast } from '@/utils/guard';

const editId = ref(0);
const saving = ref(false);
const name = ref('');
const type = ref('email');
const enabled = ref(true);
const config = ref<Record<string, string>>({});

const typeKeys = Object.keys(CHANNEL_TYPES);
const typeDef = computed(() => CHANNEL_TYPES[type.value]);
const typeLabel = computed(() => typeDef.value?.label ?? type.value);

function chooseType() {
  if (editId.value) return; // 编辑时不允许改类型
  uni.showActionSheet({
    itemList: typeKeys.map((k) => CHANNEL_TYPES[k].label),
    success: (r) => {
      type.value = typeKeys[r.tapIndex];
      config.value = {}; // 换类型清空配置
    },
  });
}

function buildConfig(): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const fld of typeDef.value.fields) {
    const v = (config.value[fld.key] ?? '').trim();
    if (fld.key === 'to') {
      out.to = v ? v.split(',').map((s) => s.trim()).filter(Boolean) : [];
    } else if (fld.key === 'smtp_port') {
      if (v) out.smtp_port = Number(v);
    } else if (v) {
      out[fld.key] = v;
    }
  }
  return out;
}

async function save() {
  if (!name.value.trim()) return toast('请填写名称');
  const payload: ChannelInput = {
    name: name.value.trim(),
    type: type.value,
    config: buildConfig(),
    is_enabled: enabled.value,
  };
  saving.value = true;
  try {
    if (editId.value) await updateChannel(editId.value, payload);
    else await createChannel(payload);
    toast('已保存', 'success');
    setTimeout(() => uni.navigateBack(), 500);
  } catch (e) {
    toast((e as Error).message);
  } finally {
    saving.value = false;
  }
}

onLoad(async (q) => {
  if (q?.id) {
    editId.value = Number(q.id);
    try {
      const c = await getChannel(editId.value);
      name.value = c.name;
      type.value = c.type;
      enabled.value = c.is_enabled;
      // 后端对密钥字段返回掩码；原样载入，未改动则提交时后端保留旧值
      const cfg: Record<string, string> = {};
      Object.entries(c.config || {}).forEach(([k, v]) => {
        cfg[k] = Array.isArray(v) ? v.join(',') : String(v ?? '');
      });
      config.value = cfg;
    } catch (e) {
      toast((e as Error).message);
    }
  }
});
</script>

<template>
  <view class="page">
    <view class="card">
      <view class="field">
        <text class="label">名称</text>
        <input v-model="name" class="input" placeholder="如 管理员邮箱" placeholder-class="ph" />
      </view>
      <view class="field">
        <text class="label">类型</text>
        <view class="picker" :class="{ disabled: !!editId }" @tap="chooseType">
          {{ typeLabel }}<text v-if="!editId"> ›</text>
        </view>
      </view>
    </view>

    <view class="card">
      <text class="ct">{{ typeLabel }} 配置</text>
      <view v-for="fld in typeDef.fields" :key="fld.key" class="field">
        <text class="label">{{ fld.label }}</text>
        <input
          v-model="config[fld.key]"
          class="input"
          :password="!!fld.secret"
          :placeholder="fld.placeholder || ''"
          placeholder-class="ph"
        />
      </view>
      <text v-if="editId" class="hint">密钥类字段留空或保持掩码即保留原值。</text>
    </view>

    <view class="card">
      <view class="field row-between">
        <text class="label nomb">启用</text>
        <switch :checked="enabled" color="#2b6fe3" @change="enabled = $event.detail.value" />
      </view>
    </view>

    <button class="btn btn-primary" :loading="saving" @tap="save">保存</button>
  </view>
</template>

<style lang="scss" scoped>
.page { padding: 24rpx 32rpx 60rpx; }
.card { background: $hg-card; border-radius: $hg-radius; padding: 8rpx 28rpx; box-shadow: $hg-shadow; margin-bottom: 20rpx; }
.ct { display: block; font-size: 26rpx; font-weight: 600; color: $hg-fg; padding: 22rpx 0 4rpx; }
.field { padding: 24rpx 0; border-bottom: 1rpx solid $hg-line; }
.field:last-child { border-bottom: none; }
.row-between { display: flex; align-items: center; justify-content: space-between; }
.label { display: block; font-size: 24rpx; color: $hg-muted; margin-bottom: 12rpx; }
.label.nomb { margin-bottom: 0; }
.input { font-size: 30rpx; color: $hg-fg; }
.ph { color: #b6bccb; }
.picker { font-size: 30rpx; color: $hg-fg; }
.picker.disabled { color: $hg-muted; }
.hint { display: block; font-size: 22rpx; color: $hg-muted; padding: 4rpx 0 18rpx; }
.btn { border-radius: $hg-radius-s; height: 90rpx; line-height: 90rpx; font-size: 32rpx; }
.btn-primary { background: $hg-accent; color: #fff; }
</style>
