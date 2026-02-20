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

class CommandLogController
{
    /**
     * 指令日志列表
     *
     * GET /api/commands?device_id=1&status=sent&page=1&per_page=20
     */
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

    /**
     * 指令详情
     *
     * GET /api/commands/{id}
     */
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
