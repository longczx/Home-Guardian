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
        return api_success(\app\service\BackupHealthService::summary() + ['current_commit'=>getenv('HG_BUILD_COMMIT') ?: 'unknown']);
    }
}
