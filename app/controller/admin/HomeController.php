<?php
/**
 * Home Guardian - Admin 家庭治理控制器
 *
 * 平台运维视角的两个页面：
 *   - 家庭成员：成员列表 / 角色调整 / 移除
 *   - 邀请码：生成 / 列表 / 作废
 */

namespace app\controller\admin;

use app\exception\BusinessException;
use app\model\Home;
use app\model\HomeUser;
use app\model\InviteCode;
use app\service\HomeService;
use support\Request;

class HomeController
{
    /* ============================
     * 家庭成员
     * ============================ */

    public function members(Request $request)
    {
        $homeId = Home::DEFAULT_HOME_ID;

        return view('admin/home/members', [
            'home'      => Home::find($homeId),
            'members'   => HomeService::members($homeId),
            'roleLabels' => [
                HomeUser::ROLE_OWNER  => HomeUser::roleLabel(HomeUser::ROLE_OWNER),
                HomeUser::ROLE_ADMIN  => HomeUser::roleLabel(HomeUser::ROLE_ADMIN),
                HomeUser::ROLE_MEMBER => HomeUser::roleLabel(HomeUser::ROLE_MEMBER),
            ],
            'nav'       => 'home-members',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function updateMemberRole(Request $request, int $userId)
    {
        try {
            HomeService::setMemberRole(Home::DEFAULT_HOME_ID, $userId, (string)$request->post('role', ''));
        } catch (BusinessException) {
            // 页面场景直接回列表，错误静默（保护性校验极少触发）
        }
        return redirect('/admin/home-members');
    }

    public function removeMember(Request $request, int $userId)
    {
        try {
            // 平台管理员视为 owner 权限操作
            HomeService::removeMember(Home::DEFAULT_HOME_ID, HomeUser::ROLE_OWNER, 0, $userId);
        } catch (BusinessException) {
        }
        return redirect('/admin/home-members');
    }

    /* ============================
     * 邀请码
     * ============================ */

    public function invites(Request $request)
    {
        $invites = InviteCode::withoutGlobalScopes()
            ->with(['creator:id,username', 'usedByUser:id,username'])
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get()
            ->map(function (InviteCode $invite) {
                $arr = $invite->toArray();
                $arr['status'] = $invite->statusText();
                return $arr;
            })
            ->toArray();

        return view('admin/home/invites', [
            'invites'   => $invites,
            'nav'       => 'invite-codes',
            'adminUser' => $request->adminUser,
        ]);
    }

    public function storeInvite(Request $request)
    {
        $role = (string)$request->post('role', HomeUser::ROLE_MEMBER);
        $ttl  = (int)$request->post('ttl_hours', 72) * 3600;

        try {
            HomeService::createInvite(Home::DEFAULT_HOME_ID, (int)$request->adminUser['id'], $role, $ttl);
        } catch (BusinessException) {
        }
        return redirect('/admin/invite-codes');
    }

    public function deleteInvite(Request $request, int $id)
    {
        $invite = InviteCode::withoutGlobalScopes()->find($id);
        if ($invite && !$invite->used_by) {
            $invite->delete();
        }
        return redirect('/admin/invite-codes');
    }
}
