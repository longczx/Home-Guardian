import { NavBar } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';

export default function AboutPage() {
  const navigate = useNavigate();

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        关于
      </NavBar>

      <div>
        <div className="page-hero" style={{ marginTop: 8, textAlign: 'center' }}>
          <div className="auth-mark" style={{ marginBottom: 16 }}>
            HG
          </div>
          <div className="page-hero__eyebrow">about</div>
          <div className="page-hero__title">Home Guardian</div>
          <div className="page-hero__subtitle">家庭环境监控、设备控制与告警管理的一体化移动控制台。</div>
          <div className="page-hero__meta" style={{ justifyContent: 'center' }}>
            <span className="soft-chip">v1.0.0</span>
            <span className="soft-chip">MIT</span>
          </div>
        </div>

        <div className="glass-card" style={{ padding: 20, textAlign: 'left', marginTop: 16 }}>
          {[
            { label: '应用名称', value: 'Home Guardian' },
            { label: '描述', value: '智能家居控制面板' },
            { label: '技术栈', value: 'React 19 + TypeScript + antd-mobile' },
            { label: '许可', value: 'MIT License' },
          ].map((item) => (
            <div key={item.label} className="detail-row" style={{ padding: '10px 0', borderBottom: '1px solid var(--color-border)' }}>
              <span className="detail-row__label">{item.label}</span>
              <span className="detail-row__value">{item.value}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
