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
      className="room-showcase-card room-showcase-card--interactive"
      onClick={onClick}
      style={{
        cursor: onClick ? 'pointer' : 'default',
        marginBottom: 12,
      }}
    >
      <div className="room-showcase-card__overlay" />
      <div style={{ position: 'relative', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12, gap: 12 }}>
        <div>
          <div style={{ fontSize: 20, fontWeight: 700, color: '#fff', marginBottom: 6 }}>{location}</div>
          <div style={{ fontSize: 13, color: 'rgba(255,255,255,0.8)' }}>{deviceCount} 台设备 · {onlineCount} 台在线</div>
        </div>
        <div style={{ padding: '7px 10px', borderRadius: 999, background: 'rgba(255,255,255,0.16)', fontSize: 12, color: '#fff' }}>
          进入房间
        </div>
      </div>
      {metrics.length > 0 && (
        <div className="metric-pill-list" style={{ position: 'relative' }}>
          {metrics.slice(0, 4).map((m) => {
            const meta = lookup(m.metric_key);
            return (
              <div key={m.metric_key} className="metric-pill metric-pill--light">
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
