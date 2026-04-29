import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, AlertTriangle, CheckCircle, XCircle, Cpu } from 'lucide-react';
import { getAlertLog, acknowledgeAlert, resolveAlert, type AlertLog } from '@/api/alert';
import { CardSkeleton } from '@/components/Skeleton';
import { ErrorState, LoadingSpinner } from '@/components/ui';
import { useToast } from '@/components/Toast';
import { formatDateTime } from '@/utils/time';

const STATUS_LABELS = { triggered: '触发中', acknowledged: '已确认', resolved: '已解决' };
const TAG_CLASSES = { triggered: 'tag-alert-triggered', acknowledged: 'tag-alert-acknowledged', resolved: 'tag-alert-resolved' };

export default function AlertDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const [alert, setAlert] = useState<AlertLog | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [acting, setActing] = useState(false);

  useEffect(() => {
    if (!id) return;
    getAlertLog(Number(id))
      .then((res) => setAlert(res.data.data))
      .catch(() => setError(true))
      .finally(() => setLoading(false));
  }, [id]);

  const handleAck = async () => {
    if (!alert) return;
    setActing(true);
    try {
      await acknowledgeAlert(alert.id);
      setAlert({ ...alert, status: 'acknowledged', acknowledged_at: new Date().toISOString() });
      toast.success('告警已确认');
    } catch { toast.error('操作失败'); }
    finally { setActing(false); }
  };

  const handleResolve = async () => {
    if (!alert) return;
    setActing(true);
    try {
      await resolveAlert(alert.id);
      setAlert({ ...alert, status: 'resolved', resolved_at: new Date().toISOString() });
      toast.success('告警已解决');
    } catch { toast.error('操作失败'); }
    finally { setActing(false); }
  };

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100 ml-1">告警详情</h1>
      </div>

      <div className="px-4 pb-24 space-y-4">
        {loading ? (
          <><CardSkeleton /><CardSkeleton /></>
        ) : error ? (
          <ErrorState />
        ) : alert ? (
          <>
            {/* Highlight card */}
            <div className={`rounded-3xl p-5 ${
              alert.status === 'triggered' ? 'bg-gradient-to-br from-red-500 to-rose-600' :
              alert.status === 'acknowledged' ? 'bg-gradient-to-br from-amber-500 to-orange-500' :
              'bg-gradient-to-br from-emerald-500 to-teal-600'
            } text-white`}>
              <div className="flex items-start gap-4">
                <div className="w-14 h-14 bg-white/20 rounded-3xl flex items-center justify-center flex-shrink-0">
                  <AlertTriangle className="w-7 h-7" />
                </div>
                <div className="flex-1">
                  <span className={`text-white/80 text-xs font-medium bg-white/20 px-2 py-0.5 rounded-full`}>
                    {STATUS_LABELS[alert.status]}
                  </span>
                  <h2 className="text-xl font-black text-white mt-2 mb-1">{alert.rule_name}</h2>
                  <p className="text-white/70 text-sm">
                    {alert.device_name} · {alert.device_location || '未知位置'}
                  </p>
                  <p className="text-white/60 text-xs mt-1">
                    {formatDateTime(alert.triggered_at)}
                  </p>
                </div>
              </div>
            </div>

            {/* Detail list */}
            <div className="card p-4">
              <h2 className="text-base font-bold text-slate-800 dark:text-slate-200 mb-3">告警详情</h2>
              {[
                { label: '告警规则', value: alert.rule_name },
                { label: '关联设备', value: alert.device_name },
                { label: '触发指标', value: alert.metric_key },
                { label: '触发值', value: String(alert.triggered_value) },
                { label: '告警消息', value: alert.message },
                { label: '触发时间', value: formatDateTime(alert.triggered_at) },
                { label: '确认时间', value: alert.acknowledged_at ? formatDateTime(alert.acknowledged_at) : '--' },
                { label: '解决时间', value: alert.resolved_at ? formatDateTime(alert.resolved_at) : '--' },
              ].map(({ label, value }) => (
                <div key={label} className="flex justify-between items-start py-2.5 border-b border-slate-100 dark:border-slate-700 last:border-0">
                  <span className="text-sm text-slate-500 dark:text-slate-400">{label}</span>
                  <span className="text-sm font-medium text-slate-900 dark:text-slate-100 text-right max-w-[55%]">{value}</span>
                </div>
              ))}
            </div>
          </>
        ) : null}
      </div>

      {/* Bottom actions */}
      {alert && (
        <div className="fixed bottom-0 left-0 right-0 p-4 bg-white/95 dark:bg-slate-900/95 backdrop-blur-md border-t border-slate-200/80 dark:border-slate-700/80"
          style={{ paddingBottom: 'max(16px, env(safe-area-inset-bottom))' }}>
          <div className="flex gap-3 max-w-lg mx-auto">
            <button onClick={() => navigate(`/devices/${alert.device_id}`)}
              className="btn-secondary flex items-center justify-center gap-2 flex-1">
              <Cpu className="w-4 h-4" />
              查看设备
            </button>
            {alert.status === 'triggered' && (
              <button onClick={handleAck} disabled={acting} className="flex-1 flex items-center justify-center gap-2 bg-amber-500 text-white font-semibold rounded-2xl py-3.5 disabled:opacity-50">
                {acting ? <LoadingSpinner size="sm" /> : <CheckCircle className="w-4 h-4" />}
                确认
              </button>
            )}
            {(alert.status === 'triggered' || alert.status === 'acknowledged') && (
              <button onClick={handleResolve} disabled={acting} className="flex-1 flex items-center justify-center gap-2 bg-emerald-500 text-white font-semibold rounded-2xl py-3.5 disabled:opacity-50">
                {acting ? <LoadingSpinner size="sm" /> : <XCircle className="w-4 h-4" />}
                解决
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
