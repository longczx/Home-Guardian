import type { ControlPoint } from '@/api/types';

export function controlOptions(control: ControlPoint, state: Record<string, unknown>) {
  const dependency = control.options_by;
  return dependency ? dependency.values[String(state[dependency.field] ?? 'COOLIX')] ?? [] : control.options ?? [];
}

export function updateControlValues(controls: ControlPoint[], state: Record<string, unknown>, field: string, value: unknown) {
  const next = { ...state, [field]: value };
  for (const control of controls) {
    if (control.options_by?.field !== field) continue;
    const options = controlOptions(control, next);
    next[control.param] = options.find((o) => o.value === control.default)?.value ?? options[0]?.value;
  }
  return next;
}
