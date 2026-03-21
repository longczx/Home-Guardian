import { Card } from 'antd-mobile';
import type { LatestMetric } from '@/api/telemetry';
import type { MetricMeta } from '@/utils/metricLookup';

interface RoomCardProps {
  location: string;
  deviceCount: number;
  onlineCount: number;
  metrics?: LatestMetric[];
  metricLookup?: (key: string) => MetricMeta;
  onClick?: () => void;
}

export default function RoomCard({ location, deviceCount, onlineCount, metrics = [], metricLookup, onClick }: RoomCardProps) {
  const lookup = metricLookup || ((key: string) => ({ label: key, unit: '', icon: '📊' }));

  return (
    <Card
      onClick={onClick}
      style={{
        borderRadius: 'var(--card-radius)',
        boxShadow: 'var(--card-shadow)',
        cursor: onClick ? 'pointer' : 'default',
        background: 'var(--color-bg-card)',
        marginBottom: 12,
      }}
      bodyStyle={{ padding: '14px 16px' }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--color-text)' }}>{location}</div>
        <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>
          {onlineCount}/{deviceCount} 在线
        </div>
      </div>
      {metrics.length > 0 && (
        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
          {metrics.slice(0, 4).map((m) => {
            const meta = lookup(m.metric_key);
            return (
              <div key={m.metric_key} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                <span style={{ fontSize: 14 }}>{meta.icon}</span>
                <span style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text)' }}>
                  {typeof m.value === 'number' ? m.value.toFixed(1) : String(m.value)}
                </span>
                <span style={{ fontSize: 11, color: 'var(--color-text-tertiary)' }}>
                  {meta.unit}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </Card>
  );
}
