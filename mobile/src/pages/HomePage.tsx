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
  RightOutline,
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

  const featuredMetrics = useMemo(() => {
    const allMetrics = Object.values(metricsMap).flat();
    const findMetric = (keywords: string[]) => allMetrics.find((item) => {
      const label = metricLookup(item.metric_key).label.toLowerCase();
      const key = item.metric_key.toLowerCase();
      return keywords.some((word) => label.includes(word) || key.includes(word));
    });

    return {
      temperature: findMetric(['温度', 'temp']),
      humidity: findMetric(['湿度', 'humidity']),
      air: findMetric(['空气', 'aqi', 'pm2.5', 'pm25', 'co2']),
    };
  }, [metricLookup, metricsMap]);

  const primaryRoom = Array.from(rooms.entries())[0];
  const quickScenes = [
    { label: '回家模式', icon: '🏠', action: () => navigate('/mobile/automations') },
    { label: '离家模式', icon: '🚪', action: () => navigate('/mobile/automations') },
    { label: '睡眠模式', icon: '🌙', action: () => navigate('/mobile/automations') },
    { label: '观影模式', icon: '🎬', action: () => navigate('/mobile/automations') },
  ];

  return (
    <div className="mobile-page">
      <PullToRefresh onRefresh={fetchAll}>
        <div>
          <NavBar backIcon={false} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)', padding: 0 }}>
            我的家
          </NavBar>

          <div className="screen-header" style={{ marginTop: 8 }}>
            <div>
              <div className="screen-header__title">{user?.username ? `${user.username} 的家` : '我的家'}</div>
              <div className="screen-header__subtitle">把环境、设备和自动化收进一个更轻盈的控制中心。</div>
            </div>
            <button className="ghost-icon-button" onClick={() => navigate('/mobile/profile')}>设置</button>
          </div>

          <div className="climate-hero">
            <div>
              <div className="climate-hero__temp">
                {featuredMetrics.temperature ? (typeof featuredMetrics.temperature.value === 'number' ? featuredMetrics.temperature.value.toFixed(1) : String(featuredMetrics.temperature.value ?? '--')) : '26'}
                <span>{featuredMetrics.temperature ? metricLookup(featuredMetrics.temperature.metric_key).unit || '°C' : '°C'}</span>
              </div>
              <div className="climate-hero__weather">多云</div>
            </div>
            <div className="climate-hero__stats">
              <div>
                <span>室内温度</span>
                <strong>{featuredMetrics.temperature ? `${featuredMetrics.temperature.value}${metricLookup(featuredMetrics.temperature.metric_key).unit || ''}` : '--'}</strong>
              </div>
              <div>
                <span>室内湿度</span>
                <strong>{featuredMetrics.humidity ? `${featuredMetrics.humidity.value}${metricLookup(featuredMetrics.humidity.metric_key).unit || ''}` : '--'}</strong>
              </div>
              <div>
                <span>空气质量</span>
                <strong>{featuredMetrics.air ? `${featuredMetrics.air.value}${metricLookup(featuredMetrics.air.metric_key).unit || ''}` : '优'}</strong>
              </div>
            </div>
          </div>

          <div className="section-row">
            <span className="section-title">常用场景</span>
            <span className="section-link" onClick={() => navigate('/mobile/automations')}>查看自动化</span>
          </div>
          <div className="scene-grid">
            {quickScenes.map((scene) => (
              <button key={scene.label} className="scene-card" onClick={scene.action}>
                <span className="scene-card__icon">{scene.icon}</span>
                <span className="scene-card__label">{scene.label}</span>
              </button>
            ))}
          </div>

          <div className="section-row">
            <span className="section-title">设备概览</span>
            <span className="section-link" onClick={() => navigate('/mobile/devices')}>查看全部</span>
          </div>
          <div className="hero-stat-grid hero-stat-grid--compact">
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
            <span className="section-link" onClick={() => navigate('/mobile/telemetry')}>数据图表</span>
          </div>
          <div className="surface-card" style={{ padding: '12px' }}>
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

          {primaryRoom && (
            <>
              <div className="section-row">
                <span className="section-title">房间</span>
                <span className="section-link" onClick={() => navigate('/mobile/devices')}>全部房间</span>
              </div>
              <RoomCard
                location={primaryRoom[0]}
                deviceCount={primaryRoom[1].devices.length}
                onlineCount={primaryRoom[1].devices.filter((d) => d.is_online).length}
                metrics={primaryRoom[1].metrics}
                metricLookup={metricLookup}
                onClick={() => navigate(`/mobile/devices?location=${encodeURIComponent(primaryRoom[0])}`)}
              />
              {rooms.size > 1 && (
                <div className="room-chip-row">
                  {Array.from(rooms.entries()).slice(1, 5).map(([loc, data]) => (
                    <button key={loc} className="room-chip-card" onClick={() => navigate(`/mobile/devices?location=${encodeURIComponent(loc)}`)}>
                      <span>{loc}</span>
                      <small>{data.devices.length} 台设备</small>
                    </button>
                  ))}
                </div>
              )}
            </>
          )}

          {recentAlerts.length > 0 && (
            <>
              <div className="section-row">
                <span className="section-title">告警信息</span>
                <span className="section-link" onClick={() => navigate('/mobile/alerts')}>查看全部</span>
              </div>
              <div className="alert-stack">
                {recentAlerts.map((a) => (
                  <div
                    key={`${a.rule_id}-${a.device_id}-${a.status}`}
                    onClick={() => navigate('/mobile/alerts')}
                    className="alert-overview-card"
                  >
                    <div className="alert-overview-card__icon" style={{ color: statusColors[a.status] || 'var(--color-text-tertiary)' }}>!</div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'flex', alignItems: 'center', gap: 6 }}>
                        {a.rule_name}
                        {a.alert_count > 1 && (
                          <span style={{ fontSize: 11, color: 'var(--color-primary)', fontWeight: 600 }}>×{a.alert_count}</span>
                        )}
                      </div>
                      <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>
                        {a.device_name} · {a.device_location}
                      </div>
                    </div>
                    <div className="alert-overview-card__aside">
                      <RelativeTime date={a.latest_triggered_at} />
                      <RightOutline fontSize={14} />
                    </div>
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
