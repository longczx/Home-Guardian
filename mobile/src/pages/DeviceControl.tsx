import { useState, useEffect } from 'react';
import { NavBar, Card, Switch, Slider, Button, Toast, SpinLoading } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getDevice, sendCommand, type Device } from '@/api/device';
import { getLatestTelemetry, type LatestMetric } from '@/api/telemetry';
import StatusTag from '@/components/StatusTag';
import MetricDisplay from '@/components/MetricDisplay';

export default function DeviceControl() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [device, setDevice] = useState<Device | null>(null);
  const [metrics, setMetrics] = useState<LatestMetric[]>([]);
  const [loading, setLoading] = useState(true);
  const [switchOn, setSwitchOn] = useState(false);
  const [brightness, setBrightness] = useState(50);

  useEffect(() => {
    if (!id) return;
    const deviceId = parseInt(id);

    Promise.all([
      getDevice(deviceId),
      getLatestTelemetry(deviceId),
    ]).then(([deviceRes, telemetryRes]) => {
      if (deviceRes.data.code === 0) {
        setDevice(deviceRes.data.data);
      }
      if (telemetryRes.data.code === 0) {
        setMetrics(telemetryRes.data.data);
      }
    }).finally(() => setLoading(false));
  }, [id]);

  const handleCommand = async (action: string, params: Record<string, unknown> = {}) => {
    if (!id) return;
    try {
      const { data: res } = await sendCommand(parseInt(id), { action, params });
      if (res.code === 0) {
        Toast.show({ content: '指令已发送', icon: 'success' });
      }
    } catch {
      Toast.show({ content: '发送失败', icon: 'fail' });
    }
  };

  const handleSwitch = (checked: boolean) => {
    setSwitchOn(checked);
    handleCommand(checked ? 'turn_on' : 'turn_off');
  };

  const handleBrightness = (value: number | number[]) => {
    const val = Array.isArray(value) ? value[0] : value;
    setBrightness(val);
    handleCommand('set_brightness', { value: val });
  };

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '100vh' }}>
        <SpinLoading color="primary" />
      </div>
    );
  }

  if (!device) {
    return (
      <div>
        <NavBar onBack={() => navigate(-1)}>设备详情</NavBar>
        <div style={{ textAlign: 'center', padding: 40, color: '#999' }}>设备不存在</div>
      </div>
    );
  }

  const getMetricValue = (key: string): string => {
    const m = metrics.find((m) => m.metric_key === key);
    if (!m) return '--';
    const v = m.value;
    if (typeof v === 'number') return v.toFixed(1);
    if (Array.isArray(v)) return String(v[0] ?? '--');
    return String(v);
  };

  const getMetricUnit = (key: string): string => {
    const units: Record<string, string> = {
      temperature: '°C',
      humidity: '%',
      pressure: 'hPa',
      light: 'lux',
      co2: 'ppm',
    };
    return units[key] || '';
  };

  return (
    <div style={{ minHeight: '100vh', background: '#f5f5f5' }}>
      <NavBar onBack={() => navigate(-1)}>
        {device.name}
      </NavBar>

      {/* 设备信息 */}
      <Card style={{ margin: 12, borderRadius: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
          <div>
            <div style={{ fontSize: 18, fontWeight: 'bold' }}>{device.name}</div>
            <div style={{ fontSize: 13, color: '#999' }}>{device.location || '未分配位置'} · {device.type}</div>
          </div>
          <StatusTag online={device.is_online} />
        </div>
        <div style={{ fontSize: 12, color: '#999' }}>
          UID: <code>{device.device_uid}</code>
        </div>
      </Card>

      {/* 实时数据 */}
      {metrics.length > 0 && (
        <Card title="实时数据" style={{ margin: 12, borderRadius: 12 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(100px, 1fr))', gap: 8 }}>
            {metrics.map((m) => (
              <MetricDisplay
                key={m.metric_key}
                label={m.metric_key}
                value={getMetricValue(m.metric_key)}
                unit={getMetricUnit(m.metric_key)}
              />
            ))}
          </div>
        </Card>
      )}

      {/* 控制面板 */}
      {device.type === 'actuator' && (
        <Card title="设备控制" style={{ margin: 12, borderRadius: 12 }}>
          <div style={{ marginBottom: 20 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span>电源开关</span>
              <Switch checked={switchOn} onChange={handleSwitch} />
            </div>
          </div>

          <div style={{ marginBottom: 20 }}>
            <div style={{ marginBottom: 8 }}>亮度: {brightness}%</div>
            <Slider value={brightness} onChange={handleBrightness} />
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
  );
}
