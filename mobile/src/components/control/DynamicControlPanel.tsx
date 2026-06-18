import { useEffect, useMemo, useRef, useState } from 'react';
import { Switch, Slider, Stepper, Selector, Button } from 'antd-mobile';
import type { Capability, ControlPoint } from '@/api/device';

interface Props {
  capability: Capability;
  state?: Record<string, unknown>;
  onCommand: (action: string, params: Record<string, unknown>) => void;
}

type StateMap = Record<string, unknown>;

/** 控制点的状态键（state_key 优先，回退 param） */
function stateKeyOf(c: ControlPoint): string {
  return c.state_key ?? c.param;
}

/** 用真实状态 + 各控制点 default 拼出初始 desired state */
function buildInitial(capability: Capability, state?: StateMap): StateMap {
  const next: StateMap = {};
  for (const c of capability.controls) {
    const key = stateKeyOf(c);
    if (state && key in state) next[key] = state[key];
    else if ('default' in c) next[key] = c.default;
  }
  return next;
}

/** merge 模式：把 desired state 映射成完整 params {param: value} */
function toParams(capability: Capability, desired: StateMap): Record<string, unknown> {
  const params: Record<string, unknown> = {};
  for (const c of capability.controls) {
    params[c.param] = desired[stateKeyOf(c)];
  }
  return params;
}

export default function DynamicControlPanel({ capability, state, onCommand }: Props) {
  const isMerge = capability.control_mode === 'merge';
  const [desired, setDesired] = useState<StateMap>(() => buildInitial(capability, state));

  // 外部状态（WS 推送 / 重新拉取）变化时同步到本地
  useEffect(() => {
    setDesired(buildInitial(capability, state));
    // 仅在传入状态引用变化时同步
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state]);

  // 简易防抖：连续操作（如滑块、温度连点）合并成一次下发
  const timers = useRef<Record<string, ReturnType<typeof setTimeout>>>({});
  const emit = (action: string, params: Record<string, unknown>, key: string) => {
    if (timers.current[key]) clearTimeout(timers.current[key]);
    timers.current[key] = setTimeout(() => onCommand(action, params), 300);
  };

  const controls = useMemo(
    () => [...capability.controls].sort((a, b) => (a.order ?? 0) - (b.order ?? 0)),
    [capability.controls],
  );

  const handleChange = (c: ControlPoint, value: unknown) => {
    const next = { ...desired, [stateKeyOf(c)]: value };
    setDesired(next);
    if (isMerge) {
      emit(c.command, toParams(capability, next), 'merge');
    } else {
      emit(c.command, { [c.param]: value }, c.key);
    }
  };

  /** depends_on：依赖条件不满足时禁用该控件 */
  const isDisabled = (c: ControlPoint): boolean => {
    if (!c.depends_on) return false;
    return Object.entries(c.depends_on).some(([k, v]) => desired[k] !== v);
  };

  const renderWidget = (c: ControlPoint) => {
    const disabled = isDisabled(c);
    const key = stateKeyOf(c);
    const val = desired[key];

    switch (c.widget) {
      case 'switch':
        return (
          <Switch
            checked={Boolean(val)}
            disabled={disabled}
            onChange={(checked) => handleChange(c, checked)}
          />
        );

      case 'slider':
        return (
          <div style={{ width: 160 }}>
            <Slider
              value={typeof val === 'number' ? val : 0}
              min={c.min ?? 0}
              max={c.max ?? 100}
              step={c.step ?? 1}
              disabled={disabled}
              onChange={(v) => handleChange(c, Array.isArray(v) ? v[0] : v)}
            />
          </div>
        );

      case 'stepper':
        return (
          <Stepper
            value={typeof val === 'number' ? val : (c.min ?? 0)}
            min={c.min}
            max={c.max}
            step={c.step ?? 1}
            disabled={disabled}
            onChange={(v) => handleChange(c, v)}
          />
        );

      case 'enum':
        return (
          <Selector
            options={(c.options ?? []).map((o) => ({ label: o.label, value: o.value }))}
            value={val !== undefined && val !== null ? [val as string | number] : []}
            disabled={disabled}
            onChange={(arr) => { if (arr.length) handleChange(c, arr[0]); }}
          />
        );

      case 'button':
        return (
          <Button size="small" disabled={disabled} onClick={() => onCommand(c.command, {})}>
            执行
          </Button>
        );

      case 'number':
      case 'text':
      default:
        return (
          <input
            className="dyn-control-input"
            type={c.widget === 'number' ? 'number' : 'text'}
            defaultValue={val as string | number | undefined}
            disabled={disabled}
            onBlur={(e) => handleChange(c, c.widget === 'number' ? Number(e.target.value) : e.target.value)}
            style={{ width: 120, height: 32, border: '1px solid var(--color-border, #e6e6e6)', borderRadius: 8, padding: '0 8px' }}
          />
        );
    }
  };

  return (
    <div className="surface-card detail-section-card" style={{ padding: 16 }}>
      <div style={{ fontSize: 15, fontWeight: 600, color: 'var(--color-text)', marginBottom: 16 }}>
        设备控制
      </div>
      {controls.map((c) => {
        const isWide = c.widget === 'enum' || c.widget === 'slider';
        return (
          <div
            key={c.key}
            style={{
              display: 'flex',
              flexDirection: isWide ? 'column' : 'row',
              alignItems: isWide ? 'stretch' : 'center',
              justifyContent: 'space-between',
              gap: isWide ? 8 : 12,
              marginBottom: 18,
              opacity: isDisabled(c) ? 0.5 : 1,
            }}
          >
            <span style={{ color: 'var(--color-text)' }}>
              {c.icon ? `${c.icon} ` : ''}{c.label}
              {c.unit && typeof desired[stateKeyOf(c)] === 'number' ? `：${desired[stateKeyOf(c)]}${c.unit}` : ''}
            </span>
            <div style={{ alignSelf: isWide ? 'stretch' : 'auto' }}>{renderWidget(c)}</div>
          </div>
        );
      })}
    </div>
  );
}
