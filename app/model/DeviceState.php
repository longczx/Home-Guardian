<?php
/**
 * Home Guardian - 设备状态模型
 *
 * 对应 device_states 表，存储执行器的当前/期望状态（JSONB）。
 * 与 Redis 缓存（hg:device:state:{id}）双写：Redis 提供热读取，本表负责持久化与重启恢复。
 *
 * 状态来源：
 *   - 闭环设备：通过 state/post 上报真实状态（reported_at 记录上报时间）
 *   - 开环设备（如红外）：下发指令时更新为"期望状态"（虚拟状态）
 */

namespace app\model;

use support\Model;

class DeviceState extends Model
{
    protected $table = 'device_states';

    protected $primaryKey = 'device_id';
    public $incrementing = false;

    // 只有 updated_at，没有 created_at
    const CREATED_AT = null;

    protected $fillable = [
        'device_id',
        'state',
        'reported_at',
        'updated_at',
    ];

    protected $casts = [
        'state'       => 'array',
        'reported_at' => 'datetime',
        'updated_at'  => 'datetime',
    ];

    /**
     * 所属设备
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
