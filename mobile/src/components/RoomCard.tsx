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
    <div
      className="glass-card glass-card--interactive"
      onClick={onClick}
      style={{
        cursor: onClick ? 'pointer' : 'default',
        marginBottom: 12,
        padding: '16px 16px 18px',
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12, gap: 12 }}>
        <div>
          <div style={{ fontSize: 17, fontWeight: 700, color: 'var(--color-text)', marginBottom: 6 }}>{location}</div>
          <div style={{ fontSize: 12, color: 'var(--color-text-secondary)' }}>{deviceCount} 台设备 · {onlineCount} 台在线</div>
        </div>
        <div style={{ padding: '7px 10px', borderRadius: 999, background: 'var(--color-fill)', fontSize: 12, color: 'var(--color-text-secondary)' }}>
          进入房间
        </div>
      </div>
      {metrics.length > 0 && (
        <div className="metric-pill-list">
          {metrics.slice(0, 4).map((m) => {
            const meta = lookup(m.metric_key);
            return (
              <div key={m.metric_key} className="metric-pill">
                <span style={{ fontSize: 14 }}>{meta.icon}</span>
                <strong>{typeof m.value === 'number' ? m.value.toFixed(1) : String(m.value)}</strong>
                <span>{meta.unit || meta.label}</span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
