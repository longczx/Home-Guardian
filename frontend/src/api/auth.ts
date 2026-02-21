import request from './request'

export interface LoginParams {
  username: string
  password: string
}

export interface AuthTokens {
  access_token: string
  refresh_token: string
}

export interface UserProfile {
  id: number
  username: string
  nickname: string
  email: string
  phone: string
  status: string
  roles: Array<{ id: number; name: string; display_name: string }>
  created_at: string
}

export function loginApi(data: LoginParams) {
  return request.post<any, { data: AuthTokens }>('/auth/login', data)
}

export function refreshTokenApi(refresh_token: string) {
  return request.post<any, { data: AuthTokens }>('/auth/refresh', { refresh_token })
}

export function getProfileApi() {
  return request.get<any, { data: UserProfile }>('/auth/me')
}

export function logoutApi() {
  return request.post('/auth/logout')
}

export function logoutAllApi() {
  return request.post('/auth/logout-all')
}
