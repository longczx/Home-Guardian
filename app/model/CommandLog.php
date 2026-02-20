<?php
/**
 * Home Guardian - 指令日志模型
 *
 * 对应 command_logs 表，记录所有从服务端下发给设备的控制指令。
 * 每条指令通过 request_id 唯一标识，设备回复时原样返回该 ID，
 * 实现指令的完整生命周期追踪（sent → delivered → replied_ok / replied_error / timeout）。
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandLog extends Model
{
    protected $table = 'command_logs';

    /**
     * 该表使用 sent_at 和 replied_at，不使用 Eloquent 默认的 created_at / updated_at
     */
    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'device_id',
        'topic',
        'payload',
        'status',
        'sent_at',
        'replied_at',
    ];

    protected $casts = [
        'payload'    => 'array',   // JSONB → PHP 数组
        'sent_at'    => 'datetime',
        'replied_at' => 'datetime',
    ];

    /**
     * 指令状态常量
     */
    const STATUS_SENT          = 'sent';           // 已发送到 MQTT
    const STATUS_DELIVERED     = 'delivered';       // 设备已确认收到
    const STATUS_REPLIED_OK    = 'replied_ok';      // 设备执行成功
    const STATUS_REPLIED_ERROR = 'replied_error';   // 设备执行失败
    const STATUS_TIMEOUT       = 'timeout';         // 超时未回复

    /**
     * 所属设备
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 按状态筛选
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 查找等待回复的指令（用于超时检测）
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }
}
