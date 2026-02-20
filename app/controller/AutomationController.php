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

class AutomationController
{
    /**
     * 自动化列表
     *
     * GET /api/automations?trigger_type=telemetry&is_enabled=1&page=1&per_page=20
     */
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

    /**
     * 自动化详情
     */
    public function show(Request $request, int $id)
    {
        $automation = Automation::with('creator:id,username')->find($id);

        if (!$automation) {
            return api_error('自动化规则不存在', 404, 4001);
        }

        return api_success($automation);
    }

    /**
     * 创建自动化
     *
     * POST /api/automations
     * Body: {
     *   "name": "温度过高开空调",
     *   "trigger_type": "telemetry",
     *   "trigger_config": {"device_id": 1, "metric_key": "temperature", "condition": "GREATER_THAN", "value": 30},
     *   "actions": [{"type": "device_command", "device_id": 2, "payload": {"action": "turn_on"}}]
     * }
     */
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

    /**
     * 更新自动化
     */
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

    /**
     * 删除自动化
     */
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
