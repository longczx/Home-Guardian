import React, { useEffect } from 'react';
import { BrowserRouter } from 'react-router-dom';
import AppLayout from '@/layouts/AppLayout';
import { ToastProvider } from '@/components/Toast';
import { initTheme } from '@/stores/themeStore';

initTheme();

export default function App() {
  useEffect(() => {
    initTheme();
  }, []);

  return (
    <BrowserRouter basename="/mobile-v2">
      <ToastProvider>
        <AppLayout />
      </ToastProvider>
    </BrowserRouter>
  );
}
