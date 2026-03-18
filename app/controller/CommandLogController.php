<?php
/**
 * Home Guardian - 指令日志控制器
 *
 * 查询设备控制指令的执行历史。
 *
 *   GET /api/commands         — 指令日志列表
 *   GET /api/commands/{id}    — 指令详情
 */

namespace app\controller;

use app\model\CommandLog;
use app\model\Device;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '指令日志', description: '设备控制指令执行历史（只读）')]
class CommandLogController
{
    #[OA\Get(
        path: '/commands',
        summary: '指令日志列表',
        description: '查询设备控制指令记录，支持按设备和状态筛选。自动按位置作用域过滤。',
        security: [['bearerAuth' => []]],
        tags: ['指令日志'],
    )]
    #[OA\Parameter(name: 'device_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'status', in: 'query', description: 'pending / delivered / success / failed / timeout', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
    public function index(Request $request)
    {
        $query = CommandLog::with('device:id,device_uid,name,location');

        // 按设备筛选
        if ($deviceId = $request->get('device_id')) {
            $query->where('device_id', (int)$deviceId);
        }

        // 按状态筛选
        if ($status = $request->get('status')) {
            $query->withStatus($status);
        }

        // 位置作用域过滤
        $locations = $request->user->locations ?? [];
        if (!empty($locations)) {
            $query->whereHas('device', function ($q) use ($locations) {
                $q->whereIn('location', $locations);
            });
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('sent_at', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    #[OA\Get(
        path: '/commands/{id}',
        summary: '指令详情',
        security: [['bearerAuth' => []]],
        tags: ['指令日志'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/CommandLog'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 403, description: '无权查看', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $commandLog = CommandLog::with('device:id,device_uid,name,location')->find($id);

        if (!$commandLog) {
            return api_error('指令记录不存在', 404, 2004);
        }

        // 位置作用域检查
        if ($commandLog->device && !$request->canAccessLocation($commandLog->device->location)) {
            return api_error('无权查看该指令', 403, 1004);
        }

        return api_success($commandLog);
    }
}
