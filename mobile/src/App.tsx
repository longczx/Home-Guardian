import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import Login from '@/pages/Login';
import Home from '@/pages/Home';
import DeviceControl from '@/pages/DeviceControl';
import TelemetryView from '@/pages/TelemetryView';
import AlertList from '@/pages/AlertList';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const token = useAuthStore((s) => s.accessToken);
  if (!token) {
    return <Navigate to="/mobile/login" replace />;
  }
  return <>{children}</>;
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/mobile/login" element={<Login />} />
        <Route
          path="/mobile"
          element={
            <ProtectedRoute>
              <Home />
            </ProtectedRoute>
          }
        />
        <Route
          path="/mobile/device/:id"
          element={
            <ProtectedRoute>
              <DeviceControl />
            </ProtectedRoute>
          }
        />
        <Route
          path="/mobile/telemetry"
          element={
            <ProtectedRoute>
              <TelemetryView />
            </ProtectedRoute>
          }
        />
        <Route
          path="/mobile/alerts"
          element={
            <ProtectedRoute>
              <AlertList />
            </ProtectedRoute>
          }
        />
        <Route path="*" element={<Navigate to="/mobile" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
