import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, CheckCircle, Zap, Clock, Activity } from 'lucide-react';
import { getAutomations, deleteAutomation, toggleAutomation, type Automation } from '@/api/automation';
import { Switch, EmptyState, ErrorState } from '@/components/ui';
import { ListSkeleton } from '@/components/Skeleton';
import { useToast } from '@/components/Toast';
import ConfirmDialog from '@/components/ConfirmDialog';
import { formatDistanceToNow } from '@/utils/time';

export default function AutomationListPage() {
  const [automations, setAutomations] = useState<Automation[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [filterTab, setFilterTab] = useState<'all' | 'enabled' | 'disabled'>('all');
  const [batchMode, setBatchMode] = useState(false);
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [deleteTarget, setDeleteTarget] = useState<number | null>(null);
  const [deleting, setDeleting] = useState(false);
  const navigate = useNavigate();
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    setError(false);
    try {
      const res = await getAutomations({ per_page: 100 });
      setAutomations(res.data.data.items);
    } catch { setError(true); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const handleToggle = async (id: number, enabled: boolean) => {
    try {
      await toggleAutomation(id, enabled);
      setAutomations(automations.map(a => a.id === id ? { ...a, is_enabled: enabled } : a));
    } catch { toast.error('操作失败'); }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteAutomation(deleteTarget);
      setAutomations(automations.filter(a => a.id !== deleteTarget));
      setDeleteTarget(null);
      toast.success('自动化已删除');
    } catch { toast.error('删除失败'); }
    finally { setDeleting(false); }
  };

  const toggleSelect = (id: number) => {
    setSelectedIds(prev => { const next = new Set(prev); if (next.has(id)) next.delete(id); else next.add(id); return next; });
  };

  const filtered = automations.filter(a =>
    filterTab === 'all' ? true : filterTab === 'enabled' ? a.is_enabled : !a.is_enabled
  );
  const enabledCount = automations.filter(a => a.is_enabled).length;

  const getTriggerSummary = (a: Automation) => {
    if (a.trigger_type === 'schedule') {
      return `定时: ${(a.trigger_config.cron as string) || ''}`;
    }
    return `遥测: 设备 ${a.trigger_config.device_id} · ${a.trigger_config.metric_key}`;
  };

  const getActionSummary = (a: Automation) => {
    if (!a.actions?.length) return '暂无动作';
    const action = a.actions[0];
    if (action.type === 'device_command') return `控制设备 #${action.device_id}`;
    if (action.type === 'notify') return `发送通知`;
    return action.type;
  };

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="px-4 pb-6 space-y-4">
        {/* Header */}
        <div className="flex items-start justify-between pt-4">
          <div>
            <h1 className="text-2xl font-black text-slate-900 dark:text-slate-100">自动化</h1>
            <p className="text-slate-500 dark:text-slate-400 text-sm mt-0.5">联动规则与定时任务</p>
          </div>
          <button onClick={() => navigate('/automations/create')}
            className="w-10 h-10 bg-indigo-500 rounded-2xl flex items-center justify-center shadow-sm">
            <Plus className="w-5 h-5 text-white" />
          </button>
        </div>

        {/* Summary */}
        <div className="card p-4">
          <div className="grid grid-cols-3 gap-4 text-center">
            {[
              { label: '规则总数', value: automations.length },
              { label: '启用中', value: enabledCount },
              { label: '当前视图', value: filtered.length },
            ].map(({ label, value }) => (
              <div key={label}>
                <p className="text-xl font-black text-slate-900 dark:text-slate-100">{value}</p>
                <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{label}</p>
              </div>
            ))}
          </div>
        </div>

        {/* Filter */}
        <div className="flex bg-slate-100 dark:bg-slate-800 rounded-2xl p-1">
          {[{ key: 'all', label: '全部' }, { key: 'enabled', label: '启用中' }, { key: 'disabled', label: '已停用' }].map(({ key, label }) => (
            <button key={key} onClick={() => setFilterTab(key as typeof filterTab)}
              className={`flex-1 py-2 rounded-xl text-sm font-medium transition-all
                ${filterTab === key ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 shadow-sm' : 'text-slate-500 dark:text-slate-400'}`}>
              {label}
            </button>
          ))}
        </div>

        {loading ? <ListSkeleton count={4} />
          : error ? <ErrorState onRetry={load} />
          : filtered.length === 0 ? <EmptyState icon={Zap} title="暂无自动化" description="点击右上角 + 新建联动规则" />
          : (
            <div className="space-y-3">
              {filtered.map((auto) => (
                <div key={auto.id} className="card p-4">
                  <div className="flex items-start justify-between gap-2 mb-3">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate flex-1">{auto.name}</p>
                        <span className={`text-[10px] font-medium px-2 py-0.5 rounded-full ${auto.is_enabled ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' : 'bg-slate-100 dark:bg-slate-700 text-slate-500'}`}>
                          {auto.is_enabled ? '启用' : '停用'}
                        </span>
                      </div>
                      <div className="flex items-center gap-1.5 mb-1">
                        {auto.trigger_type === 'schedule'
                          ? <Clock className="w-3.5 h-3.5 text-indigo-400 flex-shrink-0" />
                          : <Activity className="w-3.5 h-3.5 text-violet-400 flex-shrink-0" />}
                        <p className="text-xs text-slate-500 dark:text-slate-400 truncate">{getTriggerSummary(auto)}</p>
                      </div>
                      <div className="flex items-center gap-1.5">
                        <Zap className="w-3.5 h-3.5 text-amber-400 flex-shrink-0" />
                        <p className="text-xs text-slate-500 dark:text-slate-400">{getActionSummary(auto)}</p>
                      </div>
                      {auto.last_triggered_at && (
                        <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-1.5">
                          最近触发：{formatDistanceToNow(auto.last_triggered_at)}
                        </p>
                      )}
                    </div>
                    <Switch checked={auto.is_enabled} onChange={(v) => handleToggle(auto.id, v)} />
                  </div>
                  <div className="flex gap-2 pt-3 border-t border-slate-100 dark:border-slate-700">
                    <button onClick={() => navigate(`/automations/${auto.id}/edit`)}
                      className="flex-1 text-xs font-medium text-indigo-500 bg-indigo-50 dark:bg-indigo-900/20 py-2 rounded-xl">
                      编辑
                    </button>
                    <button onClick={() => setDeleteTarget(auto.id)}
                      className="text-xs font-medium text-red-500 bg-red-50 dark:bg-red-900/20 px-4 py-2 rounded-xl">
                      删除
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
      </div>

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        title="删除自动化规则"
        message="确定要删除这条自动化规则吗？此操作不可恢复。"
        confirmText="删除"
        confirmVariant="danger"
        onConfirm={handleDelete}
        onCancel={() => setDeleteTarget(null)}
        loading={deleting}
      />
    </div>
  );
}
