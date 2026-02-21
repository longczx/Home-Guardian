import request from './request'

export function getNotificationChannels(params?: Record<string, any>) {
  return request.get<any, any>('/notification-channels', { params })
}

export function getNotificationChannel(id: number) {
  return request.get<any, any>(`/notification-channels/${id}`)
}

export function createNotificationChannel(data: Record<string, any>) {
  return request.post<any, any>('/notification-channels', data)
}

export function updateNotificationChannel(id: number, data: Record<string, any>) {
  return request.put<any, any>(`/notification-channels/${id}`, data)
}

export function deleteNotificationChannel(id: number) {
  return request.delete<any, any>(`/notification-channels/${id}`)
}

export function testNotificationChannel(id: number) {
  return request.post<any, any>(`/notification-channels/${id}/test`)
}
