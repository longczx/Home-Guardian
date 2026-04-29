import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Save } from 'lucide-react';
import {
  getNotificationChannel,
  createNotificationChannel,
  updateNotificationChannel,
  type NotificationChannel,
} from '@/api/notification';
import { useToast } from '@/components/Toast';
import { LoadingSpinner } from '@/components/ui';

type ChannelType = NotificationChannel['type'];

const TYPES: { value: ChannelType; label: string; icon: string }[] = [
  { value: 'email',       label: '邮件',     icon: '📧' },
  { value: 'webhook',     label: 'Webhook', icon: '🌐' },
  { value: 'telegram',    label: 'Telegram', icon: '✈️' },
  { value: 'wechat_work', label: '企业微信', icon: '💬' },
  { value: 'dingtalk',    label: '钉钉',     icon: '🔔' },
];

export default function NotificationChannelFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const isEdit = !!id;

  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);
  const [channelType, setChannelType] = useState<ChannelType>('email');
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [isEnabled, setIsEnabled] = useState(true);

  // Type-specific fields
  const [emailConfig, setEmailConfig] = useState({ smtp_host: '', smtp_port: '465', smtp_user: '', smtp_pass: '', to: [''], tls: true });
  const [webhookConfig, setWebhookConfig] = useState({ url: '', method: 'POST', headers: '{}' });
  const [telegramConfig, setTelegramConfig] = useState({ bot_token: '', chat_id: '' });
  const [wechatConfig, setWechatConfig] = useState({ webhook_url: '' });
  const [dingConfig, setDingConfig] = useState({ webhook_url: '', secret: '' });

  useEffect(() => {
    if (isEdit) {
      getNotificationChannel(Number(id)).then((res) => {
        const ch = res.data.data;
        setChannelType(ch.type);
        setName(ch.name);
        setDescription(ch.description || '');
        setIsEnabled(ch.is_enabled);
        const cfg = (ch.config || {}) as Record<string, unknown>;
        const s = (v: unknown) => String(v || '');
        if (ch.type === 'email') setEmailConfig({ smtp_host: s(cfg.smtp_host), smtp_port: s(cfg.smtp_port || '465'), smtp_user: s(cfg.smtp_user), smtp_pass: s(cfg.smtp_pass), to: (cfg.to as string[]) || [''], tls: cfg.tls !== false });
        else if (ch.type === 'webhook') setWebhookConfig({ url: s(cfg.url), method: s(cfg.method || 'POST'), headers: JSON.stringify(cfg.headers || {}, null, 2) });
        else if (ch.type === 'telegram') setTelegramConfig({ bot_token: s(cfg.bot_token), chat_id: s(cfg.chat_id) });
        else if (ch.type === 'wechat_work') setWechatConfig({ webhook_url: s(cfg.webhook_url) });
        else if (ch.type === 'dingtalk') setDingConfig({ webhook_url: s(cfg.webhook_url), secret: s(cfg.secret) });
      }).finally(() => setLoading(false));
    }
  }, [id, isEdit]);

  const buildConfig = () => {
    switch (channelType) {
      case 'email': return { ...emailConfig, smtp_port: Number(emailConfig.smtp_port) };
      case 'webhook': try { return { ...webhookConfig, headers: JSON.parse(webhookConfig.headers) }; } catch { return webhookConfig; }
      case 'telegram': return telegramConfig;
      case 'wechat_work': return wechatConfig;
      case 'dingtalk': return dingConfig;
    }
  };

  const handleSave = async () => {
    if (!name) { toast.error('请填写渠道名称'); return; }
    setSaving(true);
    try {
      const payload = { name, description, is_enabled: isEnabled, type: channelType, config: buildConfig() };
      if (isEdit) await updateNotificationChannel(Number(id), payload);
      else await createNotificationChannel(payload);
      toast.success(isEdit ? '已更新' : '渠道已创建');
      navigate('/notification-channels');
    } catch { toast.error('保存失败'); }
    finally { setSaving(false); }
  };

  const LabeledField = ({ label, children }: { label: string; children: React.ReactNode }) => (
    <div>
      <label className="text-xs text-slate-500 dark:text-slate-400 mb-1.5 block">{label}</label>
      {children}
    </div>
  );

  return (
    <div className="page-container bg-slate-50 dark:bg-slate-950 overflow-y-auto">
      <div className="flex items-center justify-between px-4 pt-4 pb-2">
        <button onClick={() => navigate(-1)} className="p-2 -ml-2">
          <ArrowLeft className="w-6 h-6 text-slate-600 dark:text-slate-400" />
        </button>
        <h1 className="text-base font-bold text-slate-900 dark:text-slate-100">{isEdit ? '编辑渠道' : '新建渠道'}</h1>
        <button onClick={handleSave} disabled={saving} className="p-2 text-indigo-500">
          {saving ? <LoadingSpinner size="sm" /> : <Save className="w-5 h-5" />}
        </button>
      </div>

      <div className="px-4 pb-8 space-y-4">
        {/* Type selector */}
        <div className="grid grid-cols-5 gap-2">
          {TYPES.map(t => (
            <button key={t.value} onClick={() => setChannelType(t.value)}
              className={`flex flex-col items-center gap-1.5 py-3 rounded-2xl border-2 transition-all
                ${channelType === t.value ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800'}`}>
              <span className="text-2xl">{t.icon}</span>
              <span className={`text-[9px] font-medium ${channelType === t.value ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-500'}`}>{t.label}</span>
            </button>
          ))}
        </div>

        {/* Basic */}
        <div className="card p-4 space-y-3">
          <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">基本信息</h3>
          <LabeledField label="渠道名称 *">
            <input value={name} onChange={e => setName(e.target.value)} placeholder="如：运维邮件组" className="input-field" />
          </LabeledField>
          <LabeledField label="说明（可选）">
            <input value={description} onChange={e => setDescription(e.target.value)} placeholder="渠道用途描述" className="input-field" />
          </LabeledField>
          <div className="flex items-center justify-between">
            <p className="text-sm font-medium text-slate-700 dark:text-slate-300">启用此渠道</p>
            <label className="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" checked={isEnabled} onChange={e => setIsEnabled(e.target.checked)} className="sr-only" />
              <div className={`w-12 h-7 rounded-full transition-colors ${isEnabled ? 'bg-indigo-500' : 'bg-slate-300 dark:bg-slate-600'}`}>
                <div className={`w-5 h-5 bg-white rounded-full shadow-sm m-1 transition-transform ${isEnabled ? 'translate-x-5' : 'translate-x-0'}`} />
              </div>
            </label>
          </div>
        </div>

        {/* Type-specific config */}
        <div className="card p-4 space-y-3">
          <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300">渠道配置</h3>

          {channelType === 'email' && (
            <>
              <LabeledField label="SMTP 服务器"><input value={emailConfig.smtp_host} onChange={e => setEmailConfig(c => ({ ...c, smtp_host: e.target.value }))} placeholder="smtp.example.com" className="input-field" /></LabeledField>
              <div className="grid grid-cols-2 gap-3">
                <LabeledField label="端口"><input type="number" value={emailConfig.smtp_port} onChange={e => setEmailConfig(c => ({ ...c, smtp_port: e.target.value }))} className="input-field" /></LabeledField>
                <LabeledField label="TLS">
                  <div className="flex items-center h-[42px]">
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input type="checkbox" checked={emailConfig.tls} onChange={e => setEmailConfig(c => ({ ...c, tls: e.target.checked }))} className="sr-only" />
                      <div className={`w-12 h-7 rounded-full transition-colors ${emailConfig.tls ? 'bg-indigo-500' : 'bg-slate-300 dark:bg-slate-600'}`}>
                        <div className={`w-5 h-5 bg-white rounded-full shadow-sm m-1 transition-transform ${emailConfig.tls ? 'translate-x-5' : 'translate-x-0'}`} />
                      </div>
                    </label>
                  </div>
                </LabeledField>
              </div>
              <LabeledField label="账号"><input value={emailConfig.smtp_user} onChange={e => setEmailConfig(c => ({ ...c, smtp_user: e.target.value }))} placeholder="user@example.com" className="input-field" /></LabeledField>
              <LabeledField label="密码 / 授权码"><input type="password" value={emailConfig.smtp_pass} onChange={e => setEmailConfig(c => ({ ...c, smtp_pass: e.target.value }))} className="input-field" /></LabeledField>
              <LabeledField label="收件人（每行一个）">
                <textarea rows={3} value={emailConfig.to.join('\n')} onChange={e => setEmailConfig(c => ({ ...c, to: e.target.value.split('\n').filter(Boolean) }))}
                  className="input-field" placeholder="admin@example.com" />
              </LabeledField>
            </>
          )}

          {channelType === 'webhook' && (
            <>
              <LabeledField label="Webhook URL"><input value={webhookConfig.url} onChange={e => setWebhookConfig(c => ({ ...c, url: e.target.value }))} placeholder="https://..." className="input-field" /></LabeledField>
              <LabeledField label="请求方法">
                <select value={webhookConfig.method} onChange={e => setWebhookConfig(c => ({ ...c, method: e.target.value }))} className="input-field">
                  <option>POST</option><option>GET</option><option>PUT</option>
                </select>
              </LabeledField>
              <LabeledField label="自定义 Headers (JSON)">
                <textarea rows={3} value={webhookConfig.headers} onChange={e => setWebhookConfig(c => ({ ...c, headers: e.target.value }))}
                  className="input-field font-mono text-xs" placeholder='{"Authorization": "Bearer xxx"}' />
              </LabeledField>
            </>
          )}

          {channelType === 'telegram' && (
            <>
              <LabeledField label="Bot Token"><input value={telegramConfig.bot_token} onChange={e => setTelegramConfig(c => ({ ...c, bot_token: e.target.value }))} className="input-field font-mono text-xs" /></LabeledField>
              <LabeledField label="Chat ID"><input value={telegramConfig.chat_id} onChange={e => setTelegramConfig(c => ({ ...c, chat_id: e.target.value }))} className="input-field" /></LabeledField>
            </>
          )}

          {channelType === 'wechat_work' && (
            <LabeledField label="企业微信群机器人 Webhook URL">
              <input value={wechatConfig.webhook_url} onChange={e => setWechatConfig({ webhook_url: e.target.value })} placeholder="https://qyapi.weixin.qq.com/..." className="input-field" />
            </LabeledField>
          )}

          {channelType === 'dingtalk' && (
            <>
              <LabeledField label="钉钉机器人 Webhook URL"><input value={dingConfig.webhook_url} onChange={e => setDingConfig(c => ({ ...c, webhook_url: e.target.value }))} placeholder="https://oapi.dingtalk.com/..." className="input-field" /></LabeledField>
              <LabeledField label="加签密钥（可选）"><input value={dingConfig.secret} onChange={e => setDingConfig(c => ({ ...c, secret: e.target.value }))} className="input-field" /></LabeledField>
            </>
          )}
        </div>

        <button onClick={handleSave} disabled={saving}
          className="btn-primary w-full flex items-center justify-center gap-2">
          {saving ? <LoadingSpinner size="sm" /> : <Save className="w-4 h-4" />}
          {isEdit ? '保存更改' : '创建渠道'}
        </button>
      </div>
    </div>
  );
}
