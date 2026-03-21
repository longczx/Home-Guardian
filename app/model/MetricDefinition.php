<?php
/**
 * Home Guardian - 指标定义模型
 *
 * 对应 metric_definitions 表，存储全局遥测指标的元数据（名称、单位、图标等）。
 */

namespace app\model;

use support\Model;

class MetricDefinition extends Model
{
    protected $table = 'metric_definitions';

    protected $fillable = [
        'metric_key',
        'label',
        'unit',
        'icon',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    /**
     * 按排序字段排序
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
