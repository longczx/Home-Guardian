import { Card } from 'antd-mobile';
import type { LatestMetric } from '@/api/telemetry';

interface RoomCardProps {
  location: string;
  deviceCount: number;
  onlineCount: number;
  metrics?: LatestMetric[];
  onClick?: () => void;
}

const METRIC_ICONS: Record<string, string> = {
  temperature: '🌡',
  humidity: '💧',
  pressure: '🌀',
  co2: '☁️',
  light: '☀️',
  pm25: '🌫',
};

const METRIC_UNITS: Record<string, string> = {
  temperature: '°C',
  humidity: '%',
  pressure: 'hPa',
  co2: 'ppm',
  light: 'lux',
  pm25: 'μg/m³',
};

export default function RoomCard({ location, deviceCount, onlineCount, metrics = [], onClick }: RoomCardProps) {
  const envMetrics = metrics.filter((m) => METRIC_ICONS[m.metric_key]);

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
      {envMetrics.length > 0 && (
        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
          {envMetrics.slice(0, 4).map((m) => (
            <div key={m.metric_key} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
              <span style={{ fontSize: 14 }}>{METRIC_ICONS[m.metric_key]}</span>
              <span style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text)' }}>
                {typeof m.value === 'number' ? m.value.toFixed(1) : String(m.value)}
              </span>
              <span style={{ fontSize: 11, color: 'var(--color-text-tertiary)' }}>
                {METRIC_UNITS[m.metric_key] || ''}
              </span>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}
