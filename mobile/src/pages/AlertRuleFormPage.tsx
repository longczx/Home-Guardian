import { useState, useEffect, useMemo } from 'react';
import { NavBar, Form, Input, Button, Picker, Stepper, Switch, Toast } from 'antd-mobile';
import { useNavigate, useParams } from 'react-router-dom';
import { getAlertRule, createAlertRule, updateAlertRule } from '@/api/alertRule';
import { getDevices, type Device } from '@/api/device';
import { getNotificationChannels, type NotificationChannel } from '@/api/notificationChannel';
import { useMetricDefinitionStore } from '@/stores/metricDefinitionStore';

const CONDITIONS = [
  { label: '大于 (>)', value: 'gt' },
  { label: '大于等于 (>=)', value: 'gte' },
  { label: '小于 (<)', value: 'lt' },
  { label: '小于等于 (<=)', value: 'lte' },
  { label: '等于 (=)', value: 'eq' },
  { label: '不等于 (!=)', value: 'neq' },
  { label: '介于', value: 'between' },
];

export default function AlertRuleFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEdit = !!id;

  const [name, setName] = useState('');
  const [deviceId, setDeviceId] = useState<number | null>(null);
  const [telemetryKey, setTelemetryKey] = useState('');
  const [condition, setCondition] = useState('gt');
  const [threshold1, setThreshold1] = useState(0);
  const [threshold2, setThreshold2] = useState(0);
  const [duration, setDuration] = useState(0);
  const [enabled, setEnabled] = useState(true);
  const [channelIds, setChannelIds] = useState<number[]>([]);
  const [devices, setDevices] = useState<Device[]>([]);
  const [channels, setChannels] = useState<NotificationChannel[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [devicePickerVisible, setDevicePickerVisible] = useState(false);
  const [condPickerVisible, setCondPickerVisible] = useState(false);
  const [metricPickerVisible, setMetricPickerVisible] = useState(false);
  const { definitions, fetchDefinitions } = useMetricDefinitionStore();

  useEffect(() => {
    fetchDefinitions();
    getDevices({ per_page: 200 }).then(({ data: res }) => {
      if (res.code === 0) setDevices(res.data.items);
    });
    getNotificationChannels().then(({ data: res }) => {
      if (res.code === 0) setChannels(res.data);
    });
  }, [fetchDefinitions]);

  // Build metric options based on selected device
  const metricOptions = useMemo(() => {
    const selectedDevice = devices.find((d) => d.id === deviceId);
    const mf = selectedDevice?.metric_fields;
    if (Array.isArray(mf) && mf.length > 0) {
      return mf.map((f) => ({ label: `${f.label} (${f.key})`, value: f.key }));
    }
    // Fallback to global definitions
    return definitions.map((d) => ({ label: `${d.label} (${d.metric_key})`, value: d.metric_key }));
  }, [deviceId, devices, definitions]);

  const selectedMetricLabel = metricOptions.find((o) => o.value === telemetryKey)?.label || telemetryKey || '选择指标';

  useEffect(() => {
    if (!id) return;
    getAlertRule(parseInt(id)).then(({ data: res }) => {
      if (res.code === 0) {
        const r = res.data;
        setName(r.name);
        setDeviceId(r.device_id);
        setTelemetryKey(r.telemetry_key);
        setCondition(r.condition);
        setThreshold1(r.threshold_value[0] || 0);
        setThreshold2(r.threshold_value[1] || 0);
        setDuration(r.trigger_duration_sec);
        setEnabled(r.is_enabled);
        setChannelIds(r.notification_channel_ids);
      }
    });
  }, [id]);

  const handleSubmit = async () => {
    if (!name || !deviceId || !telemetryKey) {
      Toast.show({ content: '请填写完整信息', icon: 'fail' });
      return;
    }
    setSubmitting(true);
    const thresholdValue = condition === 'between' || condition === 'not_between'
      ? [threshold1, threshold2] : [threshold1];
    const payload = {
      name,
      device_id: deviceId,
      telemetry_key: telemetryKey,
      condition,
      threshold_value: thresholdValue,
      trigger_duration_sec: duration,
      notification_channel_ids: channelIds,
      is_enabled: enabled,
    };
    try {
      if (isEdit) {
        await updateAlertRule(parseInt(id!), payload);
        Toast.show({ content: '更新成功', icon: 'success' });
      } else {
        await createAlertRule(payload);
        Toast.show({ content: '创建成功', icon: 'success' });
      }
      navigate(-1);
    } catch {
      Toast.show({ content: '操作失败', icon: 'fail' });
    } finally {
      setSubmitting(false);
    }
  };

  const selectedDeviceName = devices.find((d) => d.id === deviceId)?.name || '选择设备';
  const selectedCondLabel = CONDITIONS.find((c) => c.value === condition)?.label || condition;

  return (
    <div style={{ minHeight: '100vh', background: 'var(--color-bg)' }}>
      <NavBar onBack={() => navigate(-1)} style={{ background: 'var(--navbar-bg)', color: 'var(--color-text)' }}>
        {isEdit ? '编辑规则' : '创建规则'}
      </NavBar>

      <Form layout="horizontal" style={{ '--border-top': 'none', '--border-bottom': 'none' } as React.CSSProperties}>
        <Form.Item label="规则名称">
          <Input value={name} onChange={setName} placeholder="输入规则名称" />
        </Form.Item>

        <Form.Item label="设备" onClick={() => setDevicePickerVisible(true)}>
          <span style={{ color: deviceId ? 'var(--color-text)' : 'var(--color-text-tertiary)' }}>
            {selectedDeviceName}
          </span>
        </Form.Item>
        <Picker
          columns={[devices.map((d) => ({ label: d.name, value: d.id }))]}
          visible={devicePickerVisible}
          onClose={() => setDevicePickerVisible(false)}
          onConfirm={(v) => { if (v[0]) { setDeviceId(v[0] as number); setTelemetryKey(''); } }}
          value={deviceId ? [deviceId] : []}
        />

        <Form.Item label="遥测指标" onClick={() => setMetricPickerVisible(true)}>
          <span style={{ color: telemetryKey ? 'var(--color-text)' : 'var(--color-text-tertiary)' }}>
            {selectedMetricLabel}
          </span>
        </Form.Item>
        <Picker
          columns={[metricOptions]}
          visible={metricPickerVisible}
          onClose={() => setMetricPickerVisible(false)}
          onConfirm={(v) => { if (v[0]) setTelemetryKey(v[0] as string); }}
          value={telemetryKey ? [telemetryKey] : []}
        />

        <Form.Item label="条件" onClick={() => setCondPickerVisible(true)}>
          <span style={{ color: 'var(--color-text)' }}>{selectedCondLabel}</span>
        </Form.Item>
        <Picker
          columns={[CONDITIONS.map((c) => ({ label: c.label, value: c.value }))]}
          visible={condPickerVisible}
          onClose={() => setCondPickerVisible(false)}
          onConfirm={(v) => { if (v[0]) setCondition(v[0] as string); }}
          value={[condition]}
        />

        <Form.Item label="阈值">
          <Stepper value={threshold1} onChange={setThreshold1} step={0.1} style={{ width: '100%' }} />
        </Form.Item>
        {(condition === 'between' || condition === 'not_between') && (
          <Form.Item label="阈值上限">
            <Stepper value={threshold2} onChange={setThreshold2} step={0.1} style={{ width: '100%' }} />
          </Form.Item>
        )}

        <Form.Item label="持续时间(秒)">
          <Stepper value={duration} onChange={setDuration} min={0} step={10} style={{ width: '100%' }} />
        </Form.Item>

        <Form.Item label="启用">
          <Switch checked={enabled} onChange={setEnabled} />
        </Form.Item>
      </Form>

      {/* Channel selection */}
      {channels.length > 0 && (
        <div style={{ padding: '0 16px 12px' }}>
          <div style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text)', marginBottom: 8 }}>通知渠道</div>
          {channels.map((ch) => (
            <div
              key={ch.id}
              onClick={() => {
                setChannelIds((prev) =>
                  prev.includes(ch.id) ? prev.filter((x) => x !== ch.id) : [...prev, ch.id]
                );
              }}
              style={{
                padding: '10px 14px',
                marginBottom: 6,
                borderRadius: 8,
                background: channelIds.includes(ch.id) ? 'var(--color-primary-light)' : 'var(--color-fill)',
                border: channelIds.includes(ch.id) ? '1px solid var(--color-primary)' : '1px solid transparent',
                cursor: 'pointer',
                fontSize: 14,
                color: 'var(--color-text)',
              }}
            >
              {ch.name} ({ch.type})
            </div>
          ))}
        </div>
      )}

      <div style={{ padding: '8px 16px 24px' }}>
        <Button block color="primary" loading={submitting} onClick={handleSubmit} style={{ borderRadius: 8 }}>
          {isEdit ? '保存修改' : '创建规则'}
        </Button>
      </div>
    </div>
  );
}
