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

  const activeKey = tabs.find((t) => location.pathname === t.key)?.key ?? '/mobile';

  return (
    <div className="app-shell">
      <div className="app-shell__content">
        <Outlet />
      </div>
      <div className="bottom-dock">
        <div className="bottom-dock__inner">
          <TabBar activeKey={activeKey} onChange={(key) => navigate(key)}>
            {tabs.map((tab) => (
              <TabBar.Item
                key={tab.key}
                icon={tab.key === '/mobile/alerts' ? (
                  <Badge content={unreadCount > 0 ? Badge.dot : null}>{tab.icon}</Badge>
                ) : tab.icon}
                title={tab.title}
              />
            ))}
          </TabBar>
        </div>
      </div>
    </div>
  );
}
