<?php
/**
 * Home Guardian - Admin 指标定义控制器
 */

namespace app\controller\admin;

use app\model\MetricDefinition;
use support\Request;

class MetricDefinitionController
{
    public function index(Request $request)
    {
        $definitions = MetricDefinition::ordered()->get();
        $definitionList = $definitions->map(fn($d) => $d->toArray())->toArray();

        return view('admin/metric-definition/list', [
            'definitionList' => $definitionList,
            'nav'            => 'metric-definitions',
            'adminUser'      => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        return view('admin/metric-definition/form', [
            'definition' => null,
            'nav'        => 'metric-definitions',
            'adminUser'  => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['metric_key']) || empty($data['label'])) {
            return view('admin/metric-definition/form', [
                'definition' => null,
                'error'      => 'metric_key 和 label 不能为空',
                'old'        => $data,
                'nav'        => 'metric-definitions',
                'adminUser'  => $request->adminUser,
            ]);
        }

        if (MetricDefinition::where('metric_key', $data['metric_key'])->exists()) {
            return view('admin/metric-definition/form', [
                'definition' => null,
                'error'      => 'metric_key 已存在',
                'old'        => $data,
                'nav'        => 'metric-definitions',
                'adminUser'  => $request->adminUser,
            ]);
        }

        MetricDefinition::create([
            'metric_key'  => $data['metric_key'],
            'label'       => $data['label'],
            'unit'        => $data['unit'] ?? '',
            'icon'        => $data['icon'] ?? '📊',
            'description' => $data['description'] ?? null,
            'sort_order'  => (int)($data['sort_order'] ?? 0),
        ]);

        return redirect('/admin/metric-definitions');
    }

    public function edit(Request $request, int $id)
    {
        $definition = MetricDefinition::find($id);
        if (!$definition) {
            return redirect('/admin/metric-definitions');
        }

        return view('admin/metric-definition/form', [
            'definition' => $definition,
            'nav'        => 'metric-definitions',
            'adminUser'  => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $definition = MetricDefinition::find($id);
        if (!$definition) {
            return redirect('/admin/metric-definitions');
        }

        $data = $request->post();
        unset($data['metric_key']); // metric_key 不可修改

        $definition->update($data);
        return redirect('/admin/metric-definitions');
    }

    public function delete(Request $request, int $id)
    {
        $definition = MetricDefinition::find($id);
        if ($definition) {
            $definition->delete();
        }
        return redirect('/admin/metric-definitions');
    }
}
