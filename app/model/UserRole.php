<?php
/**
 * Home Guardian - 用户角色关联模型
 *
 * 对应 user_roles 中间表（多对多关联表），连接用户和角色。
 * 一个用户可以拥有多个角色，一个角色可以分配给多个用户。
 */

namespace app\model;

use support\Model;

class UserRole extends Model
{
    protected $table = 'user_roles';

    public $timestamps = false;

    /**
     * 复合主键（user_id + role_id）
     */
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'role_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'role_id' => 'integer',
    ];
}
