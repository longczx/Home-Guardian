import { useState, useEffect } from 'react';
import { NavBar, Form, Input, Button, Picker, Switch, Toast } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import {
  getNotificationChannel,
  createNotificationChannel,
  updateNotificationChannel,
  type NotificationChannel,
} from '@/api/notificationChannel';

const CHANNEL_TYPES = [
  { label: '邮件', value: 'email' },
  { label: 'Webhook', value: 'webhook' },
  { label: 'Telegram', value: 'telegram' },
  { label: '企业微信', value: 'wechat_work' },
  { label: '钉钉', value: 'dingtalk' },
];

const typeLabels: Record<string, string> = {
  email: '邮件',
  webhook: 'Webhook',
  telegram: 'Telegram',
  wechat_work: '企业微信',
  dingtalk: '钉钉',
};

interface ConfigFields {
  [key: string]: string | undefined;
  smtp_host?: string;
  smtp_port?: string;
  smtp_user?: string;
  smtp_pass?: string;
  from_address?: string;
  url?: string;
  bot_token?: string;
  chat_id?: string;
  webhook_url?: string;
  secret?: string;
}

export default function NotificationChannelFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEdit = !!id;

  const [name, setName] = useState('');
  const [type, setType] = useState<NotificationChannel['type']>('email');
  const [enabled, setEnabled] = useState(true);
  const [config, setConfig] = useState<ConfigFields>({});
  const [submitting, setSubmitting] = useState(false);
  const [typePickerVisible, setTypePickerVisible] = useState(false);

  useEffect(() => {
    if (!id) return;
    getNotificationChannel(parseInt(id)).then(({ data: res }) => {
      if (res.code === 0) {
        const ch = res.data;
        setName(ch.name);
        setType(ch.type);
        setEnabled(ch.is_enabled);
        setConfig(ch.config as ConfigFields);
      }
    });
  }, [id]);

  const updateConfig = (key: string, value: string) => {
    setConfig((prev) => ({ ...prev, [key]: value }));
  };

  const handleSubmit = async () => {
    if (!name) {
      Toast.show({ content: '请填写渠道名称', icon: 'fail' });
      return;
    }
    setSubmitting(true);
    const payload = { name, type, config, is_enabled: enabled };
    try {
      if (isEdit) {
        await updateNotificationChannel(parseInt(id!), payload);
        Toast.show({ content: '更新成功', icon: 'success' });
      } else {
        await createNotificationChannel(payload);
        Toast.show({ content: '创建成功', icon: 'success' });
      }
      navigate(-1);
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    } finally {
      setSubmitting(false);
    }
  };

  const renderConfigFields = () => {
    switch (type) {
      case 'email':
        return (
          <>
            <Form.Item label="SMTP 主机">
              <Input value={config.smtp_host || ''} onChange={(v) => updateConfig('smtp_host', v)} placeholder="smtp.example.com" />
            </Form.Item>
            <Form.Item label="SMTP 端口">
              <Input value={config.smtp_port || ''} onChange={(v) => updateConfig('smtp_port', v)} placeholder="465" />
            </Form.Item>
            <Form.Item label="用户名">
              <Input value={config.smtp_user || ''} onChange={(v) => updateConfig('smtp_user', v)} placeholder="user@example.com" />
            </Form.Item>
            <Form.Item label="密码">
              <Input value={config.smtp_pass || ''} onChange={(v) => updateConfig('smtp_pass', v)} type="password" placeholder="SMTP 密码" />
            </Form.Item>
            <Form.Item label="发件地址">
              <Input value={config.from_address || ''} onChange={(v) => updateConfig('from_address', v)} placeholder="noreply@example.com" />
            </Form.Item>
          </>
        );
      case 'webhook':
        return (
          <Form.Item label="Webhook URL">
            <Input value={config.url || ''} onChange={(v) => updateConfig('url', v)} placeholder="https://example.com/webhook" />
          </Form.Item>
        );
      case 'telegram':
        return (
          <>
            <Form.Item label="Bot Token">
              <Input value={config.bot_token || ''} onChange={(v) => updateConfig('bot_token', v)} placeholder="123456:ABC-DEF..." />
            </Form.Item>
            <Form.Item label="Chat ID">
              <Input value={config.chat_id || ''} onChange={(v) => updateConfig('chat_id', v)} placeholder="-1001234567890" />
            </Form.Item>
          </>
        );
      case 'wechat_work':
        return (
          <Form.Item label="Webhook URL">
            <Input value={config.webhook_url || ''} onChange={(v) => updateConfig('webhook_url', v)} placeholder="https://qyapi.weixin.qq.com/..." />
          </Form.Item>
        );
      case 'dingtalk':
        return (
          <>
            <Form.Item label="Webhook URL">
              <Input value={config.webhook_url || ''} onChange={(v) => updateConfig('webhook_url', v)} placeholder="https://oapi.dingtalk.com/..." />
            </Form.Item>
            <Form.Item label="签名密钥">
              <Input value={config.secret || ''} onChange={(v) => updateConfig('secret', v)} placeholder="SEC..." />
            </Form.Item>
          </>
        );
      default:
        return null;
    }
  };

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        {isEdit ? '编辑通知渠道' : '创建通知渠道'}
      </NavBar>

      <Form layout="horizontal" style={{ '--border-top': 'none', '--border-bottom': 'none' } as React.CSSProperties}>
        <Form.Header>基本信息</Form.Header>
        <Form.Item label="名称">
          <Input value={name} onChange={setName} placeholder="渠道名称" />
        </Form.Item>
        <Form.Item label="类型" onClick={() => !isEdit && setTypePickerVisible(true)}>
          <span style={{ color: 'var(--color-text)' }}>{typeLabels[type] || type}</span>
        </Form.Item>
        <Picker
          columns={[CHANNEL_TYPES]}
          visible={typePickerVisible}
          onClose={() => setTypePickerVisible(false)}
          onConfirm={(v) => {
            if (v[0]) {
              setType(v[0] as NotificationChannel['type']);
              setConfig({});
            }
          }}
          value={[type]}
        />
        <Form.Item label="启用">
          <Switch checked={enabled} onChange={setEnabled} />
        </Form.Item>

        <Form.Header>渠道配置</Form.Header>
        {renderConfigFields()}
      </Form>

      <div style={{ padding: '16px 16px 24px' }}>
        <Button block color="primary" loading={submitting} onClick={handleSubmit} style={{ borderRadius: 8 }}>
          {isEdit ? '保存修改' : '创建渠道'}
        </Button>
      </div>
    </div>
  );
}
