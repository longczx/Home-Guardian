import { defineConfig } from 'vite';
import uni from '@dcloudio/vite-plugin-uni';

// uni-app 官方 vite 插件；各端由 UNI_PLATFORM 环境变量驱动，无需在此区分。
export default defineConfig({
  plugins: [uni()],
});
