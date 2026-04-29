import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Save } from 'lucide-react';
import { getAlertRule, createAlertRule, updateAlertRule } from '@/api/alert';
import { getDevices } from '@/api/device';
import { getNotificationChannels } from '@/api/notification';
import { useToast } from '@/components/Toast';
import { LoadingSpinner } from '@/components/ui';
import { Skeleton } from '@/components/Skeleton';

const CONDITIONS = [
  { value: 'GREATER_THAN', label: '大于 (>)' },
  { value: 'LESS_THAN', label: '小于 (<)' },
  { value: 'EQUAL', label: '等于 (=)' },
  { value: 'GREATER_THAN_OR_EQUAL', label: '大于等于 (≥)' },
  { value: 'LESS_THAN_OR_EQUAL', label: '小于等于 (≤)' },
  { value: 'BETWEEN', label: '区间 (BETWEEN)' },
];

export default function AlertRuleFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const isEdit = !!id;

  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);
  const [devices, setDevices] = useState<{ id: number; name: string; device_uid: string }[]>([]);
  const [channels, setChannels] = useState<{ id: number; name: string; type: string }[]>([]);

  const [form, setForm] = useState({
    name: '',
    device_id: 0,
    metric_key: '',
    condition: 'GREATER_THAN',
    threshold_value: 0,
    threshold_value2: 0,
    duration_seconds: 60,
    is_enabled: true,
    notification_channel_ids: [] as number[],
  });

  useEffect(() => {
    Promise.all([
      getDevices({ per_page: 200 }),
      getNotificationChannels(),
    ]).then(([devRes, chanRes]) => {
      setDevices(devRes.data.data.items);
      setChannels(chanRes.data.data);
    });

    if (isEdit) {
      getAlertRule(Number(id)).then((res) => {
        const r = res.data.data;
        setForm({
          name: r.name,
          device_id: r.device_id,
          metric_key: r.metric_key,
          condition: r.condition,
          threshold_value: r.threshold_value,
          threshold_value2: r.threshold_value2 ?? 0,
          duration_seconds: r.duration_seconds,
          is_enabled: r.is_enabled,
          notification_channel_ids: r.notification_channel_ids,
        });
      }).finally(() => setLoading(false));
    }
  }, [id, isEdit]);

  const setField = (key: string, value: unknown) => setForm(f => ({ ...f, [key]: value }));

  const toggleChannel = (channelId: number) => {
    setForm(f => ({
      ...f,
      notification_channel_ids: f.notification_channel_ids.includes(channelId)
        ? f.notification_channel_ids.filter(c => c !== channelId)
        : [...f.notification_channel_ids, channelId],
    }));
  };

  const handleSave = async () => {
    if (!form.name || !form.device_id || !form.metric_key) {
      toast.error('请填写必填项');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        ...form,
        threshold_value2: form.condition === 'BETWEEN' ? form.threshold_value2 : undefined,
      };
      if (isEdit) await updateAlertRule(Number(id), payload);
      else await createAlertRule(payload);
      toast.success(isEdit ? '规则已更新' : '规则已创建');
      navigate('/alert-rules');
    } catch { toast.error('保存失败'); }
    finally { setSaving(false); }
  };

  const CHANNEL_ICONS: Record<string, string> = {
    email: '📧', webhook: '🌐', telegram: '✈️', wechat_work: '💬', dingtalk: '🔔',
  };

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100">
          {isEdit ? '编辑规则' : '新建规则'}
        </h1>
        <button onClick={handleSave} disabled={saving} className="p-2 text-indigo-500">
          {saving ? <LoadingSpinner size="sm" /> : <Save className="w-5 h-5" />}
        </button>
      </div>

      <div className="px-4 pb-8 space-y-4">
        {loading ? (
          <div className="space-y-4">
            {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-28 w-full" />)}
          </div>
        ) : (
          <>
            {/* Hero card */}
            <div className="hero-card bg-gradient-to-br from-indigo-500 to-violet-600 p-5 text-white">
              <h2 className="text-lg font-bold mb-1">规则配置</h2>
              <p className="text-white/70 text-sm">定义设备、指标、阈值和通知渠道</p>
              <div className="flex gap-3 mt-3">
                <div className="bg-white/15 rounded-xl px-3 py-2">
                  <p className="text-white/60 text-[10px]">设备总数</p>
                  <p className="text-white text-sm font-bold">{devices.length}</p>
                </div>
                <div className="bg-white/15 rounded-xl px-3 py-2">
                  <p className="text-white/60 text-[10px]">通知渠道</p>
                  <p className="text-white text-sm font-bold">{channels.length}</p>
                </div>
              </div>
            </div>

            {/* Basic settings */}
            <div className="card p-4 space-y-4">
              <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">基础设置</h3>
              <div>
                <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">规则名称 *</label>
                <input value={form.name} onChange={e => setField('name', e.target.value)}
                  placeholder="如：客厅温度过高" className="input-field" />
              </div>
              <div>
                <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">选择设备 *</label>
                <select value={form.device_id} onChange={e => setField('device_id', Number(e.target.value))}
                  className="input-field">
                  <option value={0}>请选择设备</option>
                  {devices.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
              </div>
              <div>
                <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">遥测指标 *</label>
                <input value={form.metric_key} onChange={e => setField('metric_key', e.target.value)}
                  placeholder="如：temperature、humidity" className="input-field" />
              </div>
            </div>

            {/* Trigger condition */}
            <div className="card p-4 space-y-4">
              <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">触发条件</h3>
              <div>
                <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">条件类型</label>
                <select value={form.condition} onChange={e => setField('condition', e.target.value)}
                  className="input-field">
                  {CONDITIONS.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
              </div>
              <div className={`grid gap-4 ${form.condition === 'BETWEEN' ? 'grid-cols-2' : 'grid-cols-1'}`}>
                <div>
                  <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">
                    {form.condition === 'BETWEEN' ? '最小值' : '阈值'}
                  </label>
                  <input type="number" value={form.threshold_value}
                    onChange={e => setField('threshold_value', Number(e.target.value))}
                    className="input-field" />
                </div>
                {form.condition === 'BETWEEN' && (
                  <div>
                    <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">最大值</label>
                    <input type="number" value={form.threshold_value2}
                      onChange={e => setField('threshold_value2', Number(e.target.value))}
                      className="input-field" />
                  </div>
                )}
              </div>
              <div>
                <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">
                  持续时间 (秒)：{form.duration_seconds}s
                </label>
                <input type="range" min={0} max={3600} step={30} value={form.duration_seconds}
                  onChange={e => setField('duration_seconds', Number(e.target.value))}
                  className="w-full accent-indigo-500" />
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-slate-700 dark:text-slate-300">启用规则</p>
                  <p className="text-xs text-slate-400 dark:text-slate-500">创建后立即生效</p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" checked={form.is_enabled}
                    onChange={e => setField('is_enabled', e.target.checked)} className="sr-only" />
                  <div className={`w-12 h-7 rounded-full transition-colors ${form.is_enabled ? 'bg-indigo-500' : 'bg-slate-300 dark:bg-slate-600'}`}>
                    <div className={`w-5 h-5 bg-white rounded-full shadow-sm m-1 transition-transform ${form.is_enabled ? 'translate-x-5' : 'translate-x-0'}`} />
                  </div>
                </label>
              </div>
            </div>

            {/* Notification channels */}
            {channels.length > 0 && (
              <div className="card p-4">
                <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300 mb-3">通知渠道</h3>
                <div className="grid grid-cols-2 gap-2">
                  {channels.map(ch => {
                    const selected = form.notification_channel_ids.includes(ch.id);
                    return (
                      <button key={ch.id} onClick={() => toggleChannel(ch.id)}
                        className={`flex items-center gap-2 p-3 rounded-2xl border-2 transition-all text-left
                                   ${selected ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20' : 'border-slate-200 dark:border-slate-700'}`}>
                        <span className="text-xl">{CHANNEL_ICONS[ch.type] || '📬'}</span>
                        <div className="min-w-0">
                          <p className={`text-xs font-semibold truncate ${selected ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-700 dark:text-slate-300'}`}>
                            {ch.name}
                          </p>
                          <p className="text-[10px] text-slate-400">{ch.type}</p>
                        </div>
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            <button onClick={handleSave} disabled={saving}
              className="btn-primary w-full flex items-center justify-center gap-2">
              {saving ? <LoadingSpinner size="sm" /> : <Save className="w-4 h-4" />}
              {isEdit ? '保存更改' : '创建规则'}
            </button>
          </>
        )}
      </div>
    </div>
  );
}
