<?php
/**
 * Home Guardian - 执行器能力模板模型
 *
 * 对应 capability_templates 表，存储可复用的整设备控制能力模板（空调/调光灯/开关）。
 * 新建/编辑设备时从模板填充到 devices.capability。
 *
 * controls 是控制点数组，每个控制点描述一个 UI 控件及其映射的指令，结构见迁移文件与
 * ActuatorService 的校验逻辑。control_mode 决定下发方式：
 *   - discrete: 每个控制点发自己的 action
 *   - merge:    所有控制点共同维护一份状态，每次发完整状态（action 固定为 set_state）
 */

namespace app\model;

use support\Model;

class CapabilityTemplate extends Model
{
    protected $table = 'capability_templates';

    protected $fillable = [
        'name',
        'device_category',
        'control_mode',
        'controls',
        'description',
    ];

    protected $casts = [
        'controls'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const MODE_DISCRETE = 'discrete';
    const MODE_MERGE    = 'merge';

    /**
     * 合法控件类型
     */
    const VALID_WIDGETS = ['switch', 'stepper', 'slider', 'enum', 'button', 'number', 'text'];

    /**
     * 合法值类型
     */
    const VALID_VALUE_TYPES = ['bool', 'int', 'float', 'string', 'enum'];
}
