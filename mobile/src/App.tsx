import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import PageLoading from '@/components/PageLoading';

const MainLayout = lazy(() => import('@/layouts/MainLayout'));
const Login = lazy(() => import('@/pages/Login'));
const HomePage = lazy(() => import('@/pages/HomePage'));
const DeviceListPage = lazy(() => import('@/pages/DeviceListPage'));
const DeviceDetailPage = lazy(() => import('@/pages/DeviceDetailPage'));
const DeviceTelemetryPage = lazy(() => import('@/pages/DeviceTelemetryPage'));
const TelemetryView = lazy(() => import('@/pages/TelemetryView'));
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
const ChangePasswordPage = lazy(() => import('@/pages/ChangePasswordPage'));
const AboutPage = lazy(() => import('@/pages/AboutPage'));

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const token = useAuthStore((s) => s.accessToken);
  if (!token) {
    return <Navigate to="/mobile/login" replace />;
  }
  return <>{children}</>;
}

function SuspenseWrap({ children }: { children: React.ReactNode }) {
  return <Suspense fallback={<PageLoading />}>{children}</Suspense>;
}

export default function App() {
  return (
    <BrowserRouter>
      <SuspenseWrap>
        <Routes>
          <Route path="/mobile/login" element={<Login />} />

          {/* TabBar pages */}
          <Route
            element={
              <ProtectedRoute>
                <MainLayout />
              </ProtectedRoute>
            }
          >
            <Route path="/mobile" element={<HomePage />} />
            <Route path="/mobile/devices" element={<DeviceListPage />} />
            <Route path="/mobile/alerts" element={<AlertListPage />} />
            <Route path="/mobile/automations" element={<AutomationListPage />} />
            <Route path="/mobile/profile" element={<ProfilePage />} />
          </Route>

          {/* Sub-pages (no TabBar) */}
          <Route
            path="/mobile/device/:id"
            element={<ProtectedRoute><DeviceDetailPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/device/:id/telemetry"
            element={<ProtectedRoute><DeviceTelemetryPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/telemetry"
            element={<ProtectedRoute><TelemetryView /></ProtectedRoute>}
          />
          <Route
            path="/mobile/alerts/:id"
            element={<ProtectedRoute><AlertDetailPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/alert-rules"
            element={<ProtectedRoute><AlertRuleListPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/alert-rules/create"
            element={<ProtectedRoute><AlertRuleFormPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/alert-rules/:id/edit"
            element={<ProtectedRoute><AlertRuleFormPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/automations/create"
            element={<ProtectedRoute><AutomationFormPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/automations/:id/edit"
            element={<ProtectedRoute><AutomationFormPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/commands"
            element={<ProtectedRoute><CommandHistoryPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/notification-channels"
            element={<ProtectedRoute><NotificationChannelPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/notification-channels/create"
            element={<ProtectedRoute><NotificationChannelFormPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/notification-channels/:id/edit"
            element={<ProtectedRoute><NotificationChannelFormPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/profile/password"
            element={<ProtectedRoute><ChangePasswordPage /></ProtectedRoute>}
          />
          <Route
            path="/mobile/profile/about"
            element={<ProtectedRoute><AboutPage /></ProtectedRoute>}
          />

          <Route path="*" element={<Navigate to="/mobile" replace />} />
        </Routes>
      </SuspenseWrap>
    </BrowserRouter>
  );
}
