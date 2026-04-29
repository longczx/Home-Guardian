import { SpinLoading } from 'antd-mobile';

export default function PageLoading() {
  return (
    <div className="mobile-page" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="glass-card glass-card--soft" style={{ padding: '28px 32px', display: 'flex', alignItems: 'center', gap: 14 }}>
        <SpinLoading color="primary" style={{ '--size': '28px' }} />
        <span style={{ fontSize: 14, color: 'var(--color-text-secondary)', fontWeight: 600 }}>正在加载...</span>
      </div>
    </div>
  );
}
