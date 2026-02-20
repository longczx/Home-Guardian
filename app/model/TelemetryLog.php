<?php
/**
 * Home Guardian - 遥测数据模型
 *
 * 对应 telemetry_logs 表（TimescaleDB 超表）。
 * 存储所有设备上报的时序遥测数据，是系统中数据量最大的表。
 *
 * 特殊说明：
 *   1. 该表是 TimescaleDB 超表（hypertable），不支持外键约束
 *   2. 没有自增主键（id），以 ts + device_id 作为逻辑标识
 *   3. 数据写入走异步批量插入（DataIngestProcess），不在此 Model 中直接写入
 *   4. 查询时通常需要指定时间范围，避免全表扫描
 */

namespace app\model;

use support\Model;

class TelemetryLog extends Model
{
    protected $table = 'telemetry_logs';

    /**
     * 超表没有自增主键
     */
    public $incrementing = false;

    /**
     * 超表没有 created_at / updated_at 字段
     */
    public $timestamps = false;

    protected $fillable = [
        'ts',
        'device_id',
        'metric_key',
        'value',
    ];

    protected $casts = [
        'ts'        => 'datetime',
        'device_id' => 'integer',
        'value'     => 'array',  // JSONB 自动转换为 PHP 数组
    ];

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 按设备筛选
     */
    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * 按指标名称筛选
     */
    public function scopeForMetric($query, string $metricKey)
    {
        return $query->where('metric_key', $metricKey);
    }

    /**
     * 按时间范围筛选
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string $start 起始时间（ISO 8601 格式）
     * @param  string $end   结束时间（ISO 8601 格式）
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBetween($query, string $start, string $end)
    {
        return $query->whereBetween('ts', [$start, $end]);
    }

    /**
     * 查询最近 N 小时的数据
     */
    public function scopeRecentHours($query, int $hours = 24)
    {
        return $query->where('ts', '>=', now()->subHours($hours));
    }
}
