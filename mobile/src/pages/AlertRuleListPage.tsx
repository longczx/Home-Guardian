import { useState, useEffect } from 'react';
import { NavBar, List, Switch, Toast, Button } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { AddOutline } from 'antd-mobile-icons';
import { getAlertRules, updateAlertRule, type AlertRule } from '@/api/alertRule';
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

  if (loading) return <PageLoading />;

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar
        onBack={() => navigate(-1)}
        right={
          <AddOutline fontSize={22} onClick={() => navigate('/mobile/alert-rules/create')} style={{ color: 'var(--color-primary)' }} />
        }
        style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}
      >
        告警规则
      </NavBar>

      {rules.length === 0 ? (
        <EmptyState title="暂无规则" description="点击右上角添加规则" />
      ) : (
        <List style={{ '--border-top': 'none' } as React.CSSProperties}>
          {rules.map((rule) => (
            <List.Item
              key={rule.id}
              onClick={() => navigate(`/mobile/alert-rules/${rule.id}/edit`)}
              description={
                <ConditionSummary
                  telemetryKey={rule.telemetry_key}
                  condition={rule.condition}
                  thresholdValue={rule.threshold_value}
                  duration={rule.trigger_duration_sec}
                />
              }
              extra={
                <div onClick={(e) => e.stopPropagation()}>
                  <Switch
                    checked={rule.is_enabled}
                    onChange={(v) => { handleToggle(rule, v); }}
                  />
                </div>
              }
              style={{ background: 'var(--color-bg-card)' }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontWeight: 500, color: 'var(--color-text)' }}>{rule.name}</span>
                <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>{rule.device?.name}</span>
              </div>
            </List.Item>
          ))}
        </List>
      )}

      <div style={{ padding: '16px' }}>
        <Button block color="primary" onClick={() => navigate('/mobile/alert-rules/create')} style={{ borderRadius: 8 }}>
          创建新规则
        </Button>
      </div>
    </div>
  );
}
