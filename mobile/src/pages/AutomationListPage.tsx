import { useState, useEffect } from 'react';
import { Switch, Toast, Button, Dialog } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { AddOutline } from 'antd-mobile-icons';
import { getAutomations, updateAutomation, deleteAutomation, type Automation } from '@/api/automation';
import AppTag from '@/components/AppTag';
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

  const handleDelete = (item: Automation) => {
    Dialog.confirm({
      content: `确认删除「${item.name}」?`,
      onConfirm: async () => {
        try {
          await deleteAutomation(item.id);
          setAutomations((prev) => prev.filter((a) => a.id !== item.id));
          Toast.show({ content: '已删除', icon: 'success' });
        } catch {
          Toast.show({ content: '删除失败', icon: 'fail' });
        }
      },
    });
  };

  if (loading) return <PageLoading />;

  return (
    <div className="mobile-page mobile-page--tight">
      <div className="page-hero">
        <div className="page-hero__eyebrow">automation</div>
        <div className="page-hero__title">自动化编排</div>
        <div className="page-hero__subtitle">保留创建、编辑、启停和删除能力，把规则展示改成更接近参考图的卡片布局。</div>
        <div className="page-hero__meta">
          <span className="soft-chip">规则 {automations.length}</span>
          <span className="soft-chip">启用 {automations.filter((item) => item.is_enabled).length}</span>
        </div>
      </div>

      <div className="section-row">
        <span className="section-title">自动化列表</span>
        <span className="section-link" onClick={() => navigate('/mobile/automations/create')}>
          <AddOutline fontSize={16} /> 新建
        </span>
      </div>

      {automations.length === 0 ? (
        <EmptyState title="暂无自动化" description="创建自动化来自动控制设备" />
      ) : (
        <div className="list-stack">
          {automations.map((item) => (
            <div
              key={item.id}
              className="glass-card"
              onClick={() => navigate(`/mobile/automations/${item.id}/edit`)}
              style={{ padding: 16, cursor: 'pointer' }}
            >
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, justifyContent: 'space-between' }}>
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontWeight: 700, color: 'var(--color-text)', fontSize: 16 }}>{item.name}</span>
                    <AppTag tone="primary">
                      {triggerLabels[item.trigger_type] || item.trigger_type}
                    </AppTag>
                  </div>
                  <div style={{ marginTop: 10, color: 'var(--color-text-secondary)', fontSize: 13 }}>
                    <ActionSummary actions={item.actions} />
                  </div>
                </div>
                <div onClick={(e) => e.stopPropagation()}>
                  <Switch checked={item.is_enabled} onChange={(v) => handleToggle(item, v)} />
                </div>
              </div>

              {item.last_triggered_at && (
                <div style={{ marginTop: 14, fontSize: 12, color: 'var(--color-text-tertiary)' }}>
                  上次触发: <RelativeTime date={item.last_triggered_at} />
                </div>
              )}

              <div className="soft-actions" style={{ marginTop: 14 }}>
                <button
                  className="soft-button soft-button--accent"
                  onClick={(e) => {
                    e.stopPropagation();
                    navigate(`/mobile/automations/${item.id}/edit`);
                  }}
                >
                  编辑
                </button>
                <button
                  className="soft-button soft-button--danger"
                  onClick={(e) => {
                    e.stopPropagation();
                    handleDelete(item);
                  }}
                >
                  删除
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <div style={{ padding: 16 }}>
        <Button block color="primary" onClick={() => navigate('/mobile/automations/create')} style={{ borderRadius: 8 }}>
          创建自动化
        </Button>
      </div>
    </div>
  );
}
