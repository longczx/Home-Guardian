import { useState, useEffect, useCallback } from 'react';
import { NavBar, Card, Switch, Slider, Button, Toast, Grid, PullToRefresh } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getDevice, sendCommand, type Device } from '@/api/device';
import { getLatestTelemetry, type LatestMetric } from '@/api/telemetry';
import { useWSSubscription } from '@/hooks/useWSSubscription';
import StatusTag from '@/components/StatusTag';
import DeviceIcon from '@/components/DeviceIcon';
import MetricCard from '@/components/MetricCard';
import PageLoading from '@/components/PageLoading';

const METRIC_LABELS: Record<string, string> = {
  temperature: '温度', humidity: '湿度', pressure: '气压',
  co2: 'CO₂', light: '光照', pm25: 'PM2.5',
};
const METRIC_UNITS: Record<string, string> = {
  temperature: '°C', humidity: '%', pressure: 'hPa',
  co2: 'ppm', light: 'lux', pm25: 'μg/m³',
};
const METRIC_ICONS: Record<string, string> = {
  temperature: '🌡', humidity: '💧', pressure: '🌀',
  co2: '☁️', light: '☀️', pm25: '🌫',
};

export default function DeviceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [device, setDevice] = useState<Device | null>(null);
  const [metrics, setMetrics] = useState<LatestMetric[]>([]);
  const [loading, setLoading] = useState(true);
  const [switchOn, setSwitchOn] = useState(false);
  const [brightness, setBrightness] = useState(50);

  const deviceId = id ? parseInt(id) : 0;

  const fetchData = useCallback(async () => {
    if (!deviceId) return;
    try {
      const [deviceRes, telemetryRes] = await Promise.all([
        getDevice(deviceId),
        getLatestTelemetry(deviceId),
      ]);
      if (deviceRes.data.code === 0) setDevice(deviceRes.data.data);
      if (telemetryRes.data.code === 0) setMetrics(telemetryRes.data.data);
    } finally {
      setLoading(false);
    }
  }, [deviceId]);

  useEffect(() => { fetchData(); }, [fetchData]);

  // Real-time telemetry update
  const handleWS = useCallback((msg: { type: string; data: unknown }) => {
    const d = msg.data as { device_id: number; metric_key: string; value: unknown; ts: string };
    if (d.device_id === deviceId) {
      setMetrics((prev) => {
        const idx = prev.findIndex((m) => m.metric_key === d.metric_key);
        if (idx >= 0) return prev.map((m, i) => (i === idx ? { ...m, value: d.value, ts: d.ts } : m));
        return [...prev, { metric_key: d.metric_key, value: d.value, ts: d.ts }];
      });
    }
  }, [deviceId]);
  useWSSubscription('telemetry', handleWS);

  const handleCommand = async (action: string, params: Record<string, unknown> = {}) => {
    if (!deviceId) return;
    try {
      const { data: res } = await sendCommand(deviceId, { action, params });
      if (res.code === 0) Toast.show({ content: '指令已发送', icon: 'success' });
    } catch {
      Toast.show({ content: '发送失败', icon: 'fail' });
    }
  };

  if (loading) return <PageLoading />;

  if (!device) {
    return (
      <div>
        <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)' }}>设备详情</NavBar>
        <div style={{ textAlign: 'center', padding: 40, color: 'var(--color-text-tertiary)' }}>设备不存在</div>
      </div>
    );
  }

  const getMetricValue = (m: LatestMetric): string => {
    if (typeof m.value === 'number') return m.value.toFixed(1);
    return String(m.value ?? '--');
  };

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar
        onBack={() => navigate(-1)}
        right={
          <span onClick={() => navigate(`/mobile/device/${device.id}/telemetry`)} style={{ fontSize: 14, color: 'var(--color-primary)' }}>
            图表
          </span>
        }
        style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}
      >
        {device.name}
      </NavBar>

      <PullToRefresh onRefresh={fetchData}>
        <div style={{ padding: '12px 16px' }}>
          {/* Device Info */}
          <Card style={{ borderRadius: 'var(--card-radius)', boxShadow: 'var(--card-shadow)', background: 'var(--color-bg-card)', marginBottom: 12 }} bodyStyle={{ padding: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
              <div style={{
                width: 48, height: 48, borderRadius: 12,
                background: 'var(--color-primary-light)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>
                <DeviceIcon type={device.type} size={24} />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 18, fontWeight: 600, color: 'var(--color-text)' }}>{device.name}</div>
                <div style={{ fontSize: 13, color: 'var(--color-text-tertiary)', marginTop: 2 }}>
                  {device.location || '未分配位置'} · {device.type}
                </div>
              </div>
              <StatusTag online={device.is_online} />
            </div>
            <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 12, fontFamily: 'monospace' }}>
              UID: {device.device_uid}
            </div>
          </Card>

          {/* Metrics Grid */}
          {metrics.length > 0 && (
            <>
              <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)', marginBottom: 10, marginTop: 4 }}>
                实时数据
              </div>
              <Grid columns={2} gap={10} style={{ marginBottom: 12 }}>
                {metrics.map((m) => (
                  <Grid.Item key={m.metric_key}>
                    <MetricCard
                      icon={<span>{METRIC_ICONS[m.metric_key] || '📊'}</span>}
                      label={METRIC_LABELS[m.metric_key] || m.metric_key}
                      value={getMetricValue(m)}
                      unit={METRIC_UNITS[m.metric_key]}
                    />
                  </Grid.Item>
                ))}
              </Grid>
            </>
          )}

          {/* Control Panel */}
          {device.type === 'actuator' && (
            <Card
              style={{ borderRadius: 'var(--card-radius)', boxShadow: 'var(--card-shadow)', background: 'var(--color-bg-card)' }}
              bodyStyle={{ padding: 16 }}
            >
              <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)', marginBottom: 16 }}>
                设备控制
              </div>
              <div style={{ marginBottom: 20, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ color: 'var(--color-text)' }}>电源开关</span>
                <Switch
                  checked={switchOn}
                  onChange={(checked) => {
                    setSwitchOn(checked);
                    handleCommand(checked ? 'turn_on' : 'turn_off');
                  }}
                />
              </div>
              <div style={{ marginBottom: 20 }}>
                <div style={{ marginBottom: 8, color: 'var(--color-text)' }}>亮度: {brightness}%</div>
                <Slider
                  value={brightness}
                  onChange={(v) => {
                    const val = Array.isArray(v) ? v[0] : v;
                    setBrightness(val);
                    handleCommand('set_brightness', { value: val });
                  }}
                />
              </div>
              <Button
                block
                color="primary"
                onClick={() => handleCommand('refresh_state')}
                style={{ borderRadius: 8 }}
              >
                刷新状态
              </Button>
            </Card>
          )}
        </div>
      </PullToRefresh>
    </div>
  );
}
