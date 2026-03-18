import { NavBar } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';

export default function AboutPage() {
  const navigate = useNavigate();

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        关于
      </NavBar>

      <div style={{ padding: '48px 24px', textAlign: 'center' }}>
        <div style={{
          width: 80, height: 80, borderRadius: 20, margin: '0 auto 16px',
          background: 'linear-gradient(135deg, var(--color-primary), #764ba2)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontSize: 36, color: '#fff', fontWeight: 700,
        }}>
          HG
        </div>
        <div style={{ fontSize: 22, fontWeight: 700, color: 'var(--color-text)', marginBottom: 4 }}>
          Home Guardian
        </div>
        <div style={{ fontSize: 14, color: 'var(--color-text-tertiary)', marginBottom: 32 }}>
          v1.0.0
        </div>

        <div style={{
          background: 'var(--color-bg-card)',
          borderRadius: 'var(--card-radius)',
          padding: 20,
          boxShadow: 'var(--card-shadow)',
          textAlign: 'left',
        }}>
          {[
            { label: '应用名称', value: 'Home Guardian' },
            { label: '描述', value: '智能家居控制面板' },
            { label: '技术栈', value: 'React 19 + TypeScript + antd-mobile' },
            { label: '许可', value: 'MIT License' },
          ].map((item) => (
            <div key={item.label} style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 0', borderBottom: '1px solid var(--color-border)' }}>
              <span style={{ fontSize: 14, color: 'var(--color-text-tertiary)' }}>{item.label}</span>
              <span style={{ fontSize: 14, color: 'var(--color-text)' }}>{item.value}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
