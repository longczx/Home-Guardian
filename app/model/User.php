<?php
/**
 * Home Guardian - 用户模型
 *
 * 对应 users 表，存储系统用户信息。
 * 密码以 bcrypt 哈希存储，绝不明文保存。
 *
 * 关联关系：
 *   - roles             → Role（多对多，通过 user_roles 中间表）
 *   - allowedLocations  → UserAllowedLocation（一对多，位置作用域）
 *   - refreshTokens     → RefreshToken（一对多，登录会话）
 *   - dashboards        → Dashboard（一对多，拥有的仪表盘）
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $table = 'users';

    protected $fillable = [
        'username',
        'password_hash',
        'email',
        'full_name',
        'is_active',
    ];

    /**
     * 序列化时隐藏敏感字段
     */
    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ============================
     * 关联关系
     * ============================ */

    /**
     * 用户拥有的角色（多对多）
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    /**
     * 用户的位置作用域限制
     */
    public function allowedLocations(): HasMany
    {
        return $this->hasMany(UserAllowedLocation::class, 'user_id');
    }

    /**
     * 用户的 refresh_token 记录
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'user_id');
    }

    /**
     * 用户创建的仪表盘
     */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class, 'owner_id');
    }

    /* ============================
     * 业务方法
     * ============================ */

    /**
     * 验证密码是否匹配
     *
     * @param  string $password 明文密码
     * @return bool
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    /**
     * 设置密码（自动哈希）
     *
     * @param string $password 明文密码
     */
    public function setPassword(string $password): void
    {
        $this->password_hash = password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * 获取用户的合并权限集合
     *
     * 用户可能拥有多个角色，需要合并所有角色的权限。
     * 如果任一角色是 admin，直接返回 {"admin": true}。
     *
     * @return array 合并后的权限集合
     */
    public function getMergedPermissions(): array
    {
        $merged = [];

        foreach ($this->roles as $role) {
            $permissions = $role->permissions ?? [];

            // 任一角色是 admin，直接返回全权限
            if (!empty($permissions['admin'])) {
                return ['admin' => true];
            }

            // 合并各角色的权限（同一资源的操作取并集）
            foreach ($permissions as $resource => $actions) {
                if (!isset($merged[$resource])) {
                    $merged[$resource] = [];
                }
                $merged[$resource] = array_unique(
                    array_merge($merged[$resource], (array)$actions)
                );
            }
        }

        return $merged;
    }

    /**
     * 获取用户允许访问的位置列表
     *
     * @return array 位置名称数组，空数组表示不限制
     */
    public function getAllowedLocationList(): array
    {
        return $this->allowedLocations->pluck('location')->toArray();
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 只查询激活状态的用户
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
