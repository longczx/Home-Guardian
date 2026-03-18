function getRelativeTimeString(dateStr: string): string {
  const now = Date.now();
  const then = new Date(dateStr).getTime();
  const diff = now - then;

  if (diff < 0) return '刚刚';

  const seconds = Math.floor(diff / 1000);
  if (seconds < 60) return '刚刚';

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}分钟前`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}小时前`;

  const days = Math.floor(hours / 24);
  if (days < 30) return `${days}天前`;

  const months = Math.floor(days / 30);
  if (months < 12) return `${months}个月前`;

  return `${Math.floor(months / 12)}年前`;
}

interface RelativeTimeProps {
  date: string;
  style?: React.CSSProperties;
}

export default function RelativeTime({ date, style }: RelativeTimeProps) {
  return (
    <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)', ...style }}>
      {getRelativeTimeString(date)}
    </span>
  );
}
