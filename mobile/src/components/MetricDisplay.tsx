interface MetricDisplayProps {
  label: string;
  value: string | number;
  unit?: string;
  icon?: React.ReactNode;
  trend?: 'up' | 'down' | null;
}

export default function MetricDisplay({ label, value, unit, icon, trend }: MetricDisplayProps) {
  return (
    <div style={{ textAlign: 'center', padding: '8px 0' }}>
      {icon && <div style={{ marginBottom: 4, color: 'var(--color-primary)' }}>{icon}</div>}
      <div style={{ fontSize: 24, fontWeight: 'bold', color: 'var(--color-text)', display: 'flex', alignItems: 'baseline', justifyContent: 'center', gap: 2 }}>
        {value}
        {unit && <span style={{ fontSize: 14, fontWeight: 'normal', color: 'var(--color-text-tertiary)' }}>{unit}</span>}
        {trend && (
          <span style={{ fontSize: 14, color: trend === 'up' ? 'var(--color-danger)' : 'var(--color-success)' }}>
            {trend === 'up' ? '↑' : '↓'}
          </span>
        )}
      </div>
      <div style={{ fontSize: 12, color: 'var(--color-text-tertiary)', marginTop: 4 }}>{label}</div>
    </div>
  );
}
