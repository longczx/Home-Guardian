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
use OpenApi\Attributes as OA;

#[OA\Tag(name: '遥测数据', description: '设备遥测数据查询（只读）')]
class TelemetryController
{
    /**
     * 查询原始遥测数据
     *
     * GET /api/telemetry?device_id=1&metric_key=temperature&start=2026-02-01&end=2026-02-20&per_page=50
     *
     * 必须指定 device_id，并自动应用位置作用域。
     */
    #[OA\Get(
        path: '/telemetry',
        summary: '原始遥测数据',
        description: '查询指定设备的原始遥测记录，支持按指标和时间范围筛选。必须指定 device_id。',
        security: [['bearerAuth' => []]],
        tags: ['遥测数据'],
    )]
    #[OA\Parameter(name: 'device_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'metric_key', in: 'query', description: '指标名（如 temperature）', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'start', in: 'query', description: '起始时间（ISO 8601）', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\Parameter(name: 'end', in: 'query', description: '结束时间（ISO 8601）', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 500))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
    #[OA\Response(response: 422, description: '缺少 device_id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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
    #[OA\Get(
        path: '/telemetry/latest',
        summary: '设备最新遥测值',
        description: '获取指定设备各指标的最新值。优先从 Redis 缓存读取，未命中则查询数据库。',
        security: [['bearerAuth' => []]],
        tags: ['遥测数据'],
    )]
    #[OA\Parameter(name: 'device_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                properties: [
                    new OA\Property(property: 'metric_key', type: 'string', example: 'temperature'),
                    new OA\Property(property: 'value', example: 25.6),
                    new OA\Property(property: 'ts', type: 'string', format: 'date-time'),
                ]
            )),
        ]
    ))]
    #[OA\Response(response: 422, description: '缺少 device_id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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
            $data = json_decode($cached, true) ?: [];
            // 转换为与数据库查询一致的格式: [{metric_key, value, ts}, ...]
            $now = date('Y-m-d\TH:i:sP');
            $result = [];
            foreach ($data as $key => $value) {
                if ($key === 'timestamp' || $key === 'request_id') continue;
                $result[] = [
                    'metric_key' => $key,
                    'value'      => $value,
                    'ts'         => $now,
                ];
            }
            return api_success($result);
        }

        // Redis 未命中，从数据库查询每个指标的最新值
        $latest = Db::table('telemetry_logs')
            ->select('metric_key', 'value', 'ts')
            ->where('device_id', $deviceId)
            ->where('ts', '>=', now()->subHours(24))
            ->orderBy('ts', 'desc')
            ->get()
            ->unique('metric_key')  // 每个指标只取最新一条
            ->values()
            ->map(function ($item) {
                // value 是 JSONB 字段，解码为原生类型
                $item->value = json_decode($item->value, true) ?? $item->value;
                return $item;
            });

        return api_success($latest);
    }

    /**
     * 查询小时聚合数据（用于 Dashboard 历史图表）
     *
     * GET /api/telemetry/aggregated?device_id=1&metric_key=temperature&start=2026-01-01&end=2026-02-01
     *
     * 查询 telemetry_hourly 连续聚合视图，性能远优于查原始表。
     */
    #[OA\Get(
        path: '/telemetry/aggregated',
        summary: '小时聚合数据',
        description: '查询指定设备和指标的小时级聚合数据，用于图表展示。查询 telemetry_hourly 视图，性能优。',
        security: [['bearerAuth' => []]],
        tags: ['遥测数据'],
    )]
    #[OA\Parameter(name: 'device_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'metric_key', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'start', in: 'query', description: '起始时间（默认 7 天前）', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\Parameter(name: 'end', in: 'query', description: '结束时间（默认当前）', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                properties: [
                    new OA\Property(property: 'bucket', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'avg_value', type: 'number', example: 25.3),
                    new OA\Property(property: 'min_value', type: 'number', example: 24.1),
                    new OA\Property(property: 'max_value', type: 'number', example: 26.8),
                    new OA\Property(property: 'sample_count', type: 'integer', example: 60),
                ]
            )),
        ]
    ))]
    #[OA\Response(response: 422, description: '参数缺失', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
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

        // 计算时间跨度，短范围直接查原始表（连续聚合有 1 小时延迟）
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        $spanHours = ($endTs - $startTs) / 3600;

        if ($spanHours <= 24) {
            // 短范围：从原始表按 5 分钟分桶聚合
            $data = Db::select("
                SELECT
                    time_bucket('5 minutes', ts) AS bucket,
                    avg((value ->> 0)::NUMERIC)  AS avg_value,
                    min((value ->> 0)::NUMERIC)  AS min_value,
                    max((value ->> 0)::NUMERIC)  AS max_value,
                    count(*)                     AS sample_count
                FROM telemetry_logs
                WHERE device_id = ?
                  AND metric_key = ?
                  AND ts BETWEEN ? AND ?
                  AND (jsonb_typeof(value) = 'number'
                       OR (jsonb_typeof(value) = 'array' AND jsonb_typeof(value -> 0) = 'number'))
                GROUP BY bucket
                ORDER BY bucket ASC
            ", [$deviceId, $metricKey, $start, $end]);
        } else {
            // 长范围：从连续聚合视图查询
            $data = Db::table('telemetry_hourly')
                ->select('bucket', 'avg_value', 'min_value', 'max_value', 'sample_count')
                ->where('device_id', $deviceId)
                ->where('metric_key', $metricKey)
                ->whereBetween('bucket', [$start, $end])
                ->orderBy('bucket', 'asc')
                ->get();
        }

        return api_success($data);
    }
}
