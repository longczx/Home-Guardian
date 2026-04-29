import { useState, useEffect } from 'react';
import { NavBar, Form, Input, Button, Picker, Switch, Toast, TextArea, Stepper } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getAutomation, createAutomation, updateAutomation, type AutomationAction } from '@/api/automation';
import { getDevices, type Device } from '@/api/device';

const TRIGGER_TYPES = [
  { label: '遥测触发', value: 'telemetry' },
  { label: '定时触发', value: 'schedule' },
];

const ACTION_TYPES = [
  { label: '发送指令', value: 'send_command' },
  { label: '发送通知', value: 'send_notification' },
];

export default function AutomationFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEdit = !!id;

  const [name, setName] = useState('');
  const [triggerType, setTriggerType] = useState<'telemetry' | 'schedule'>('telemetry');
  const [triggerConfig, setTriggerConfig] = useState('{}');
  const [enabled, setEnabled] = useState(true);
  const [devices, setDevices] = useState<Device[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [triggerPickerVisible, setTriggerPickerVisible] = useState(false);

  // Telemetry trigger fields
  const [trigDeviceId, setTrigDeviceId] = useState<number | null>(null);
  const [trigMetricKey, setTrigMetricKey] = useState('');
  const [trigCondition, setTrigCondition] = useState('gt');
  const [trigThreshold, setTrigThreshold] = useState(0);

  // Action fields
  const [actDeviceId, setActDeviceId] = useState<number | null>(null);
  const [actAction, setActAction] = useState('');
  const [actType, setActType] = useState<'send_command' | 'send_notification'>('send_command');

  const [devPickerVisible, setDevPickerVisible] = useState(false);
  const [actDevPickerVisible, setActDevPickerVisible] = useState(false);

  useEffect(() => {
    getDevices({ per_page: 200 }).then(({ data: res }) => {
      if (res.code === 0) setDevices(res.data.items);
    });
  }, []);

  useEffect(() => {
    if (!id) return;
    getAutomation(parseInt(id)).then(({ data: res }) => {
      if (res.code === 0) {
        const a = res.data;
        setName(a.name);
        setTriggerType(a.trigger_type);
        setEnabled(a.is_enabled);
        if (a.trigger_type === 'telemetry') {
          const tc = a.trigger_config as Record<string, unknown>;
          setTrigDeviceId(tc.device_id as number || null);
          setTrigMetricKey(tc.metric_key as string || '');
          setTrigCondition(tc.condition as string || 'gt');
          setTrigThreshold(tc.threshold as number || 0);
        } else {
          setTriggerConfig(JSON.stringify(a.trigger_config, null, 2));
        }
        if (a.actions[0]) {
          setActType(a.actions[0].type);
          if (a.actions[0].type === 'send_command') {
            setActDeviceId(a.actions[0].config.device_id as number || null);
            setActAction(a.actions[0].config.action as string || '');
          }
        }
      }
    });
  }, [id]);

  const handleSubmit = async () => {
    if (!name) {
      Toast.show({ content: '请填写名称', icon: 'fail' });
      return;
    }
    setSubmitting(true);

    let tc: Record<string, unknown>;
    if (triggerType === 'telemetry') {
      tc = { device_id: trigDeviceId, metric_key: trigMetricKey, condition: trigCondition, threshold: trigThreshold };
    } else {
      try { tc = JSON.parse(triggerConfig); } catch { tc = {}; }
    }

    const acts: AutomationAction[] = [];
    if (actType === 'send_command') {
      acts.push({ type: 'send_command', config: { device_id: actDeviceId, action: actAction } });
    } else {
      acts.push({ type: 'send_notification', config: {} });
    }

    const payload = { name, trigger_type: triggerType, trigger_config: tc, actions: acts, is_enabled: enabled };
    try {
      if (isEdit) {
        await updateAutomation(parseInt(id!), payload);
        Toast.show({ content: '更新成功', icon: 'success' });
      } else {
        await createAutomation(payload);
        Toast.show({ content: '创建成功', icon: 'success' });
      }
      navigate(-1);
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    } finally {
      setSubmitting(false);
    }
  };

  const trigDeviceName = devices.find((d) => d.id === trigDeviceId)?.name || '选择设备';
  const actDeviceName = devices.find((d) => d.id === actDeviceId)?.name || '选择设备';

  return (
    <div className="mobile-page mobile-page--tight">
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        {isEdit ? '编辑自动化' : '创建自动化'}
      </NavBar>

      <div className="page-hero" style={{ marginTop: 8 }}>
        <div className="page-hero__eyebrow">automation form</div>
        <div className="page-hero__title">{isEdit ? '编辑自动化' : '创建自动化'}</div>
        <div className="page-hero__subtitle">配置触发条件和执行动作，继续复用现有 API 结构。</div>
        <div className="page-hero__meta">
          <span className="soft-chip">设备 {devices.length}</span>
          <span className="soft-chip">类型 {triggerType === 'telemetry' ? '遥测触发' : '定时触发'}</span>
        </div>
      </div>

      <div className="form-shell">
        <div className="glass-card form-card">
          <div className="form-card__title">基本信息</div>
          <div className="form-card__subtitle">设置名称、触发类型和启用状态。</div>
          <Form layout="vertical" style={{ '--border-top': 'none', '--border-bottom': 'none' } as React.CSSProperties}>
            <Form.Item label="名称">
              <Input value={name} onChange={setName} placeholder="自动化名称" />
            </Form.Item>
            <Form.Item label="触发类型" onClick={() => setTriggerPickerVisible(true)}>
              <div className="picker-trigger">{triggerType === 'telemetry' ? '遥测触发' : '定时触发'}</div>
            </Form.Item>
            <Form.Item label="启用">
              <Switch checked={enabled} onChange={setEnabled} />
            </Form.Item>
          </Form>
        </div>
        <Picker
          columns={[TRIGGER_TYPES]}
          visible={triggerPickerVisible}
          onClose={() => setTriggerPickerVisible(false)}
          onConfirm={(v) => { if (v[0]) setTriggerType(v[0] as 'telemetry' | 'schedule'); }}
          value={[triggerType]}
        />

        <div className="glass-card form-card">
          <div className="form-card__title">触发条件</div>
          <div className="form-card__subtitle">根据设备遥测或 cron 计划来触发自动化。</div>
          <Form layout="vertical" style={{ '--border-top': 'none', '--border-bottom': 'none' } as React.CSSProperties}>
            {triggerType === 'telemetry' ? (
              <>
                <Form.Item label="设备" onClick={() => setDevPickerVisible(true)}>
                  <div className="picker-trigger" style={{ color: trigDeviceId ? 'var(--color-text)' : 'var(--color-text-tertiary)' }}>{trigDeviceName}</div>
                </Form.Item>
                <Form.Item label="遥测指标">
                  <Input value={trigMetricKey} onChange={setTrigMetricKey} placeholder="如 temperature" />
                </Form.Item>
                <Form.Item label="条件">
                  <Input value={trigCondition} onChange={setTrigCondition} placeholder="gt / lt / eq" />
                </Form.Item>
                <Form.Item label="阈值">
                  <Stepper value={trigThreshold} onChange={setTrigThreshold} step={0.1} style={{ width: '100%' }} />
                </Form.Item>
              </>
            ) : (
              <Form.Item label="Cron 配置">
                <TextArea value={triggerConfig} onChange={setTriggerConfig} rows={3} placeholder='{"cron": "0 8 * * *"}' />
              </Form.Item>
            )}
          </Form>
        </div>
            <Picker
              columns={[devices.map((d) => ({ label: d.name, value: d.id }))]}
              visible={devPickerVisible}
              onClose={() => setDevPickerVisible(false)}
              onConfirm={(v) => { if (v[0]) setTrigDeviceId(v[0] as number); }}
              value={trigDeviceId ? [trigDeviceId] : []}
            />

        <div className="glass-card form-card">
          <div className="form-card__title">执行动作</div>
          <div className="form-card__subtitle">选择动作类型，并在需要时指定目标设备和指令。</div>
          <Form layout="vertical" style={{ '--border-top': 'none', '--border-bottom': 'none' } as React.CSSProperties}>
            <Form.Item label="动作类型">
              <div className="option-chip-row">
            {ACTION_TYPES.map((t) => (
              <div
                key={t.value}
                onClick={() => setActType(t.value as 'send_command' | 'send_notification')}
                className={`option-chip ${actType === t.value ? 'option-chip--active' : ''}`}
              >
                {t.label}
              </div>
            ))}
              </div>
            </Form.Item>
            {actType === 'send_command' && (
              <>
                <Form.Item label="目标设备" onClick={() => setActDevPickerVisible(true)}>
                  <div className="picker-trigger" style={{ color: actDeviceId ? 'var(--color-text)' : 'var(--color-text-tertiary)' }}>{actDeviceName}</div>
                </Form.Item>
                <Form.Item label="指令">
                  <Input value={actAction} onChange={setActAction} placeholder="如 turn_on" />
                </Form.Item>
              </>
            )}
          </Form>
        </div>
            <Picker
              columns={[devices.map((d) => ({ label: d.name, value: d.id }))]}
              visible={actDevPickerVisible}
              onClose={() => setActDevPickerVisible(false)}
              onConfirm={(v) => { if (v[0]) setActDeviceId(v[0] as number); }}
              value={actDeviceId ? [actDeviceId] : []}
            />

        <div className="submit-wrap">
          <Button block color="primary" loading={submitting} onClick={handleSubmit}>
            {isEdit ? '保存修改' : '创建自动化'}
          </Button>
        </div>
      </div>
    </div>
  );
}
