<?php
/**
 * Home Guardian - 仪表盘模型
 *
 * 对应 dashboards 表，存储用户自定义的可视化仪表盘配置。
 * configuration 字段以 JSONB 格式存储仪表盘的布局和组件配置，
 * 前端 Vue3 应用根据此配置动态渲染仪表盘界面。
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dashboard extends Model
{
    protected $table = 'dashboards';

    protected $fillable = [
        'name',
        'description',
        'configuration',
        'owner_id',
    ];

    protected $casts = [
        'configuration' => 'array',    // JSONB → PHP 数组
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    /**
     * 仪表盘的所有者
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
