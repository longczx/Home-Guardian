<?php
/**
 * Home Guardian - 角色模型
 *
 * 对应 roles 表，定义系统中的角色及其权限集合。
 * 权限以 JSONB 格式存储，格式为 {"资源": ["操作1", "操作2"]}。
 *
 * 预置角色：
 *   - admin:  {"admin": true} — 超级管理员，拥有全部权限
 *   - member: {"devices": ["view", "control"], ...} — 家庭成员
 *   - guest:  {"devices": ["view"], ...} — 访客，仅查看
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $table = 'roles';

    /**
     * roles 表没有 created_at / updated_at 字段
     */
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',  // JSONB → PHP 数组
    ];

    /**
     * 拥有该角色的用户（多对多）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}
