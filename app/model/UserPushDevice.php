<?php
/**
 * Home Guardian - 用户推送设备模型
 *
 * 对应 user_push_devices 表。一个用户可在多端登录 → 多条 cid 记录。
 * 不挂 HomeScope：推送由常驻进程按 home_id 显式查询，无请求上下文。
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPushDevice extends Model
{
    protected $table = 'user_push_devices';

    protected $fillable = [
        'user_id',
        'home_id',
        'cid',
        'platform',
        'push_enabled',
        'min_severity',
        'last_active_at',
    ];

    protected $casts = [
        'push_enabled'   => 'boolean',
        'last_active_at' => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    /** 级别权重，用于 min_severity 过滤 */
    public const SEVERITY_WEIGHT = [
        'info'     => 1,
        'warning'  => 2,
        'critical' => 3,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 给定告警级别，本设备是否应接收（push_enabled 且级别达标）
     */
    public function acceptsSeverity(?string $severity): bool
    {
        if (!$this->push_enabled) {
            return false;
        }
        $incoming = self::SEVERITY_WEIGHT[$severity ?? 'warning'] ?? 2;
        $threshold = self::SEVERITY_WEIGHT[$this->min_severity] ?? 2;
        return $incoming >= $threshold;
    }
}
