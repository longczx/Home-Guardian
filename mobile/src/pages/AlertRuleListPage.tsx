import { useState, useEffect } from 'react';
import { NavBar, Switch, Toast, Button, Dialog } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { AddOutline } from 'antd-mobile-icons';
import { getAlertRules, updateAlertRule, deleteAlertRule, type AlertRule } from '@/api/alertRule';
import ConditionSummary from '@/components/ConditionSummary';
import EmptyState from '@/components/EmptyState';
import PageLoading from '@/components/PageLoading';

export default function AlertRuleListPage() {
  const navigate = useNavigate();
  const [rules, setRules] = useState<AlertRule[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'all' | 'enabled' | 'disabled'>('all');
  const [selectionMode, setSelectionMode] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);

  const fetchRules = () => {
    setLoading(true);
    getAlertRules({ per_page: 100 }).then(({ data: res }) => {
      if (res.code === 0) setRules(res.data.items);
    }).finally(() => setLoading(false));
  };

  useEffect(() => { fetchRules(); }, []);

  const handleToggle = async (rule: AlertRule, enabled: boolean) => {
    try {
      await updateAlertRule(rule.id, { is_enabled: enabled });
      setRules((prev) => prev.map((r) => (r.id === rule.id ? { ...r, is_enabled: enabled } : r)));
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  const handleDelete = (rule: AlertRule) => {
    Dialog.confirm({
      content: `确认删除「${rule.name}」?`,
      onConfirm: async () => {
        try {
          await deleteAlertRule(rule.id);
          setRules((prev) => prev.filter((r) => r.id !== rule.id));
          Toast.show({ content: '已删除', icon: 'success' });
        } catch {
          Toast.show({ content: '删除失败', icon: 'fail' });
        }
      },
    });
  };

  const visibleRules = rules.filter((rule) => {
    if (filter === 'enabled') return rule.is_enabled;
    if (filter === 'disabled') return !rule.is_enabled;
    return true;
  });

  const selectedRules = visibleRules.filter((rule) => selectedIds.includes(rule.id));

  const resetSelection = () => {
    setSelectionMode(false);
    setSelectedIds([]);
  };

  const toggleSelection = (ruleId: number) => {
    setSelectedIds((prev) => (
      prev.includes(ruleId) ? prev.filter((id) => id !== ruleId) : [...prev, ruleId]
    ));
  };

  const handleSelectAll = () => {
    if (selectedRules.length === visibleRules.length) {
      setSelectedIds([]);
      return;
    }
    setSelectedIds(visibleRules.map((rule) => rule.id));
  };

  const handleBatchToggle = async (enabled: boolean) => {
    const targetRules = selectedRules.filter((rule) => rule.is_enabled !== enabled);
    if (targetRules.length === 0) return;

    try {
      await Promise.all(targetRules.map((rule) => updateAlertRule(rule.id, { is_enabled: enabled })));
      setRules((prev) => prev.map((rule) => (
        targetRules.some((target) => target.id === rule.id) ? { ...rule, is_enabled: enabled } : rule
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
      <NavBar
        onBack={() => navigate(-1)}
        right={
          <AddOutline fontSize={22} onClick={() => navigate('/mobile/alert-rules/create')} style={{ color: 'var(--color-primary)' }} />
        }
        style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}
      >
        告警规则
      </NavBar>

      <div className="screen-header" style={{ marginTop: 8 }}>
        <div>
          <div className="screen-header__title">告警规则</div>
          <div className="screen-header__subtitle">用统一的筛选和管理节奏，维护规则的启停与处理动作。</div>
        </div>
        <button className="ghost-icon-button" onClick={() => navigate('/mobile/alert-rules/create')}>新建</button>
      </div>

      <div className="surface-card automation-overview-card">
        <div className="surface-card__eyebrow">alert rules</div>
        <div className="surface-card__title">规则编排</div>
        <div className="surface-card__meta">
          <span className="soft-chip">规则 {rules.length}</span>
          <span className="soft-chip">启用 {rules.filter((rule) => rule.is_enabled).length}</span>
          <span className="soft-chip">当前视图 {visibleRules.length}</span>
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
            <div className="management-action-bar__meta">已选择 {selectedRules.length} / {visibleRules.length} 条规则</div>
            <div className="management-action-bar__buttons">
              <button className="soft-button" disabled={visibleRules.length === 0} onClick={handleSelectAll}>
                {selectedRules.length === visibleRules.length ? '取消全选' : '全选'}
              </button>
              <button className="soft-button soft-button--accent" disabled={selectedRules.length === 0} onClick={() => handleBatchToggle(true)}>启用</button>
              <button className="soft-button" disabled={selectedRules.length === 0} onClick={() => handleBatchToggle(false)}>停用</button>
              <button className="soft-button soft-button--danger" onClick={resetSelection}>退出</button>
            </div>
          </>
        ) : (
          <>
            <div className="management-action-bar__meta">当前筛选下共 {visibleRules.length} 条规则</div>
            <div className="management-action-bar__buttons">
              <button className="soft-button" disabled={visibleRules.length === 0} onClick={() => setSelectionMode(true)}>选择</button>
            </div>
          </>
        )}
      </div>

      {visibleRules.length === 0 ? (
        <EmptyState title="暂无规则" description="点击右上角添加规则" />
      ) : (
        <div className="list-stack">
          {visibleRules.map((rule) => (
            <div
              key={rule.id}
              className={`automation-card${selectionMode && selectedIds.includes(rule.id) ? ' selection-card--active' : ''}`}
              onClick={() => {
                if (selectionMode) {
                  toggleSelection(rule.id);
                  return;
                }
                navigate(`/mobile/alert-rules/${rule.id}/edit`);
              }}
            >
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }}>
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontWeight: 700, color: 'var(--color-text)', fontSize: 16 }}>{rule.name}</span>
                    <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>{rule.device?.name}</span>
                  </div>
                  <div style={{ marginTop: 10, color: 'var(--color-text-secondary)', fontSize: 13 }}>
                    <ConditionSummary
                      telemetryKey={rule.telemetry_key}
                      condition={rule.condition}
                      thresholdValue={rule.threshold_value}
                      duration={rule.trigger_duration_sec}
                    />
                  </div>
                </div>
                {selectionMode ? (
                  <div className="selection-card__check" />
                ) : (
                  <div onClick={(e) => e.stopPropagation()}>
                    <Switch checked={rule.is_enabled} onChange={(v) => { handleToggle(rule, v); }} />
                  </div>
                )}
              </div>

              {!selectionMode && <div className="soft-actions" style={{ marginTop: 14 }}>
                <button
                  className="soft-button soft-button--accent"
                  onClick={(e) => {
                    e.stopPropagation();
                    navigate(`/mobile/alert-rules/${rule.id}/edit`);
                  }}
                >
                  编辑
                </button>
                <button
                  className="soft-button soft-button--danger"
                  onClick={(e) => {
                    e.stopPropagation();
                    handleDelete(rule);
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
        <Button block color="primary" onClick={() => navigate('/mobile/alert-rules/create')} style={{ borderRadius: 18 }}>
          创建新规则
        </Button>
      </div>
    </div>
  );
}
