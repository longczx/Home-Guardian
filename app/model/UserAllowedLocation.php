<?php
/**
 * Home Guardian - 用户位置作用域模型
 *
 * 对应 user_allowed_locations 表，限定用户可访问的设备位置范围。
 * 作为 RBAC 权限系统的第二层（位置作用域），配合角色权限使用。
 *
 * 规则：
 *   - 表中无记录 → 不限制，可访问所有位置的设备
 *   - 表中有记录 → 只能看到和操作指定位置的设备
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAllowedLocation extends Model
{
    protected $table = 'user_allowed_locations';

    public $timestamps = false;
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'location',
    ];

    /**
     * 所属用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
