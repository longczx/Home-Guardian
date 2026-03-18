<?php
/**
 * Home Guardian - 审计日志控制器
 *
 * 查询操作审计日志（只读），用于安全审计和问题追溯。
 *
 *   GET /api/audit-logs — 审计日志列表（仅 admin 可查询全部，其他用户只能看自己）
 */

namespace app\controller;

use app\model\AuditLog;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '审计日志', description: '操作审计日志查询（只读）')]
class AuditLogController
{
    #[OA\Get(
        path: '/audit-logs',
        summary: '审计日志列表',
        description: '查询操作审计日志。非 admin 用户只能查看自己的操作记录。',
        security: [['bearerAuth' => []]],
        tags: ['审计日志'],
    )]
    #[OA\Parameter(name: 'user_id', in: 'query', description: '按用户筛选（仅 admin）', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'action', in: 'query', description: '操作类型（login/create/update/delete 等）', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'resource_type', in: 'query', description: '资源类型（device/user/alert_rule 等）', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'resource_id', in: 'query', description: '资源 ID（需配合 resource_type 使用）', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'start', in: 'query', description: '起始时间', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\Parameter(name: 'end', in: 'query', description: '结束时间', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
    public function index(Request $request)
    {
        $query = AuditLog::with('user:id,username');

        // 非 admin 只能查看自己的操作日志
        if (!$request->isAdmin()) {
            $query->where('user_id', $request->userId());
        } elseif ($userId = $request->get('user_id')) {
            // admin 可按用户筛选
            $query->where('user_id', (int)$userId);
        }

        // 按操作类型筛选
        if ($action = $request->get('action')) {
            $query->withAction($action);
        }

        // 按资源类型筛选
        if ($resourceType = $request->get('resource_type')) {
            $resourceId = $request->get('resource_id') ? (int)$request->get('resource_id') : null;
            $query->forResource($resourceType, $resourceId);
        }

        // 时间范围
        if (($start = $request->get('start')) && ($end = $request->get('end'))) {
            $query->between($start, $end);
        }

        $perPage = min((int)($request->get('per_page', 50)), 200);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }
}
