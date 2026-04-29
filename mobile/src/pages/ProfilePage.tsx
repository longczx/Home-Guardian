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
  const { theme, paletteId, setTheme } = useTheme();

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
      <div className="profile-head-card">
        <div className="profile-head-card__avatar">{user?.username?.[0]?.toUpperCase() || 'U'}</div>
        <div>
          <div className="profile-head-card__title">{user?.username || '用户'}</div>
          <div className="profile-head-card__subtitle">{user?.roles?.join(', ') || '普通用户'}</div>
        </div>
        <div className="profile-head-card__meta">
          <span>主题 {currentThemeLabel}</span>
          <span>色板 {paletteId === 'custom' ? '自定义' : '预设'}</span>
        </div>
      </div>

      <div className="section-row">
        <span className="section-title">设置中心</span>
      </div>

      <div className="surface-card" style={{ padding: '4px 0' }}>
        <div className="inline-list">
          <button className="inline-list__item" onClick={handleThemeChange}>
            <span className="inline-list__meta"><SetOutline />主题</span>
            <span className="inline-list__tail">{currentThemeLabel}</span>
          </button>
          <button className="inline-list__item" onClick={() => navigate('/mobile/profile/theme')}>
            <span className="inline-list__meta"><span style={{ fontSize: 18 }}>🎨</span>色系与外观</span>
            <span className="inline-list__tail"><RightOutline /></span>
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
            background: '#fff',
            borderRadius: '24px',
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
