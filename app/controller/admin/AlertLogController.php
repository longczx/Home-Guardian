<?php
/**
 * Home Guardian - Admin 告警日志控制器
 */

namespace app\controller\admin;

use app\model\AlertLog;
use app\service\AlertService;
use support\Request;
use support\Db;

class AlertLogController
{
    public function index(Request $request)
    {
        // 聚合查询：按 rule_id + device_id + status 分组
        $query = Db::table('alert_logs')
            ->join('alert_rules', 'alert_logs.rule_id', '=', 'alert_rules.id')
            ->join('devices', 'alert_logs.device_id', '=', 'devices.id')
            ->select(
                'alert_logs.rule_id',
                'alert_logs.device_id',
                'alert_logs.status',
                'alert_rules.name as rule_name',
                'devices.name as device_name',
                'devices.location as device_location',
                Db::raw('count(*) as alert_count'),
                Db::raw('max(alert_logs.triggered_at) as latest_triggered_at'),
                Db::raw('min(alert_logs.triggered_at) as earliest_triggered_at')
            )
            ->groupBy('alert_logs.rule_id', 'alert_logs.device_id', 'alert_logs.status',
                       'alert_rules.name', 'devices.name', 'devices.location');

        if ($status = $request->get('status')) {
            $query->where('alert_logs.status', $status);
        }

        $groups = $query->orderBy('latest_triggered_at', 'desc')->get()->toArray();

        return view('admin/alert-log/list', [
            'groups'    => $groups,
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

    public function batchAcknowledge(Request $request)
    {
        $ruleId   = (int)$request->post('rule_id');
        $deviceId = (int)$request->post('device_id');
        if ($ruleId && $deviceId) {
            AlertLog::where('rule_id', $ruleId)
                ->where('device_id', $deviceId)
                ->where('status', AlertLog::STATUS_TRIGGERED)
                ->update([
                    'status'          => AlertLog::STATUS_ACKNOWLEDGED,
                    'acknowledged_by' => $request->adminUser['id'],
                    'acknowledged_at' => now(),
                ]);
        }
        return redirect('/admin/alert-logs');
    }

    public function batchResolve(Request $request)
    {
        $ruleId   = (int)$request->post('rule_id');
        $deviceId = (int)$request->post('device_id');
        if ($ruleId && $deviceId) {
            AlertLog::where('rule_id', $ruleId)
                ->where('device_id', $deviceId)
                ->whereIn('status', [AlertLog::STATUS_TRIGGERED, AlertLog::STATUS_ACKNOWLEDGED])
                ->update(['status' => AlertLog::STATUS_RESOLVED]);
        }
        return redirect('/admin/alert-logs');
    }
}
