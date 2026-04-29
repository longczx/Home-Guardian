import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, EyeOff, Shield, Wifi, Bell } from 'lucide-react';
import { login } from '@/api/auth';
import { useAuthStore } from '@/stores/authStore';
import { useToast } from '@/components/Toast';
import { LoadingSpinner } from '@/components/ui';

export default function LoginPage() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPwd, setShowPwd] = useState(false);
  const [loading, setLoading] = useState(false);
  const { setTokens, setUser } = useAuthStore();
  const toast = useToast();
  const navigate = useNavigate();

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!username.trim() || !password.trim()) {
      toast.error('请填写用户名和密码');
      return;
    }
    setLoading(true);
    try {
      const { data } = await login({ username: username.trim(), password });
      setTokens(data.data.access_token, data.data.refresh_token);
      setUser(data.data.user);
      toast.success('登录成功，欢迎回来！');
      navigate('/', { replace: true });
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      toast.error(msg || '用户名或密码错误');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700"
      style={{ paddingTop: 'env(safe-area-inset-top)', paddingBottom: 'env(safe-area-inset-bottom)' }}>
      
      {/* Background decoration */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute -top-20 -right-20 w-72 h-72 bg-white/10 rounded-full blur-3xl" />
        <div className="absolute -bottom-20 -left-20 w-80 h-80 bg-purple-900/30 rounded-full blur-3xl" />
      </div>

      <div className="relative flex flex-col flex-1 items-center justify-between px-6 py-10">
        {/* Brand section */}
        <div className="flex flex-col items-center pt-8">
          <div className="w-20 h-20 bg-white/20 backdrop-blur-sm rounded-3xl flex items-center justify-center mb-4 shadow-2xl">
            <span className="text-white font-black text-3xl tracking-tighter">HG</span>
          </div>
          <h1 className="text-3xl font-black text-white mb-2">Home Guardian</h1>
          <p className="text-white/70 text-sm text-center">实时查看家庭状态，随时处理告警</p>
          <div className="flex items-center gap-3 mt-4">
            {[
              { icon: Shield, label: 'IoT' },
              { icon: Wifi, label: 'Real-time' },
              { icon: Bell, label: 'Smart Alert' },
            ].map(({ icon: Icon, label }) => (
              <div key={label} className="flex items-center gap-1.5 bg-white/15 rounded-full px-3 py-1.5">
                <Icon className="w-3.5 h-3.5 text-white/80" />
                <span className="text-white/80 text-xs font-medium">{label}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Login card */}
        <div className="w-full max-w-sm">
          <div className="bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl rounded-3xl p-6 shadow-2xl">
            <h2 className="text-xl font-bold text-slate-900 dark:text-slate-100 mb-6">账号登录</h2>
            <form onSubmit={handleLogin} className="space-y-4">
              <div>
                <label className="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1.5 block">
                  用户名
                </label>
                <input
                  type="text"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  placeholder="请输入用户名"
                  autoComplete="username"
                  className="input-field"
                />
              </div>
              <div>
                <label className="text-sm font-medium text-slate-600 dark:text-slate-400 mb-1.5 block">
                  密码
                </label>
                <div className="relative">
                  <input
                    type={showPwd ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="请输入密码"
                    autoComplete="current-password"
                    className="input-field pr-12"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPwd(!showPwd)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1"
                  >
                    {showPwd ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
                  </button>
                </div>
              </div>
              <button
                type="submit"
                disabled={loading}
                className="btn-primary w-full flex items-center justify-center gap-2 mt-2"
              >
                {loading ? <LoadingSpinner size="sm" /> : null}
                {loading ? '登录中...' : '登录'}
              </button>
            </form>
          </div>
          <p className="text-center text-white/50 text-xs mt-4">
            Home Guardian v2 · 私有部署
          </p>
        </div>
      </div>
    </div>
  );
}
