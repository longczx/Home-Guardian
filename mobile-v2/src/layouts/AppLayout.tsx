import React, { Suspense, lazy } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import TabBar from './TabBar';
import { useWebSocket } from '@/hooks/useWebSocket';
import { PageLoader } from '@/components/ui';

const LoginPage = lazy(() => import('@/pages/LoginPage'));
const HomePage = lazy(() => import('@/pages/HomePage'));
const DeviceListPage = lazy(() => import('@/pages/DeviceListPage'));
const DeviceDetailPage = lazy(() => import('@/pages/DeviceDetailPage'));
const DeviceTelemetryPage = lazy(() => import('@/pages/DeviceTelemetryPage'));
const GlobalTelemetryPage = lazy(() => import('@/pages/GlobalTelemetryPage'));
const AlertListPage = lazy(() => import('@/pages/AlertListPage'));
const AlertDetailPage = lazy(() => import('@/pages/AlertDetailPage'));
const AlertRuleListPage = lazy(() => import('@/pages/AlertRuleListPage'));
const AlertRuleFormPage = lazy(() => import('@/pages/AlertRuleFormPage'));
const AutomationListPage = lazy(() => import('@/pages/AutomationListPage'));
const AutomationFormPage = lazy(() => import('@/pages/AutomationFormPage'));
const CommandHistoryPage = lazy(() => import('@/pages/CommandHistoryPage'));
const NotificationChannelPage = lazy(() => import('@/pages/NotificationChannelPage'));
const NotificationChannelFormPage = lazy(() => import('@/pages/NotificationChannelFormPage'));
const ProfilePage = lazy(() => import('@/pages/ProfilePage'));
const ThemeCenterPage = lazy(() => import('@/pages/ThemeCenterPage'));
const ChangePasswordPage = lazy(() => import('@/pages/ChangePasswordPage'));
const AboutPage = lazy(() => import('@/pages/AboutPage'));

const TAB_ROUTES = ['/', '/devices', '/alerts', '/automations', '/profile'];

function AuthenticatedApp() {
  useWebSocket();
  const location = useLocation();
  const showTabBar = TAB_ROUTES.includes(location.pathname);

  return (
    <div className="flex flex-col h-full overflow-hidden">
      <div className="flex-1 overflow-hidden">
        <Suspense fallback={<PageLoader />}>
          <Routes>
            <Route path="/" element={<HomePage />} />
            <Route path="/devices" element={<DeviceListPage />} />
            <Route path="/devices/:id" element={<DeviceDetailPage />} />
            <Route path="/devices/:id/telemetry" element={<DeviceTelemetryPage />} />
            <Route path="/telemetry" element={<GlobalTelemetryPage />} />
            <Route path="/alerts" element={<AlertListPage />} />
            <Route path="/alerts/:id" element={<AlertDetailPage />} />
            <Route path="/alert-rules" element={<AlertRuleListPage />} />
            <Route path="/alert-rules/create" element={<AlertRuleFormPage />} />
            <Route path="/alert-rules/:id/edit" element={<AlertRuleFormPage />} />
            <Route path="/automations" element={<AutomationListPage />} />
            <Route path="/automations/create" element={<AutomationFormPage />} />
            <Route path="/automations/:id/edit" element={<AutomationFormPage />} />
            <Route path="/commands" element={<CommandHistoryPage />} />
            <Route path="/notification-channels" element={<NotificationChannelPage />} />
            <Route path="/notification-channels/create" element={<NotificationChannelFormPage />} />
            <Route path="/notification-channels/:id/edit" element={<NotificationChannelFormPage />} />
            <Route path="/profile" element={<ProfilePage />} />
            <Route path="/profile/theme" element={<ThemeCenterPage />} />
            <Route path="/profile/password" element={<ChangePasswordPage />} />
            <Route path="/profile/about" element={<AboutPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </div>
      {showTabBar && <TabBar />}
    </div>
  );
}

function RequireAuth({ children }: { children: React.ReactNode }) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

export default function AppLayout() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  return (
    <Suspense fallback={<PageLoader />}>
      <Routes>
        <Route path="/login" element={isAuthenticated ? <Navigate to="/" replace /> : <LoginPage />} />
        <Route path="/*" element={
          <RequireAuth>
            <AuthenticatedApp />
          </RequireAuth>
        } />
      </Routes>
    </Suspense>
  );
}
