import request from './request'

export function getAutomations(params?: Record<string, any>) {
  return request.get<any, any>('/automations', { params })
}

export function getAutomation(id: number) {
  return request.get<any, any>(`/automations/${id}`)
}

export function createAutomation(data: Record<string, any>) {
  return request.post<any, any>('/automations', data)
}

export function updateAutomation(id: number, data: Record<string, any>) {
  return request.put<any, any>(`/automations/${id}`, data)
}

export function deleteAutomation(id: number) {
  return request.delete<any, any>(`/automations/${id}`)
}
