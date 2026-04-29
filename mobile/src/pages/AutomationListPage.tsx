import { useState, useEffect } from 'react';
import { Switch, Toast, Button, Dialog } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
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
  const [filter, setFilter] = useState('all');
  const [selectionMode, setSelectionMode] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);

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

  const visibleAutomations = automations.filter((item) => {
    if (filter === 'enabled') return item.is_enabled;
    if (filter === 'disabled') return !item.is_enabled;
    return true;
  });

  const selectedAutomations = visibleAutomations.filter((item) => selectedIds.includes(item.id));

  const resetSelection = () => {
    setSelectionMode(false);
    setSelectedIds([]);
  };

  const toggleSelection = (automationId: number) => {
    setSelectedIds((prev) => (
      prev.includes(automationId) ? prev.filter((id) => id !== automationId) : [...prev, automationId]
    ));
  };

  const handleSelectAll = () => {
    if (selectedAutomations.length === visibleAutomations.length) {
      setSelectedIds([]);
      return;
    }
    setSelectedIds(visibleAutomations.map((item) => item.id));
  };

  const handleBatchToggle = async (enabled: boolean) => {
    const targets = selectedAutomations.filter((item) => item.is_enabled !== enabled);
    if (targets.length === 0) return;

    try {
      await Promise.all(targets.map((item) => updateAutomation(item.id, { is_enabled: enabled })));
      setAutomations((prev) => prev.map((item) => (
        targets.some((target) => target.id === item.id) ? { ...item, is_enabled: enabled } : item
      )));
      Toast.show({ content: enabled ? '已批量启用' : '已批量停用', icon: 'success' });
      resetSelection();
    } catch {
      Toast.show({ content: '批量操作失败', icon: 'fail' });
    }
  };

  if (loading) return <PageLoading />;

  return (
    <div className="mobile-page mobile-page--tight">
      <div className="screen-header">
        <div>
          <div className="screen-header__title">自动化</div>
          <div className="screen-header__subtitle">按启用状态查看规则，把常用联动放到更靠前的位置。</div>
        </div>
        <button className="ghost-icon-button" onClick={() => navigate('/mobile/automations/create')}>新建</button>
      </div>

      <div className="surface-card automation-overview-card">
        <div className="surface-card__eyebrow">automation summary</div>
        <div className="surface-card__title">让家自动响应</div>
        <div className="surface-card__meta">
          <span className="soft-chip">规则 {automations.length}</span>
          <span className="soft-chip">启用 {automations.filter((item) => item.is_enabled).length}</span>
          <span className="soft-chip">最近触发 {automations.filter((item) => item.last_triggered_at).length}</span>
        </div>
      </div>

      <div className="management-filter-bar">
        <button className={`filter-pill${filter === 'all' ? ' filter-pill--active' : ''}`} onClick={() => setFilter('all')}>全部</button>
        <button className={`filter-pill${filter === 'enabled' ? ' filter-pill--active' : ''}`} onClick={() => setFilter('enabled')}>启用中</button>
        <button className={`filter-pill${filter === 'disabled' ? ' filter-pill--active' : ''}`} onClick={() => setFilter('disabled')}>已停用</button>
      </div>

      <div className="management-action-bar">
        {selectionMode ? (
          <>
            <div className="management-action-bar__meta">已选择 {selectedAutomations.length} / {visibleAutomations.length} 条自动化</div>
            <div className="management-action-bar__buttons">
              <button className="soft-button" disabled={visibleAutomations.length === 0} onClick={handleSelectAll}>
                {selectedAutomations.length === visibleAutomations.length ? '取消全选' : '全选'}
              </button>
              <button className="soft-button soft-button--accent" disabled={selectedAutomations.length === 0} onClick={() => handleBatchToggle(true)}>启用</button>
              <button className="soft-button" disabled={selectedAutomations.length === 0} onClick={() => handleBatchToggle(false)}>停用</button>
              <button className="soft-button soft-button--danger" onClick={resetSelection}>退出</button>
            </div>
          </>
        ) : (
          <>
            <div className="management-action-bar__meta">当前筛选下共 {visibleAutomations.length} 条自动化</div>
            <div className="management-action-bar__buttons">
              <button className="soft-button" disabled={visibleAutomations.length === 0} onClick={() => setSelectionMode(true)}>选择</button>
            </div>
          </>
        )}
      </div>

      {visibleAutomations.length === 0 ? (
        <EmptyState title="暂无自动化" description="创建自动化来自动控制设备" />
      ) : (
        <div className="list-stack">
          {visibleAutomations.map((item) => (
            <div
              key={item.id}
              className={`automation-card${selectionMode && selectedIds.includes(item.id) ? ' selection-card--active' : ''}`}
              onClick={() => {
                if (selectionMode) {
                  toggleSelection(item.id);
                  return;
                }
                navigate(`/mobile/automations/${item.id}/edit`);
              }}
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
                {selectionMode ? (
                  <div className="selection-card__check" />
                ) : (
                  <div onClick={(e) => e.stopPropagation()}>
                    <Switch checked={item.is_enabled} onChange={(v) => handleToggle(item, v)} />
                  </div>
                )}
              </div>

              {item.last_triggered_at && (
                <div style={{ marginTop: 14, fontSize: 12, color: 'var(--color-text-tertiary)' }}>
                  上次触发: <RelativeTime date={item.last_triggered_at} />
                </div>
              )}

              {!selectionMode && <div className="soft-actions" style={{ marginTop: 14 }}>
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
              </div>}
            </div>
          ))}
        </div>
      )}

      <div className="page-cta-bar">
        <Button block color="primary" onClick={() => navigate('/mobile/automations/create')} style={{ borderRadius: 18 }}>
          创建自动化
        </Button>
      </div>
    </div>
  );
}
