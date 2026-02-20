<?php
/**
 * Home Guardian - 告警日志模型
 *
 * 对应 alert_logs 表，记录每次告警触发的详细信息。
 * 告警状态流转：triggered → acknowledged → resolved
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertLog extends Model
{
    protected $table = 'alert_logs';

    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'device_id',
        'triggered_at',
        'triggered_value',
        'status',
        'acknowledged_by',
        'acknowledged_at',
    ];

    protected $casts = [
        'triggered_value' => 'array',    // JSONB
        'triggered_at'    => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /**
     * 告警状态常量
     */
    const STATUS_TRIGGERED    = 'triggered';     // 已触发，等待处理
    const STATUS_ACKNOWLEDGED = 'acknowledged';  // 已确认，正在处理
    const STATUS_RESOLVED     = 'resolved';      // 已解决

    /**
     * 触发此告警的规则
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'rule_id');
    }

    /**
     * 触发告警的设备
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /**
     * 确认告警的用户
     */
    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 按告警状态筛选
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 未处理的告警
     */
    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [
            self::STATUS_TRIGGERED,
            self::STATUS_ACKNOWLEDGED,
        ]);
    }
}
