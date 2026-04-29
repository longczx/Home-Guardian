import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Plus, Trash2, CheckCircle } from 'lucide-react';
import { getAlertRules, deleteAlertRule, toggleAlertRule, batchToggleAlertRules, type AlertRule } from '@/api/alert';
import { Switch, EmptyState, ErrorState } from '@/components/ui';
import { ListSkeleton } from '@/components/Skeleton';
import { useToast } from '@/components/Toast';
import ConfirmDialog from '@/components/ConfirmDialog';
import { Bell } from 'lucide-react';

export default function AlertRuleListPage() {
  const [rules, setRules] = useState<AlertRule[]>([]);
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
      const params: Record<string, string | number | boolean> = {};
      if (filterTab === 'enabled') params.is_enabled = true;
      else if (filterTab === 'disabled') params.is_enabled = false;
      const res = await getAlertRules(params);
      setRules(res.data.data.items);
    } catch { setError(true); }
    finally { setLoading(false); }
  }, [filterTab]);

  useEffect(() => { load(); }, [load]);

  const handleToggle = async (id: number, enabled: boolean) => {
    try {
      await toggleAlertRule(id, enabled);
      setRules(rules.map(r => r.id === id ? { ...r, is_enabled: enabled } : r));
    } catch { toast.error('操作失败'); }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteAlertRule(deleteTarget);
      setRules(rules.filter(r => r.id !== deleteTarget));
      setDeleteTarget(null);
      toast.success('规则已删除');
    } catch { toast.error('删除失败'); }
    finally { setDeleting(false); }
  };

  const handleBatchToggle = async (enabled: boolean) => {
    const ids = Array.from(selectedIds);
    try {
      await batchToggleAlertRules(ids, enabled);
      setRules(rules.map(r => selectedIds.has(r.id) ? { ...r, is_enabled: enabled } : r));
      setSelectedIds(new Set());
      setBatchMode(false);
      toast.success(`批量${enabled ? '启用' : '停用'}成功`);
    } catch { toast.error('操作失败'); }
  };

  const toggleSelect = (id: number) => {
    setSelectedIds(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const CONDITION_LABELS: Record<string, string> = {
    GREATER_THAN: '>', LESS_THAN: '<', EQUAL: '=',
    GREATER_THAN_OR_EQUAL: '≥', LESS_THAN_OR_EQUAL: '≤', BETWEEN: '区间',
  };

  const filtered = rules;
  const enabledCount = rules.filter(r => r.is_enabled).length;

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100">告警规则</h1>
        <button onClick={() => navigate('/alert-rules/create')} className="p-2">
          <Plus className="w-6 h-6 text-indigo-500" />
        </button>
      </div>

      <div className="px-4 pb-6 space-y-4">
        {/* Summary */}
        <div className="card p-4">
          <div className="grid grid-cols-3 gap-4 text-center">
            {[
              { label: '规则总数', value: rules.length },
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

        {/* Filter tabs */}
        <div className="flex bg-slate-100 dark:bg-slate-800 rounded-2xl p-1">
          {[{ key: 'all', label: '全部' }, { key: 'enabled', label: '启用中' }, { key: 'disabled', label: '已停用' }].map(({ key, label }) => (
            <button key={key} onClick={() => setFilterTab(key as typeof filterTab)}
              className={`flex-1 py-2 rounded-xl text-sm font-medium transition-all
                ${filterTab === key ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 shadow-sm' : 'text-slate-500 dark:text-slate-400'}`}>
              {label}
            </button>
          ))}
        </div>

        {/* Actions */}
        <div className="flex items-center justify-between">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {batchMode ? `已选 ${selectedIds.size} 条` : `共 ${filtered.length} 条`}
          </p>
          <div className="flex gap-2">
            {batchMode ? (
              <>
                {selectedIds.size > 0 && (
                  <>
                    <button onClick={() => handleBatchToggle(true)}
                      className="text-xs font-medium text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20 px-3 py-1.5 rounded-xl">
                      启用
                    </button>
                    <button onClick={() => handleBatchToggle(false)}
                      className="text-xs font-medium text-slate-600 bg-slate-100 dark:bg-slate-700 px-3 py-1.5 rounded-xl">
                      停用
                    </button>
                  </>
                )}
                <button onClick={() => { setBatchMode(false); setSelectedIds(new Set()); }}
                  className="text-xs font-medium text-slate-500 px-3 py-1.5">退出</button>
              </>
            ) : (
              <button onClick={() => setBatchMode(true)}
                className="text-xs font-medium text-indigo-500 px-3 py-1.5">批量操作</button>
            )}
          </div>
        </div>

        {loading ? <ListSkeleton count={4} />
          : error ? <ErrorState onRetry={load} />
          : filtered.length === 0 ? <EmptyState icon={Bell} title="暂无规则" description="点击右上角 + 新建告警规则" />
          : (
            <div className="space-y-3">
              {filtered.map((rule) => (
                <div key={rule.id} className="card p-4">
                  <div className="flex items-start gap-3">
                    {batchMode && (
                      <button onClick={() => toggleSelect(rule.id)}
                        className={`w-5 h-5 rounded-full border-2 flex-shrink-0 mt-0.5 flex items-center justify-center
                                   ${selectedIds.has(rule.id) ? 'bg-indigo-500 border-indigo-500' : 'border-slate-300 dark:border-slate-600'}`}>
                        {selectedIds.has(rule.id) && <CheckCircle className="w-3 h-3 text-white" />}
                      </button>
                    )}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-2 mb-1">
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate flex-1">{rule.name}</p>
                        <Switch checked={rule.is_enabled} onChange={(v) => handleToggle(rule.id, v)} />
                      </div>
                      {rule.device_name && (
                        <p className="text-xs text-slate-500 dark:text-slate-400 mb-1">{rule.device_name}</p>
                      )}
                      <p className="text-xs text-indigo-600 dark:text-indigo-400 font-mono bg-indigo-50 dark:bg-indigo-900/20 px-2 py-1 rounded-lg inline-block">
                        {rule.metric_key} {CONDITION_LABELS[rule.condition] || rule.condition} {rule.threshold_value}
                        {rule.threshold_value2 !== undefined ? ` ~ ${rule.threshold_value2}` : ''}
                      </p>
                    </div>
                  </div>
                  <div className="flex gap-2 mt-3 pt-3 border-t border-slate-100 dark:border-slate-700">
                    <button onClick={() => navigate(`/alert-rules/${rule.id}/edit`)}
                      className="flex-1 text-xs font-medium text-indigo-500 bg-indigo-50 dark:bg-indigo-900/20 py-2 rounded-xl">
                      编辑
                    </button>
                    <button onClick={() => setDeleteTarget(rule.id)}
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
        title="删除告警规则"
        message="确定要删除这条告警规则吗？此操作不可恢复。"
        confirmText="删除"
        confirmVariant="danger"
        onConfirm={handleDelete}
        onCancel={() => setDeleteTarget(null)}
        loading={deleting}
      />
    </div>
  );
}
