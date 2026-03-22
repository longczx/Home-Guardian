import { useEffect, useMemo } from 'react';
import { NavBar, PullToRefresh, Grid } from 'antd-mobile';
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
      if (dm) room.metrics = dm;
    });
    return map;
  }, [devices, metricsMap]);

  const shortcuts = [
    { icon: <HistogramOutline />, label: '数据图表', path: '/mobile/telemetry' },
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
    <div style={{ background: 'var(--color-bg)', minHeight: '100%' }}>
      <NavBar backIcon={false} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        Home Guardian
      </NavBar>

      <PullToRefresh onRefresh={fetchAll}>
        <div style={{ padding: '12px 16px' }}>
          <div style={{ fontSize: 14, color: 'var(--color-text-secondary)', marginBottom: 16 }}>
            你好, {user?.username}
          </div>

          {/* Stats */}
          <Grid columns={3} gap={10} style={{ marginBottom: 16 }}>
            <Grid.Item>
              <StatCard icon={<AppstoreOutline />} label="设备总数" value={devices.length} />
            </Grid.Item>
            <Grid.Item>
              <StatCard icon={<UnorderedListOutline />} label="在线" value={onlineCount} color="var(--color-success)" />
            </Grid.Item>
            <Grid.Item>
              <StatCard
                icon={<BellOutline />}
                label="待处理告警"
                value={unreadCount}
                color="var(--color-danger)"
                onClick={() => navigate('/mobile/alerts')}
              />
            </Grid.Item>
          </Grid>

          {/* Shortcuts */}
          <div style={{ background: 'var(--color-bg-card)', borderRadius: 'var(--card-radius)', padding: '12px 8px', marginBottom: 16, boxShadow: 'var(--card-shadow)' }}>
            <Grid columns={3} gap={0}>
              {shortcuts.map((s) => (
                <Grid.Item key={s.path} onClick={() => navigate(s.path)}>
                  <div style={{ textAlign: 'center', padding: '10px 0', cursor: 'pointer' }}>
                    <div style={{ fontSize: 22, color: 'var(--color-primary)' }}>{s.icon}</div>
                    <div style={{ fontSize: 12, color: 'var(--color-text-secondary)', marginTop: 4 }}>{s.label}</div>
                  </div>
                </Grid.Item>
              ))}
            </Grid>
          </div>

          {/* Rooms */}
          {rooms.size > 0 && (
            <>
              <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--color-text)', marginBottom: 10 }}>
                房间概览
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

          {/* Recent Alerts */}
          {recentAlerts.length > 0 && (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8, marginBottom: 10 }}>
                <span style={{ fontSize: 16, fontWeight: 600, color: 'var(--color-text)' }}>最近告警</span>
                <span
                  style={{ fontSize: 13, color: 'var(--color-primary)', cursor: 'pointer' }}
                  onClick={() => navigate('/mobile/alerts')}
                >
                  查看全部
                </span>
              </div>
              <div style={{ background: 'var(--color-bg-card)', borderRadius: 'var(--card-radius)', boxShadow: 'var(--card-shadow)', overflow: 'hidden' }}>
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
                      <div style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'flex', alignItems: 'center', gap: 6 }}>
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
