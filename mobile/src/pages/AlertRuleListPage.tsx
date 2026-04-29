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

      <div className="page-hero" style={{ marginTop: 8, marginBottom: 16 }}>
        <div className="page-hero__eyebrow">alert rules</div>
        <div className="page-hero__title">告警规则</div>
        <div className="page-hero__subtitle">查看和管理所有规则，保持启停、编辑和删除能力不变。</div>
        <div className="page-hero__meta">
          <span className="soft-chip">规则 {rules.length}</span>
          <span className="soft-chip">启用 {rules.filter((rule) => rule.is_enabled).length}</span>
        </div>
      </div>

      {rules.length === 0 ? (
        <EmptyState title="暂无规则" description="点击右上角添加规则" />
      ) : (
        <div className="list-stack">
          {rules.map((rule) => (
            <div
              key={rule.id}
              className="glass-card"
              onClick={() => navigate(`/mobile/alert-rules/${rule.id}/edit`)}
              style={{ padding: 16, cursor: 'pointer' }}
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
                <div onClick={(e) => e.stopPropagation()}>
                  <Switch checked={rule.is_enabled} onChange={(v) => { handleToggle(rule, v); }} />
                </div>
              </div>

              <div className="soft-actions" style={{ marginTop: 14 }}>
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
              </div>
            </div>
          ))}
        </div>
      )}

      <div style={{ padding: '16px' }}>
        <Button block color="primary" onClick={() => navigate('/mobile/alert-rules/create')} style={{ borderRadius: 18 }}>
          创建新规则
        </Button>
      </div>
    </div>
  );
}
