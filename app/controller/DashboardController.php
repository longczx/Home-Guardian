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

class DashboardController
{
    /**
     * 仪表盘列表
     *
     * GET /api/dashboards?page=1&per_page=20
     *
     * 普通用户只能看到自己的仪表盘，admin 可看到所有。
     */
    public function index(Request $request)
    {
        $query = Dashboard::with('owner:id,username');

        // 非 admin 只能看到自己的仪表盘
        if (!$request->isAdmin()) {
            $query->where('owner_id', $request->userId());
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    /**
     * 仪表盘详情
     */
    public function show(Request $request, int $id)
    {
        $dashboard = Dashboard::with('owner:id,username')->find($id);

        if (!$dashboard) {
            return api_error('仪表盘不存在', 404, 7001);
        }

        // 非 admin 只能查看自己的仪表盘
        if (!$request->isAdmin() && $dashboard->owner_id !== $request->userId()) {
            return api_error('无权查看该仪表盘', 403, 1004);
        }

        return api_success($dashboard);
    }

    /**
     * 创建仪表盘
     *
     * POST /api/dashboards
     * Body: {
     *   "name": "主仪表盘",
     *   "description": "客厅环境监控",
     *   "configuration": { ... }
     * }
     */
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

    /**
     * 更新仪表盘
     */
    public function update(Request $request, int $id)
    {
        $dashboard = Dashboard::find($id);
        if (!$dashboard) {
            return api_error('仪表盘不存在', 404, 7001);
        }

        // 非 admin 只能编辑自己的仪表盘
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

    /**
     * 删除仪表盘
     */
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
