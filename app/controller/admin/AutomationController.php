<?php
/**
 * Home Guardian - Admin 自动化控制器
 */

namespace app\controller\admin;

use app\model\Automation;
use app\model\Device;
use app\service\AutomationService;
use support\Request;

class AutomationController
{
    public function index(Request $request)
    {
        $query = Automation::query();

        if ($triggerType = $request->get('trigger_type')) {
            $query->ofTriggerType($triggerType);
        }
        if ($request->get('is_enabled') !== null && $request->get('is_enabled') !== '') {
            $query->where('is_enabled', (bool)$request->get('is_enabled'));
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $automations = $query->orderBy('id', 'desc')->paginate($perPage);

        return view('admin/automation/list', [
            'automations' => $automations,
            'filters'     => $request->get(),
            'nav'         => 'automations',
            'adminUser'   => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        $devices = Device::orderBy('name')->get(['id', 'name', 'device_uid']);

        return view('admin/automation/form', [
            'automation' => null,
            'devices'    => $devices,
            'nav'        => 'automations',
            'adminUser'  => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['name']) || empty($data['trigger_type'])) {
            $devices = Device::orderBy('name')->get(['id', 'name', 'device_uid']);
            return view('admin/automation/form', [
                'automation' => null,
                'devices'    => $devices,
                'error'      => '请填写必填字段',
                'old'        => $data,
                'nav'        => 'automations',
                'adminUser'  => $request->adminUser,
            ]);
        }

        if (!empty($data['trigger_config_json'])) {
            $data['trigger_config'] = json_decode($data['trigger_config_json'], true) ?: [];
        }
        if (!empty($data['actions_json'])) {
            $data['actions'] = json_decode($data['actions_json'], true) ?: [];
        }
        $data['created_by'] = $request->adminUser['id'];

        AutomationService::create($data);
        return redirect('/admin/automations');
    }

    public function edit(Request $request, int $id)
    {
        $automation = Automation::find($id);
        if (!$automation) {
            return redirect('/admin/automations');
        }

        $devices = Device::orderBy('name')->get(['id', 'name', 'device_uid']);

        return view('admin/automation/form', [
            'automation' => $automation,
            'devices'    => $devices,
            'nav'        => 'automations',
            'adminUser'  => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $automation = Automation::find($id);
        if (!$automation) {
            return redirect('/admin/automations');
        }

        $data = $request->post();
        if (!empty($data['trigger_config_json'])) {
            $data['trigger_config'] = json_decode($data['trigger_config_json'], true) ?: [];
        }
        if (!empty($data['actions_json'])) {
            $data['actions'] = json_decode($data['actions_json'], true) ?: [];
        }

        AutomationService::update($id, $data);
        return redirect('/admin/automations');
    }

    public function delete(Request $request, int $id)
    {
        $automation = Automation::find($id);
        if ($automation) {
            AutomationService::delete($id);
        }
        return redirect('/admin/automations');
    }
}
