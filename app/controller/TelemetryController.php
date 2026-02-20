<?php
/**
 * Home Guardian - 遥测数据控制器
 *
 * 查询设备上报的遥测数据，支持时间范围筛选和聚合查询。
 *
 *   GET /api/telemetry                — 查询原始遥测数据
 *   GET /api/telemetry/latest         — 查询设备最新遥测值
 *   GET /api/telemetry/aggregated     — 查询小时聚合数据（用于历史图表）
 *
 * 注意：遥测数据只读，写入由 DataIngestProcess 异步批量完成。
 */

namespace app\controller;

use app\model\TelemetryLog;
use app\model\Device;
use support\Request;
use support\Db;

class TelemetryController
{
    /**
     * 查询原始遥测数据
     *
     * GET /api/telemetry?device_id=1&metric_key=temperature&start=2026-02-01&end=2026-02-20&per_page=50
     *
     * 必须指定 device_id，并自动应用位置作用域。
     */
    public function index(Request $request)
    {
        $deviceId = (int)$request->get('device_id', 0);
        if (!$deviceId) {
            return api_error('device_id 参数必填', 422, 1000);
        }

        // 验证设备存在且有权访问
        $device = Device::find($deviceId);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }
        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权访问该设备数据', 403, 1004);
        }

        $query = TelemetryLog::forDevice($deviceId);

        // 按指标筛选
        if ($metricKey = $request->get('metric_key')) {
            $query->forMetric($metricKey);
        }

        // 时间范围（默认最近 24 小时）
        $start = $request->get('start');
        $end = $request->get('end');
        if ($start && $end) {
            $query->between($start, $end);
        } else {
            $query->recentHours(24);
        }

        $perPage = min((int)($request->get('per_page', 50)), 500);
        $paginator = $query->orderBy('ts', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    /**
     * 查询设备最新遥测值
     *
     * GET /api/telemetry/latest?device_id=1
     *
     * 从 Redis 缓存中获取（由 MqttSubscriber 实时更新），
     * 如果缓存未命中则从数据库查询最新一条记录。
     */
    public function latest(Request $request)
    {
        $deviceId = (int)$request->get('device_id', 0);
        if (!$deviceId) {
            return api_error('device_id 参数必填', 422, 1000);
        }

        $device = Device::find($deviceId);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }
        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权访问该设备数据', 403, 1004);
        }

        // 尝试从 Redis 获取最新数据
        $cacheKey = "device:latest:{$deviceId}";
        $cached = \support\Redis::connection('default')->get($cacheKey);

        if ($cached) {
            return api_success(json_decode($cached, true));
        }

        // Redis 未命中，从数据库查询每个指标的最新值
        $latest = Db::table('telemetry_logs')
            ->select('metric_key', 'value', 'ts')
            ->where('device_id', $deviceId)
            ->where('ts', '>=', now()->subHours(24))
            ->orderBy('ts', 'desc')
            ->get()
            ->unique('metric_key')  // 每个指标只取最新一条
            ->values();

        return api_success($latest);
    }

    /**
     * 查询小时聚合数据（用于 Dashboard 历史图表）
     *
     * GET /api/telemetry/aggregated?device_id=1&metric_key=temperature&start=2026-01-01&end=2026-02-01
     *
     * 查询 telemetry_hourly 连续聚合视图，性能远优于查原始表。
     */
    public function aggregated(Request $request)
    {
        $deviceId = (int)$request->get('device_id', 0);
        $metricKey = $request->get('metric_key', '');

        if (!$deviceId || !$metricKey) {
            return api_error('device_id 和 metric_key 参数必填', 422, 1000);
        }

        $device = Device::find($deviceId);
        if (!$device) {
            return api_error('设备不存在', 404, 2001);
        }
        if (!$request->canAccessLocation($device->location)) {
            return api_error('无权访问该设备数据', 403, 1004);
        }

        // 时间范围（默认最近 7 天）
        $start = $request->get('start', now()->subDays(7)->toDateTimeString());
        $end = $request->get('end', now()->toDateTimeString());

        $data = Db::table('telemetry_hourly')
            ->select('bucket', 'avg_value', 'min_value', 'max_value', 'sample_count')
            ->where('device_id', $deviceId)
            ->where('metric_key', $metricKey)
            ->whereBetween('bucket', [$start, $end])
            ->orderBy('bucket', 'asc')
            ->get();

        return api_success($data);
    }
}
