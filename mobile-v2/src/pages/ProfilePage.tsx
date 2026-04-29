import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronRight, User, Palette, Lock, Info, LogOut } from 'lucide-react';
import { logoutAll } from '@/api/auth';
import { useAuthStore } from '@/stores/authStore';
import { useToast } from '@/components/Toast';
import ConfirmDialog from '@/components/ConfirmDialog';

const MenuItem = ({ icon: Icon, label, sublabel, onClick, danger }: {
  icon: React.ElementType; label: string; sublabel?: string; onClick: () => void; danger?: boolean;
}) => (
  <button onClick={onClick}
    className="flex items-center gap-3 w-full px-4 py-3.5 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
    <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${danger ? 'bg-red-50 dark:bg-red-900/20' : 'bg-indigo-50 dark:bg-indigo-900/20'}`}>
      <Icon className={`w-4.5 h-4.5 ${danger ? 'text-red-500' : 'text-indigo-500'}`} />
    </div>
    <div className="flex-1 text-left">
      <p className={`text-sm font-medium ${danger ? 'text-red-500' : 'text-slate-700 dark:text-slate-200'}`}>{label}</p>
      {sublabel && <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{sublabel}</p>}
    </div>
    <ChevronRight className="w-4 h-4 text-slate-300 dark:text-slate-600" />
  </button>
);

export default function ProfilePage() {
  const [logoutConfirm, setLogoutConfirm] = React.useState(false);
  const [loggingOut, setLoggingOut] = React.useState(false);
  const navigate = useNavigate();
  const toast = useToast();
  const { user, logout } = useAuthStore();

  const getInitials = (name: string) => {
    return name ? name.slice(0, 2).toUpperCase() : 'HG';
  };

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      await logoutAll();
    } catch { /* ignore */ }
    logout();
    navigate('/login', { replace: true });
  };

  const roles = user?.roles?.join('、') || '用户';

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="pb-6 space-y-4">
        {/* Hero */}
        <div className="bg-gradient-to-br from-indigo-500 to-violet-600 px-4 pb-8 pt-10">
          <div className="flex flex-col items-center">
            <div className="w-20 h-20 rounded-3xl bg-white/20 backdrop-blur-sm flex items-center justify-center mb-4 ring-4 ring-white/30">
              <span className="text-2xl font-black text-white">{getInitials(user?.username || '')}</span>
            </div>
            <h1 className="text-xl font-black text-white">{user?.username || '未登录'}</h1>
            <p className="text-white/60 text-sm mt-1">{user?.username || ''}</p>
            <div className="flex gap-2 mt-3">
              {(user?.roles || ['user']).map(role => (
                <span key={role} className="bg-white/15 text-white text-xs px-3 py-1 rounded-full font-medium">{role}</span>
              ))}
            </div>
          </div>
        </div>

        {/* Menu cards */}
        <div className="px-4 space-y-3">
          {/* Settings */}
          <div className="card divide-y divide-slate-100 dark:divide-slate-700 overflow-hidden p-0">
            <MenuItem icon={Palette} label="外观主题" sublabel="深色模式、色彩主题" onClick={() => navigate('/profile/theme')} />
            <MenuItem icon={Lock} label="修改密码" sublabel="安全凭证管理" onClick={() => navigate('/profile/password')} />
            <MenuItem icon={Info} label="关于" sublabel="版本信息与技术栈" onClick={() => navigate('/profile/about')} />
          </div>

          {/* User info */}
          <div className="card p-4 space-y-2">
            <h3 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">账户信息</h3>
            {[
              { label: '用户名', value: user?.username || '-' },
              { label: '角色', value: roles },
              { label: '权限数量', value: String(user?.permissions?.length || 0) },
            ].map(({ label, value }) => (
              <div key={label} className="flex justify-between items-center py-1">
                <p className="text-xs text-slate-400 dark:text-slate-500">{label}</p>
                <p className="text-sm text-slate-700 dark:text-slate-300 font-medium">{value}</p>
              </div>
            ))}
          </div>

          {/* Logout */}
          <div className="card overflow-hidden p-0">
            <MenuItem icon={LogOut} label="退出登录" sublabel="退出所有设备的登录状态" onClick={() => setLogoutConfirm(true)} danger />
          </div>
        </div>
      </div>

      <ConfirmDialog
        isOpen={logoutConfirm}
        title="退出登录"
        message="将退出所有设备上的登录状态，确定继续吗？"
        confirmText="退出"
        confirmVariant="danger"
        onConfirm={handleLogout}
        onCancel={() => setLogoutConfirm(false)}
        loading={loggingOut}
      />
    </div>
  );
}
