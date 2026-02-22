<?php
/**
 * Home Guardian - Admin 设备管理控制器
 */

namespace app\controller\admin;

use app\model\Device;
use app\service\DeviceService;
use support\Request;

class DeviceController
{
    public function index(Request $request)
    {
        $query = Device::query();

        if ($type = $request->get('type')) {
            $query->ofType($type);
        }
        if ($location = $request->get('location')) {
            $query->atLocation($location);
        }
        if ($request->get('is_online') !== null && $request->get('is_online') !== '') {
            $query->where('is_online', (bool)$request->get('is_online'));
        }
        if ($keyword = $request->get('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'ILIKE', "%{$keyword}%")
                  ->orWhere('device_uid', 'ILIKE', "%{$keyword}%");
            });
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $devices = $query->orderBy('id', 'desc')->paginate($perPage);

        return view('admin/device/list', [
            'devices'   => $devices,
            'filters'   => $request->get(),
            'nav'       => 'devices',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        return view('admin/device/form', [
            'device'    => null,
            'nav'       => 'devices',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['device_uid']) || empty($data['name']) || empty($data['type'])) {
            return view('admin/device/form', [
                'device'    => null,
                'error'     => 'device_uid、名称和类型不能为空',
                'old'       => $data,
                'nav'       => 'devices',
                'adminUser' => $request->adminUser,
            ]);
        }

        DeviceService::create($data);
        return redirect('/admin/devices');
    }

    public function edit(Request $request, int $id)
    {
        $device = Device::with('attributes')->find($id);
        if (!$device) {
            return redirect('/admin/devices');
        }

        return view('admin/device/form', [
            'device'    => $device,
            'nav'       => 'devices',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return redirect('/admin/devices');
        }

        $data = $request->post();
        unset($data['device_uid']);

        DeviceService::update($id, $data);
        return redirect('/admin/devices');
    }

    public function delete(Request $request, int $id)
    {
        $device = Device::find($id);
        if ($device) {
            DeviceService::delete($id);
        }
        return redirect('/admin/devices');
    }
}
