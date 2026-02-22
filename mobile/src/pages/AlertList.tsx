import { useState, useEffect, useCallback } from 'react';
import { NavBar, List, Tag, InfiniteScroll, Button, Toast } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { getAlertLogs, acknowledgeAlert, type AlertLog as AlertLogType } from '@/api/telemetry';

export default function AlertList() {
  const navigate = useNavigate();
  const [alerts, setAlerts] = useState<AlertLogType[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);

  const fetchAlerts = useCallback(async (p: number) => {
    try {
      const { data: res } = await getAlertLogs({ page: p, per_page: 20 });
      if (res.code === 0) {
        if (p === 1) {
          setAlerts(res.data.items);
        } else {
          setAlerts((prev) => [...prev, ...res.data.items]);
        }
        setHasMore(res.data.current_page < res.data.last_page);
      }
    } catch {
      setHasMore(false);
    }
  }, []);

  useEffect(() => {
    fetchAlerts(1);
  }, [fetchAlerts]);

  const loadMore = async () => {
    const nextPage = page + 1;
    setPage(nextPage);
    await fetchAlerts(nextPage);
  };

  const handleAcknowledge = async (id: number) => {
    try {
      await acknowledgeAlert(id);
      Toast.show({ content: '已确认', icon: 'success' });
      setAlerts((prev) =>
        prev.map((a) => (a.id === id ? { ...a, status: 'acknowledged' } : a))
      );
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  const statusColors: Record<string, 'danger' | 'warning' | 'success' | 'default'> = {
    triggered: 'danger',
    acknowledged: 'warning',
    resolved: 'success',
  };

  const statusLabels: Record<string, string> = {
    triggered: '触发中',
    acknowledged: '已确认',
    resolved: '已解决',
  };

  return (
    <div style={{ minHeight: '100vh', background: '#f5f5f5' }}>
      <NavBar onBack={() => navigate(-1)}>告警通知</NavBar>

      {alerts.length === 0 ? (
        <div style={{ textAlign: 'center', color: '#999', padding: 60 }}>
          暂无告警
        </div>
      ) : (
        <List>
          {alerts.map((alert) => (
            <List.Item
              key={alert.id}
              title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span>{alert.rule?.name || '未知规则'}</span>
                  <Tag color={statusColors[alert.status] || 'default'} fill="outline">
                    {statusLabels[alert.status] || alert.status}
                  </Tag>
                </div>
              }
              description={
                <div>
                  <div style={{ fontSize: 12 }}>
                    {alert.device?.name} ({alert.device?.location})
                  </div>
                  <div style={{ fontSize: 12, color: '#999' }}>
                    {new Date(alert.triggered_at).toLocaleString()}
                  </div>
                </div>
              }
              extra={
                alert.status === 'triggered' ? (
                  <Button
                    size="mini"
                    color="primary"
                    fill="outline"
                    onClick={(e) => {
                      e.stopPropagation();
                      handleAcknowledge(alert.id);
                    }}
                  >
                    确认
                  </Button>
                ) : null
              }
            />
          ))}
        </List>
      )}

      <InfiniteScroll loadMore={loadMore} hasMore={hasMore} />
    </div>
  );
}
