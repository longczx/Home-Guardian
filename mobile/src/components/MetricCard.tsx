import type { ReactNode } from 'react';

interface MetricCardProps {
  icon?: ReactNode;
  label: string;
  value: string | number;
  unit?: string;
  trend?: 'up' | 'down' | null;
}

export default function MetricCard({ icon, label, value, unit, trend }: MetricCardProps) {
  return (
    <div className="glass-card glass-card--soft" style={{ padding: '14px 14px 16px' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        {icon && <span style={{ fontSize: 18, color: 'var(--color-primary-strong)' }}>{icon}</span>}
        <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)', fontWeight: 700, letterSpacing: '0.06em', textTransform: 'uppercase' }}>{label}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 2 }}>
        <span style={{ fontSize: 28, fontWeight: 700, color: 'var(--color-text)', letterSpacing: '-0.03em' }}>{value}</span>
        {unit && <span style={{ fontSize: 12, color: 'var(--color-text-tertiary)' }}>{unit}</span>}
        {trend && (
          <span style={{
            fontSize: 14, marginLeft: 4,
            color: trend === 'up' ? 'var(--color-danger)' : 'var(--color-success)',
          }}>
            {trend === 'up' ? '↑' : '↓'}
          </span>
        )}
      </div>
    </div>
  );
}
