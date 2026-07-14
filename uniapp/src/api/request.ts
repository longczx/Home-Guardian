import { useServerStore } from '@/stores/server';
import { useAuthStore } from '@/stores/auth';

/**
 * uni.request 封装
 *
 * - baseURL 取当前服务器（多服务器切换即换基址）
 * - 自动注入 JWT；401 时用 refresh_token 换新并重放一次
 * - 后端统一响应 { code, message, data }：code!==0 视为业务错误
 * - refresh 也失败 → 清登录态并跳登录页
 */

export interface ApiResponse<T = unknown> {
  code: number;
  message: string;
  data: T;
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  data?: Record<string, unknown>;
  params?: Record<string, string | number | boolean | undefined>;
  /** 跳过鉴权（登录/注册/刷新接口） */
  auth?: boolean;
  _retry?: boolean;
}

/** 把回调式 uni.request 包成 Promise，规避不同 @dcloudio/types 版本的 Promise 类型差异 */
function uniRequest(options: UniApp.RequestOptions): Promise<UniApp.RequestSuccessCallbackResult> {
  return new Promise((resolve, reject) => {
    uni.request({
      ...options,
      success: (res) => resolve(res),
      fail: (err) => reject(err),
    });
  });
}

function buildQuery(params?: RequestOptions['params']): string {
  if (!params) return '';
  const pairs = Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
  return pairs.length ? `?${pairs.join('&')}` : '';
}

// 刷新期间并发请求排队，拿到新 token 后统一重放
let isRefreshing = false;
let waiters: Array<(token: string | null) => void> = [];

function onRefreshed(token: string | null) {
  waiters.forEach((cb) => cb(token));
  waiters = [];
}

async function doRefresh(): Promise<string | null> {
  const server = useServerStore();
  const auth = useAuthStore();
  const refreshToken = auth.refreshToken;
  if (!refreshToken) return null;

  try {
    const res = await uniRequest({
      url: `${server.apiBase}/auth/refresh`,
      method: 'POST',
      data: { refresh_token: refreshToken },
      header: { 'Content-Type': 'application/json' },
    });
    const body = res.data as ApiResponse<{ access_token: string; refresh_token: string }>;
    if (res.statusCode === 200 && body.code === 0) {
      auth.setTokens(body.data.access_token, body.data.refresh_token);
      return body.data.access_token;
    }
  } catch {
    /* fallthrough */
  }
  return null;
}

function redirectToLogin() {
  useAuthStore().logout();
  uni.reLaunch({ url: '/pages/auth/login' });
}

export async function apiRequest<T = unknown>(url: string, options: RequestOptions = {}): Promise<T> {
  const server = useServerStore();
  const auth = useAuthStore();
  const { method = 'GET', data, params, auth: needAuth = true } = options;

  if (!server.apiBase) {
    uni.reLaunch({ url: '/pages/server/list' });
    throw new Error('未配置服务器');
  }

  const header: Record<string, string> = { 'Content-Type': 'application/json' };
  if (needAuth && auth.accessToken) {
    header.Authorization = `Bearer ${auth.accessToken}`;
  }

  const res = await uniRequest({
    url: `${server.apiBase}${url}${buildQuery(params)}`,
    method,
    data,
    header,
  });

  const body = res.data as ApiResponse<T>;

  // 401：尝试刷新后重放一次
  if (res.statusCode === 401 && needAuth && !options._retry) {
    if (isRefreshing) {
      const token = await new Promise<string | null>((resolve) => waiters.push(resolve));
      if (!token) throw new Error('登录已失效');
      return apiRequest<T>(url, { ...options, _retry: true });
    }
    isRefreshing = true;
    const token = await doRefresh();
    isRefreshing = false;
    onRefreshed(token);
    if (!token) {
      redirectToLogin();
      throw new Error('登录已失效');
    }
    return apiRequest<T>(url, { ...options, _retry: true });
  }

  if (res.statusCode < 200 || res.statusCode >= 300) {
    throw new Error(body?.message || `请求失败 (${res.statusCode})`);
  }
  if (body.code !== 0) {
    throw new Error(body.message || '业务错误');
  }
  return body.data;
}

export default {
  get: <T = unknown>(url: string, params?: RequestOptions['params'], opts?: RequestOptions) =>
    apiRequest<T>(url, { ...opts, method: 'GET', params }),
  post: <T = unknown>(url: string, data?: RequestOptions['data'], opts?: RequestOptions) =>
    apiRequest<T>(url, { ...opts, method: 'POST', data }),
  put: <T = unknown>(url: string, data?: RequestOptions['data'], opts?: RequestOptions) =>
    apiRequest<T>(url, { ...opts, method: 'PUT', data }),
  patch: <T = unknown>(url: string, data?: RequestOptions['data'], opts?: RequestOptions) =>
    apiRequest<T>(url, { ...opts, method: 'PATCH', data }),
  del: <T = unknown>(url: string, data?: RequestOptions['data'], opts?: RequestOptions) =>
    apiRequest<T>(url, { ...opts, method: 'DELETE', data }),
};
