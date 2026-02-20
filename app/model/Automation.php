<?php
/**
 * Home Guardian - 自动化规则模型
 *
 * 对应 automations 表，定义场景自动化规则。
 * 支持两种触发方式：
 *   - telemetry: 遥测数据条件触发（如温度 > 30°C 持续 60 秒）
 *   - schedule:  定时计划触发（如每晚 22:00）
 *
 * 每条规则可包含多个动作（actions 数组），按顺序执行：
 *   - device_command: 向指定设备发送 MQTT 控制指令
 *   - notify:         通过通知渠道发送消息
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Automation extends Model
{
    protected $table = 'automations';

    protected $fillable = [
        'name',
        'description',
        'trigger_type',
        'trigger_config',
        'actions',
        'is_enabled',
        'last_triggered_at',
        'created_by',
    ];

    protected $casts = [
        'trigger_config'    => 'array',    // JSONB
        'actions'           => 'array',    // JSONB: [{"type": "device_command", ...}]
        'is_enabled'        => 'boolean',
        'last_triggered_at' => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    /**
     * 触发类型常量
     */
    const TRIGGER_TELEMETRY = 'telemetry';  // 遥测条件触发
    const TRIGGER_SCHEDULE  = 'schedule';    // 定时计划触发

    /**
     * 动作类型常量
     */
    const ACTION_DEVICE_COMMAND = 'device_command';  // 设备控制
    const ACTION_NOTIFY         = 'notify';           // 发送通知

    /**
     * 创建者
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 只查询启用的自动化规则
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * 按触发类型筛选
     */
    public function scopeOfTriggerType($query, string $type)
    {
        return $query->where('trigger_type', $type);
    }
}
