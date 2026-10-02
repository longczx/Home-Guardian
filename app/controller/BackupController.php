<?php
namespace app\controller;

use support\Request;

class BackupController
{
    public function index(Request $request)
    {
        // A full instance backup spans families. Never expose it as a per-family backup.
        if (($request->user->home_role ?? '') !== 'owner' || \app\model\Home::withoutGlobalScopes()->count() !== 1) {
            return api_error('仅单家庭实例的家庭所有者可查看备份状态', 403, 1004);
        }
        $items = [];
        foreach (glob(runtime_path('backup-catalog') . '/*.json') ?: [] as $file) {
            $row = json_decode(file_get_contents($file), true);
            if (is_array($row)) $items[] = array_intersect_key($row, array_flip(['id','created_at','status','size_bytes','commit','error']));
        }
        usort($items, fn ($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return api_success(['items'=>array_slice($items, 0, 100)]);
    }
}
