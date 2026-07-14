export interface Paginated<T> {
  items: T[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

export interface MetricField {
  key: string;
  label: string;
  unit?: string;
  icon?: string;
}

export type ControlWidget = 'switch' | 'stepper' | 'slider' | 'enum' | 'button' | 'number' | 'text';

export interface ControlPoint {
  key: string;
  label: string;
  widget: ControlWidget;
  value_type: 'bool' | 'int' | 'float' | 'string' | 'enum';
  command: string;
  param: string;
  state_key?: string;
  min?: number;
  max?: number;
  step?: number;
  unit?: string;
  options?: { label: string; value: string | number }[];
  default?: unknown;
  group?: string;
  order?: number;
}

export interface Capability {
  control_mode: 'merge' | 'discrete';
  controls: ControlPoint[];
}

export interface Device {
  id: number;
  device_uid: string;
  name: string;
  type: string;
  location: string | null;
  firmware_version: string | null;
  is_online: boolean;
  last_seen: string | null;
  metric_fields: MetricField[] | null;
  gateway_uid: string | null;
  capability: Capability | null;
  state?: Record<string, unknown>;
}

export interface LatestMetric {
  metric_key: string;
  value: unknown;
  ts: string;
}

export interface AggregatedPoint {
  bucket: string;
  avg_value: number;
  min_value: number;
  max_value: number;
  sample_count: number;
}

export interface AlertLog {
  id: number;
  rule: { id: number; name: string } | null;
  device: { id: number; name: string; location: string } | null;
  status: 'triggered' | 'acknowledged' | 'resolved';
  severity: 'info' | 'warning' | 'critical' | null;
  triggered_at: string;
  resolved_at: string | null;
  acknowledged_at: string | null;
  triggered_value: unknown;
  message: string | null;
}
