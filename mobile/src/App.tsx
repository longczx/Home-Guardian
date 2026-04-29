import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import PageLoading from '@/components/PageLoading';
import TelemetryPageSkeleton from '@/components/TelemetryPageSkeleton';
import {
  loadAboutPage,
  loadAlertDetailPage,
  loadAlertListPage,
  loadAlertRuleFormPage,
  loadAlertRuleListPage,
  loadAuthenticatedApp,
  loadAutomationFormPage,
  loadAutomationListPage,
  loadChangePasswordPage,
  loadCommandHistoryPage,
  loadDeviceDetailPage,
  loadDeviceListPage,
  loadDeviceTelemetryPage,
  loadHomePage,
  loadLoginPage,
  loadMainLayout,
  loadNotificationChannelFormPage,
  loadNotificationChannelPage,
  loadProfilePage,
  loadTelemetryViewPage,
} from '@/router/routeLoaders';

const AuthenticatedApp = lazy(loadAuthenticatedApp);
const MainLayout = lazy(loadMainLayout);
const Login = lazy(loadLoginPage);
const HomePage = lazy(loadHomePage);
const DeviceListPage = lazy(loadDeviceListPage);
const DeviceDetailPage = lazy(loadDeviceDetailPage);
const DeviceTelemetryPage = lazy(loadDeviceTelemetryPage);
const TelemetryView = lazy(loadTelemetryViewPage);
const AlertListPage = lazy(loadAlertListPage);
const AlertDetailPage = lazy(loadAlertDetailPage);
const AlertRuleListPage = lazy(loadAlertRuleListPage);
const AlertRuleFormPage = lazy(loadAlertRuleFormPage);
const AutomationListPage = lazy(loadAutomationListPage);
const AutomationFormPage = lazy(loadAutomationFormPage);
const CommandHistoryPage = lazy(loadCommandHistoryPage);
const NotificationChannelPage = lazy(loadNotificationChannelPage);
const NotificationChannelFormPage = lazy(loadNotificationChannelFormPage);
const ProfilePage = lazy(loadProfilePage);
const ChangePasswordPage = lazy(loadChangePasswordPage);
const AboutPage = lazy(loadAboutPage);

function SuspenseWrap({ children, fallback = <PageLoading /> }: { children: React.ReactNode; fallback?: React.ReactNode }) {
  return <Suspense fallback={fallback}>{children}</Suspense>;
}

export default function App() {
  return (
    <BrowserRouter>
      <SuspenseWrap>
        <Routes>
          <Route path="/mobile/login" element={<Login />} />

          <Route element={<AuthenticatedApp />}>
            <Route element={<MainLayout />}>
              <Route path="/mobile" element={<HomePage />} />
              <Route path="/mobile/devices" element={<DeviceListPage />} />
              <Route path="/mobile/alerts" element={<AlertListPage />} />
              <Route path="/mobile/automations" element={<AutomationListPage />} />
              <Route path="/mobile/profile" element={<ProfilePage />} />
            </Route>

            <Route path="/mobile/device/:id" element={<DeviceDetailPage />} />
            <Route path="/mobile/device/:id/telemetry" element={<SuspenseWrap fallback={<TelemetryPageSkeleton />}><DeviceTelemetryPage /></SuspenseWrap>} />
            <Route path="/mobile/telemetry" element={<SuspenseWrap fallback={<TelemetryPageSkeleton />}><TelemetryView /></SuspenseWrap>} />
            <Route path="/mobile/alerts/:id" element={<AlertDetailPage />} />
            <Route path="/mobile/alert-rules" element={<AlertRuleListPage />} />
            <Route path="/mobile/alert-rules/create" element={<AlertRuleFormPage />} />
            <Route path="/mobile/alert-rules/:id/edit" element={<AlertRuleFormPage />} />
            <Route path="/mobile/automations/create" element={<AutomationFormPage />} />
            <Route path="/mobile/automations/:id/edit" element={<AutomationFormPage />} />
            <Route path="/mobile/commands" element={<CommandHistoryPage />} />
            <Route path="/mobile/notification-channels" element={<NotificationChannelPage />} />
            <Route path="/mobile/notification-channels/create" element={<NotificationChannelFormPage />} />
            <Route path="/mobile/notification-channels/:id/edit" element={<NotificationChannelFormPage />} />
            <Route path="/mobile/profile/password" element={<ChangePasswordPage />} />
            <Route path="/mobile/profile/about" element={<AboutPage />} />
          </Route>

          <Route path="*" element={<Navigate to="/mobile" replace />} />
        </Routes>
      </SuspenseWrap>
    </BrowserRouter>
  );
}
