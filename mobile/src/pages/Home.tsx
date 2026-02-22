import { useState, useEffect, useCallback } from 'react';
import { NavBar, Grid, Badge, Card, PullToRefresh } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { getDevices, type Device } from '@/api/device';
import { useAuthStore } from '@/stores/authStore';
import { useWebSocket } from '@/hooks/useWebSocket';
import DeviceCard from '@/components/DeviceCard';
import MetricDisplay from '@/components/MetricDisplay';

export default function Home() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [alertCount, setAlertCount] = useState(0);
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);

  const fetchDevices = useCallback(async () => {
    try {
      const { data: res } = await getDevices({ per_page: 100 });
      if (res.code === 0) {
        setDevices(res.data.items);
      }
    } catch {
      // ignore
    }
  }, []);

  useEffect(() => {
    fetchDevices();
  }, [fetchDevices]);

  // WebSocket 实时更新
  const onWsMessage = useCallback((msg: { type: string; data: unknown }) => {
    if (msg.type === 'device_status') {
      fetchDevices();
    }
    if (msg.type === 'alert') {
      setAlertCount((c) => c + 1);
    }
  }, [fetchDevices]);

  useWebSocket(onWsMessage);

  const onlineCount = devices.filter((d) => d.is_online).length;

  return (
    <div style={{ minHeight: '100vh', background: '#f5f5f5' }}>
      <NavBar
        backIcon={false}
        right={
          <span onClick={() => { logout(); navigate('/mobile/login', { replace: true }); }} style={{ fontSize: 14, color: '#999' }}>
            退出
          </span>
        }
        style={{ background: '#fff' }}
      >
        Home Guardian
      </NavBar>

      <PullToRefresh onRefresh={fetchDevices}>
        {/* 概览卡片 */}
        <Card style={{ margin: 12, borderRadius: 12 }}>
          <div style={{ fontSize: 14, color: '#999', marginBottom: 8 }}>
            欢迎, {user?.username}
          </div>
          <Grid columns={3} gap={8}>
            <Grid.Item>
              <MetricDisplay label="设备总数" value={devices.length} />
            </Grid.Item>
            <Grid.Item>
              <MetricDisplay label="在线设备" value={onlineCount} />
            </Grid.Item>
            <Grid.Item>
              <MetricDisplay label="待处理告警" value={alertCount} />
            </Grid.Item>
          </Grid>
        </Card>

        {/* 快捷导航 */}
        <Grid columns={3} gap={0} style={{ margin: '0 12px 12px', background: '#fff', borderRadius: 12, padding: '12px 0' }}>
          <Grid.Item onClick={() => navigate('/mobile/telemetry')}>
            <div style={{ textAlign: 'center', padding: '8px 0', cursor: 'pointer' }}>
              <div style={{ fontSize: 24 }}>📊</div>
              <div style={{ fontSize: 12, color: '#666', marginTop: 4 }}>数据图表</div>
            </div>
          </Grid.Item>
          <Grid.Item onClick={() => navigate('/mobile/alerts')}>
            <div style={{ textAlign: 'center', padding: '8px 0', cursor: 'pointer' }}>
              <Badge content={alertCount > 0 ? alertCount : null}>
                <div style={{ fontSize: 24 }}>🔔</div>
              </Badge>
              <div style={{ fontSize: 12, color: '#666', marginTop: 4 }}>告警通知</div>
            </div>
          </Grid.Item>
          <Grid.Item>
            <div style={{ textAlign: 'center', padding: '8px 0' }}>
              <div style={{ fontSize: 24 }}>⚙️</div>
              <div style={{ fontSize: 12, color: '#666', marginTop: 4 }}>设置</div>
            </div>
          </Grid.Item>
        </Grid>

        {/* 设备列表 */}
        <div style={{ padding: '0 12px 24px' }}>
          <div style={{ fontSize: 16, fontWeight: 'bold', marginBottom: 12, color: '#333' }}>
            我的设备
          </div>
          {devices.length === 0 ? (
            <Card style={{ borderRadius: 12, textAlign: 'center', color: '#999', padding: 40 }}>
              暂无设备
            </Card>
          ) : (
            devices.map((device) => (
              <DeviceCard key={device.id} device={device} />
            ))
          )}
        </div>
      </PullToRefresh>
    </div>
  );
}
