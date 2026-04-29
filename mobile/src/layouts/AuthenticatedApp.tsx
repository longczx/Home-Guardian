import { useEffect } from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useGlobalWebSocket } from '@/hooks/useGlobalWebSocket';
import { preloadTelemetryRoutes } from '@/router/routeLoaders';

export default function AuthenticatedApp() {
  const token = useAuthStore((state) => state.accessToken);

  useGlobalWebSocket();

  useEffect(() => {
    if (!token) {
      return undefined;
    }

    const win = window as Window & {
      requestIdleCallback?: (callback: () => void) => number;
      cancelIdleCallback?: (id: number) => void;
    };

    if (win.requestIdleCallback) {
      const idleId = win.requestIdleCallback(() => {
        void preloadTelemetryRoutes();
      });

      return () => win.cancelIdleCallback?.(idleId);
    }

    const timer = window.setTimeout(() => {
      void preloadTelemetryRoutes();
    }, 800);

    return () => window.clearTimeout(timer);
  }, [token]);

  if (!token) {
    return <Navigate to="/mobile/login" replace />;
  }

  return <Outlet />;
}