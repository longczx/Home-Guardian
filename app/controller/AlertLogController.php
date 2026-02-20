<?php
/**
 * Home Guardian - 告警日志控制器
 *
 *   GET   /api/alert-logs                 — 告警日志列表
 *   GET   /api/alert-logs/{id}            — 告警详情
 *   PATCH /api/alert-logs/{id}/acknowledge — 确认告警
 *   PATCH /api/alert-logs/{id}/resolve     — 解决告警
 */

namespace app\controller;

use app\model\AlertLog;
use app\service\AlertService;
use app\service\AuditService;
use support\Request;

class AlertLogController
{
    /**
     * 告警日志列表
     *
     * GET /api/alert-logs?device_id=1&status=triggered&page=1&per_page=20
     */
    public function index(Request $request)
    {
        $query = AlertLog::with([
            'rule:id,name',
            'device:id,device_uid,name,location',
        ]);

        if ($deviceId = $request->get('device_id')) {
            $query->where('device_id', (int)$deviceId);
        }
        if ($ruleId = $request->get('rule_id')) {
            $query->where('rule_id', (int)$ruleId);
        }
        if ($status = $request->get('status')) {
            $query->withStatus($status);
        }

        // 位置作用域
        $locations = $request->user->locations ?? [];
        if (!empty($locations)) {
            $query->whereHas('device', function ($q) use ($locations) {
                $q->whereIn('location', $locations);
            });
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $paginator = $query->orderBy('triggered_at', 'desc')->paginate($perPage);

        return api_paginate($paginator);
    }

    /**
     * 告警详情
     */
    public function show(Request $request, int $id)
    {
        $alertLog = AlertLog::with([
            'rule:id,name,condition,threshold_value',
            'device:id,device_uid,name,location',
            'acknowledgedByUser:id,username',
        ])->find($id);

        if (!$alertLog) {
            return api_error('告警记录不存在', 404, 3002);
        }

        return api_success($alertLog);
    }

    /**
     * 确认告警
     *
     * PATCH /api/alert-logs/{id}/acknowledge
     */
    public function acknowledge(Request $request, int $id)
    {
        $alertLog = AlertService::acknowledgeAlert($id, $request->userId());

        AuditService::log($request, 'update', 'alert_log', $id, [
            'action' => 'acknowledge',
        ]);

        return api_success($alertLog, '告警已确认');
    }

    /**
     * 解决告警
     *
     * PATCH /api/alert-logs/{id}/resolve
     */
    public function resolve(Request $request, int $id)
    {
        $alertLog = AlertService::resolveAlert($id);

        AuditService::log($request, 'update', 'alert_log', $id, [
            'action' => 'resolve',
        ]);

        return api_success($alertLog, '告警已解决');
    }
}
