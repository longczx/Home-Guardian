import { Dialog, Toast } from 'antd-mobile';
import { useNavigate } from 'react-router-dom';
import {
  RightOutline,
  SetOutline,
  BellOutline,
  InformationCircleOutline,
  LockOutline,
} from 'antd-mobile-icons';
import { useAuthStore } from '@/stores/authStore';
import { useTheme } from '@/hooks/useTheme';
import { logout as logoutApi } from '@/api/auth';

export default function ProfilePage() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const refreshToken = useAuthStore((s) => s.refreshToken);
  const logoutStore = useAuthStore((s) => s.logout);
  const { theme, setTheme } = useTheme();

  const handleLogout = () => {
    Dialog.confirm({
      content: '确认退出登录?',
      onConfirm: async () => {
        try {
          if (refreshToken) await logoutApi(refreshToken);
        } catch { /* ignore */ }
        logoutStore();
        navigate('/mobile/login', { replace: true });
      },
    });
  };

  const themeOptions = [
    { label: '跟随系统', value: 'system' },
    { label: '浅色', value: 'light' },
    { label: '深色', value: 'dark' },
  ];

  const handleThemeChange = () => {
    const currentIdx = themeOptions.findIndex((o) => o.value === theme);
    const next = themeOptions[(currentIdx + 1) % themeOptions.length];
    setTheme(next.value as 'light' | 'dark' | 'system');
    Toast.show({ content: `已切换: ${next.label}` });
  };

  const currentThemeLabel = themeOptions.find((o) => o.value === theme)?.label || '跟随系统';

  return (
    <div className="mobile-page mobile-page--tight">
      <div className="page-hero">
        <div className="page-hero__eyebrow">profile</div>
        <div style={{
          width: 60, height: 60, borderRadius: '50%', background: 'rgba(255,255,255,0.2)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontSize: 28, color: '#fff', fontWeight: 700, marginBottom: 12,
        }}>
          {user?.username?.[0]?.toUpperCase() || 'U'}
        </div>
        <div style={{ fontSize: 22, fontWeight: 700, color: '#fff' }}>{user?.username}</div>
        <div style={{ fontSize: 13, color: 'rgba(255,255,255,0.7)', marginTop: 4 }}>
          {user?.roles?.join(', ') || '用户'}
        </div>
        <div className="page-hero__meta">
          <span className="soft-chip">主题 {currentThemeLabel}</span>
          <span className="soft-chip">通知渠道可配置</span>
        </div>
      </div>

      <div className="section-row">
        <span className="section-title">账号与设置</span>
      </div>

      <div className="glass-card" style={{ padding: '4px 0' }}>
        <div className="inline-list">
          <button className="inline-list__item" onClick={handleThemeChange}>
            <span className="inline-list__meta"><SetOutline />主题</span>
            <span className="inline-list__tail">{currentThemeLabel}</span>
          </button>
          <button className="inline-list__item" onClick={() => navigate('/mobile/profile/password')}>
            <span className="inline-list__meta"><LockOutline />修改密码</span>
            <span className="inline-list__tail"><RightOutline /></span>
          </button>
          <button className="inline-list__item" onClick={() => navigate('/mobile/notification-channels')}>
            <span className="inline-list__meta"><BellOutline />通知渠道</span>
            <span className="inline-list__tail"><RightOutline /></span>
          </button>
          <button className="inline-list__item" onClick={() => navigate('/mobile/profile/about')}>
            <span className="inline-list__meta"><InformationCircleOutline />关于</span>
            <span className="inline-list__tail"><RightOutline /></span>
          </button>
        </div>
      </div>

      <div style={{ padding: '24px 0' }}>
        <div
          onClick={handleLogout}
          style={{
            textAlign: 'center',
            padding: '14px 0',
            color: 'var(--color-danger)',
            fontSize: 16,
            fontWeight: 700,
            background: 'var(--color-bg-card)',
            borderRadius: 'var(--card-radius)',
            cursor: 'pointer',
            boxShadow: 'var(--card-shadow)',
          }}
        >
          退出登录
        </div>
      </div>
    </div>
  );
}
