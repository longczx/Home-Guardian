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

class AlertRuleController
{
    /**
     * 告警规则列表
     *
     * GET /api/alert-rules?device_id=1&is_enabled=1&page=1&per_page=20
     */
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

    /**
     * 告警规则详情
     */
    public function show(Request $request, int $id)
    {
        $rule = AlertRule::with(['device:id,device_uid,name,location', 'creator:id,username'])->find($id);

        if (!$rule) {
            return api_error('告警规则不存在', 404, 3001);
        }

        return api_success($rule);
    }

    /**
     * 创建告警规则
     *
     * POST /api/alert-rules
     * Body: {
     *   "name": "温度过高",
     *   "device_id": 1,
     *   "telemetry_key": "temperature",
     *   "condition": "GREATER_THAN",
     *   "threshold_value": 35,
     *   "trigger_duration_sec": 60,
     *   "notification_channel_ids": [1, 3]
     * }
     */
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

        if (!empty($errors)) {
            return api_error('参数验证失败', 422, 1000, $errors);
        }

        // 确保 threshold_value 以数组格式存储
        if (!is_array($data['threshold_value'])) {
            $data['threshold_value'] = [$data['threshold_value']];
        }

        $data['created_by'] = $request->userId();

        $rule = AlertService::createRule($data);

        AuditService::log($request, 'create', 'alert_rule', $rule->id, [
            'name' => $rule->name,
        ]);

        return api_success($rule, '告警规则创建成功', 201);
    }

    /**
     * 更新告警规则
     */
    public function update(Request $request, int $id)
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            return api_error('告警规则不存在', 404, 3001);
        }

        $data = $request->post();
        $original = $rule->toArray();

        if (isset($data['threshold_value']) && !is_array($data['threshold_value'])) {
            $data['threshold_value'] = [$data['threshold_value']];
        }

        $rule = AlertService::updateRule($id, $data);

        AuditService::log($request, 'update', 'alert_rule', $id,
            AuditService::diffChanges($original, $rule->toArray())
        );

        return api_success($rule, '告警规则更新成功');
    }

    /**
     * 删除告警规则
     */
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
}
