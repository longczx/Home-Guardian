<?php
/**
 * Home Guardian - 自动化控制器
 *
 *   GET    /api/automations          — 自动化列表
 *   GET    /api/automations/{id}     — 自动化详情
 *   POST   /api/automations          — 创建自动化
 *   PUT    /api/automations/{id}     — 更新自动化
 *   DELETE /api/automations/{id}     — 删除自动化
 */

namespace app\controller;

use app\model\Automation;
use app\service\AutomationService;
use app\service\AuditService;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '自动化', description: '自动化规则的 CRUD')]
class AutomationController
{
    #[OA\Get(
        path: '/automations',
        summary: '自动化列表',
        description: '获取自动化规则列表，支持按触发类型和启用状态筛选。',
        security: [['bearerAuth' => []]],
        tags: ['自动化'],
    )]
    #[OA\Parameter(name: 'trigger_type', in: 'query', description: 'telemetry / schedule', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'is_enabled', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: [0, 1]))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
    public function index(Request $request)
    {
        $query = Automation::query();

        if ($triggerType = $request->get('trigger_type')) {
            $query->ofTriggerType($triggerType);
        }
        if ($request->get('is_enabled') !== null) {
            $query->where('is_enabled', (bool)$request->get('is_enabled'));
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('id', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    #[OA\Get(
        path: '/automations/{id}',
        summary: '自动化详情',
        security: [['bearerAuth' => []]],
        tags: ['自动化'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Automation'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $automation = Automation::with('creator:id,username')->find($id);

        if (!$automation) {
            return api_error('自动化规则不存在', 404, 4001);
        }

        return api_success($automation);
    }

    #[OA\Post(
        path: '/automations',
        summary: '创建自动化',
        description: '创建新的自动化规则。支持遥测触发（telemetry）和定时触发（schedule）两种类型。',
        security: [['bearerAuth' => []]],
        tags: ['自动化'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'trigger_type', 'trigger_config', 'actions'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: '温度过高开空调'),
                new OA\Property(property: 'trigger_type', type: 'string', enum: ['telemetry', 'schedule']),
                new OA\Property(property: 'trigger_config', type: 'object', example: ['device_id' => 1, 'metric_key' => 'temperature', 'condition' => 'GREATER_THAN', 'value' => 30]),
                new OA\Property(property: 'actions', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'device_command'),
                        new OA\Property(property: 'device_id', type: 'integer', example: 2),
                        new OA\Property(property: 'payload', type: 'object', example: ['action' => 'turn_on']),
                    ]
                )),
                new OA\Property(property: 'is_enabled', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 201, description: '创建成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Automation'),
        ]
    ))]
    #[OA\Response(response: 422, description: '参数验证失败', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function store(Request $request)
    {
        $data = $request->post();

        $errors = [];
        if (empty($data['name'])) $errors[] = 'name 不能为空';
        if (empty($data['trigger_type'])) $errors[] = 'trigger_type 不能为空';
        if (empty($data['trigger_config'])) $errors[] = 'trigger_config 不能为空';
        if (empty($data['actions'])) $errors[] = 'actions 不能为空';

        $validTriggerTypes = ['telemetry', 'schedule'];
        if (!empty($data['trigger_type']) && !in_array($data['trigger_type'], $validTriggerTypes)) {
            $errors[] = 'trigger_type 必须是 telemetry 或 schedule';
        }

        if (!empty($errors)) {
            return api_error('参数验证失败', 422, 1000, $errors);
        }

        $data['created_by'] = $request->userId();

        $automation = AutomationService::create($data);

        AuditService::log($request, 'create', 'automation', $automation->id, [
            'name'         => $automation->name,
            'trigger_type' => $automation->trigger_type,
        ]);

        return api_success($automation, '自动化规则创建成功', 201);
    }

    #[OA\Put(
        path: '/automations/{id}',
        summary: '更新自动化',
        security: [['bearerAuth' => []]],
        tags: ['自动化'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'trigger_config', type: 'object'),
            new OA\Property(property: 'actions', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'is_enabled', type: 'boolean'),
        ]
    ))]
    #[OA\Response(response: 200, description: '更新成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Automation'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function update(Request $request, int $id)
    {
        $automation = Automation::find($id);
        if (!$automation) {
            return api_error('自动化规则不存在', 404, 4001);
        }

        $data = $request->post();
        $original = $automation->toArray();

        $automation = AutomationService::update($id, $data);

        AuditService::log($request, 'update', 'automation', $id,
            AuditService::diffChanges($original, $automation->toArray())
        );

        return api_success($automation, '自动化规则更新成功');
    }

    #[OA\Delete(
        path: '/automations/{id}',
        summary: '删除自动化',
        security: [['bearerAuth' => []]],
        tags: ['自动化'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function destroy(Request $request, int $id)
    {
        $automation = Automation::find($id);
        if (!$automation) {
            return api_error('自动化规则不存在', 404, 4001);
        }

        $name = $automation->name;
        AutomationService::delete($id);

        AuditService::log($request, 'delete', 'automation', $id, [
            'name' => $name,
        ]);

        return api_success(null, '自动化规则已删除');
    }
}
