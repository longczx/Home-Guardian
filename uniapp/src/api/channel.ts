import request from './request';

export interface NotificationChannel {
  id: number;
  name: string;
  type: string;
  config: Record<string, unknown>;
  is_enabled: boolean;
}

export interface ChannelInput {
  name: string;
  type: string;
  config: Record<string, unknown>;
  is_enabled: boolean;
}

// 后端该接口返回普通数组（非分页）
export function getChannels(params?: Record<string, string | number>) {
  return request.get<NotificationChannel[]>('/notification-channels', params);
}

export function getChannel(id: number) {
  return request.get<NotificationChannel>(`/notification-channels/${id}`);
}

export function createChannel(data: ChannelInput) {
  return request.post<NotificationChannel>('/notification-channels', data as unknown as Record<string, unknown>);
}

export function updateChannel(id: number, data: ChannelInput) {
  return request.put<NotificationChannel>(`/notification-channels/${id}`, data as unknown as Record<string, unknown>);
}

export function deleteChannel(id: number) {
  return request.del(`/notification-channels/${id}`);
}

/** 各渠道类型的配置字段定义（label + key + 是否密钥） */
export const CHANNEL_TYPES: Record<string, { label: string; fields: { key: string; label: string; secret?: boolean; placeholder?: string }[] }> = {
  email: {
    label: '邮件',
    fields: [
      { key: 'smtp_host', label: 'SMTP 主机', placeholder: 'smtp.example.com' },
      { key: 'smtp_port', label: 'SMTP 端口', placeholder: '587' },
      { key: 'smtp_user', label: '账号' },
      { key: 'smtp_pass', label: '密码', secret: true },
      { key: 'to', label: '收件人（逗号分隔）', placeholder: 'a@x.com,b@y.com' },
    ],
  },
  webhook: {
    label: 'Webhook',
    fields: [
      { key: 'url', label: 'URL', placeholder: 'https://…' },
      { key: 'method', label: '方法', placeholder: 'POST' },
    ],
  },
  telegram: {
    label: 'Telegram',
    fields: [
      { key: 'bot_token', label: 'Bot Token', secret: true },
      { key: 'chat_id', label: 'Chat ID' },
    ],
  },
  wechat_work: {
    label: '企业微信',
    fields: [{ key: 'webhook_url', label: '机器人 Webhook', secret: true }],
  },
  dingtalk: {
    label: '钉钉',
    fields: [
      { key: 'webhook_url', label: '机器人 Webhook', secret: true },
      { key: 'secret', label: '加签密钥（可选）', secret: true },
    ],
  },
  unipush: {
    label: 'App 推送 (uniPush)',
    fields: [
      { key: 'app_id', label: '个推 AppID' },
      { key: 'app_key', label: '个推 AppKey' },
      { key: 'master_secret', label: '个推 MasterSecret', secret: true },
    ],
  },
};

export function testChannel(id: number) {
  return request.post(`/notification-channels/${id}/test`);
}
