import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Save, Plus, Trash2 } from 'lucide-react';
import { getAutomation, createAutomation, updateAutomation, type AutomationAction } from '@/api/automation';
import { getDevices } from '@/api/device';
import { getNotificationChannels } from '@/api/notification';
import { useToast } from '@/components/Toast';
import { LoadingSpinner } from '@/components/ui';
import { Skeleton } from '@/components/Skeleton';

export default function AutomationFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const isEdit = !!id;

  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);
  const [devices, setDevices] = useState<{ id: number; name: string }[]>([]);
  const [channels, setChannels] = useState<{ id: number; name: string; type: string }[]>([]);

  const [form, setForm] = useState({
    name: '',
    is_enabled: true,
    trigger_type: 'telemetry' as 'telemetry' | 'schedule',
    trigger_config: {
      device_id: 0, metric_key: '', condition: 'GREATER_THAN', value: 0,
      duration_sec: 60, cron: '0 8 * * *', timezone: 'Asia/Shanghai',
    },
    actions: [{ type: 'device_command' as const, device_id: 0, payload: { action: 'turn_on' } }] as AutomationAction[],
  });

  const setField = (path: string, value: unknown) => {
    setForm(f => {
      const keys = path.split('.');
      const copy = JSON.parse(JSON.stringify(f));
      let obj: Record<string, unknown> = copy;
      for (let i = 0; i < keys.length - 1; i++) obj = obj[keys[i]] as Record<string, unknown>;
      obj[keys[keys.length - 1]] = value;
      return copy;
    });
  };

  const addAction = () => {
    setForm(f => ({
      ...f,
      actions: [...f.actions, { type: 'device_command' as const, device_id: 0, payload: { action: 'turn_on' } }],
    }));
  };

  const removeAction = (idx: number) => {
    setForm(f => ({ ...f, actions: f.actions.filter((_, i) => i !== idx) }));
  };

  const setActionType = (idx: number, type: 'device_command' | 'notify') => {
    setForm(f => {
      const actions = [...f.actions] as AutomationAction[];
      actions[idx] = type === 'device_command'
        ? { type, device_id: 0, payload: { action: 'turn_on' } }
        : { type: 'notify' as const, channel_ids: [] };
      return { ...f, actions };
    });
  };

  useEffect(() => {
    Promise.all([getDevices({ per_page: 200 }), getNotificationChannels()]).then(([dRes, cRes]) => {
      setDevices(dRes.data.data.items);
      setChannels(cRes.data.data);
    });

    if (isEdit) {
      getAutomation(Number(id)).then((res) => {
        const a = res.data.data;
        setForm({
          name: a.name,
          is_enabled: a.is_enabled,
          trigger_type: a.trigger_type,
          trigger_config: { ...form.trigger_config, ...a.trigger_config } as typeof form.trigger_config,
          actions: a.actions.length > 0 ? a.actions as typeof form.actions : form.actions,
        });
      }).finally(() => setLoading(false));
    }
  }, [id, isEdit]);

  const handleSave = async () => {
    if (!form.name) { toast.error('请填写规则名称'); return; }
    setSaving(true);
    try {
      const payload = {
        name: form.name,
        is_enabled: form.is_enabled,
        trigger_type: form.trigger_type,
        trigger_config: form.trigger_type === 'telemetry'
          ? { device_id: form.trigger_config.device_id, metric_key: form.trigger_config.metric_key, condition: form.trigger_config.condition, value: form.trigger_config.value, duration_sec: form.trigger_config.duration_sec }
          : { cron: form.trigger_config.cron, timezone: form.trigger_config.timezone },
        actions: form.actions,
      };
      if (isEdit) await updateAutomation(Number(id), payload);
      else await createAutomation(payload);
      toast.success(isEdit ? '自动化已更新' : '自动化已创建');
      navigate('/automations');
    } catch { toast.error('保存失败'); }
    finally { setSaving(false); }
  };

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100">{isEdit ? '编辑自动化' : '新建自动化'}</h1>
        <button onClick={handleSave} disabled={saving} className="p-2 text-indigo-500">
          {saving ? <LoadingSpinner size="sm" /> : <Save className="w-5 h-5" />}
        </button>
      </div>

      <div className="px-4 pb-8 space-y-4">
        {loading ? (
          <div className="space-y-4">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-32" />)}</div>
        ) : (
          <>
            {/* Hero card */}
            <div className="hero-card bg-gradient-to-br from-amber-500 to-orange-500 p-5 text-white">
              <h2 className="text-lg font-bold mb-1">自动化配置</h2>
              <p className="text-white/70 text-sm">设置触发条件与执行动作的联动规则</p>
            </div>

            {/* Basic */}
            <div className="card p-4 space-y-4">
              <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">基本信息</h3>
              <div>
                <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">规则名称 *</label>
                <input value={form.name} onChange={e => setField('name', e.target.value)}
                  placeholder="如：夜间关灯" className="input-field" />
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-slate-700 dark:text-slate-300">启用</p>
                  <p className="text-xs text-slate-400">创建后立即生效</p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input type="checkbox" checked={form.is_enabled} onChange={e => setField('is_enabled', e.target.checked)} className="sr-only" />
                  <div className={`w-12 h-7 rounded-full transition-colors ${form.is_enabled ? 'bg-indigo-500' : 'bg-slate-300 dark:bg-slate-600'}`}>
                    <div className={`w-5 h-5 bg-white rounded-full shadow-sm m-1 transition-transform ${form.is_enabled ? 'translate-x-5' : 'translate-x-0'}`} />
                  </div>
                </label>
              </div>
            </div>

            {/* Trigger */}
            <div className="card p-4 space-y-4">
              <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">触发条件</h3>
              <div className="flex bg-slate-100 dark:bg-slate-800 rounded-2xl p-1">
                {[{ k: 'telemetry', l: '遥测条件' }, { k: 'schedule', l: '定时计划' }].map(({ k, l }) => (
                  <button key={k} onClick={() => setField('trigger_type', k)}
                    className={`flex-1 py-2 rounded-xl text-sm font-medium transition-all
                      ${form.trigger_type === k ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 shadow-sm' : 'text-slate-500 dark:text-slate-400'}`}>
                    {l}
                  </button>
                ))}
              </div>

              {form.trigger_type === 'telemetry' ? (
                <div className="space-y-3">
                  <div>
                    <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">选择设备</label>
                    <select value={form.trigger_config.device_id} onChange={e => setField('trigger_config.device_id', Number(e.target.value))} className="input-field">
                      <option value={0}>请选择设备</option>
                      {devices.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </select>
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">指标</label>
                      <input value={form.trigger_config.metric_key} onChange={e => setField('trigger_config.metric_key', e.target.value)}
                        placeholder="temperature" className="input-field text-sm py-2.5" />
                    </div>
                    <div>
                      <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">阈值</label>
                      <input type="number" value={form.trigger_config.value} onChange={e => setField('trigger_config.value', Number(e.target.value))}
                        className="input-field text-sm py-2.5" />
                    </div>
                  </div>
                </div>
              ) : (
                <div className="space-y-3">
                  <div>
                    <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">Cron 表达式</label>
                    <input value={form.trigger_config.cron} onChange={e => setField('trigger_config.cron', e.target.value)}
                      placeholder="0 22 * * *" className="input-field font-mono" />
                    <p className="text-[10px] text-slate-400 mt-1">分 时 日 月 周 · 示例: 每晚22点 = 0 22 * * *</p>
                  </div>
                </div>
              )}
            </div>

            {/* Actions */}
            <div className="card p-4 space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">执行动作</h3>
                <button onClick={addAction} className="text-indigo-500 text-xs font-medium flex items-center gap-1">
                  <Plus className="w-3.5 h-3.5" /> 添加动作
                </button>
              </div>
              <div className="space-y-3">
                {form.actions.map((action, idx) => (
                  <div key={idx} className="bg-slate-50 dark:bg-slate-700/50 rounded-2xl p-3 space-y-3">
                    <div className="flex items-center justify-between">
                      <p className="text-xs font-medium text-slate-500 dark:text-slate-400">动作 {idx + 1}</p>
                      {form.actions.length > 1 && (
                        <button onClick={() => removeAction(idx)} className="text-red-400 p-1">
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      )}
                    </div>
                    <div className="flex gap-2">
                      {[{ k: 'device_command', l: '控制设备' }, { k: 'notify', l: '发送通知' }].map(({ k, l }) => (
                        <button key={k} onClick={() => setActionType(idx, k as 'device_command' | 'notify')}
                          className={`flex-1 py-1.5 rounded-xl text-xs font-medium border transition-all
                            ${action.type === k ? 'bg-indigo-500 text-white border-indigo-500' : 'border-slate-200 dark:border-slate-600 text-slate-500'}`}>
                          {l}
                        </button>
                      ))}
                    </div>
                    {action.type === 'device_command' ? (
                      <div className="grid grid-cols-2 gap-2">
                        <select value={'device_id' in action ? action.device_id : 0}
                          onChange={e => {
                            const actions = [...form.actions];
                            (actions[idx] as { type: 'device_command'; device_id: number; payload: Record<string, unknown> }).device_id = Number(e.target.value);
                            setForm(f => ({ ...f, actions }));
                          }}
                          className="input-field text-sm py-2">
                          <option value={0}>选择设备</option>
                          {devices.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                        </select>
                        <input
                          value={'payload' in action ? String(action.payload?.action || '') : ''}
                          onChange={e => {
                            const actions = JSON.parse(JSON.stringify(form.actions));
                            (actions[idx] as { payload: Record<string, unknown> }).payload.action = e.target.value;
                            setForm(f => ({ ...f, actions }));
                          }}
                          placeholder="turn_on" className="input-field text-sm py-2" />
                      </div>
                    ) : (
                      <div>
                        <p className="text-xs text-slate-400 mb-2">选择通知渠道</p>
                        <div className="flex flex-wrap gap-2">
                          {channels.map(ch => {
                            const ids = ('channel_ids' in action ? action.channel_ids : []) as number[];
                            const selected = ids.includes(ch.id);
                            return (
                              <button key={ch.id} onClick={() => {
                                const actions = JSON.parse(JSON.stringify(form.actions));
                                const channelIds = (actions[idx] as { channel_ids: number[] }).channel_ids || [];
                                (actions[idx] as { channel_ids: number[] }).channel_ids = selected
                                  ? channelIds.filter((c: number) => c !== ch.id)
                                  : [...channelIds, ch.id];
                                setForm(f => ({ ...f, actions }));
                              }}
                                className={`text-xs px-3 py-1.5 rounded-xl border transition-all
                                  ${selected ? 'bg-indigo-500 text-white border-indigo-500' : 'border-slate-200 dark:border-slate-600 text-slate-500'}`}>
                                {ch.name}
                              </button>
                            );
                          })}
                        </div>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>

            <button onClick={handleSave} disabled={saving}
              className="btn-primary w-full flex items-center justify-center gap-2">
              {saving ? <LoadingSpinner size="sm" /> : <Save className="w-4 h-4" />}
              {isEdit ? '保存更改' : '创建自动化'}
            </button>
          </>
        )}
      </div>
    </div>
  );
}
