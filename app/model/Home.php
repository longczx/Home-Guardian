<?php
/**
 * Home Guardian - 家庭模型
 *
 * 租户模型：一切资源（设备/规则/日志/自动化/渠道/配对码）挂在家庭之下。
 * 单家庭版全服只有一行（DEFAULT_HOME_ID = 1「我的家」），
 * 未来多家庭版直接放开创建入口即可。
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Home extends Model
{
    /** 单家庭版的默认家庭 ID */
    public const DEFAULT_HOME_ID = 1;

    protected $table = 'homes';

    protected $fillable = [
        'name',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 家庭成员（多对多，带家庭内角色）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'home_users', 'home_id', 'user_id')
            ->withPivot(['role', 'joined_at']);
    }

    /**
     * 成员关系记录
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(HomeUser::class, 'home_id');
    }

    /**
     * 家庭的设备
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'home_id');
    }
}
