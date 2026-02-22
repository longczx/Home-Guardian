import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';
import path from 'path';

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
