<?php
/**
 * Home Guardian - 告警规则控制器
 *
 *   GET    /api/alert-rules          — 规则列表
 *   GET    /api/alert-rules/{id}     — 规则详情
 *   POST   /api/alert-rules          — 创建规则
 *   PUT    /api/alert-rules/{id}     — 更新规则
 *   DELETE /api/alert-rules/{id}     — 删除规则
 */

namespace app\controller;

use app\model\AlertRule;
use app\service\AlertService;
use app\service\AuditService;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '告警规则', description: '告警规则的 CRUD')]
class AlertRuleController
{
    #[OA\Get(
        path: '/alert-rules',
        summary: '告警规则列表',
        description: '获取告警规则列表，支持按设备和启用状态筛选。自动按用户位置作用域过滤。',
        security: [['bearerAuth' => []]],
        tags: ['告警规则'],
    )]
    #[OA\Parameter(name: 'device_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'is_enabled', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: [0, 1]))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
    public function index(Request $request)
    {
        $query = AlertRule::with('device:id,device_uid,name,location');

        if ($deviceId = $request->get('device_id')) {
            $query->where('device_id', (int)$deviceId);
        }
        if ($request->get('is_enabled') !== null) {
            $query->where('is_enabled', (bool)$request->get('is_enabled'));
        }

        // 位置作用域过滤
        $locations = $request->user->locations ?? [];
        if (!empty($locations)) {
            $query->whereHas('device', function ($q) use ($locations) {
                $q->whereIn('location', $locations);
            });
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('id', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    #[OA\Get(
        path: '/alert-rules/{id}',
        summary: '告警规则详情',
        security: [['bearerAuth' => []]],
        tags: ['告警规则'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/AlertRule'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $rule = AlertRule::with(['device:id,device_uid,name,location', 'creator:id,username'])->find($id);

        if (!$rule) {
            return api_error('告警规则不存在', 404, 3001);
        }

        return api_success($rule);
    }

    #[OA\Post(
        path: '/alert-rules',
        summary: '创建告警规则',
        description: '创建新的告警规则。当设备遥测值满足条件并持续指定时间后触发告警。',
        security: [['bearerAuth' => []]],
        tags: ['告警规则'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'device_id', 'telemetry_key', 'condition', 'threshold_value'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: '温度过高'),
                new OA\Property(property: 'device_id', type: 'integer', example: 1),
                new OA\Property(property: 'telemetry_key', type: 'string', example: 'temperature'),
                new OA\Property(property: 'condition', type: 'string', example: 'GREATER_THAN', description: 'GREATER_THAN / LESS_THAN / EQUAL / NOT_EQUAL / BETWEEN / NOT_BETWEEN'),
                new OA\Property(property: 'threshold_value', description: '阈值，单值或数组（BETWEEN 用 [min, max]）', example: [35]),
                new OA\Property(property: 'trigger_duration_sec', type: 'integer', example: 60, description: '持续触发秒数（0=立即）'),
                new OA\Property(property: 'notification_channel_ids', type: 'array', items: new OA\Items(type: 'integer'), description: '通知渠道 ID 列表'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: '创建成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/AlertRule'),
        ]
    ))]
    #[OA\Response(response: 422, description: '参数验证失败', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function store(Request $request)
    {
        $data = $request->post();

        // 参数校验
        $errors = [];
        if (empty($data['name'])) $errors[] = 'name 不能为空';
        if (empty($data['device_id'])) $errors[] = 'device_id 不能为空';
        if (empty($data['telemetry_key'])) $errors[] = 'telemetry_key 不能为空';
        if (empty($data['condition'])) $errors[] = 'condition 不能为空';
        if (!isset($data['threshold_value'])) $errors[] = 'threshold_value 不能为空';

        if (!empty($data['condition']) && !in_array($data['condition'], AlertRule::VALID_CONDITIONS, true)) {
            $errors[] = 'condition 非法，必须是 ' . implode(' / ', AlertRule::VALID_CONDITIONS) . ' 之一';
        }

        if (!empty($errors)) {
            return api_error('参数验证失败', 422, 1000, $errors);
        }

        // 确保 threshold_value 以数组格式存储
        if (!is_array($data['threshold_value'])) {
            $data['threshold_value'] = [$data['threshold_value']];
        }

        // 区间条件需要 [min, max] 两个数值阈值
        if ($err = self::validateThreshold($data['condition'], $data['threshold_value'])) {
            return api_error('参数验证失败', 422, 1000, [$err]);
        }

        $data['created_by'] = $request->userId();

        $rule = AlertService::createRule($data);

        AuditService::log($request, 'create', 'alert_rule', $rule->id, [
            'name' => $rule->name,
        ]);

        return api_success($rule, '告警规则创建成功', 201);
    }

    #[OA\Put(
        path: '/alert-rules/{id}',
        summary: '更新告警规则',
        security: [['bearerAuth' => []]],
        tags: ['告警规则'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'telemetry_key', type: 'string'),
            new OA\Property(property: 'condition', type: 'string'),
            new OA\Property(property: 'threshold_value'),
            new OA\Property(property: 'trigger_duration_sec', type: 'integer'),
            new OA\Property(property: 'is_enabled', type: 'boolean'),
            new OA\Property(property: 'notification_channel_ids', type: 'array', items: new OA\Items(type: 'integer')),
        ]
    ))]
    #[OA\Response(response: 200, description: '更新成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/AlertRule'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function update(Request $request, int $id)
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            return api_error('告警规则不存在', 404, 3001);
        }

        $data = $request->post();
        $original = $rule->toArray();

        if (!empty($data['condition']) && !in_array($data['condition'], AlertRule::VALID_CONDITIONS, true)) {
            return api_error('参数验证失败', 422, 1000, ['condition 非法']);
        }

        if (isset($data['threshold_value']) && !is_array($data['threshold_value'])) {
            $data['threshold_value'] = [$data['threshold_value']];
        }

        // 校验区间阈值（condition 未传时沿用原值）
        $effectiveCondition = $data['condition'] ?? $rule->condition;
        $effectiveThreshold = $data['threshold_value'] ?? $rule->threshold_value;
        if ($err = self::validateThreshold($effectiveCondition, $effectiveThreshold)) {
            return api_error('参数验证失败', 422, 1000, [$err]);
        }

        $rule = AlertService::updateRule($id, $data);

        AuditService::log($request, 'update', 'alert_rule', $id,
            AuditService::diffChanges($original, $rule->toArray())
        );

        return api_success($rule, '告警规则更新成功');
    }

    #[OA\Delete(
        path: '/alert-rules/{id}',
        summary: '删除告警规则',
        security: [['bearerAuth' => []]],
        tags: ['告警规则'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function destroy(Request $request, int $id)
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            return api_error('告警规则不存在', 404, 3001);
        }

        AlertService::deleteRule($id);

        AuditService::log($request, 'delete', 'alert_rule', $id, [
            'name' => $rule->name,
        ]);

        return api_success(null, '告警规则已删除');
    }

    /**
     * 校验区间条件的阈值格式
     *
     * BETWEEN / NOT_BETWEEN 需要 threshold_value 为 [最小值, 最大值] 两个数值。
     *
     * @param  string|null $condition 条件类型
     * @param  mixed        $threshold 阈值（已归一化为数组）
     * @return string|null  错误信息，校验通过返回 null
     */
    private static function validateThreshold(?string $condition, mixed $threshold): ?string
    {
        if ($condition !== AlertRule::CONDITION_BETWEEN && $condition !== AlertRule::CONDITION_NOT_BETWEEN) {
            return null;
        }

        if (!is_array($threshold) || count($threshold) < 2
            || !is_numeric($threshold[0]) || !is_numeric($threshold[1])) {
            return 'BETWEEN / NOT_BETWEEN 需要 threshold_value 为 [最小值, 最大值] 两个数值';
        }

        return null;
    }
}
