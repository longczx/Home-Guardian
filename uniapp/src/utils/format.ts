/** 相对时间："刚刚 / 5 分钟前 / 3 小时前 / 2 天前"，超过 30 天回退日期 */
export function timeAgo(iso: string | null): string {
  if (!iso) return '-';
  const t = new Date(iso.replace(/-/g, '/')).getTime();
  if (Number.isNaN(t)) return iso;
  const diff = Date.now() - t;
  const min = Math.floor(diff / 60000);
  if (min < 1) return '刚刚';
  if (min < 60) return `${min} 分钟前`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr} 小时前`;
  const day = Math.floor(hr / 24);
  if (day < 30) return `${day} 天前`;
  return iso.slice(0, 10);
}

export function severityLabel(sev: string | null): string {
  return sev === 'critical' ? '严重' : sev === 'info' ? '提醒' : '警告';
}

export function severityColor(sev: string | null): string {
  return sev === 'critical' ? '#e5484d' : sev === 'info' ? '#4a83e8' : '#f0980b';
}
