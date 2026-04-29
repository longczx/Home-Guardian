import { useState, useEffect } from 'react';
import { NavBar, Switch, Button, Dialog, Toast } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import { AddOutline } from 'antd-mobile-icons';
import {
  getNotificationChannels,
  deleteNotificationChannel,
  updateNotificationChannel,
  testNotificationChannel,
  type NotificationChannel,
} from '@/api/notificationChannel';
import EmptyState from '@/components/EmptyState';
import PageLoading from '@/components/PageLoading';
import AppTag from '@/components/AppTag';

const typeLabels: Record<string, string> = {
  email: '邮件',
  webhook: 'Webhook',
  telegram: 'Telegram',
  wechat_work: '企业微信',
  dingtalk: '钉钉',
};

export default function NotificationChannelPage() {
  const navigate = useNavigate();
  const [channels, setChannels] = useState<NotificationChannel[]>([]);
  const [loading, setLoading] = useState(true);

  const fetch = () => {
    setLoading(true);
    getNotificationChannels()
      .then(({ data: res }) => {
        if (res.code === 0) setChannels(res.data);
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetch();
  }, []);

  const handleToggle = async (channel: NotificationChannel, enabled: boolean) => {
    try {
      await updateNotificationChannel(channel.id, { is_enabled: enabled });
      setChannels((prev) => prev.map((item) => (item.id === channel.id ? { ...item, is_enabled: enabled } : item)));
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  const handleTest = async (channel: NotificationChannel) => {
    try {
      await testNotificationChannel(channel.id);
      Toast.show({ content: '测试消息已发送', icon: 'success' });
    } catch {
      Toast.show({ content: '发送失败', icon: 'fail' });
    }
  };

  const handleDelete = (channel: NotificationChannel) => {
    Dialog.confirm({
      content: `确认删除「${channel.name}」?`,
      onConfirm: async () => {
        try {
          await deleteNotificationChannel(channel.id);
          setChannels((prev) => prev.filter((item) => item.id !== channel.id));
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
        right={<AddOutline fontSize={22} onClick={() => navigate('/mobile/notification-channels/create')} style={{ color: 'var(--color-primary)' }} />}
        style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}
      >
        通知渠道
      </NavBar>

      <div className="screen-header" style={{ marginTop: 8 }}>
        <div>
          <div className="screen-header__title">通知渠道</div>
          <div className="screen-header__subtitle">把所有消息通道集中到同一套分层列表里管理。</div>
        </div>
      </div>

      <div className="surface-card detail-summary-card">
        <div className="surface-card__eyebrow">channels</div>
        <div className="surface-card__title">消息通路</div>
        <div className="surface-card__meta">
          <span className="soft-chip">渠道 {channels.length}</span>
          <span className="soft-chip">启用 {channels.filter((item) => item.is_enabled).length}</span>
          <span className="soft-chip">支持测试发送</span>
        </div>
      </div>

      {channels.length === 0 ? (
        <EmptyState title="暂无通知渠道" />
      ) : (
        <div className="list-stack">
          {channels.map((channel) => (
            <div key={channel.id} className="channel-list-card" onClick={() => navigate(`/mobile/notification-channels/${channel.id}/edit`)}>
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }}>
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontWeight: 700, color: 'var(--color-text)', fontSize: 16 }}>{channel.name}</span>
                    <AppTag tone="primary">
                      {typeLabels[channel.type] || channel.type}
                    </AppTag>
                  </div>
                  <div style={{ marginTop: 8, fontSize: 12, color: 'var(--color-text-secondary)' }}>
                    {channel.type === 'email' ? '邮件渠道' : channel.type === 'webhook' ? 'Webhook 推送' : '消息机器人渠道'}
                  </div>
                </div>
                <div onClick={(event) => event.stopPropagation()}>
                  <Switch checked={channel.is_enabled} onChange={(value) => handleToggle(channel, value)} />
                </div>
              </div>

              <div className="soft-actions" style={{ marginTop: 14 }}>
                <button
                  className="soft-button soft-button--accent"
                  onClick={(event) => {
                    event.stopPropagation();
                    handleTest(channel);
                  }}
                >
                  测试
                </button>
                <button
                  className="soft-button"
                  onClick={(event) => {
                    event.stopPropagation();
                    navigate(`/mobile/notification-channels/${channel.id}/edit`);
                  }}
                >
                  编辑
                </button>
                <button
                  className="soft-button soft-button--danger"
                  onClick={(event) => {
                    event.stopPropagation();
                    handleDelete(channel);
                  }}
                >
                  删除
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <div style={{ padding: '16px 0' }}>
        <Button block color="primary" onClick={() => navigate('/mobile/notification-channels/create')} style={{ borderRadius: 18 }}>
          创建新渠道
        </Button>
      </div>
    </div>
  );
}
