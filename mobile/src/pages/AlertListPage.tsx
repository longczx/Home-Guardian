import { useState, useEffect, useCallback } from 'react';
import { CapsuleTabs, SwipeAction, InfiniteScroll, Tag, PullToRefresh, Toast } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { RightOutline } from 'antd-mobile-icons';
import { getAlertLogs, acknowledgeAlert, resolveAlert, type AlertLog } from '@/api/telemetry';
import { useAlertStore } from '@/stores/alertStore';
import RelativeTime from '@/components/RelativeTime';
import EmptyState from '@/components/EmptyState';

const statusConfig: Record<string, { color: 'danger' | 'warning' | 'success' | 'default'; label: string }> = {
  triggered: { color: 'danger', label: '触发中' },
  acknowledged: { color: 'warning', label: '已确认' },
  resolved: { color: 'success', label: '已解决' },
};

export default function AlertListPage() {
  const navigate = useNavigate();
  const [tab, setTab] = useState('all');
  const [alerts, setAlerts] = useState<AlertLog[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  const decrement = useAlertStore((s) => s.decrement);

  const fetchAlerts = useCallback(async (p: number, status?: string) => {
    const params: Record<string, string | number> = { page: p, per_page: 20 };
    if (status && status !== 'all') params.status = status;
    try {
      const { data: res } = await getAlertLogs(params);
      if (res.code === 0) {
        if (p === 1) setAlerts(res.data.items);
        else setAlerts((prev) => [...prev, ...res.data.items]);
        setHasMore(res.data.current_page < res.data.last_page);
      }
    } catch {
      setHasMore(false);
    }
  }, []);

  useEffect(() => {
    setPage(1);
    setAlerts([]);
    setHasMore(true);
    fetchAlerts(1, tab);
  }, [tab, fetchAlerts]);

  const loadMore = async () => {
    const next = page + 1;
    setPage(next);
    await fetchAlerts(next, tab);
  };

  const handleAcknowledge = async (id: number) => {
    try {
      await acknowledgeAlert(id);
      Toast.show({ content: '已确认', icon: 'success' });
      setAlerts((prev) => prev.map((a) => (a.id === id ? { ...a, status: 'acknowledged' } : a)));
      decrement();
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  const handleResolve = async (id: number) => {
    try {
      await resolveAlert(id);
      Toast.show({ content: '已解决', icon: 'success' });
      setAlerts((prev) => prev.map((a) => (a.id === id ? { ...a, status: 'resolved' } : a)));
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  const getActions = (alert: AlertLog) => {
    const actions = [];
    if (alert.status === 'triggered') {
      actions.push({
        key: 'ack',
        text: '确认',
        color: 'warning' as const,
        onClick: () => handleAcknowledge(alert.id),
      });
    }
    if (alert.status !== 'resolved') {
      actions.push({
        key: 'resolve',
        text: '解决',
        color: 'success' as const,
        onClick: () => handleResolve(alert.id),
      });
    }
    return actions;
  };

  return (
    <div style={{ background: 'var(--color-bg)', minHeight: '100%' }}>
      <div style={{ padding: '12px 16px 0', background: 'var(--navbar-bg)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
          <span style={{ fontSize: 20, fontWeight: 700, color: 'var(--color-text)' }}>告警</span>
          <span
            onClick={() => navigate('/mobile/alert-rules')}
            style={{ fontSize: 14, color: 'var(--color-primary)', cursor: 'pointer' }}
          >
            规则管理
          </span>
        </div>
        <CapsuleTabs activeKey={tab} onChange={setTab}>
          <CapsuleTabs.Tab title="全部" key="all" />
          <CapsuleTabs.Tab title="触发中" key="triggered" />
          <CapsuleTabs.Tab title="已确认" key="acknowledged" />
          <CapsuleTabs.Tab title="已解决" key="resolved" />
        </CapsuleTabs>
      </div>

      <PullToRefresh onRefresh={() => fetchAlerts(1, tab)}>
        <div style={{ padding: '8px 16px' }}>
          {alerts.length === 0 && !hasMore ? (
            <EmptyState title="暂无告警" />
          ) : (
            alerts.map((alert) => (
              <SwipeAction key={alert.id} rightActions={getActions(alert)}>
                <div
                  onClick={() => navigate(`/mobile/alerts/${alert.id}`)}
                  style={{
                    background: 'var(--color-bg-card)',
                    borderRadius: 'var(--card-radius)',
                    padding: '14px 16px',
                    marginBottom: 8,
                    boxShadow: 'var(--card-shadow)',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 12,
                  }}
                >
                  <div style={{
                    width: 10, height: 10, borderRadius: '50%', flexShrink: 0,
                    background: alert.status === 'triggered' ? 'var(--color-danger)' : alert.status === 'acknowledged' ? 'var(--color-warning)' : 'var(--color-success)',
                  }} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                      <span style={{ fontSize: 15, fontWeight: 500, color: 'var(--color-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {alert.rule?.name || '未知规则'}
                      </span>
                      <Tag color={statusConfig[alert.status]?.color || 'default'} fill="outline" style={{ flexShrink: 0 }}>
                        {statusConfig[alert.status]?.label || alert.status}
                      </Tag>
                    </div>
                    <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>
                      {alert.device?.name} · {alert.device?.location}
                    </div>
                  </div>
                  <div style={{ textAlign: 'right', flexShrink: 0 }}>
                    <RelativeTime date={alert.triggered_at} />
                    <div style={{ color: 'var(--color-text-tertiary)', marginTop: 4 }}><RightOutline fontSize={12} /></div>
                  </div>
                </div>
              </SwipeAction>
            ))
          )}
        </div>
        <InfiniteScroll loadMore={loadMore} hasMore={hasMore} />
      </PullToRefresh>
    </div>
  );
}
