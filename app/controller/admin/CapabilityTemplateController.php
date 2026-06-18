<?php
/**
 * Home Guardian - Admin 执行器能力模板控制器
 *
 * 管理可复用的整设备控制能力模板（空调/调光灯/开关）。
 * controls 字段以 JSON 文本编辑（v1 简版，可视化编辑器留后续）。
 */

namespace app\controller\admin;

use app\model\CapabilityTemplate;
use support\Request;

class CapabilityTemplateController
{
    public function index(Request $request)
    {
        $templates = CapabilityTemplate::orderBy('id')->get();
        $templateList = $templates->map(function ($t) {
            $arr = $t->toArray();
            $arr['control_count'] = is_array($t->controls) ? count($t->controls) : 0;
            return $arr;
        })->toArray();

        return view('admin/capability-template/list', [
            'templateList' => $templateList,
            'nav'          => 'capability-templates',
            'adminUser'    => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        return view('admin/capability-template/form', [
            'template'  => null,
            'nav'       => 'capability-templates',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if ($err = $this->validateData($data)) {
            return view('admin/capability-template/form', [
                'template'  => null,
                'error'     => $err,
                'old'       => $data,
                'nav'       => 'capability-templates',
                'adminUser' => $request->adminUser,
            ]);
        }

        CapabilityTemplate::create([
            'name'            => $data['name'],
            'device_category' => $data['device_category'] ?? null,
            'control_mode'    => $data['control_mode'] ?? 'discrete',
            'controls'        => json_decode($data['controls'] ?? '[]', true) ?: [],
            'description'     => $data['description'] ?? null,
        ]);

        return redirect('/admin/capability-templates');
    }

    public function edit(Request $request, int $id)
    {
        $template = CapabilityTemplate::find($id);
        if (!$template) {
            return redirect('/admin/capability-templates');
        }

        return view('admin/capability-template/form', [
            'template'  => $template,
            'nav'       => 'capability-templates',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $template = CapabilityTemplate::find($id);
        if (!$template) {
            return redirect('/admin/capability-templates');
        }

        $data = $request->post();
        if ($err = $this->validateData($data)) {
            return view('admin/capability-template/form', [
                'template'  => $template,
                'error'     => $err,
                'old'       => $data,
                'nav'       => 'capability-templates',
                'adminUser' => $request->adminUser,
            ]);
        }

        $template->update([
            'name'            => $data['name'],
            'device_category' => $data['device_category'] ?? null,
            'control_mode'    => $data['control_mode'] ?? 'discrete',
            'controls'        => json_decode($data['controls'] ?? '[]', true) ?: [],
            'description'     => $data['description'] ?? null,
        ]);

        return redirect('/admin/capability-templates');
    }

    public function delete(Request $request, int $id)
    {
        $template = CapabilityTemplate::find($id);
        if ($template) {
            $template->delete();
        }
        return redirect('/admin/capability-templates');
    }

    /**
     * 校验表单数据，返回错误信息（无错误返回 null）
     */
    private function validateData(array $data): ?string
    {
        if (empty($data['name'])) {
            return 'name 不能为空';
        }
        if (!in_array($data['control_mode'] ?? '', ['discrete', 'merge'], true)) {
            return 'control_mode 必须是 discrete 或 merge';
        }
        $controls = json_decode($data['controls'] ?? '[]', true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($controls)) {
            return 'controls 必须是合法的 JSON 数组';
        }
        return null;
    }
}
