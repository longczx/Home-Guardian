<?php
/**
 * Home Guardian - Admin 后台首页控制器
 *
 *   GET /admin/dashboard — 统计概览页面
 */

namespace app\controller\admin;

use app\model\Device;
use app\model\User;
use app\model\AlertLog;
use app\model\Automation;
use support\Request;

class DashboardController
{
    public function index(Request $request)
    {
        $stats = [
            'device_total'    => Device::count(),
            'device_online'   => Device::where('is_online', true)->count(),
            'user_total'      => User::count(),
            'alert_triggered' => AlertLog::where('status', 'triggered')->count(),
            'automation_total'=> Automation::where('is_enabled', true)->count(),
        ];

        // 最近告警
        $recentAlerts = AlertLog::with(['rule:id,name', 'device:id,name,location'])
            ->orderBy('triggered_at', 'desc')
            ->limit(10)
            ->get();

        return view('admin/dashboard', [
            'nav'          => 'dashboard',
            'stats'        => $stats,
            'recentAlerts' => $recentAlerts,
            'adminUser'    => $request->adminUser,
        ]);
    }
}
