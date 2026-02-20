<?php
/**
 * Home Guardian - 设备模型
 *
 * 对应 devices 表，存储所有 IoT 设备的基本信息。
 * 设备通过 device_uid 在 MQTT 主题和 API 中唯一标识，
 * 通过 mqtt_username/mqtt_password_hash 进行 EMQX 连接认证。
 *
 * 关联关系：
 *   - attributes  → DeviceAttribute（一对多，设备的扩展属性）
 *   - commandLogs → CommandLog（一对多，下发的指令记录）
 *   - alertRules  → AlertRule（一对多，关联的告警规则）
 *   - alertLogs   → AlertLog（一对多，触发的告警记录）
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /**
     * 关联的数据表名
     */
    protected $table = 'devices';

    /**
     * 可批量赋值的字段
     *
     * 包含设备注册和更新时需要填写的字段。
     * mqtt_password_hash 不在此列，密码哈希由 Service 层控制写入。
     */
    protected $fillable = [
        'device_uid',
        'name',
        'type',
        'location',
        'firmware_version',
        'mqtt_username',
        'mqtt_password_hash',
        'last_seen',
        'is_online',
    ];

    /**
     * 隐藏字段
     *
     * JSON 序列化时自动隐藏敏感字段，防止 MQTT 密码哈希泄露给前端。
     */
    protected $hidden = [
        'mqtt_password_hash',
    ];

    /**
     * 属性类型转换
     */
    protected $casts = [
        'is_online'  => 'boolean',
        'last_seen'  => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ============================
     * 关联关系
     * ============================ */

    /**
     * 设备的扩展属性（如 MAC 地址、型号等）
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(DeviceAttribute::class, 'device_id');
    }

    /**
     * 设备收到的控制指令记录
     */
    public function commandLogs(): HasMany
    {
        return $this->hasMany(CommandLog::class, 'device_id');
    }

    /**
     * 设备关联的告警规则
     */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class, 'device_id');
    }

    /**
     * 设备触发的告警日志
     */
    public function alertLogs(): HasMany
    {
        return $this->hasMany(AlertLog::class, 'device_id');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 按设备类型筛选
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string $type 设备类型
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * 按设备位置筛选
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string $location 位置名称
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAtLocation($query, string $location)
    {
        return $query->where('location', $location);
    }

    /**
     * 只查询在线设备
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    /**
     * 按位置作用域列表筛选（用于权限过滤）
     *
     * 当 $locations 为空数组时不添加任何条件（表示不限制）。
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  array $locations 允许的位置列表
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInLocations($query, array $locations)
    {
        if (empty($locations)) {
            return $query;
        }

        return $query->whereIn('location', $locations);
    }
}
