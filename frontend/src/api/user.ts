import request from './request'

export function getUsers(params?: Record<string, any>) {
  return request.get<any, any>('/users', { params })
}

export function getUser(id: number) {
  return request.get<any, any>(`/users/${id}`)
}

export function createUser(data: Record<string, any>) {
  return request.post<any, any>('/users', data)
}

export function updateUser(id: number, data: Record<string, any>) {
  return request.put<any, any>(`/users/${id}`, data)
}

export function deleteUser(id: number) {
  return request.delete<any, any>(`/users/${id}`)
}

export function getRoles(params?: Record<string, any>) {
  return request.get<any, any>('/roles', { params })
}

export function getRole(id: number) {
  return request.get<any, any>(`/roles/${id}`)
}

export function createRole(data: Record<string, any>) {
  return request.post<any, any>('/roles', data)
}

export function updateRole(id: number, data: Record<string, any>) {
  return request.put<any, any>(`/roles/${id}`, data)
}

export function deleteRole(id: number) {
  return request.delete<any, any>(`/roles/${id}`)
}
