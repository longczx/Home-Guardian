/**
 * 服务器配置二维码格式：hg://server?url=<baseURL>&name=<名称>
 * 与后台「生成配置二维码」入口一致。
 */
export interface ParsedServer {
  url: string;
  name: string;
}

export function parseServerQr(raw: string): ParsedServer | null {
  const text = (raw || '').trim();
  if (!text.startsWith('hg://server')) {
    // 也容许直接扫到一个裸 URL
    if (/^https?:\/\//i.test(text)) {
      return { url: text, name: '' };
    }
    return null;
  }
  const qs = text.split('?')[1] || '';
  const params: Record<string, string> = {};
  qs.split('&').forEach((pair) => {
    const [k, v] = pair.split('=');
    if (k) params[decodeURIComponent(k)] = decodeURIComponent(v || '');
  });
  if (!params.url) return null;
  return { url: params.url, name: params.name || '' };
}
