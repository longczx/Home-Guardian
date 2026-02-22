<?php
/**
 * Home Guardian - Admin 告警日志控制器
 */

namespace app\controller\admin;

use app\model\AlertLog;
use app\service\AlertService;
use support\Request;

class AlertLogController
{
    public function index(Request $request)
    {
        $query = AlertLog::with([
            'rule:id,name',
            'device:id,device_uid,name,location',
        ]);

        if ($deviceId = $request->get('device_id')) {
            $query->where('device_id', (int)$deviceId);
        }
        if ($status = $request->get('status')) {
            $query->withStatus($status);
        }

        $perPage = min((int)($request->get('per_page', 20)), 100);
        $logs = $query->orderBy('triggered_at', 'desc')->paginate($perPage);
        $logList = array_map(fn($l) => $l->toArray(), $logs->items());

        return view('admin/alert-log/list', [
            'logList'   => $logList,
            'logs'      => $logs,
            'filters'   => $request->get(),
            'nav'       => 'alert-logs',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function acknowledge(Request $request, int $id)
    {
        AlertService::acknowledgeAlert($id, $request->adminUser['id']);
        return redirect('/admin/alert-logs');
    }

    public function resolve(Request $request, int $id)
    {
        AlertService::resolveAlert($id);
        return redirect('/admin/alert-logs');
    }
}
