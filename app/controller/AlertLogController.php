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
use OpenApi\Attributes as OA;

#[OA\Tag(name: '告警日志', description: '告警记录查询、确认、解决')]
class AlertLogController
{
    #[OA\Get(
        path: '/alert-logs',
        summary: '告警日志列表',
        description: '查询告警记录，支持按设备、规则、状态筛选。自动按用户位置作用域过滤。',
        security: [['bearerAuth' => []]],
        tags: ['告警日志'],
    )]
    #[OA\Parameter(name: 'device_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'rule_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'status', in: 'query', description: 'triggered / acknowledged / resolved', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(ref: '#/components/schemas/PaginationMeta'))]
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

    #[OA\Get(
        path: '/alert-logs/{id}',
        summary: '告警详情',
        description: '获取指定告警的详细信息，包含关联规则、设备和确认人信息。',
        security: [['bearerAuth' => []]],
        tags: ['告警日志'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'data', ref: '#/components/schemas/AlertLog'),
        ]
    ))]
    #[OA\Response(response: 404, description: '不存在', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function show(Request $request, int $id)
    {
        $alertLog = AlertLog::with([
            'rule:id,name,condition,threshold_value',
            'device:id,device_uid,name,location',
        ])->find($id);

        if (!$alertLog) {
            return api_error('告警记录不存在', 404, 3002);
        }

        $data = $alertLog->toArray();
        // 手动加载确认人（避免 acknowledged_by=NULL 时 BelongsTo eager load 触发 null offset 错误）
        if ($alertLog->acknowledged_by) {
            $user = \app\model\User::find($alertLog->acknowledged_by, ['id', 'username']);
            $data['acknowledged_by_user'] = $user ? $user->toArray() : null;
        } else {
            $data['acknowledged_by_user'] = null;
        }

        return api_success($data);
    }

    #[OA\Patch(
        path: '/alert-logs/{id}/acknowledge',
        summary: '确认告警',
        description: '标记告警为已确认状态，记录确认人和时间。',
        security: [['bearerAuth' => []]],
        tags: ['告警日志'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '确认成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'message', type: 'string', example: '告警已确认'),
            new OA\Property(property: 'data', ref: '#/components/schemas/AlertLog'),
        ]
    ))]
    public function acknowledge(Request $request, int $id)
    {
        $alertLog = AlertService::acknowledgeAlert($id, $request->userId());

        AuditService::log($request, 'update', 'alert_log', $id, [
            'action' => 'acknowledge',
        ]);

        return api_success($alertLog, '告警已确认');
    }

    #[OA\Patch(
        path: '/alert-logs/{id}/resolve',
        summary: '解决告警',
        description: '标记告警为已解决状态。',
        security: [['bearerAuth' => []]],
        tags: ['告警日志'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '解决成功', content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'message', type: 'string', example: '告警已解决'),
            new OA\Property(property: 'data', ref: '#/components/schemas/AlertLog'),
        ]
    ))]
    public function resolve(Request $request, int $id)
    {
        $alertLog = AlertService::resolveAlert($id);

        AuditService::log($request, 'update', 'alert_log', $id, [
            'action' => 'resolve',
        ]);

        return api_success($alertLog, '告警已解决');
    }
}
