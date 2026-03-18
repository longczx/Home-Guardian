<?php
/**
 * Home Guardian - 仪表盘控制器
 *
 *   GET    /api/dashboards          — 仪表盘列表
 *   GET    /api/dashboards/{id}     — 仪表盘详情
 *   POST   /api/dashboards          — 创建仪表盘
 *   PUT    /api/dashboards/{id}     — 更新仪表盘
 *   DELETE /api/dashboards/{id}     — 删除仪表盘
 */

namespace app\controller;

use app\model\Dashboard;
use app\service\AuditService;
use app\exception\BusinessException;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '仪表盘', description: '用户自定义仪表盘 CRUD')]
class DashboardController
{
    #[OA\Get(
        path: '/dashboards',
        summary: '仪表盘列表',
        description: '获取仪表盘列表。普通用户只能看到自己的，admin 可看到所有。',
        security: [['bearerAuth' => []]],
        tags: ['仪表盘'],
    )]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
    public function index(Request $request)
    {
        $query = Dashboard::with('owner:id,username');

        if (!$request->isAdmin()) {
            $query->where('owner_id', $request->userId());
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    #[OA\Get(
        path: '/dashboards/{id}',
        summary: '仪表盘详情',
        security: [['bearerAuth' => []]],
        tags: ['仪表盘'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Dashboard'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 403, description: '无权查看', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $dashboard = Dashboard::with('owner:id,username')->find($id);

        if (!$dashboard) {
            return api_error('仪表盘不存在', 404, 7001);
        }

        if (!$request->isAdmin() && $dashboard->owner_id !== $request->userId()) {
            return api_error('无权查看该仪表盘', 403, 1004);
        }

        return api_success($dashboard);
    }

    #[OA\Post(
        path: '/dashboards',
        summary: '创建仪表盘',
        security: [['bearerAuth' => []]],
        tags: ['仪表盘'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: '主仪表盘'),
                new OA\Property(property: 'description', type: 'string', example: '客厅环境监控'),
                new OA\Property(property: 'configuration', type: 'object', description: '仪表盘配置 JSON'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: '创建成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Dashboard'),
        ]
    ))]
    #[OA\Response(response: 422, description: '名称为空', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['name'])) {
            return api_error('仪表盘名称不能为空', 422, 1000);
        }

        $dashboard = Dashboard::create([
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'configuration' => $data['configuration'] ?? [],
            'owner_id'      => $request->userId(),
        ]);

        AuditService::log($request, 'create', 'dashboard', $dashboard->id, [
            'name' => $dashboard->name,
        ]);

        return api_success($dashboard, '仪表盘创建成功', 201);
    }

    #[OA\Put(
        path: '/dashboards/{id}',
        summary: '更新仪表盘',
        security: [['bearerAuth' => []]],
        tags: ['仪表盘'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'configuration', type: 'object'),
        ]
    ))]
    #[OA\Response(response: 200, description: '更新成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/Dashboard'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function update(Request $request, int $id)
    {
        $dashboard = Dashboard::find($id);
        if (!$dashboard) {
            return api_error('仪表盘不存在', 404, 7001);
        }

        if (!$request->isAdmin() && $dashboard->owner_id !== $request->userId()) {
            return api_error('无权编辑该仪表盘', 403, 1004);
        }

        $original = $dashboard->toArray();
        $data = $request->post();

        $dashboard->update(array_filter([
            'name'          => $data['name'] ?? null,
            'description'   => $data['description'] ?? null,
            'configuration' => $data['configuration'] ?? null,
        ], fn($v) => $v !== null));

        AuditService::log($request, 'update', 'dashboard', $id,
            AuditService::diffChanges($original, $dashboard->fresh()->toArray())
        );

        return api_success($dashboard->fresh(), '仪表盘更新成功');
    }

    #[OA\Delete(
        path: '/dashboards/{id}',
        summary: '删除仪表盘',
        security: [['bearerAuth' => []]],
        tags: ['仪表盘'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '删除成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function destroy(Request $request, int $id)
    {
        $dashboard = Dashboard::find($id);
        if (!$dashboard) {
            return api_error('仪表盘不存在', 404, 7001);
        }

        if (!$request->isAdmin() && $dashboard->owner_id !== $request->userId()) {
            return api_error('无权删除该仪表盘', 403, 1004);
        }

        $name = $dashboard->name;
        $dashboard->delete();

        AuditService::log($request, 'delete', 'dashboard', $id, [
            'name' => $name,
        ]);

        return api_success(null, '仪表盘已删除');
    }
}
