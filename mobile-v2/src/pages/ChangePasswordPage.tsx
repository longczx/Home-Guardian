import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Eye, EyeOff, Save } from 'lucide-react';
import { changePassword } from '@/api/auth';
import { useToast } from '@/components/Toast';
import { LoadingSpinner } from '@/components/ui';

export default function ChangePasswordPage() {
  const navigate = useNavigate();
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [showOld, setShowOld] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState({ current_password: '', new_password: '', confirm_password: '' });
  const setField = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }));

  const handleSave = async () => {
    if (!form.current_password || !form.new_password) { toast.error('请填写所有密码字段'); return; }
    if (form.new_password.length < 8) { toast.error('新密码至少 8 位'); return; }
    if (form.new_password !== form.confirm_password) { toast.error('两次新密码不一致'); return; }
    setSaving(true);
    try {
      await changePassword({ current_password: form.current_password, new_password: form.new_password });
      toast.success('密码已修改，请重新登录');
      navigate(-1);
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message || '修改失败';
      toast.error(msg);
    }
    finally { setSaving(false); }
  };

  const PasswordInput = ({ id, label, value, show, onToggle, placeholder }: {
    id: string; label: string; value: string; show: boolean; onToggle: () => void; placeholder?: string;
  }) => (
    <div>
      <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">{label}</label>
      <div className="relative">
        <input
          id={id}
          type={show ? 'text' : 'password'}
          value={value}
          onChange={e => setField(id, e.target.value)}
          placeholder={placeholder}
          className="input-field pr-12"
        />
        <button type="button" onClick={onToggle}
          className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
          {show ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
        </button>
      </div>
    </div>
  );

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center gap-3 px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-lg font-bold text-slate-900 dark:text-slate-100">修改密码</h1>
      </div>

      <div className="px-4 pb-8 space-y-4">
        {/* Hero */}
        <div className="hero-card bg-gradient-to-br from-slate-700 to-slate-900 p-5 text-white">
          <h2 className="text-lg font-bold mb-1">安全验证</h2>
          <p className="text-white/60 text-sm">输入当前密码后设置新密码，修改后需重新登录。</p>
        </div>

        <div className="card p-4 space-y-4">
          <PasswordInput id="current_password" label="当前密码" value={form.current_password}
            show={showOld} onToggle={() => setShowOld(s => !s)} />
          <div className="border-t border-slate-100 dark:border-slate-700 pt-4 space-y-4">
            <PasswordInput id="new_password" label="新密码（至少 8 位）" value={form.new_password}
              show={showNew} onToggle={() => setShowNew(s => !s)} placeholder="至少 8 位字符" />
            <PasswordInput id="confirm_password" label="确认新密码" value={form.confirm_password}
              show={showNew} onToggle={() => {}} />
          </div>
        </div>

        {/* Tips */}
        <div className="card p-4 bg-amber-50/80 dark:bg-amber-900/10">
          <p className="text-xs text-amber-600 dark:text-amber-400 font-medium mb-2">密码安全建议</p>
          <ul className="space-y-1 text-xs text-amber-600/80 dark:text-amber-400/70">
            <li>• 至少 8 个字符，建议包含大小写字母</li>
            <li>• 加入数字和特殊符号（如 @、#、!）</li>
            <li>• 避免使用生日、手机号等易猜测内容</li>
          </ul>
        </div>

        <button onClick={handleSave} disabled={saving}
          className="btn-primary w-full flex items-center justify-center gap-2">
          {saving ? <LoadingSpinner size="sm" /> : <Save className="w-4 h-4" />}
          确认修改
        </button>
      </div>
    </div>
  );
}
