<?php
/**
 * Home Guardian - Admin 设备管理控制器
 */

namespace app\controller\admin;

use app\model\Device;
use app\model\MetricDefinition;
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

        $perPage = min((int)($request->get('per_page', 100)), 200);
        $devices = $query->orderBy('type', 'desc')->orderBy('gateway_uid')->orderBy('name')->paginate($perPage);
        $deviceList = array_map(fn($d) => $d->toArray(), $devices->items());

        // 按网关分组：网关 → 其下传感器
        $gateways = [];
        $standalone = [];
        $gatewayMap = []; // gateway_uid => index in $gateways

        foreach ($deviceList as $d) {
            if ($d['type'] === 'gateway') {
                $gatewayMap[$d['device_uid']] = count($gateways);
                $gateways[] = ['gateway' => $d, 'sensors' => []];
            }
        }
        foreach ($deviceList as $d) {
            if ($d['type'] === 'gateway') continue;
            $gwUid = $d['gateway_uid'] ?? null;
            if ($gwUid && isset($gatewayMap[$gwUid])) {
                $gateways[$gatewayMap[$gwUid]]['sensors'][] = $d;
            } else {
                $standalone[] = $d;
            }
        }

        return view('admin/device/list', [
            'gateways'   => $gateways,
            'standalone'  => $standalone,
            'devices'    => $devices,
            'filters'    => $request->get(),
            'nav'        => 'devices',
            'adminUser'  => $request->adminUser,
        ]);
    }

    public function create(Request $request)
    {
        $metricDefinitions = MetricDefinition::ordered()->get(['id', 'metric_key', 'label', 'unit', 'icon'])->toArray();
        $gateways = Device::where('type', 'gateway')->orderBy('name')->get(['id', 'device_uid', 'name'])->toArray();

        return view('admin/device/form', [
            'device'            => null,
            'metricDefinitions' => $metricDefinitions,
            'gateways'          => $gateways,
            'nav'               => 'devices',
            'adminUser'         => $request->adminUser,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->post();

        if (empty($data['device_uid']) || empty($data['name']) || empty($data['type'])) {
            $metricDefinitions = MetricDefinition::ordered()->get(['id', 'metric_key', 'label', 'unit', 'icon'])->toArray();
            $gateways = Device::where('type', 'gateway')->orderBy('name')->get(['id', 'device_uid', 'name'])->toArray();
            return view('admin/device/form', [
                'device'            => null,
                'metricDefinitions' => $metricDefinitions,
                'gateways'          => $gateways,
                'error'             => 'device_uid、名称和类型不能为空',
                'old'               => $data,
                'nav'               => 'devices',
                'adminUser'         => $request->adminUser,
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

        $metricDefinitions = MetricDefinition::ordered()->get(['id', 'metric_key', 'label', 'unit', 'icon'])->toArray();
        $gateways = Device::where('type', 'gateway')->orderBy('name')->get(['id', 'device_uid', 'name'])->toArray();

        return view('admin/device/form', [
            'device'            => $device,
            'metricDefinitions' => $metricDefinitions,
            'gateways'          => $gateways,
            'nav'               => 'devices',
            'adminUser'         => $request->adminUser,
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

    public function firmwareConfig(Request $request, int $id)
    {
        $device = Device::find($id);
        if (!$device) {
            return redirect('/admin/devices');
        }

        // 查询挂载在此网关下的传感器
        $sensors = [];
        if ($device->type === 'gateway') {
            $sensors = Device::where('gateway_uid', $device->device_uid)
                ->get(['id', 'device_uid', 'name', 'type', 'metric_fields'])
                ->toArray();
        }

        return view('admin/device/firmware-config', [
            'device'    => $device,
            'sensors'   => $sensors,
            'mqttHost'  => getenv('MQTT_HOST') ?: '192.168.1.100',
            'mqttPort'  => getenv('MQTT_PORT') ?: '1883',
            'nav'       => 'devices',
            'adminUser' => $request->adminUser,
        ]);
    }
}
