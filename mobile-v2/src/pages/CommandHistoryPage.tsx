import React, { useEffect, useState, useCallback, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { Terminal, Clock, CheckCircle, XCircle, Loader2, AlertTriangle } from 'lucide-react';
import { getCommandLogs, type CommandLog } from '@/api/command';
import { EmptyState, ErrorState } from '@/components/ui';
import { ListSkeleton } from '@/components/Skeleton';
import { formatDateTime } from '@/utils/time';

const STATUS_META: Record<CommandLog['status'], { label: string; color: string; icon: React.ReactNode }> = {
  pending:  { label: '等待中', color: 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400', icon: <Clock className="w-3.5 h-3.5" /> },
  sent:     { label: '已发送', color: 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400', icon: <Loader2 className="w-3.5 h-3.5 animate-spin" /> },
  success:  { label: '成功',   color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400', icon: <CheckCircle className="w-3.5 h-3.5" /> },
  failed:   { label: '失败',   color: 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400', icon: <XCircle className="w-3.5 h-3.5" /> },
  timeout:  { label: '超时',   color: 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400', icon: <AlertTriangle className="w-3.5 h-3.5" /> },
};

export default function CommandHistoryPage() {
  const [logs, setLogs] = useState<CommandLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const loaderRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  const load = useCallback(async (reset = true) => {
    if (reset) { setLoading(true); setError(false); }
    else setLoadingMore(true);
    try {
      const pg = reset ? 1 : page + 1;
      const res = await getCommandLogs({ page: pg, per_page: 20 });
      const { items, total } = res.data.data;
      setLogs(prev => reset ? items : [...prev, ...items]);
      setPage(pg);
      setHasMore((reset ? items.length : logs.length + items.length) < total);
    } catch { if (reset) setError(true); }
    finally { if (reset) setLoading(false); else setLoadingMore(false); }
  }, [page, logs.length]);

  useEffect(() => { load(true); }, []);

  // Infinite scroll observer
  useEffect(() => {
    if (!loaderRef.current) return;
    const observer = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting && hasMore && !loadingMore) load(false);
    }, { rootMargin: '100px' });
    observer.observe(loaderRef.current);
    return () => observer.disconnect();
  }, [hasMore, loadingMore, load]);

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="px-4 pb-6 space-y-4">
        {/* Header */}
        <div className="pt-4">
          <h1 className="text-2xl font-black text-slate-900 dark:text-slate-100">命令历史</h1>
          <p className="text-slate-500 dark:text-slate-400 text-sm mt-0.5">设备控制指令执行记录</p>
        </div>

        {/* Hero card */}
        <div className="hero-card bg-gradient-to-br from-slate-700 to-slate-900 p-5 text-white">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center">
              <Terminal className="w-6 h-6 text-white" />
            </div>
            <div>
              <p className="text-2xl font-black">{logs.length}</p>
              <p className="text-white/60 text-xs">条记录</p>
            </div>
          </div>
        </div>

        {loading ? <ListSkeleton count={5} />
          : error ? <ErrorState onRetry={() => load(true)} />
          : logs.length === 0 ? <EmptyState icon={Terminal} title="暂无命令历史" description="设备控制记录将在这里显示" />
          : (
            <div className="space-y-3">
              {logs.map((log) => {
                const meta = STATUS_META[log.status];
                return (
                  <div key={log.id} className="card p-4">
                    <div className="flex items-start justify-between gap-2 mb-2">
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">
                          {log.topic || 'N/A'}
                        </p>
                        <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                          设备 #{log.device_id}
                          {log.username && <span> · 由 {log.username}</span>}
                        </p>
                      </div>
                      <span className={`flex items-center gap-1 text-[10px] font-medium px-2 py-1 rounded-full ${meta.color}`}>
                        {meta.icon} {meta.label}
                      </span>
                    </div>

                    {log.payload && (
                      <div className="bg-slate-900 dark:bg-slate-950 rounded-xl p-2.5 mb-2">
                        <p className="text-[10px] text-slate-400 mb-1">指令负载</p>
                        <code className="text-xs text-emerald-400 break-all">{JSON.stringify(log.payload)}</code>
                      </div>
                    )}

                    {log.response && (
                      <div className="bg-slate-100 dark:bg-slate-700/50 rounded-xl p-2.5 mb-2">
                        <p className="text-[10px] text-slate-400 mb-1">设备响应</p>
                        <code className="text-xs text-slate-700 dark:text-slate-300 break-all">{JSON.stringify(log.response)}</code>
                      </div>
                    )}

                    <div className="flex gap-4 pt-2 border-t border-slate-100 dark:border-slate-700">
                      <div>
                        <p className="text-[10px] text-slate-400">发送时间</p>
                        <p className="text-xs text-slate-600 dark:text-slate-400">{formatDateTime(log.sent_at)}</p>
                      </div>
                      {log.replied_at && (
                        <div>
                          <p className="text-[10px] text-slate-400">响应时间</p>
                          <p className="text-xs text-slate-600 dark:text-slate-400">{formatDateTime(log.replied_at)}</p>
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}

              <div ref={loaderRef} className="py-2 text-center">
                {loadingMore && <p className="text-xs text-slate-400">加载中...</p>}
                {!hasMore && logs.length > 0 && <p className="text-xs text-slate-400">已显示全部记录</p>}
              </div>
            </div>
          )}
      </div>
    </div>
  );
}
