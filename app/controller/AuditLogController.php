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

class AuditLogController
{
    /**
     * 审计日志列表
     *
     * GET /api/audit-logs?user_id=1&action=login&resource_type=device&start=2026-02-01&end=2026-02-20&page=1&per_page=50
     */
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
