<?php
/**
 * Home Guardian - Admin 告警规则控制器
 */

namespace app\controller\admin;

use app\model\AlertRule;
use app\model\Device;
use app\model\NotificationChannel;
use app\service\AlertService;
use support\Request;

class AlertRuleController
{
    public function index(Request $request)
    {
        $query = AlertRule::with('device:id,device_uid,name,location');

        if ($deviceId = $request->get('device_id')) {
            $query->where('device_id', (int)$deviceId);
        }
        if ($request->get('is_enabled') !== null && $request->get('is_enabled') !== '') {
            $query->where('is_enabled', (bool)$request->get('is_enabled'));
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $rules = $query->orderBy('id', 'desc')->paginate($perPage);
        $ruleList = array_map(fn($r) => $r->toArray(), $rules->items());

        return view('admin/alert-rule/list', [
            'ruleList'  => $ruleList,
            'rules'     => $rules,
            'filters'   => $request->get(),
            'nav'       => 'alert-rules',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        $devices  = Device::orderBy('name')->get(['id', 'name', 'device_uid', 'location'])->toArray();
        $channels = NotificationChannel::where('is_enabled', true)->get(['id', 'name', 'type'])->toArray();

        return view('admin/alert-rule/form', [
            'rule'      => null,
            'devices'   => $devices,
            'channels'  => $channels,
            'nav'       => 'alert-rules',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['name']) || empty($data['device_id']) || empty($data['telemetry_key']) || empty($data['condition'])) {
            $devices  = Device::orderBy('name')->get(['id', 'name', 'device_uid', 'location'])->toArray();
            $channels = NotificationChannel::where('is_enabled', true)->get(['id', 'name', 'type'])->toArray();
            return view('admin/alert-rule/form', [
                'rule'      => null,
                'devices'   => $devices,
                'channels'  => $channels,
                'error'     => '请填写必填字段',
                'old'       => $data,
                'nav'       => 'alert-rules',
                'adminUser' => $request->adminUser,
            ]);
        }

        if (!is_array($data['threshold_value'] ?? null)) {
            $data['threshold_value'] = [$data['threshold_value'] ?? 0];
        }
        $data['notification_channel_ids'] = $data['notification_channel_ids'] ?? [];
        $data['created_by'] = $request->adminUser['id'];

        AlertService::createRule($data);
        return redirect('/admin/alert-rules');
    }

    public function edit(Request $request, int $id)
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            return redirect('/admin/alert-rules');
        }

        $devices  = Device::orderBy('name')->get(['id', 'name', 'device_uid', 'location'])->toArray();
        $channels = NotificationChannel::where('is_enabled', true)->get(['id', 'name', 'type'])->toArray();

        return view('admin/alert-rule/form', [
            'rule'      => $rule,
            'devices'   => $devices,
            'channels'  => $channels,
            'nav'       => 'alert-rules',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            return redirect('/admin/alert-rules');
        }

        $data = $request->post();
        if (isset($data['threshold_value']) && !is_array($data['threshold_value'])) {
            $data['threshold_value'] = [$data['threshold_value']];
        }
        $data['notification_channel_ids'] = $data['notification_channel_ids'] ?? [];

        AlertService::updateRule($id, $data);
        return redirect('/admin/alert-rules');
    }

    public function delete(Request $request, int $id)
    {
        $rule = AlertRule::find($id);
        if ($rule) {
            AlertService::deleteRule($id);
        }
        return redirect('/admin/alert-rules');
    }
}
