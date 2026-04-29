import { useEffect, useMemo } from 'react';
import { NavBar, PullToRefresh } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import {
  AppstoreOutline,
  BellOutline,
  UnorderedListOutline,
  HistogramOutline,
  SetOutline,
  FileOutline,
} from 'antd-mobile-icons';
import { useDeviceStore } from '@/stores/deviceStore';
import { useAlertStore } from '@/stores/alertStore';
import { useAuthStore } from '@/stores/authStore';
import { useMetricDefinitionStore } from '@/stores/metricDefinitionStore';
import { buildMetricLookup } from '@/utils/metricLookup';
import StatCard from '@/components/StatCard';
import RoomCard from '@/components/RoomCard';
import { getGroupedAlertLogs, type GroupedAlert } from '@/api/telemetry';
import { useState, useCallback } from 'react';
import RelativeTime from '@/components/RelativeTime';
import { preloadTelemetryRoutes } from '@/router/routeLoaders';

export default function HomePage() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const { devices, metricsMap, fetchDevices } = useDeviceStore();
  const unreadCount = useAlertStore((s) => s.unreadCount);
  const { definitions, fetchDefinitions } = useMetricDefinitionStore();
  const [recentAlerts, setRecentAlerts] = useState<GroupedAlert[]>([]);

  const metricLookup = useMemo(
    () => buildMetricLookup(null, definitions),
    [definitions],
  );

  const fetchAll = useCallback(async () => {
    await fetchDevices();
    fetchDefinitions();
    try {
      const { data: res } = await getGroupedAlertLogs({});
      if (res.code === 0) setRecentAlerts(res.data.slice(0, 8));
    } catch { /* ignore */ }
  }, [fetchDevices]);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  const onlineCount = devices.filter((d) => d.is_online).length;

  const rooms = useMemo(() => {
    const map = new Map<string, { devices: typeof devices; metrics: typeof metricsMap[0] }>();
    devices.forEach((d) => {
      const loc = d.location || '未分配';
      if (!map.has(loc)) map.set(loc, { devices: [], metrics: [] });
      const room = map.get(loc)!;
      room.devices.push(d);
      const dm = metricsMap[d.id];
      if (dm && room.metrics.length === 0) room.metrics = dm;
    });
    return map;
  }, [devices, metricsMap]);

  const shortcuts = [
    { icon: <HistogramOutline />, label: '数据图表', path: '/mobile/telemetry', prefetch: preloadTelemetryRoutes },
    { icon: <BellOutline />, label: '告警规则', path: '/mobile/alert-rules' },
    { icon: <SetOutline />, label: '自动化', path: '/mobile/automations' },
    { icon: <FileOutline />, label: '命令历史', path: '/mobile/commands' },
    { icon: <UnorderedListOutline />, label: '通知渠道', path: '/mobile/notification-channels' },
    { icon: <AppstoreOutline />, label: '全部设备', path: '/mobile/devices' },
  ];

  const statusColors: Record<string, string> = {
    triggered: 'var(--color-danger)',
    acknowledged: 'var(--color-warning)',
    resolved: 'var(--color-success)',
  };

  return (
    <div className="mobile-page">
      <PullToRefresh onRefresh={fetchAll}>
        <div>
          <NavBar backIcon={false} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)', padding: 0 }}>
            Home Guardian
          </NavBar>

          <div className="page-hero" style={{ marginTop: 8 }}>
            <div className="page-hero__eyebrow">smart living</div>
            <div className="page-hero__title">你好，{user?.username || '访客'}</div>
            <div className="page-hero__subtitle">把家庭环境、设备状态和自动化流程收拢到一个移动控制台里。</div>
            <div className="page-hero__meta">
              <span className="soft-chip">在线设备 {onlineCount}/{devices.length}</span>
              <span className="soft-chip">待处理告警 {unreadCount}</span>
              <span className="soft-chip">房间 {rooms.size}</span>
            </div>
          </div>

          <div className="hero-stat-grid">
            <StatCard icon={<AppstoreOutline />} label="设备总数" value={devices.length} />
            <StatCard icon={<UnorderedListOutline />} label="在线" value={onlineCount} color="var(--color-success)" />
            <StatCard
              icon={<BellOutline />}
              label="待处理告警"
              value={unreadCount}
              color="var(--color-danger)"
              onClick={() => navigate('/mobile/alerts')}
            />
          </div>

          <div className="section-row">
            <span className="section-title">快捷入口</span>
          </div>
          <div className="glass-card glass-card--soft" style={{ padding: '12px' }}>
            <div className="shortcut-grid">
              {shortcuts.map((s) => (
                <div
                  key={s.path}
                  className="shortcut-tile"
                  onClick={() => navigate(s.path)}
                  onMouseEnter={() => s.prefetch?.()}
                  onTouchStart={() => s.prefetch?.()}
                >
                  <div className="shortcut-tile__icon">{s.icon}</div>
                  <div className="shortcut-tile__label">{s.label}</div>
                </div>
              ))}
            </div>
          </div>

          {rooms.size > 0 && (
            <>
              <div className="section-row">
                <span className="section-title">房间概览</span>
                <span className="section-link" onClick={() => navigate('/mobile/devices')}>查看设备</span>
              </div>
              {Array.from(rooms.entries()).map(([loc, data]) => (
                <RoomCard
                  key={loc}
                  location={loc}
                  deviceCount={data.devices.length}
                  onlineCount={data.devices.filter((d) => d.is_online).length}
                  metrics={data.metrics}
                  metricLookup={metricLookup}
                  onClick={() => navigate(`/mobile/devices?location=${encodeURIComponent(loc)}`)}
                />
              ))}
            </>
          )}

          {recentAlerts.length > 0 && (
            <>
              <div className="section-row">
                <span className="section-title">最近告警</span>
                <span className="section-link" onClick={() => navigate('/mobile/alerts')}>查看全部</span>
              </div>
              <div className="glass-card glass-card--soft" style={{ overflow: 'hidden' }}>
                {recentAlerts.map((a) => (
                  <div
                    key={`${a.rule_id}-${a.device_id}-${a.status}`}
                    onClick={() => navigate('/mobile/alerts')}
                    style={{
                      padding: '12px 16px',
                      borderBottom: '1px solid var(--color-border)',
                      cursor: 'pointer',
                      display: 'flex', alignItems: 'center', gap: 10,
                    }}
                  >
                    <div style={{
                      width: 8, height: 8, borderRadius: '50%', flexShrink: 0,
                      background: statusColors[a.status] || 'var(--color-text-tertiary)',
                    }} />
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--color-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'flex', alignItems: 'center', gap: 6 }}>
                        {a.rule_name}
                        {a.alert_count > 1 && (
                          <span style={{ fontSize: 11, color: 'var(--color-primary)', fontWeight: 600 }}>×{a.alert_count}</span>
                        )}
                      </div>
                      <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>
                        {a.device_name} · {a.device_location}
                      </div>
                    </div>
                    <RelativeTime date={a.latest_triggered_at} />
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </PullToRefresh>
    </div>
  );
}
