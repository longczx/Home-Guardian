<?php
/**
 * Home Guardian - 设备配网配对码模型
 *
 * 对应 provision_codes 表。用户在移动端点"添加设备"时生成一个短时有效的配对码，
 * 交给设备（经 SoftAP 配网页）；设备连上网后凭该码向平台自注册。
 * 配对码本身即信任凭证：只有登录用户能生成，注册出的设备自动归属该用户 + 位置。
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisionCode extends Model
{
    protected $table = 'provision_codes';

    protected $fillable = [
        'code',
        'user_id',
        'location',
        'status',
        'device_uid',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_PENDING    = 'pending';
    const STATUS_REGISTERED = 'registered';
    const STATUS_EXPIRED    = 'expired';

    /**
     * 生成该码的用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
