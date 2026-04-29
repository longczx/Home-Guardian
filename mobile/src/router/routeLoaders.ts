export const loadAuthenticatedApp = () => import('@/layouts/AuthenticatedApp');
export const loadMainLayout = () => import('@/layouts/MainLayout');
export const loadLoginPage = () => import('@/pages/Login');
export const loadHomePage = () => import('@/pages/HomePage');
export const loadDeviceListPage = () => import('@/pages/DeviceListPage');
export const loadDeviceDetailPage = () => import('@/pages/DeviceDetailPage');
export const loadDeviceTelemetryPage = () => import('@/pages/DeviceTelemetryPage');
export const loadTelemetryViewPage = () => import('@/pages/TelemetryView');
export const loadAlertListPage = () => import('@/pages/AlertListPage');
export const loadAlertDetailPage = () => import('@/pages/AlertDetailPage');
export const loadAlertRuleListPage = () => import('@/pages/AlertRuleListPage');
export const loadAlertRuleFormPage = () => import('@/pages/AlertRuleFormPage');
export const loadAutomationListPage = () => import('@/pages/AutomationListPage');
export const loadAutomationFormPage = () => import('@/pages/AutomationFormPage');
export const loadCommandHistoryPage = () => import('@/pages/CommandHistoryPage');
export const loadNotificationChannelPage = () => import('@/pages/NotificationChannelPage');
export const loadNotificationChannelFormPage = () => import('@/pages/NotificationChannelFormPage');
export const loadProfilePage = () => import('@/pages/ProfilePage');
export const loadChangePasswordPage = () => import('@/pages/ChangePasswordPage');
export const loadAboutPage = () => import('@/pages/AboutPage');

let telemetryPrefetchPromise: Promise<unknown> | null = null;

export function preloadTelemetryRoutes() {
  if (!telemetryPrefetchPromise) {
    telemetryPrefetchPromise = Promise.all([
      loadTelemetryViewPage(),
      loadDeviceTelemetryPage(),
    ]);
  }

  return telemetryPrefetchPromise;
}