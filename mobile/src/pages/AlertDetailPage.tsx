import { useState, useEffect } from 'react';
import { NavBar, Button, Toast } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getAlertLog, acknowledgeAlert, resolveAlert, type AlertLog } from '@/api/telemetry';
import StatusTag from '@/components/StatusTag';
import RelativeTime from '@/components/RelativeTime';
import PageLoading from '@/components/PageLoading';

export default function AlertDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [alert, setAlert] = useState<AlertLog | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!id) return;
    getAlertLog(parseInt(id)).then(({ data: res }) => {
      if (res.code === 0) setAlert(res.data);
    }).finally(() => setLoading(false));
  }, [id]);

  const handleAck = async () => {
    if (!alert) return;
    try {
      await acknowledgeAlert(alert.id);
      Toast.show({ content: '已确认', icon: 'success' });
      setAlert({ ...alert, status: 'acknowledged' });
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  const handleResolve = async () => {
    if (!alert) return;
    try {
      await resolveAlert(alert.id);
      Toast.show({ content: '已解决', icon: 'success' });
      setAlert({ ...alert, status: 'resolved' });
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  if (loading) return <PageLoading />;
  if (!alert) {
    return (
      <div className="mobile-page">
        <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)' }}>告警详情</NavBar>
        <div style={{ textAlign: 'center', padding: 40, color: 'var(--color-text-tertiary)' }}>告警不存在</div>
      </div>
    );
  }

  const infoItems = [
    { label: '规则名称', value: alert.rule?.name || '未知' },
    { label: '设备', value: `${alert.device?.name || '未知'} (${alert.device?.location || '未知'})` },
    { label: '触发值', value: String(alert.triggered_value ?? '--') },
    { label: '触发时间', value: new Date(alert.triggered_at).toLocaleString() },
    ...(alert.acknowledged_at ? [{ label: '确认时间', value: new Date(alert.acknowledged_at).toLocaleString() }] : []),
    ...(alert.resolved_at ? [{ label: '解决时间', value: new Date(alert.resolved_at).toLocaleString() }] : []),
    ...(alert.message ? [{ label: '消息', value: alert.message }] : []),
  ];

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        告警详情
      </NavBar>

      <div>
        <div className="screen-header" style={{ marginTop: 8 }}>
          <div>
            <div className="screen-header__title">{alert.rule?.name || '告警详情'}</div>
            <div className="screen-header__subtitle">查看异常上下文、状态流转和可执行处理动作。</div>
          </div>
        </div>

        <div className="surface-card detail-summary-card">
          <div className="surface-card__eyebrow">alert detail</div>
          <div className="surface-card__title">告警事件</div>
          <div className="surface-card__meta">
            <span className="soft-chip">状态 {alert.status}</span>
            <span className="soft-chip">设备 {alert.device?.name || '未知设备'}</span>
            <span className="soft-chip">触发时间 {new Date(alert.triggered_at).toLocaleTimeString()}</span>
          </div>
        </div>

        <div className="alert-detail-highlight-card">
          <div className="alert-detail-highlight-card__icon">!</div>
          <div style={{ flex: 1 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 8 }}>
              <span style={{ fontSize: 18, fontWeight: 700, color: 'var(--color-text)' }}>{alert.rule?.name || '未知规则'}</span>
              <StatusTag status={alert.status} />
            </div>
            <div style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
              {alert.device?.name || '未知设备'} · {alert.device?.location || '未知位置'}
            </div>
            <div style={{ marginTop: 8 }}>
              <RelativeTime date={alert.triggered_at} />
            </div>
          </div>
        </div>

        <div className="surface-card detail-section-card" style={{ marginBottom: 16 }}>
          {infoItems.map((item) => (
            <div key={item.label} className="detail-row" style={{ padding: '10px 0', borderBottom: '1px solid var(--color-border)' }}>
              <span className="detail-row__label">{item.label}</span>
              <span className="detail-row__value" style={{ fontWeight: 500 }}>{item.value}</span>
            </div>
          ))}
        </div>

        <div className="soft-actions soft-actions--stacked">
          {alert.status === 'triggered' && (
            <Button block color="warning" onClick={handleAck} style={{ borderRadius: 18, flex: 1 }}>
              确认告警
            </Button>
          )}
          {alert.status !== 'resolved' && (
            <Button block color="success" onClick={handleResolve} style={{ borderRadius: 18, flex: 1 }}>
              标记解决
            </Button>
          )}
          {alert.device && (
            <Button
              block
              color="primary"
              fill="outline"
              onClick={() => navigate(`/mobile/device/${alert.device!.id}`)}
              style={{ borderRadius: 18, flex: 1 }}
            >
              查看设备
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
