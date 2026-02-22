<?php
/**
 * Home Guardian - Admin 审计日志控制器
 */

namespace app\controller\admin;

use app\model\AuditLog;
use support\Request;

class AuditLogController
{
    public function index(Request $request)
    {
        $query = AuditLog::with('user:id,username');

        if ($userId = $request->get('user_id')) {
            $query->where('user_id', (int)$userId);
        }
        if ($action = $request->get('action')) {
            $query->withAction($action);
        }
        if ($resourceType = $request->get('resource_type')) {
            $query->where('resource_type', $resourceType);
        }

        if (($start = $request->get('start')) && ($end = $request->get('end'))) {
            $query->between($start, $end);
        }

        $perPage = min((int)($request->get('per_page', 50)), 200);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return view('admin/audit-log/list', [
            'logs'      => $logs,
            'filters'   => $request->get(),
            'nav'       => 'audit-logs',
            'adminUser' => $request->adminUser,
        ]);
    }
}
