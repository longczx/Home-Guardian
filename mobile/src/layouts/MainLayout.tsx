import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { TabBar, Badge } from 'antd-mobile';
import {
  AppOutline,
  UnorderedListOutline,
  BellOutline,
  SetOutline,
  UserOutline,
} from 'antd-mobile-icons';
import { useAlertStore } from '@/stores/alertStore';
import { useGlobalWebSocket } from '@/hooks/useGlobalWebSocket';

const tabs = [
  { key: '/mobile', title: '首页', icon: <AppOutline /> },
  { key: '/mobile/devices', title: '设备', icon: <UnorderedListOutline /> },
  { key: '/mobile/alerts', title: '告警', icon: <BellOutline /> },
  { key: '/mobile/automations', title: '自动化', icon: <SetOutline /> },
  { key: '/mobile/profile', title: '我的', icon: <UserOutline /> },
];

export default function MainLayout() {
  const location = useLocation();
  const navigate = useNavigate();
  const unreadCount = useAlertStore((s) => s.unreadCount);

  useGlobalWebSocket();

  const activeKey = tabs.find((t) => location.pathname === t.key)?.key ?? '/mobile';

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100vh' }}>
      <div style={{ flex: 1, overflow: 'auto' }}>
        <Outlet />
      </div>
      <div style={{
        borderTop: '1px solid var(--color-border)',
        background: 'var(--tabbar-bg)',
        paddingBottom: 'env(safe-area-inset-bottom)',
      }}>
        <TabBar
          activeKey={activeKey}
          onChange={(key) => navigate(key)}
          style={{ '--adm-color-primary': 'var(--color-primary)' } as React.CSSProperties}
        >
          {tabs.map((tab) => (
            <TabBar.Item
              key={tab.key}
              icon={tab.key === '/mobile/alerts' ? (
                <Badge content={unreadCount > 0 ? Badge.dot : null}>
                  {tab.icon}
                </Badge>
              ) : tab.icon}
              title={tab.title}
            />
          ))}
        </TabBar>
      </div>
    </div>
  );
}
