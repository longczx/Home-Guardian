import { useState, useEffect, useCallback } from 'react';
import { CapsuleTabs, Tag, PullToRefresh, Toast, Dialog } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { RightOutline } from 'antd-mobile-icons';
import { getGroupedAlertLogs, getAlertLogs, batchAcknowledgeAlerts, batchResolveAlerts, type GroupedAlert, type AlertLog } from '@/api/telemetry';
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
  const [groups, setGroups] = useState<GroupedAlert[]>([]);
  const [expandedKey, setExpandedKey] = useState<string | null>(null);
  const [expandedAlerts, setExpandedAlerts] = useState<AlertLog[]>([]);
  const fetchUnreadCount = useAlertStore((s) => s.fetchUnreadCount);

  const fetchGroups = useCallback(async (status?: string) => {
    const params: Record<string, string | number> = {};
    if (status && status !== 'all') params.status = status;
    try {
      const { data: res } = await getGroupedAlertLogs(params);
      if (res.code === 0) setGroups(res.data);
    } catch { /* ignore */ }
  }, []);

  useEffect(() => {
    setExpandedKey(null);
    fetchGroups(tab);
  }, [tab, fetchGroups]);

  const groupKey = (g: GroupedAlert) => `${g.rule_id}-${g.device_id}-${g.status}`;

  const handleExpand = async (g: GroupedAlert) => {
    const key = groupKey(g);
    if (expandedKey === key) {
      setExpandedKey(null);
      return;
    }
    setExpandedKey(key);
    try {
      const { data: res } = await getAlertLogs({ rule_id: g.rule_id, device_id: g.device_id, status: g.status, per_page: 50 });
      if (res.code === 0) setExpandedAlerts(res.data.items);
    } catch { setExpandedAlerts([]); }
  };

  const handleBatchAck = (g: GroupedAlert) => {
    Dialog.confirm({
      title: '批量确认',
      content: `确定将「${g.rule_name}」在「${g.device_name}」的 ${g.alert_count} 条告警全部标记为已确认？`,
      onConfirm: async () => {
        try {
          await batchAcknowledgeAlerts(g.rule_id, g.device_id);
          Toast.show({ content: '批量确认成功', icon: 'success' });
          fetchGroups(tab);
          fetchUnreadCount();
        } catch { Toast.show({ content: '操作失败', icon: 'fail' }); }
      },
    });
  };

  const handleBatchResolve = (g: GroupedAlert) => {
    Dialog.confirm({
      title: '批量解决',
      content: `确定将「${g.rule_name}」在「${g.device_name}」的 ${g.alert_count} 条告警全部标记为已解决？`,
      onConfirm: async () => {
        try {
          await batchResolveAlerts(g.rule_id, g.device_id);
          Toast.show({ content: '批量解决成功', icon: 'success' });
          fetchGroups(tab);
          fetchUnreadCount();
        } catch { Toast.show({ content: '操作失败', icon: 'fail' }); }
      },
    });
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

      <PullToRefresh onRefresh={() => fetchGroups(tab)}>
        <div style={{ padding: '8px 16px' }}>
          {groups.length === 0 ? (
            <EmptyState title="暂无告警" />
          ) : (
            groups.map((g) => {
              const key = groupKey(g);
              const isExpanded = expandedKey === key;
              return (
                <div key={key} style={{ marginBottom: 10 }}>
                  {/* Group card */}
                  <div
                    style={{
                      background: 'var(--color-bg-card)',
                      borderRadius: 'var(--card-radius)',
                      boxShadow: 'var(--card-shadow)',
                      overflow: 'hidden',
                    }}
                  >
                    <div
                      onClick={() => handleExpand(g)}
                      style={{ padding: '14px 16px', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 12 }}
                    >
                      <div style={{
                        width: 10, height: 10, borderRadius: '50%', flexShrink: 0,
                        background: g.status === 'triggered' ? 'var(--color-danger)' : g.status === 'acknowledged' ? 'var(--color-warning)' : 'var(--color-success)',
                      }} />
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                          <span style={{ fontSize: 15, fontWeight: 500, color: 'var(--color-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                            {g.rule_name}
                          </span>
                          <Tag color={statusConfig[g.status]?.color || 'default'} fill="outline" style={{ flexShrink: 0 }}>
                            {statusConfig[g.status]?.label || g.status}
                          </Tag>
                          <span style={{ fontSize: 12, color: 'var(--color-primary)', fontWeight: 600, flexShrink: 0 }}>
                            ×{g.alert_count}
                          </span>
                        </div>
                        <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>
                          {g.device_name} · {g.device_location}
                        </div>
                      </div>
                      <div style={{ textAlign: 'right', flexShrink: 0 }}>
                        <RelativeTime date={g.latest_triggered_at} />
                        <div style={{ color: 'var(--color-text-tertiary)', marginTop: 4 }}>
                          <RightOutline fontSize={12} style={{ transform: isExpanded ? 'rotate(90deg)' : 'none', transition: 'transform 0.2s' }} />
                        </div>
                      </div>
                    </div>

                    {/* Batch actions */}
                    {g.status !== 'resolved' && (
                      <div style={{ padding: '0 16px 12px', display: 'flex', gap: 8 }}>
                        {g.status === 'triggered' && (
                          <button
                            onClick={(e) => { e.stopPropagation(); handleBatchAck(g); }}
                            style={{
                              flex: 1, padding: '6px 0', border: '1px solid var(--color-warning)', borderRadius: 6,
                              background: 'transparent', color: 'var(--color-warning)', fontSize: 13, cursor: 'pointer',
                            }}
                          >
                            全部确认 ({g.alert_count})
                          </button>
                        )}
                        <button
                          onClick={(e) => { e.stopPropagation(); handleBatchResolve(g); }}
                          style={{
                            flex: 1, padding: '6px 0', border: '1px solid var(--color-success)', borderRadius: 6,
                            background: 'transparent', color: 'var(--color-success)', fontSize: 13, cursor: 'pointer',
                          }}
                        >
                          全部解决 ({g.alert_count})
                        </button>
                      </div>
                    )}

                    {/* Expanded detail list */}
                    {isExpanded && (
                      <div style={{ borderTop: '1px solid var(--color-border)' }}>
                        {expandedAlerts.map((a) => (
                          <div
                            key={a.id}
                            onClick={() => navigate(`/mobile/alerts/${a.id}`)}
                            style={{
                              padding: '10px 16px', borderBottom: '1px solid var(--color-border)',
                              display: 'flex', justifyContent: 'space-between', alignItems: 'center', cursor: 'pointer',
                            }}
                          >
                            <span style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>#{a.id}</span>
                            <RelativeTime date={a.triggered_at} />
                          </div>
                        ))}
                        {expandedAlerts.length === 0 && (
                          <div style={{ padding: 16, textAlign: 'center', color: 'var(--color-text-tertiary)', fontSize: 13 }}>加载中...</div>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              );
            })
          )}
        </div>
      </PullToRefresh>
    </div>
  );
}
