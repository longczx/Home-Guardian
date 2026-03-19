import { useState, useEffect } from 'react';
import { NavBar, List, Switch, Button, Dialog, Toast, Tag } from 'antd-mobile';
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
    getNotificationChannels().then(({ data: res }) => {
      if (res.code === 0) setChannels(res.data);
    }).finally(() => setLoading(false));
  };

  useEffect(() => { fetch(); }, []);

  const handleToggle = async (ch: NotificationChannel, enabled: boolean) => {
    try {
      await updateNotificationChannel(ch.id, { is_enabled: enabled });
      setChannels((prev) => prev.map((c) => (c.id === ch.id ? { ...c, is_enabled: enabled } : c)));
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    }
  };

  const handleTest = async (ch: NotificationChannel) => {
    try {
      await testNotificationChannel(ch.id);
      Toast.show({ content: '测试消息已发送', icon: 'success' });
    } catch {
      Toast.show({ content: '发送失败', icon: 'fail' });
    }
  };

  const handleDelete = (ch: NotificationChannel) => {
    Dialog.confirm({
      content: `确认删除「${ch.name}」?`,
      onConfirm: async () => {
        try {
          await deleteNotificationChannel(ch.id);
          setChannels((prev) => prev.filter((c) => c.id !== ch.id));
          Toast.show({ content: '已删除', icon: 'success' });
        } catch {
          Toast.show({ content: '删除失败', icon: 'fail' });
        }
      },
    });
  };

  if (loading) return <PageLoading />;

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar
        onBack={() => navigate(-1)}
        right={
          <AddOutline fontSize={22} onClick={() => navigate('/mobile/notification-channels/create')} style={{ color: 'var(--color-primary)' }} />
        }
        style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}
      >
        通知渠道
      </NavBar>

      {channels.length === 0 ? (
        <EmptyState title="暂无通知渠道" />
      ) : (
        <List style={{ '--border-top': 'none' } as React.CSSProperties}>
          {channels.map((ch) => (
            <List.Item
              key={ch.id}
              onClick={() => navigate(`/mobile/notification-channels/${ch.id}/edit`)}
              description={
                <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
                  <Button size="mini" color="primary" fill="outline" onClick={(e) => { e.stopPropagation(); handleTest(ch); }}>
                    测试
                  </Button>
                  <Button size="mini" color="danger" fill="outline" onClick={(e) => { e.stopPropagation(); handleDelete(ch); }}>
                    删除
                  </Button>
                </div>
              }
              extra={
                <div onClick={(e) => e.stopPropagation()}>
                  <Switch
                    checked={ch.is_enabled}
                    onChange={(v) => handleToggle(ch, v)}
                  />
                </div>
              }
              style={{ background: 'var(--color-bg-card)' }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontWeight: 500, color: 'var(--color-text)' }}>{ch.name}</span>
                <Tag color="primary" fill="outline" style={{ fontSize: 11 }}>
                  {typeLabels[ch.type] || ch.type}
                </Tag>
              </div>
            </List.Item>
          ))}
        </List>
      )}
    </div>
  );
}
