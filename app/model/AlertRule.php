<?php
/**
 * Home Guardian - 告警规则模型
 *
 * 对应 alert_rules 表，定义遥测数据的告警触发条件。
 * 告警引擎进程（AlertEngineProcess）在内存中加载所有启用的规则，
 * 并对每条遥测数据进行实时匹配。
 *
 * 条件判断支持：GREATER_THAN / LESS_THAN / EQUALS / NOT_EQUALS / BETWEEN / NOT_BETWEEN
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

    public function getDates()
    {
        return array_filter(parent::getDates());
    }

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
        'severity',
        'notify_cooldown_sec',
        'notify_on_recovery',
        'trigger_type',
        'offline_timeout_sec',
    ];

    protected $casts = [
        'threshold_value'         => 'array',   // JSONB
        'notification_channel_ids' => 'array',   // JSONB: [1, 3]
        'trigger_duration_sec'     => 'integer',
        'is_enabled'              => 'boolean',
        'created_at'              => 'datetime',
        'notify_cooldown_sec'     => 'integer',
        'notify_on_recovery'      => 'boolean',
        'offline_timeout_sec'     => 'integer',
    ];

    /**
     * 条件常量
     */
    const CONDITION_GREATER_THAN = 'GREATER_THAN';
    const CONDITION_LESS_THAN    = 'LESS_THAN';
    const CONDITION_EQUALS       = 'EQUALS';
    const CONDITION_NOT_EQUALS   = 'NOT_EQUALS';
    const CONDITION_BETWEEN      = 'BETWEEN';
    const CONDITION_NOT_BETWEEN  = 'NOT_BETWEEN';

    /**
     * 所有合法的条件类型（供创建/更新时校验）
     */
    const VALID_CONDITIONS = [
        self::CONDITION_GREATER_THAN,
        self::CONDITION_LESS_THAN,
        self::CONDITION_EQUALS,
        self::CONDITION_NOT_EQUALS,
        self::CONDITION_BETWEEN,
        self::CONDITION_NOT_BETWEEN,
    ];

    /* 告警分级 */
    const SEVERITY_INFO     = 'info';
    const SEVERITY_WARNING  = 'warning';
    const SEVERITY_CRITICAL = 'critical';
    const VALID_SEVERITIES  = [self::SEVERITY_INFO, self::SEVERITY_WARNING, self::SEVERITY_CRITICAL];

    /* 触发类型 */
    const TRIGGER_TELEMETRY = 'telemetry';  // 遥测阈值
    const TRIGGER_OFFLINE   = 'offline';     // 设备离线
    const VALID_TRIGGER_TYPES = [self::TRIGGER_TELEMETRY, self::TRIGGER_OFFLINE];

    /**
     * severity 的中文标签（用于通知标题）
     */
    public static function severityLabel(?string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_INFO     => '提醒',
            self::SEVERITY_CRITICAL => '严重',
            default                 => '警告',
        };
    }

    /**
     * 只查询遥测型规则（供告警引擎加载）
     */
    public function scopeTelemetryType($query)
    {
        return $query->where('trigger_type', self::TRIGGER_TELEMETRY);
    }

    /**
     * 只查询离线型规则
     */
    public function scopeOfflineType($query)
    {
        return $query->where('trigger_type', self::TRIGGER_OFFLINE);
    }

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

        $numericValue = is_numeric($value) ? (float)$value : null;
        if ($numericValue === null) {
            return false;
        }

        // 区间条件：threshold_value 需为 [min, max] 两元素数组
        if ($this->condition === self::CONDITION_BETWEEN || $this->condition === self::CONDITION_NOT_BETWEEN) {
            if (!is_array($threshold) || count($threshold) < 2
                || !is_numeric($threshold[0]) || !is_numeric($threshold[1])) {
                return false;
            }
            $min = (float)$threshold[0];
            $max = (float)$threshold[1];
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }
            $inside = $numericValue >= $min && $numericValue <= $max;
            return $this->condition === self::CONDITION_BETWEEN ? $inside : !$inside;
        }

        // 单阈值条件：数组格式取第一个元素（兼容 JSONB 存储格式）
        if (is_array($threshold)) {
            $threshold = $threshold[0] ?? null;
        }
        $numericThreshold = is_numeric($threshold) ? (float)$threshold : null;
        if ($numericThreshold === null) {
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
