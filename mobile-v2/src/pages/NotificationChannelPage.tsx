import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Send, Trash2 } from 'lucide-react';
import {
  getNotificationChannels,
  deleteNotificationChannel,
  testNotificationChannel,
  toggleNotificationChannel,
  type NotificationChannel,
} from '@/api/notification';
import { Switch, EmptyState, ErrorState } from '@/components/ui';
import { ListSkeleton } from '@/components/Skeleton';
import { useToast } from '@/components/Toast';
import ConfirmDialog from '@/components/ConfirmDialog';

const TYPE_META: Record<string, { label: string; icon: string; color: string }> = {
  email:       { label: '邮件',     icon: '📧', color: 'bg-blue-50 dark:bg-blue-900/20' },
  webhook:     { label: 'Webhook', icon: '🌐', color: 'bg-violet-50 dark:bg-violet-900/20' },
  telegram:    { label: 'Telegram', icon: '✈️', color: 'bg-sky-50 dark:bg-sky-900/20' },
  wechat_work: { label: '企业微信', icon: '💬', color: 'bg-emerald-50 dark:bg-emerald-900/20' },
  dingtalk:    { label: '钉钉',     icon: '🔔', color: 'bg-orange-50 dark:bg-orange-900/20' },
};

export default function NotificationChannelPage() {
  const [channels, setChannels] = useState<NotificationChannel[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<number | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [testingId, setTestingId] = useState<number | null>(null);
  const navigate = useNavigate();
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true); setError(false);
    try {
      const res = await getNotificationChannels();
      setChannels(res.data.data);
    } catch { setError(true); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { load(); }, [load]);

  const handleToggle = async (id: number, enabled: boolean) => {
    try {
      await toggleNotificationChannel(id, enabled);
      setChannels(channels.map(c => c.id === id ? { ...c, is_enabled: enabled } : c));
    } catch { toast.error('操作失败'); }
  };

  const handleTest = async (id: number) => {
    setTestingId(id);
    try {
      await testNotificationChannel(id);
      toast.success('测试消息已发送');
    } catch { toast.error('测试发送失败'); }
    finally { setTestingId(null); }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await deleteNotificationChannel(deleteTarget);
      setChannels(channels.filter(c => c.id !== deleteTarget));
      setDeleteTarget(null);
      toast.success('渠道已删除');
    } catch { toast.error('删除失败'); }
    finally { setDeleting(false); }
  };

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="px-4 pb-6 space-y-4">
        {/* Header */}
        <div className="flex items-start justify-between pt-4">
          <div>
            <h1 className="text-2xl font-black text-slate-900 dark:text-slate-100">通知渠道</h1>
            <p className="text-slate-500 dark:text-slate-400 text-sm mt-0.5">管理告警通知的发送方式</p>
          </div>
          <button onClick={() => navigate('/notification-channels/create')}
            className="w-10 h-10 bg-indigo-500 rounded-2xl flex items-center justify-center shadow-sm">
            <Plus className="w-5 h-5 text-white" />
          </button>
        </div>

        {/* Summary */}
        <div className="card p-4">
          <div className="grid grid-cols-3 gap-4 text-center">
            {[
              { label: '渠道总数', value: channels.length },
              { label: '启用中', value: channels.filter(c => c.is_enabled).length },
              { label: '已停用', value: channels.filter(c => !c.is_enabled).length },
            ].map(({ label, value }) => (
              <div key={label}>
                <p className="text-xl font-black text-slate-900 dark:text-slate-100">{value}</p>
                <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{label}</p>
              </div>
            ))}
          </div>
        </div>

        {loading ? <ListSkeleton count={3} />
          : error ? <ErrorState onRetry={load} />
          : channels.length === 0 ? (
            <EmptyState icon={Send} title="暂无通知渠道" description="添加邮件、Webhook 或即时通讯渠道"
              action={<button onClick={() => navigate('/notification-channels/create')} className="btn-primary">新建渠道</button>} />
          ) : (
            <div className="space-y-3">
              {channels.map((ch) => {
                const meta = TYPE_META[ch.type] || { label: ch.type, icon: '📬', color: 'bg-slate-50' };
                return (
                  <div key={ch.id} className={`card p-4 ${meta.color}`}>
                    <div className="flex items-start gap-3">
                      <div className="w-11 h-11 bg-white dark:bg-slate-800 rounded-2xl flex items-center justify-center text-xl flex-shrink-0 shadow-sm">
                        {meta.icon}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate">{ch.name}</p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">{meta.label}</p>
                        {ch.description && <p className="text-[10px] text-slate-400 mt-0.5 truncate">{ch.description}</p>}
                      </div>
                      <Switch checked={ch.is_enabled} onChange={(v) => handleToggle(ch.id, v)} />
                    </div>
                    <div className="flex gap-2 mt-3 pt-3 border-t border-white/50 dark:border-slate-600">
                      <button onClick={() => navigate(`/notification-channels/${ch.id}/edit`)}
                        className="flex-1 text-xs font-medium text-indigo-500 bg-white dark:bg-slate-700 py-2 rounded-xl">
                        编辑
                      </button>
                      <button onClick={() => handleTest(ch.id)} disabled={testingId === ch.id}
                        className="text-xs font-medium text-emerald-600 bg-white dark:bg-slate-700 px-3 py-2 rounded-xl flex items-center gap-1">
                        <Send className="w-3 h-3" />
                        {testingId === ch.id ? '发送中' : '测试'}
                      </button>
                      <button onClick={() => setDeleteTarget(ch.id)}
                        className="text-xs font-medium text-red-500 bg-white dark:bg-slate-700 px-3 py-2 rounded-xl">
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
      </div>

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        title="删除通知渠道"
        message="删除后，使用此渠道的告警规则将停止通知。"
        confirmText="删除"
        confirmVariant="danger"
        onConfirm={handleDelete}
        onCancel={() => setDeleteTarget(null)}
        loading={deleting}
      />
    </div>
  );
}
