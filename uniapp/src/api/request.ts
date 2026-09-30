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

interface RequestContext { id: string; base: string; revision: number }
const refreshJobs = new Map<string, Promise<string | null>>();

function assertCurrent(context: RequestContext) {
  const server = useServerStore();
  if (server.currentId !== context.id || server.apiBase !== context.base || server.connectionRevision !== context.revision) {
    throw new Error('服务器已切换，已忽略原服务器响应');
  }
}

async function doRefresh(context: RequestContext): Promise<string | null> {
  const auth = useAuthStore();
  const refreshToken = auth.byServer[context.id]?.refreshToken;
  if (!refreshToken) return null;
  try {
    const res = await uniRequest({
      url: `${context.base}/auth/refresh`, method: 'POST', data: { refresh_token: refreshToken },
      header: { 'Content-Type': 'application/json' },
    });
    const body = res.data as ApiResponse<{ access_token: string; refresh_token: string }>;
    if (res.statusCode === 200 && body.code === 0 && auth.byServer[context.id]?.refreshToken === refreshToken) {
      auth.setTokens(body.data.access_token, body.data.refresh_token, context.id);
      return body.data.access_token;
    }
  } catch { /* 返回 null，由原服务器请求处理登录失效 */ }
  return null;
}

async function execute<T>(url: string, options: RequestOptions, context: RequestContext): Promise<T> {
  assertCurrent(context);
  const auth = useAuthStore();
  const { method = 'GET', data, params, auth: needAuth = true } = options;
  const header: Record<string, string> = { 'Content-Type': 'application/json' };
  const token = auth.byServer[context.id]?.accessToken;
  if (needAuth && token) header.Authorization = `Bearer ${token}`;
  const res = await uniRequest({ url: `${context.base}${url}${buildQuery(params)}`, method: method as UniApp.RequestOptions['method'], data, header });
  assertCurrent(context);
  const body = res.data as ApiResponse<T>;
  if (res.statusCode === 401 && needAuth && !options._retry) {
    const key = `${context.id}:${context.base}`;
    let job = refreshJobs.get(key);
    if (!job) {
      job = doRefresh(context).finally(() => refreshJobs.delete(key));
      refreshJobs.set(key, job);
    }
    const expectedRefreshToken = auth.byServer[context.id]?.refreshToken;
    const freshToken = await job;
    assertCurrent(context);
    if (!freshToken) {
      if (auth.byServer[context.id]?.refreshToken !== expectedRefreshToken) throw new Error('登录状态已更新，请重新请求');
      auth.logout(context.id);
      uni.reLaunch({ url: '/pages/auth/login' });
      throw new Error('登录已失效');
    }
    return execute<T>(url, { ...options, _retry: true }, context);
  }
  if (res.statusCode < 200 || res.statusCode >= 300) throw new Error(body?.message || `请求失败 (${res.statusCode})`);
  if (body?.code !== 0) throw new Error(body?.message || '业务错误');
  return body.data;
}

export async function apiRequest<T = unknown>(url: string, options: RequestOptions = {}): Promise<T> {
  const server = useServerStore();
  if (!server.apiBase) {
    uni.reLaunch({ url: '/pages/server/list' });
    throw new Error('未配置服务器');
  }
  return execute<T>(url, options, { id: server.currentId, base: server.apiBase, revision: server.connectionRevision });
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
