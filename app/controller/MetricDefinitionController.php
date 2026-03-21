<?php
/**
 * Home Guardian - 指标定义控制器
 *
 *   GET    /api/metric-definitions          — 全部定义列表
 *   GET    /api/metric-definitions/{id}     — 定义详情
 *   POST   /api/metric-definitions          — 创建定义
 *   PUT    /api/metric-definitions/{id}     — 更新定义
 *   DELETE /api/metric-definitions/{id}     — 删除定义
 */

namespace app\controller;

use app\model\MetricDefinition;
use app\service\AuditService;
use support\Request;

class MetricDefinitionController
{
    /**
     * 指标定义列表（按 sort_order 排序，不分页）
     */
    public function index(Request $request)
    {
        $definitions = MetricDefinition::ordered()->get();
        return api_success($definitions);
    }

    /**
     * 指标定义详情
     */
    public function show(Request $request, int $id)
    {
        $definition = MetricDefinition::find($id);
        if (!$definition) {
            return api_error('指标定义不存在', 404, 3001);
        }
        return api_success($definition);
    }

    /**
     * 创建指标定义
     */
    public function store(Request $request)
    {
        $data = $request->post();

        $errors = [];
        if (empty($data['metric_key'])) {
            $errors[] = 'metric_key 不能为空';
        }
        if (empty($data['label'])) {
            $errors[] = 'label 不能为空';
        }
        if (!empty($errors)) {
            return api_error('参数验证失败', 422, 1000, $errors);
        }

        if (MetricDefinition::where('metric_key', $data['metric_key'])->exists()) {
            return api_error('metric_key 已存在', 409, 3002);
        }

        $definition = MetricDefinition::create([
            'metric_key'  => $data['metric_key'],
            'label'       => $data['label'],
            'unit'        => $data['unit'] ?? '',
            'icon'        => $data['icon'] ?? '📊',
            'description' => $data['description'] ?? null,
            'sort_order'  => (int)($data['sort_order'] ?? 0),
        ]);

        AuditService::log($request, 'create', 'metric_definition', $definition->id, [
            'metric_key' => $definition->metric_key,
            'label'      => $definition->label,
        ]);

        return api_success($definition, '指标定义创建成功', 201);
    }

    /**
     * 更新指标定义
     */
    public function update(Request $request, int $id)
    {
        $definition = MetricDefinition::find($id);
        if (!$definition) {
            return api_error('指标定义不存在', 404, 3001);
        }

        $original = $definition->toArray();
        $data = $request->post();
        unset($data['metric_key']); // metric_key 不可修改

        $definition->update($data);
        $definition = $definition->fresh();

        AuditService::log($request, 'update', 'metric_definition', $id,
            AuditService::diffChanges($original, $definition->toArray())
        );

        return api_success($definition, '指标定义更新成功');
    }

    /**
     * 删除指标定义
     */
    public function destroy(Request $request, int $id)
    {
        $definition = MetricDefinition::find($id);
        if (!$definition) {
            return api_error('指标定义不存在', 404, 3001);
        }

        AuditService::log($request, 'delete', 'metric_definition', $id, [
            'metric_key' => $definition->metric_key,
            'label'      => $definition->label,
        ]);

        $definition->delete();
        return api_success(null, '指标定义已删除');
    }
}
