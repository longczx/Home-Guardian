import request from './request';

export interface NotificationChannel {
  id: number;
  name: string;
  type: 'email' | 'webhook' | 'telegram' | 'wechat_work' | 'dingtalk';
  config: Record<string, unknown>;
  is_enabled: boolean;
  created_at: string;
  updated_at: string;
}

export interface NotificationChannelForm {
  name: string;
  type: string;
  config: Record<string, unknown>;
  is_enabled?: boolean;
}

export function getNotificationChannels(params?: Record<string, string>) {
  return request.get<{ code: number; data: NotificationChannel[] }>(
    '/notification-channels', { params }
  );
}

export function getNotificationChannel(id: number) {
  return request.get<{ code: number; data: NotificationChannel }>(`/notification-channels/${id}`);
}

export function createNotificationChannel(data: NotificationChannelForm) {
  return request.post<{ code: number; data: NotificationChannel }>('/notification-channels', data);
}

export function updateNotificationChannel(id: number, data: Partial<NotificationChannelForm>) {
  return request.put<{ code: number; data: NotificationChannel }>(`/notification-channels/${id}`, data);
}

export function deleteNotificationChannel(id: number) {
  return request.delete(`/notification-channels/${id}`);
}

export function testNotificationChannel(id: number) {
  return request.post(`/notification-channels/${id}/test`);
}
