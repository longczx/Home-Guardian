import type { ReactNode } from 'react';

interface StatCardProps {
  icon: ReactNode;
  label: string;
  value: number | string;
  color?: string;
  onClick?: () => void;
}

export default function StatCard({ icon, label, value, color = 'var(--color-primary)', onClick }: StatCardProps) {
  return (
    <div
      className="glass-card glass-card--interactive"
      onClick={onClick}
      style={{
        cursor: onClick ? 'pointer' : 'default',
        padding: '16px 14px',
      }}
    >
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 14 }}>
        <div style={{
          width: 42, height: 42, borderRadius: 14,
          background: color + '18',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontSize: 20, color,
        }}>
          {icon}
        </div>
        <div style={{ width: '100%' }}>
          <div style={{ fontSize: 27, fontWeight: 700, lineHeight: 1, color: 'var(--color-text)', letterSpacing: '-0.03em' }}>{value}</div>
          <div style={{ fontSize: 12, color: 'var(--color-text-secondary)', marginTop: 7, fontWeight: 600 }}>{label}</div>
        </div>
      </div>
    </div>
  );
}
