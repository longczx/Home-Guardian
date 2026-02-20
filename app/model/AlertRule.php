<?php
/**
 * Home Guardian - 告警规则模型
 *
 * 对应 alert_rules 表，定义遥测数据的告警触发条件。
 * 告警引擎进程（AlertEngineProcess）在内存中加载所有启用的规则，
 * 并对每条遥测数据进行实时匹配。
 *
 * 条件判断支持：GREATER_THAN / LESS_THAN / EQUALS / NOT_EQUALS
 * 防抖机制：trigger_duration_sec 秒内持续满足条件才触发告警
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    protected $table = 'alert_rules';

    /**
     * alert_rules 只有 created_at，没有 updated_at
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'description',
        'device_id',
        'telemetry_key',
        'condition',
        'threshold_value',
        'trigger_duration_sec',
        'notification_channel_ids',
        'is_enabled',
        'created_by',
    ];

    protected $casts = [
        'threshold_value'         => 'array',   // JSONB
        'notification_channel_ids' => 'array',   // JSONB: [1, 3]
        'trigger_duration_sec'     => 'integer',
        'is_enabled'              => 'boolean',
        'created_at'              => 'datetime',
    ];

    /**
     * 条件常量
     */
    const CONDITION_GREATER_THAN = 'GREATER_THAN';
    const CONDITION_LESS_THAN    = 'LESS_THAN';
    const CONDITION_EQUALS       = 'EQUALS';
    const CONDITION_NOT_EQUALS   = 'NOT_EQUALS';

    /* ============================
     * 关联关系
     * ============================ */

    /**
     * 关联的设备
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /**
     * 创建者
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 该规则触发的告警记录
     */
    public function alertLogs(): HasMany
    {
        return $this->hasMany(AlertLog::class, 'rule_id');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 只查询启用的规则
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /* ============================
     * 业务方法
     * ============================ */

    /**
     * 判断给定的遥测值是否满足此规则的告警条件
     *
     * @param  mixed $value 遥测值（数字类型）
     * @return bool 是否满足告警条件
     */
    public function evaluate(mixed $value): bool
    {
        $threshold = $this->threshold_value;

        // 如果阈值是数组格式，取第一个元素（兼容 JSONB 存储格式）
        if (is_array($threshold)) {
            $threshold = $threshold[0] ?? $threshold;
        }

        // 转为数值进行比较
        $numericValue = is_numeric($value) ? (float)$value : null;
        $numericThreshold = is_numeric($threshold) ? (float)$threshold : null;

        if ($numericValue === null || $numericThreshold === null) {
            return false;
        }

        return match ($this->condition) {
            self::CONDITION_GREATER_THAN => $numericValue > $numericThreshold,
            self::CONDITION_LESS_THAN    => $numericValue < $numericThreshold,
            self::CONDITION_EQUALS       => abs($numericValue - $numericThreshold) < 0.0001,
            self::CONDITION_NOT_EQUALS   => abs($numericValue - $numericThreshold) >= 0.0001,
            default                      => false,
        };
    }
}
