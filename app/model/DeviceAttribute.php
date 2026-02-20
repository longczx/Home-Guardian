<?php
/**
 * Home Guardian - 设备属性模型
 *
 * 对应 device_attributes 表，存储设备的静态扩展属性。
 * 采用 EAV（Entity-Attribute-Value）模式，提供极高的扩展性，
 * 无需修改数据表即可为不同类型的设备添加不同的属性。
 *
 * 典型属性示例：
 *   - mac_address: "AA:BB:CC:DD:EE:FF"
 *   - chip_model:  "ESP32-S3"
 *   - sensor_type: "DHT22"
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAttribute extends Model
{
    protected $table = 'device_attributes';

    /**
     * 该表没有 created_at / updated_at 字段
     */
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'attribute_key',
        'attribute_value',
    ];

    /**
     * 所属设备
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
