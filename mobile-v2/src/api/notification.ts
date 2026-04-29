import request from './request';

export interface NotificationChannel {
  id: number;
  name: string;
  description?: string;
  type: 'email' | 'webhook' | 'telegram' | 'wechat_work' | 'dingtalk';
  config: Record<string, unknown>;
  is_enabled: boolean;
  created_at: string;
}

export function getNotificationChannels() {
  return request.get<{ code: number; data: NotificationChannel[] }>('/notification-channels');
}
export function getNotificationChannel(id: number) {
  return request.get<{ code: number; data: NotificationChannel }>(`/notification-channels/${id}`);
}
export function createNotificationChannel(data: Partial<NotificationChannel>) {
  return request.post<{ code: number; data: NotificationChannel }>('/notification-channels', data);
}
export function updateNotificationChannel(id: number, data: Partial<NotificationChannel>) {
  return request.put<{ code: number; data: NotificationChannel }>(`/notification-channels/${id}`, data);
}
export function deleteNotificationChannel(id: number) {
  return request.delete(`/notification-channels/${id}`);
}
export function testNotificationChannel(id: number) {
  return request.post(`/notification-channels/${id}/test`);
}
export function toggleNotificationChannel(id: number, enabled: boolean) {
  return request.put(`/notification-channels/${id}/toggle`, { is_enabled: enabled });
}
