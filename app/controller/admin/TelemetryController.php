<?php
/**
 * Home Guardian - Admin 遥测数据控制器
 */

namespace app\controller\admin;

use app\model\Device;
use app\model\TelemetryLog;
use support\Request;
use support\Db;

class TelemetryController
{
    public function index(Request $request)
    {
        $devices  = Device::orderBy('name')->get(['id', 'name', 'device_uid', 'location'])->toArray();
        $deviceId = (int)$request->get('device_id', 0);
        $data     = null;
        $dataList = [];

        if ($deviceId) {
            $query = TelemetryLog::forDevice($deviceId);

            if ($metricKey = $request->get('metric_key')) {
                $query->forMetric($metricKey);
            }

            $start = $request->get('start');
            $end   = $request->get('end');
            if ($start && $end) {
                $query->between($start, $end);
            } else {
                $query->recentHours(24);
            }

            $perPage = min((int)($request->get('per_page', 50)), 500);
            $data = $query->orderBy('ts', 'desc')->paginate($perPage);
            $dataList = array_map(fn($r) => $r->toArray(), $data->items());
        }

        return view('admin/telemetry/index', [
            'devices'   => $devices,
            'data'      => $data,
            'dataList'  => $dataList,
            'filters'   => $request->get(),
            'nav'       => 'telemetry',
            'adminUser' => $request->adminUser,
        ]);
    }
}
