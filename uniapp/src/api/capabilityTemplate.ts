import request from './request';
import type { ControlPoint } from './types';

/**
 * 能力模板（capability_templates）
 *
 * 预置的执行器控制能力（空调/开关/调光灯/窗帘…），决定设备详情页渲染哪些控件。
 * 给设备套上模板后，其 capability 字段即被填充，App 按 schema 渲染控制卡。
 * 模板本身的增删改在 Admin 后台维护，App 端只读取选用。
 */
export interface CapabilityTemplate {
  id: number;
  name: string;
  device_category: string | null;
  control_mode: 'merge' | 'discrete';
  controls: ControlPoint[];
  description: string | null;
}

/** 全部能力模板 */
export function getCapabilityTemplates() {
  return request.get<CapabilityTemplate[]>('/capability-templates');
}
