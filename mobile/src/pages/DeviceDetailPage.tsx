import { useState, useEffect, useCallback, useMemo } from 'react';
import { NavBar, Switch, Slider, Button, Toast, PullToRefresh } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getDevice, sendCommand, getDeviceAttributes, setDeviceAttributes, type Device, type DeviceAttribute } from '@/api/device';
import { getLatestTelemetry, type LatestMetric } from '@/api/telemetry';
import { useWSSubscription } from '@/hooks/useWSSubscription';
import { useMetricDefinitionStore } from '@/stores/metricDefinitionStore';
import { buildMetricLookup } from '@/utils/metricLookup';
import { normalizeBackendTimestamp } from '@/utils/dateTime';
import StatusTag from '@/components/StatusTag';
import DeviceIcon from '@/components/DeviceIcon';
import MetricCard from '@/components/MetricCard';
import PageLoading from '@/components/PageLoading';
import { preloadTelemetryRoutes } from '@/router/routeLoaders';

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
  const handleWS = useCallback((msg: Record<string, unknown>) => {
    const msgDeviceId = msg.device_id as number;
    if (msgDeviceId !== deviceId) return;
    const data = msg.data as Record<string, unknown> | undefined;
    const ts = normalizeBackendTimestamp(msg.ts);
    if (!data || typeof data !== 'object') return;
    setMetrics((prev) => {
      const updated = [...prev];
      for (const [key, value] of Object.entries(data)) {
        const idx = updated.findIndex((m) => m.metric_key === key);
        if (idx >= 0) {
          updated[idx] = { ...updated[idx], value, ts: ts ?? updated[idx].ts };
        } else {
          updated.push({ metric_key: key, value, ts: ts ?? new Date().toISOString() });
        }
      }
      return updated;
    });
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

  const handleEditAttr = async (attr: DeviceAttribute) => {
    const newValue = window.prompt(`编辑属性: ${attr.key}`, String(attr.value ?? ''));
    if (newValue === null) return; // 用户取消
    try {
      await setDeviceAttributes(deviceId, { [attr.key]: newValue });
      setAttributes((prev) => prev.map((a) => (a.key === attr.key ? { ...a, value: newValue } : a)));
      Toast.show({ content: '已更新', icon: 'success' });
    } catch {
      Toast.show({ content: '更新失败', icon: 'fail' });
    }
  };

  const handleAddAttr = async () => {
    const newKey = window.prompt('请输入属性名 (key)');
    if (!newKey) return;
    const newValue = window.prompt(`请输入 "${newKey}" 的值`) ?? '';
    try {
      await setDeviceAttributes(deviceId, { [newKey]: newValue });
      setAttributes((prev) => [...prev, { key: newKey, value: newValue }]);
      Toast.show({ content: '已添加', icon: 'success' });
    } catch {
      Toast.show({ content: '添加失败', icon: 'fail' });
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
    <div className="mobile-page mobile-page--tight">
      <NavBar
        onBack={() => navigate(-1)}
        right={
          <span
            onClick={() => navigate(`/mobile/device/${device.id}/telemetry`)}
            onMouseEnter={() => preloadTelemetryRoutes()}
            onTouchStart={() => preloadTelemetryRoutes()}
            style={{ fontSize: 14, color: 'var(--color-primary)' }}
          >
            图表
          </span>
        }
        style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}
      >
        {device.name}
      </NavBar>

      <PullToRefresh onRefresh={fetchData}>
        <div>
          <div className="screen-header" style={{ marginTop: 8 }}>
            <div>
              <div className="screen-header__title">{device.name}</div>
              <div className="screen-header__subtitle">{device.location || '未分配位置'} · {device.type}</div>
            </div>
            <button
              className="ghost-icon-button"
              onClick={() => navigate(`/mobile/device/${device.id}/telemetry`)}
              onMouseEnter={() => preloadTelemetryRoutes()}
              onTouchStart={() => preloadTelemetryRoutes()}
            >
              图表
            </button>
          </div>

          <div className="detail-hero-panel detail-hero-panel--room">
            <div className="detail-hero-panel__main">
              <div className="detail-hero-panel__eyebrow">room context</div>
              <div className="detail-hero-panel__title">{device.location || '设备空间'}</div>
              <div className="detail-hero-panel__subtitle">从房间语义看设备状态、在线情况和控制动作。</div>
              <div className="page-hero__meta">
                <span className="soft-chip">{device.type}</span>
                <span className="soft-chip">{device.is_online ? '在线' : '离线'}</span>
                <span className="soft-chip">固件 {device.firmware_version || '未知'}</span>
              </div>
            </div>
            <div className="detail-hero-panel__aside">
              <div className="detail-kpi-card">
                <span>设备 UID</span>
                <strong>{device.device_uid}</strong>
              </div>
              <div className="detail-kpi-card">
                <span>最后状态</span>
                <strong>{device.is_online ? '正常连接' : '等待上报'}</strong>
                <small>{device.last_seen || '暂无上报时间'}</small>
              </div>
            </div>
          </div>

          <div className="surface-card detail-section-card" style={{ padding: 16, marginTop: 16, marginBottom: 12 }}>
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
            <div className="detail-grid" style={{ marginTop: 14 }}>
              <div className="detail-row">
                <span className="detail-row__label">类型</span>
                <span className="detail-row__value">{device.type}</span>
              </div>
              <div className="detail-row">
                <span className="detail-row__label">位置</span>
                <span className="detail-row__value">{device.location || '未分配位置'}</span>
              </div>
              <div className="detail-row">
                <span className="detail-row__label">固件版本</span>
                <span className="detail-row__value">{device.firmware_version || '未知'}</span>
              </div>
            </div>
          </div>

          {metrics.length > 0 && (
            <>
              <div className="section-row">
                <span className="section-title">实时数据</span>
                <span
                  className="section-link"
                  onClick={() => navigate(`/mobile/device/${device.id}/telemetry`)}
                  onMouseEnter={() => preloadTelemetryRoutes()}
                  onTouchStart={() => preloadTelemetryRoutes()}
                >
                  查看图表
                </span>
              </div>
              <div className="metric-grid metric-grid--spacious" style={{ marginBottom: 12 }}>
                {metrics.map((m) => {
                  const meta = metricLookup(m.metric_key);
                  return (
                    <MetricCard
                      key={m.metric_key}
                      icon={<span>{meta.icon}</span>}
                      label={meta.label}
                      value={getMetricValue(m)}
                      unit={meta.unit}
                    />
                  );
                })}
              </div>
            </>
          )}

          <div className="surface-card detail-section-card" style={{ padding: 16, marginBottom: 12 }}>
            <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)', marginBottom: 12 }}>
              设备属性
            </div>
            {attributes.length > 0 ? (
              <div className="inline-list">
                {attributes.map((attr) => (
                  <button
                    key={attr.key}
                    onClick={() => handleEditAttr(attr)}
                    className="inline-list__item"
                  >
                    <span style={{ color: 'var(--color-text-secondary)', fontSize: 14 }}>{attr.key}</span>
                    <span style={{ color: 'var(--color-text)', fontSize: 14 }}>{String(attr.value ?? '--')}</span>
                  </button>
                ))}
              </div>
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
              style={{ borderRadius: 14, marginTop: 10 }}
            >
              新增属性
            </Button>
          </div>

          {device.type === 'actuator' && (
            <div className="surface-card detail-section-card" style={{ padding: 16 }}>
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
            </div>
          )}
        </div>
      </PullToRefresh>
    </div>
  );
}
