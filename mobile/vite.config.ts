import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';
import path from 'path';

function manualChunks(id: string) {
  if (!id.includes('node_modules')) {
    return undefined;
  }

  const normalizedId = id.replace(/\\/g, '/');

  if (
    normalizedId.includes('/react-dom/') ||
    normalizedId.includes('/react/') ||
    normalizedId.includes('/react-router/') ||
    normalizedId.includes('/react-router-dom/') ||
    normalizedId.includes('/scheduler/') ||
    normalizedId.includes('/@remix-run/router/')
  ) {
    return 'react-vendor';
  }

  if (normalizedId.includes('/antd-mobile/') || normalizedId.includes('/antd-mobile-icons/') || normalizedId.includes('/@react-spring/')) {
    return 'ui-vendor';
  }

  if (normalizedId.includes('/echarts/') || normalizedId.includes('/zrender/') || normalizedId.includes('/echarts-for-react/')) {
    return 'charts-vendor';
  }

  if (normalizedId.includes('/axios/')) {
    return 'network-vendor';
  }

  if (normalizedId.includes('/zustand/')) {
    return 'state-vendor';
  }

  return undefined;
}

export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'Home Guardian',
        short_name: 'HomeGuard',
        description: '智能家居控制面板',
        theme_color: '#1677ff',
        background_color: '#f5f5f5',
        display: 'standalone',
        start_url: '/mobile/',
        scope: '/mobile/',
        icons: [
          {
            src: '/favicon.ico',
            sizes: '64x64',
            type: 'image/x-icon',
          },
        ],
      },
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  base: '/mobile/',
  build: {
    outDir: '../public/mobile',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        manualChunks,
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8787',
        changeOrigin: true,
      },
      '/ws': {
        target: 'ws://localhost:8788',
        ws: true,
      },
    },
  },
});
