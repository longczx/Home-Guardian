import React from 'react';
import { Wifi, WifiOff } from 'lucide-react';
import { useWsStore } from '@/stores/wsStore';

export function StatusDot({ online }: { online: boolean }) {
  return (
    <span className={`inline-block w-2 h-2 rounded-full flex-shrink-0 ${online ? 'bg-emerald-400' : 'bg-slate-300 dark:bg-slate-600'}`} />
  );
}

export function OnlineTag({ online }: { online: boolean }) {
  return (
    <span className={online ? 'tag-online' : 'tag-offline'}>
      {online ? '在线' : '离线'}
    </span>
  );
}

export function WsIndicator() {
  const connected = useWsStore((s) => s.connected);
  return (
    <div className={`flex items-center gap-1 text-xs font-medium ${connected ? 'text-emerald-500' : 'text-slate-400'}`}>
      {connected ? <Wifi className="w-3 h-3" /> : <WifiOff className="w-3 h-3" />}
      <span>{connected ? '实时' : '断连'}</span>
    </div>
  );
}

export function EmptyState({ icon: Icon, title, description, action }: {
  icon: React.ElementType;
  title: string;
  description?: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
      <div className="w-20 h-20 bg-slate-100 dark:bg-slate-800 rounded-3xl flex items-center justify-center mb-4">
        <Icon className="w-10 h-10 text-slate-400 dark:text-slate-500" />
      </div>
      <h3 className="text-base font-semibold text-slate-700 dark:text-slate-300 mb-1">{title}</h3>
      {description && <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">{description}</p>}
      {action}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message?: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 px-6 text-center">
      <div className="w-20 h-20 bg-red-50 dark:bg-red-900/20 rounded-3xl flex items-center justify-center mb-4">
        <span className="text-4xl">⚠️</span>
      </div>
      <h3 className="text-base font-semibold text-slate-700 dark:text-slate-300 mb-1">加载失败</h3>
      <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">{message || '请检查网络连接后重试'}</p>
      {onRetry && (
        <button onClick={onRetry} className="btn-primary text-sm px-6 py-2.5">
          重新加载
        </button>
      )}
    </div>
  );
}

export function LoadingSpinner({ size = 'md' }: { size?: 'sm' | 'md' | 'lg' }) {
  const sizes = { sm: 'w-4 h-4', md: 'w-6 h-6', lg: 'w-8 h-8' };
  return (
    <div className={`${sizes[size]} border-2 border-indigo-500 border-t-transparent rounded-full animate-spin`} />
  );
}

export function PageLoader() {
  return (
    <div className="flex items-center justify-center h-full">
      <LoadingSpinner size="lg" />
    </div>
  );
}

export function Switch({ checked, onChange, disabled }: {
  checked: boolean;
  onChange: (v: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors duration-200
                 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed
                 ${checked ? 'bg-indigo-500' : 'bg-slate-300 dark:bg-slate-600'}`}
    >
      <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow-sm transition-transform duration-200
                       ${checked ? 'translate-x-6' : 'translate-x-1'}`} />
    </button>
  );
}
