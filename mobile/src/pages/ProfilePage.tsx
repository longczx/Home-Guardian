import { List, Dialog, Toast } from 'antd-mobile';
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
    <div style={{ background: 'var(--color-bg)', minHeight: '100%' }}>
      {/* User header */}
      <div style={{
        background: 'linear-gradient(135deg, var(--color-primary), #764ba2)',
        padding: '48px 24px 32px',
        borderRadius: '0 0 24px 24px',
      }}>
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
      </div>

      {/* Settings */}
      <div style={{ padding: '16px 16px 0' }}>
        <List style={{ '--border-top': 'none', borderRadius: 'var(--card-radius)', overflow: 'hidden' } as React.CSSProperties}>
          <List.Item
            prefix={<SetOutline />}
            onClick={handleThemeChange}
            extra={<span style={{ color: 'var(--color-text-tertiary)' }}>{currentThemeLabel}</span>}
            arrow={false}
          >
            主题
          </List.Item>
          <List.Item
            prefix={<LockOutline />}
            onClick={() => navigate('/mobile/profile/password')}
            arrow={<RightOutline />}
          >
            修改密码
          </List.Item>
          <List.Item
            prefix={<BellOutline />}
            onClick={() => navigate('/mobile/notification-channels')}
            arrow={<RightOutline />}
          >
            通知渠道
          </List.Item>
          <List.Item
            prefix={<InformationCircleOutline />}
            onClick={() => navigate('/mobile/profile/about')}
            arrow={<RightOutline />}
          >
            关于
          </List.Item>
        </List>
      </div>

      <div style={{ padding: '24px 16px' }}>
        <div
          onClick={handleLogout}
          style={{
            textAlign: 'center',
            padding: '12px 0',
            color: 'var(--color-danger)',
            fontSize: 16,
            fontWeight: 500,
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
