import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { Home, Cpu, Bell, Zap, User } from 'lucide-react';
import { useWsStore } from '@/stores/wsStore';

const tabs = [
  { to: '/', icon: Home, label: '首页' },
  { to: '/devices', icon: Cpu, label: '设备' },
  { to: '/alerts', icon: Bell, label: '告警' },
  { to: '/automations', icon: Zap, label: '自动化' },
  { to: '/profile', icon: User, label: '我的' },
];

export default function TabBar() {
  const location = useLocation();
  const unreadAlerts = useWsStore((s) => s.unreadAlerts);

  return (
    <nav className="tab-bar">
      <div className="flex items-stretch justify-around">
        {tabs.map(({ to, icon: Icon, label }) => {
          const isActive = to === '/'
            ? location.pathname === '/'
            : location.pathname.startsWith(to);
          const isAlerts = to === '/alerts';
          return (
            <NavLink
              key={to}
              to={to}
              className={`flex flex-col items-center justify-center gap-1 flex-1 pt-3 pb-1 min-h-[60px] 
                         relative transition-colors duration-150
                         ${isActive ? 'text-indigo-500' : 'text-slate-400 dark:text-slate-500'}`}
            >
              <div className="relative">
                <Icon className="w-6 h-6" strokeWidth={isActive ? 2.5 : 1.8} />
                {isAlerts && unreadAlerts > 0 && (
                  <span className="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full
                                   flex items-center justify-center text-[9px] text-white font-bold">
                    {unreadAlerts > 9 ? '9+' : unreadAlerts}
                  </span>
                )}
              </div>
              <span className={`text-[10px] font-medium ${isActive ? 'text-indigo-500' : ''}`}>{label}</span>
            </NavLink>
          );
        })}
      </div>
    </nav>
  );
}
