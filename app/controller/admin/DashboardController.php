<?php
/**
 * Home Guardian - Admin 后台首页控制器
 *
 *   GET /admin/dashboard — 统计概览 + 实时设备仪表盘
 */

namespace app\controller\admin;

use app\model\Device;
use app\model\User;
use app\model\AlertLog;
use app\model\Automation;
use app\service\JwtService;
use support\Request;
use support\Redis;

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

        // 最近告警（转为数组供 volist 使用）
        $recentAlerts = AlertLog::with(['rule:id,name', 'device:id,name,location'])
            ->orderBy('triggered_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($a) => $a->toArray())
            ->toArray();

        // 所有设备（用于实时状态面板）
        $allDevices = Device::select('id', 'name', 'device_uid', 'location', 'is_online')
            ->orderByDesc('is_online')
            ->orderBy('name')
            ->get()
            ->map(fn($d) => $d->toArray())
            ->toArray();

        // 从 Redis 获取在线设备的最新遥测数据（供仪表盘 gauge 展示）
        $deviceMetrics = [];
        foreach ($allDevices as $dev) {
            $cached = null;
            try {
                $cached = Redis::get("device:latest:{$dev['id']}");
            } catch (\Throwable $e) {}
            $metrics = [];
            if ($cached) {
                $data = json_decode($cached, true) ?: [];
                unset($data['timestamp'], $data['request_id']);
                foreach ($data as $k => $v) {
                    if (is_numeric($v)) $metrics[$k] = (float)$v;
                }
            }
            $deviceMetrics[] = [
                'id'       => $dev['id'],
                'uid'      => $dev['device_uid'],
                'name'     => $dev['name'],
                'location' => $dev['location'] ?? '',
                'online'   => (bool)$dev['is_online'],
                'metrics'  => $metrics,
            ];
        }

        // 为当前管理员签发短期 JWT，用于浏览器 WebSocket 连接
        $wsToken = '';
        try {
            $admin = $request->adminUser;
            $wsToken = JwtService::issueAccessToken(
                $admin['id'],
                $admin['username'],
                ['admin'],
                ['admin' => true],
                []
            );
        } catch (\Throwable $e) {}

        // 构建 WebSocket URL
        $scheme = $request->header('x-forwarded-proto', 'http');
        $wsScheme = ($scheme === 'https') ? 'wss' : 'ws';
        $host = $request->host();
        $hostParts = explode(':', $host);
        $hostname = $hostParts[0];
        $port = $hostParts[1] ?? ($scheme === 'https' ? '443' : '80');
        if ($port === '8787') {
            $wsUrl = "{$wsScheme}://{$hostname}:8788/ws";
        } else {
            $wsUrl = "{$wsScheme}://{$host}/ws";
        }

        return view('admin/dashboard', [
            'nav'           => 'dashboard',
            'stats'         => $stats,
            'recentAlerts'  => $recentAlerts,
            'allDevices'    => $allDevices,
            'deviceMetrics' => json_encode($deviceMetrics, JSON_UNESCAPED_UNICODE),
            'wsToken'       => $wsToken,
            'wsUrl'         => $wsUrl,
            'adminUser'     => $request->adminUser,
        ]);
    }
}
