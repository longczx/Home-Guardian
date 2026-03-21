import { useState, useEffect, useCallback, useMemo } from 'react';
import { NavBar, Card, Switch, Slider, Button, Toast, Grid, PullToRefresh, Dialog, Input, List } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getDevice, sendCommand, getDeviceAttributes, setDeviceAttributes, type Device, type DeviceAttribute } from '@/api/device';
import { getLatestTelemetry, type LatestMetric } from '@/api/telemetry';
import { useWSSubscription } from '@/hooks/useWSSubscription';
import { useMetricDefinitionStore } from '@/stores/metricDefinitionStore';
import { buildMetricLookup } from '@/utils/metricLookup';
import StatusTag from '@/components/StatusTag';
import DeviceIcon from '@/components/DeviceIcon';
import MetricCard from '@/components/MetricCard';
import PageLoading from '@/components/PageLoading';

export default function DeviceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [device, setDevice] = useState<Device | null>(null);
  const [metrics, setMetrics] = useState<LatestMetric[]>([]);
  const [attributes, setAttributes] = useState<DeviceAttribute[]>([]);
  const [loading, setLoading] = useState(true);
  const [switchOn, setSwitchOn] = useState(false);
  const [brightness, setBrightness] = useState(50);

  const { definitions, fetchDefinitions } = useMetricDefinitionStore();

  const deviceId = id ? parseInt(id) : 0;

  const fetchData = useCallback(async () => {
    if (!deviceId) return;
    try {
      const [deviceRes, telemetryRes, attrRes] = await Promise.all([
        getDevice(deviceId),
        getLatestTelemetry(deviceId),
        getDeviceAttributes(deviceId),
      ]);
      if (deviceRes.data.code === 0) setDevice(deviceRes.data.data);
      if (telemetryRes.data.code === 0) setMetrics(telemetryRes.data.data);
      if (attrRes.data.code === 0) setAttributes(attrRes.data.data);
    } finally {
      setLoading(false);
    }
  }, [deviceId]);

  useEffect(() => { fetchDefinitions(); }, [fetchDefinitions]);
  useEffect(() => { fetchData(); }, [fetchData]);

  const metricLookup = useMemo(
    () => buildMetricLookup(device?.metric_fields, definitions),
    [device?.metric_fields, definitions],
  );

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

  const handleEditAttr = (attr: DeviceAttribute) => {
    let newValue = String(attr.value ?? '');
    Dialog.confirm({
      title: `编辑属性: ${attr.key}`,
      content: (
        <Input
          defaultValue={newValue}
          onChange={(v) => { newValue = v; }}
          placeholder="属性值"
        />
      ),
      onConfirm: async () => {
        try {
          await setDeviceAttributes(deviceId, { [attr.key]: newValue });
          setAttributes((prev) => prev.map((a) => (a.key === attr.key ? { ...a, value: newValue } : a)));
          Toast.show({ content: '已更新', icon: 'success' });
        } catch {
          Toast.show({ content: '更新失败', icon: 'fail' });
        }
      },
    });
  };

  const handleAddAttr = () => {
    let newKey = '';
    let newValue = '';
    Dialog.confirm({
      title: '新增属性',
      content: (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          <Input onChange={(v) => { newKey = v; }} placeholder="属性名 (key)" />
          <Input onChange={(v) => { newValue = v; }} placeholder="属性值 (value)" />
        </div>
      ),
      onConfirm: async () => {
        if (!newKey) {
          Toast.show({ content: '属性名不能为空', icon: 'fail' });
          return;
        }
        try {
          await setDeviceAttributes(deviceId, { [newKey]: newValue });
          setAttributes((prev) => [...prev, { key: newKey, value: newValue }]);
          Toast.show({ content: '已添加', icon: 'success' });
        } catch {
          Toast.show({ content: '添加失败', icon: 'fail' });
        }
      },
    });
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
                {metrics.map((m) => {
                  const meta = metricLookup(m.metric_key);
                  return (
                    <Grid.Item key={m.metric_key}>
                      <MetricCard
                        icon={<span>{meta.icon}</span>}
                        label={meta.label}
                        value={getMetricValue(m)}
                        unit={meta.unit}
                      />
                    </Grid.Item>
                  );
                })}
              </Grid>
            </>
          )}

          {/* Device Attributes */}
          <Card
            style={{ borderRadius: 'var(--card-radius)', boxShadow: 'var(--card-shadow)', background: 'var(--color-bg-card)', marginBottom: 12 }}
            bodyStyle={{ padding: 16 }}
          >
            <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)', marginBottom: 12 }}>
              设备属性
            </div>
            {attributes.length > 0 ? (
              <List style={{ '--border-top': 'none', '--border-bottom': 'none' } as React.CSSProperties}>
                {attributes.map((attr) => (
                  <List.Item
                    key={attr.key}
                    onClick={() => handleEditAttr(attr)}
                    extra={<span style={{ color: 'var(--color-text)', fontSize: 14 }}>{String(attr.value ?? '--')}</span>}
                    style={{ background: 'transparent' }}
                  >
                    <span style={{ color: 'var(--color-text-secondary)', fontSize: 14 }}>{attr.key}</span>
                  </List.Item>
                ))}
              </List>
            ) : (
              <div style={{ color: 'var(--color-text-tertiary)', fontSize: 13, textAlign: 'center', padding: '8px 0' }}>
                暂无属性
              </div>
            )}
            <Button
              block
              size="small"
              fill="outline"
              color="primary"
              onClick={handleAddAttr}
              style={{ borderRadius: 6, marginTop: 8 }}
            >
              新增属性
            </Button>
          </Card>

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
