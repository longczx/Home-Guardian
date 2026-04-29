import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Check } from 'lucide-react';
import { useThemeStore, type Theme, type ColorScheme } from '@/stores/themeStore';

const THEMES: { value: Theme; label: string; icon: string; desc: string }[] = [
  { value: 'light',  label: '浅色',     icon: '☀️', desc: '始终使用亮色主题' },
  { value: 'dark',   label: '深色',     icon: '🌙', desc: '始终使用暗色主题' },
  { value: 'system', label: '跟随系统', icon: '⚙️', desc: '自动切换' },
];

const COLORS: { value: ColorScheme; label: string; from: string; to: string }[] = [
  { value: 'indigo',  label: '靛蓝',   from: 'from-indigo-500',  to: 'to-indigo-600' },
  { value: 'violet',  label: '紫罗兰', from: 'from-violet-500',  to: 'to-violet-600' },
  { value: 'blue',    label: '天蓝',   from: 'from-blue-500',    to: 'to-blue-600' },
  { value: 'emerald', label: '翠绿',   from: 'from-emerald-500', to: 'to-emerald-600' },
  { value: 'rose',    label: '玫瑰红', from: 'from-rose-500',    to: 'to-rose-600' },
];

export default function ThemeCenterPage() {
  const navigate = useNavigate();
  const { theme, colorScheme, setTheme, setColorScheme } = useThemeStore();

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      {/* Header */}
      <div className="flex items-center gap-3 px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-lg font-bold text-slate-900 dark:text-slate-100">外观主题</h1>
      </div>

      <div className="px-4 pb-8 space-y-6">
        {/* Preview card */}
        <div className="hero-card bg-gradient-to-br from-indigo-500 to-violet-600 p-5 text-white">
          <p className="text-white/60 text-xs mb-1">当前主题预览</p>
          <p className="text-xl font-black">{THEMES.find(t => t.value === theme)?.label} · {COLORS.find(c => c.value === colorScheme)?.label}</p>
          <div className="flex gap-2 mt-4">
            <div className="bg-white/15 rounded-xl px-3 py-2 text-sm font-semibold">主按钮</div>
            <div className="bg-white/10 border border-white/20 rounded-xl px-3 py-2 text-sm">次按钮</div>
          </div>
        </div>

        {/* Theme mode */}
        <div>
          <h2 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">主题模式</h2>
          <div className="grid grid-cols-3 gap-3">
            {THEMES.map(t => (
              <button key={t.value} onClick={() => setTheme(t.value)}
                className={`card p-4 flex flex-col items-center gap-2 relative transition-all
                  ${theme === t.value ? 'ring-2 ring-indigo-500' : ''}`}>
                {theme === t.value && (
                  <div className="absolute top-2 right-2 w-5 h-5 bg-indigo-500 rounded-full flex items-center justify-center">
                    <Check className="w-3 h-3 text-white" />
                  </div>
                )}
                <span className="text-2xl">{t.icon}</span>
                <p className="text-xs font-bold text-slate-700 dark:text-slate-300">{t.label}</p>
                <p className="text-[10px] text-slate-400 text-center leading-relaxed">{t.desc}</p>
              </button>
            ))}
          </div>
        </div>

        {/* Color scheme */}
        <div>
          <h2 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">色彩主题</h2>
          <div className="grid grid-cols-5 gap-2">
            {COLORS.map(c => (
              <button key={c.value} onClick={() => setColorScheme(c.value)}
                className="flex flex-col items-center gap-2">
                <div className={`w-14 h-14 rounded-2xl bg-gradient-to-br ${c.from} ${c.to} flex items-center justify-center
                  ${colorScheme === c.value ? 'ring-3 ring-offset-2 ring-slate-400 dark:ring-slate-500 scale-110' : ''} transition-transform`}>
                  {colorScheme === c.value && <Check className="w-5 h-5 text-white" />}
                </div>
                <p className="text-[10px] text-slate-500 dark:text-slate-400">{c.label}</p>
              </button>
            ))}
          </div>
        </div>

        {/* Typography preview */}
        <div className="card p-4 space-y-2">
          <h3 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider mb-3">样式预览</h3>
          <p className="text-2xl font-black text-slate-900 dark:text-slate-100">标题文字</p>
          <p className="text-base font-semibold text-slate-700 dark:text-slate-200">副标题文字</p>
          <p className="text-sm text-slate-500 dark:text-slate-400">正文说明文字，展示当前主题的色彩与字重效果。</p>
          <div className="flex gap-2 pt-2">
            <span className="px-3 py-1 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 text-xs rounded-xl font-medium">在线</span>
            <span className="px-3 py-1 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-xs rounded-xl font-medium">告警</span>
            <span className="px-3 py-1 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400 text-xs rounded-xl font-medium">正常</span>
          </div>
        </div>
      </div>
    </div>
  );
}
