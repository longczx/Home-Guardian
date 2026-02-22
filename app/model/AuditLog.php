<?php
/**
 * Home Guardian - 审计日志模型
 *
 * 对应 audit_logs 表，记录所有关键用户操作，用于安全审计和问题追溯。
 * 该表只写不改（append-only），数据一旦写入不允许修改或删除。
 *
 * 记录范围：
 *   - 认证操作：login / logout
 *   - 设备控制：control / command_send
 *   - 资源管理：create / update / delete
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    /**
     * 审计日志只有 created_at，没有 updated_at（只写不改）
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'resource_type',
        'resource_id',
        'detail',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'detail'     => 'array',     // JSONB → PHP 数组
        'created_at' => 'datetime',
    ];

    /**
     * UPDATED_AT = null 会在 PHP 8.1+ 触发 "null as array offset" 弃用警告，
     * 此处过滤掉 null，避免 toArray() 时报错。
     */
    public function getDates(): array
    {
        return array_values(array_filter(parent::getDates()));
    }

    /**
     * 操作类型常量
     */
    const ACTION_LOGIN        = 'login';
    const ACTION_LOGOUT       = 'logout';
    const ACTION_CREATE       = 'create';
    const ACTION_UPDATE       = 'update';
    const ACTION_DELETE       = 'delete';
    const ACTION_CONTROL      = 'control';
    const ACTION_COMMAND_SEND = 'command_send';

    /**
     * 操作人（用户被删除后此关联返回 null，但日志保留）
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 按操作类型筛选
     */
    public function scopeWithAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * 按资源类型和 ID 筛选
     */
    public function scopeForResource($query, string $resourceType, ?int $resourceId = null)
    {
        $query->where('resource_type', $resourceType);

        if ($resourceId !== null) {
            $query->where('resource_id', $resourceId);
        }

        return $query;
    }

    /**
     * 按时间范围筛选
     */
    public function scopeBetween($query, string $start, string $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}
