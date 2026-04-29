import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronRight, AlertTriangle, Settings, CheckCircle, XCircle } from 'lucide-react';
import { getAlertLogs, acknowledgeAlert, resolveAlert, batchAcknowledgeAlerts, batchResolveAlerts, type AlertLog } from '@/api/alert';
import { useWsStore } from '@/stores/wsStore';
import { EmptyState, ErrorState } from '@/components/ui';
import { ListSkeleton } from '@/components/Skeleton';
import { useToast } from '@/components/Toast';
import ConfirmDialog from '@/components/ConfirmDialog';
import { formatDistanceToNow } from '@/utils/time';

type Status = 'all' | 'triggered' | 'acknowledged' | 'resolved';

const STATUS_TABS: { key: Status; label: string }[] = [
  { key: 'all', label: '全部' },
  { key: 'triggered', label: '触发中' },
  { key: 'acknowledged', label: '已确认' },
  { key: 'resolved', label: '已解决' },
];

const STATUS_COLORS = {
  triggered: 'bg-red-50 dark:bg-red-900/20 border-red-100 dark:border-red-800',
  acknowledged: 'bg-amber-50 dark:bg-amber-900/20 border-amber-100 dark:border-amber-800',
  resolved: 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-100 dark:border-emerald-800',
};

const STATUS_ICON_BG = {
  triggered: 'bg-red-100 dark:bg-red-900/40',
  acknowledged: 'bg-amber-100 dark:bg-amber-900/40',
  resolved: 'bg-emerald-100 dark:bg-emerald-900/40',
};

const STATUS_ICON_COLOR = {
  triggered: 'text-red-500',
  acknowledged: 'text-amber-500',
  resolved: 'text-emerald-500',
};

const STATUS_LABELS = { triggered: '触发中', acknowledged: '已确认', resolved: '已解决' };
const TAG_CLASSES = {
  triggered: 'tag-alert-triggered',
  acknowledged: 'tag-alert-acknowledged',
  resolved: 'tag-alert-resolved',
};

