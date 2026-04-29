const APP_TIME_ZONE = 'Asia/Shanghai';
export const APP_TIME_ZONE_LABEL = 'UTC+08';

export function normalizeBackendTimestamp(value: unknown): string | undefined {
  if (typeof value !== 'string') {
    return undefined;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  if (/[zZ]$|[+-]\d{2}:\d{2}$/.test(trimmed)) {
    return trimmed;
  }

  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(trimmed)) {
    return `${trimmed.replace(' ', 'T')}+08:00`;
  }

  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(trimmed)) {
    return `${trimmed}+08:00`;
  }

  return trimmed;
}

export function parseBackendDate(value: unknown): Date | null {
  const normalized = normalizeBackendTimestamp(value);
  if (!normalized) {
    return null;
  }

  const parsed = new Date(normalized);
  if (Number.isNaN(parsed.getTime())) {
    return null;
  }

  return parsed;
}

function formatInAppTimeZone(date: Date, options: Intl.DateTimeFormatOptions): string {
  return new Intl.DateTimeFormat('zh-CN', {
    timeZone: APP_TIME_ZONE,
    hour12: false,
    ...options,
  }).format(date);
}

export function formatTelemetryAxisLabel(value: string, range: string): string {
  const date = parseBackendDate(value);
  if (!date) {
    return '--';
  }

  if (range === '7d' || range === '30d') {
    return formatInAppTimeZone(date, { month: 'numeric', day: 'numeric' }).replace('/', '/');
  }

  return formatInAppTimeZone(date, { hour: '2-digit', minute: '2-digit' });
}

export function getTelemetryRange(range: string): [string, string] {
  const now = new Date();
  const msMap: Record<string, number> = {
    '1h': 3600000,
    '24h': 86400000,
    '7d': 604800000,
    '30d': 2592000000,
  };
  const duration = msMap[range] || 86400000;
  return [new Date(now.getTime() - duration).toISOString(), now.toISOString()];
}

export function getTelemetrySourceLabel(range: string): string {
  return range === '1h' || range === '24h' ? '原始遥测 · 5 分钟分桶' : '小时聚合视图';
}