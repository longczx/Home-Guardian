import { useState, useEffect } from 'react';
import { List, Switch, Toast, Button, Tag } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { AddOutline } from 'antd-mobile-icons';
import { getAutomations, updateAutomation, type Automation } from '@/api/automation';
import ActionSummary from '@/components/ActionSummary';
import EmptyState from '@/components/EmptyState';
import PageLoading from '@/components/PageLoading';
import RelativeTime from '@/components/RelativeTime';

const triggerLabels: Record<string, string> = {
  telemetry: '遥测触发',
  schedule: '定时触发',
};

export default function AutomationListPage() {
  const navigate = useNavigate();
  const [automations, setAutomations] = useState<Automation[]>([]);
  const [loading, setLoading] = useState(true);

  const fetch = () => {
    setLoading(true);
    getAutomations({ per_page: 100 }).then(({ data: res }) => {
      if (res.code === 0) setAutomations(res.data.items);
    }).finally(() => setLoading(false));
  };

  useEffect(() => { fetch(); }, []);

  const handleToggle = async (item: Automation, enabled: boolean) => {
    try {
      await updateAutomation(item.id, { is_enabled: enabled });
      setAutomations((prev) => prev.map((a) => (a.id === item.id ? { ...a, is_enabled: enabled } : a)));
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  if (loading) return <PageLoading />;

  return (
    <div style={{ background: 'var(--color-bg)', minHeight: '100%' }}>
      <div style={{ padding: '12px 16px 0', background: 'var(--navbar-bg)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span style={{ fontSize: 20, fontWeight: 700, color: 'var(--color-text)' }}>自动化</span>
          <AddOutline fontSize={22} onClick={() => navigate('/mobile/automations/create')} style={{ color: 'var(--color-primary)' }} />
        </div>
      </div>

      {automations.length === 0 ? (
        <EmptyState title="暂无自动化" description="创建自动化来自动控制设备" />
      ) : (
        <List style={{ '--border-top': 'none' } as React.CSSProperties}>
          {automations.map((item) => (
            <List.Item
              key={item.id}
              onClick={() => navigate(`/mobile/automations/${item.id}/edit`)}
              description={
                <div>
                  <ActionSummary actions={item.actions} />
                  {item.last_triggered_at && (
                    <div style={{ marginTop: 4 }}>
                      <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>上次触发: </span>
                      <RelativeTime date={item.last_triggered_at} />
                    </div>
                  )}
                </div>
              }
              extra={
                <div onClick={(e) => e.stopPropagation()}>
                  <Switch
                    checked={item.is_enabled}
                    onChange={(v) => handleToggle(item, v)}
                  />
                </div>
              }
              style={{ background: 'var(--color-bg-card)' }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontWeight: 500, color: 'var(--color-text)' }}>{item.name}</span>
                <Tag color="primary" fill="outline" style={{ fontSize: 11 }}>
                  {triggerLabels[item.trigger_type] || item.trigger_type}
                </Tag>
              </div>
            </List.Item>
          ))}
        </List>
      )}

      <div style={{ padding: 16 }}>
        <Button block color="primary" onClick={() => navigate('/mobile/automations/create')} style={{ borderRadius: 8 }}>
          创建自动化
        </Button>
      </div>
    </div>
  );
}