export default function AlertListPage() {
  const [alerts, setAlerts] = useState<AlertLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [activeTab, setActiveTab] = useState<Status>('triggered');
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [batchMode, setBatchMode] = useState(false);
  const [confirmAction, setConfirmAction] = useState<{ action: 'ack' | 'resolve'; ids: number[] } | null>(null);
  const navigate = useNavigate();
  const toast = useToast();
  const clearUnread = useWsStore((s) => s.clearUnreadAlerts);

  const load = useCallback(async () => {
    setLoading(true);
    setError(false);
    clearUnread();
    try {
      const params: Record<string, string | number> = { per_page: 100 };
      if (activeTab !== 'all') params.status = activeTab;
      const res = await getAlertLogs(params);
      setAlerts(res.data.data.items);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [activeTab, clearUnread]);

  useEffect(() => { load(); }, [load]);

  const handleBatchAction = async (action: 'ack' | 'resolve') => {
    const ids = Array.from(selectedIds);
    try {
      if (action === 'ack') await batchAcknowledgeAlerts(ids);
      else await batchResolveAlerts(ids);
      toast.success(`批量${action === 'ack' ? '确认' : '解决'}成功`);
      setSelectedIds(new Set());
      setBatchMode(false);
      setConfirmAction(null);
      await load();
    } catch {
      toast.error('操作失败');
      setConfirmAction(null);
    }
  };

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const triggeredCount = alerts.filter(a => a.status === 'triggered').length;

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="px-4 pb-6 space-y-4">
        {/* Header */}
        <div className="flex items-start justify-between pt-4">
          <div>
            <h1 className="text-2xl font-black text-slate-900 dark:text-slate-100">告警</h1>
            <p className="text-slate-500 dark:text-slate-400 text-sm mt-0.5">确认并处理告警事件</p>
          </div>
          <button onClick={() => navigate('/alert-rules')}
            className="w-10 h-10 bg-white dark:bg-slate-800 rounded-2xl flex items-center justify-center shadow-sm">
            <Settings className="w-5 h-5 text-slate-500" />
          </button>
        </div>

        {/* Summary card */}
        <div className="card p-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className={`w-10 h-10 rounded-2xl flex items-center justify-center ${triggeredCount > 0 ? 'bg-red-100 dark:bg-red-900/30' : 'bg-slate-100 dark:bg-slate-700'}`}>
                <AlertTriangle className={`w-5 h-5 ${triggeredCount > 0 ? 'text-red-500' : 'text-slate-400'}`} />
              </div>
              <div>
                <p className="text-xl font-black text-slate-900 dark:text-slate-100">{triggeredCount}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">触发中告警</p>
              </div>
            </div>
            <div className="text-right">
              <p className="text-sm font-medium text-slate-600 dark:text-slate-400">{alerts.length} 条</p>
              <p className="text-xs text-slate-400 dark:text-slate-500">{activeTab === 'all' ? '全部' : STATUS_LABELS[activeTab]}</p>
            </div>
          </div>
        </div>

        {/* Status tabs */}
        <div className="flex bg-slate-100 dark:bg-slate-800 rounded-2xl p-1">
          {STATUS_TABS.map(({ key, label }) => (
            <button key={key} onClick={() => { setActiveTab(key); setSelectedIds(new Set()); setBatchMode(false); }}
              className={`flex-1 py-2 rounded-xl text-xs font-medium transition-all
                ${activeTab === key ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 shadow-sm' : 'text-slate-500 dark:text-slate-400'}`}>
              {label}
            </button>
          ))}
        </div>

        {/* Batch action bar */}
        <div className="flex items-center justify-between">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {batchMode ? `已选 ${selectedIds.size} 条` : `共 ${alerts.length} 条`}
          </p>
          <div className="flex gap-2">
            {batchMode ? (
              <>
                {selectedIds.size > 0 && activeTab !== 'acknowledged' && activeTab !== 'resolved' && (
                  <button onClick={() => setConfirmAction({ action: 'ack', ids: Array.from(selectedIds) })}
                    className="bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-xs font-medium px-3 py-1.5 rounded-xl">
                    确认
                  </button>
                )}
                {selectedIds.size > 0 && activeTab !== 'resolved' && (
                  <button onClick={() => setConfirmAction({ action: 'resolve', ids: Array.from(selectedIds) })}
                    className="bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 text-xs font-medium px-3 py-1.5 rounded-xl">
                    解决
                  </button>
                )}
                <button onClick={() => { setBatchMode(false); setSelectedIds(new Set()); }}
                  className="text-xs font-medium text-slate-500 dark:text-slate-400 px-3 py-1.5">
                  退出
                </button>
              </>
            ) : (
              <button onClick={() => setBatchMode(true)}
                className="text-xs font-medium text-indigo-500 px-3 py-1.5">
                批量操作
              </button>
            )}
          </div>
        </div>

        {loading ? (
          <ListSkeleton count={5} />
        ) : error ? (
          <ErrorState onRetry={load} />
        ) : alerts.length === 0 ? (
          <EmptyState icon={AlertTriangle} title="暂无告警" description="当前筛选条件下没有告警记录" />
        ) : (
          <div className="space-y-3">
            {alerts.map((alert) => (
              <div key={alert.id}
                className={`card border ${STATUS_COLORS[alert.status]} overflow-hidden`}>
                <button
                  onClick={() => batchMode ? toggleSelect(alert.id) : navigate(`/alerts/${alert.id}`)}
                  className="w-full p-4 flex items-start gap-3 text-left">
                  {batchMode && (
                    <div className={`w-5 h-5 rounded-full border-2 flex-shrink-0 mt-0.5 flex items-center justify-center
                                   ${selectedIds.has(alert.id) ? 'bg-indigo-500 border-indigo-500' : 'border-slate-300 dark:border-slate-600'}`}>
                      {selectedIds.has(alert.id) && <CheckCircle className="w-3 h-3 text-white" />}
                    </div>
                  )}
                  <div className={`w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0 ${STATUS_ICON_BG[alert.status]}`}>
                    <AlertTriangle className={`w-5 h-5 ${STATUS_ICON_COLOR[alert.status]}`} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                      <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate flex-1">
                        {alert.rule_name}
                      </p>
                      <span className={TAG_CLASSES[alert.status]}>
                        {STATUS_LABELS[alert.status]}
                      </span>
                    </div>
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      {alert.device_name} · {alert.device_location || '未知位置'}
                    </p>
                    {alert.message && (
                      <p className="text-xs text-slate-600 dark:text-slate-400 mt-1 truncate">{alert.message}</p>
                    )}
                    <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-1.5">
                      {formatDistanceToNow(alert.triggered_at)}
                    </p>
                  </div>
                  {!batchMode && <ChevronRight className="w-4 h-4 text-slate-300 dark:text-slate-600 flex-shrink-0 mt-1" />}
                </button>
              </div>
            ))}
          </div>
        )}
      </div>

      <ConfirmDialog
        isOpen={!!confirmAction}
        title={confirmAction?.action === 'ack' ? '批量确认告警' : '批量解决告警'}
        message={`确定要对选中的 ${confirmAction?.ids.length} 条告警执行此操作吗？`}
        confirmText={confirmAction?.action === 'ack' ? '确认告警' : '标记解决'}
        confirmVariant="primary"
        onConfirm={() => confirmAction && handleBatchAction(confirmAction.action)}
        onCancel={() => setConfirmAction(null)}
      />
    </div>
  );
}
