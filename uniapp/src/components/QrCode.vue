<script setup lang="ts">
import { onMounted, watch, getCurrentInstance } from 'vue';
// uQRCode 仅提供 UMD 包；作为 ESM 打包时无 default 导出，
// 故以副作用方式引入（UMD 会把构造器挂到全局），再从全局取用。
import '@/utils/uqrcode/uqrcode.js';

interface UQRCodeInstance {
  data: string;
  size: number;
  make(): void;
  canvasContext: unknown;
  drawCanvas(): void;
}
const UQRCode = (globalThis as unknown as { UQRCode: new () => UQRCodeInstance }).UQRCode;

const props = withDefaults(defineProps<{ value: string; size?: number; canvasId?: string }>(), {
  size: 200,
  canvasId: 'qrcode-canvas',
});

const instance = getCurrentInstance();

function render() {
  if (!props.value || !UQRCode) return;
  const qr = new UQRCode();
  qr.data = props.value;
  qr.size = props.size;
  qr.make();
  qr.canvasContext = uni.createCanvasContext(props.canvasId, instance);
  qr.drawCanvas();
}

onMounted(() => setTimeout(render, 50));
watch(() => props.value, () => setTimeout(render, 50));
</script>

<template>
  <canvas
    :canvas-id="canvasId"
    :id="canvasId"
    :style="{ width: size + 'px', height: size + 'px' }"
  />
</template>
