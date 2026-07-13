<?php
/**
 * Home Guardian - 家庭控制器
 *
 *   GET    /api/home                      — 当前家庭信息 + 成员列表（任意成员）
 *   PUT    /api/home                      — 修改家庭名称（owner）
 *   PUT    /api/home/members/{userId}     — 修改成员角色（owner，支持 owner 转让）
 *   DELETE /api/home/members/{userId}     — 移除成员（owner/admin）
 */

namespace app\controller;

use app\model\Home;
use app\model\HomeUser;
use app\service\AuditService;
use app\service\HomeService;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '家庭', description: '家庭信息与成员管理')]
class HomeController
{
    #[OA\Get(
        path: '/home',
        summary: '当前家庭信息与成员列表',
        security: [['bearerAuth' => []]],
        tags: ['家庭'],
    )]
    #[OA\Response(response: 200, description: '成功')]
    public function show(Request $request)
    {
        $homeId = (int)$request->user->home_id;
        $home = Home::find($homeId);
        if (!$home) {
            return api_error('家庭不存在', 404, 8020);
        }

        return api_success([
            'home'    => $home->toArray(),
            'my_role' => $request->user->home_role,
            'members' => HomeService::members($homeId),
        ]);
    }

    #[OA\Put(
        path: '/home',
        summary: '修改家庭名称',
        description: '仅户主可操作。',
        security: [['bearerAuth' => []]],
        tags: ['家庭'],
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['name'],
        properties: [new OA\Property(property: 'name', type: 'string', example: '我的家')]
    ))]
    #[OA\Response(response: 200, description: '更新成功')]
    public function update(Request $request)
    {
        $name = trim((string)$request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 50) {
            return api_error('家庭名称需为 1-50 个字符', 422, 8021);
        }

        $home = Home::find((int)$request->user->home_id);
        if (!$home) {
            return api_error('家庭不存在', 404, 8020);
        }

        $home->update(['name' => $name]);

        AuditService::log($request, 'update', 'home', $home->id, ['name' => $name]);

        return api_success($home, '家庭信息已更新');
    }

    #[OA\Put(
        path: '/home/members/{userId}',
        summary: '修改成员角色',
        description: '仅户主可操作。目标角色为 owner 时执行转让（当前户主降为管理员）。',
        security: [['bearerAuth' => []]],
        tags: ['家庭'],
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['role'],
        properties: [new OA\Property(property: 'role', type: 'string', enum: ['owner', 'admin', 'member'])]
    ))]
    #[OA\Response(response: 200, description: '更新成功')]
    public function updateMember(Request $request, int $userId)
    {
        $role = (string)$request->post('role', '');

        HomeService::updateMemberRole(
            (int)$request->user->home_id,
            $request->userId(),
            $userId,
            $role
        );

        AuditService::log($request, 'update', 'home_member', $userId, ['role' => $role]);

        return api_success(null, '成员角色已更新');
    }

    #[OA\Delete(
        path: '/home/members/{userId}',
        summary: '移除成员',
        description: '户主可移除任何非户主成员；管理员只能移除普通成员。移除后其所有会话立即失效。',
        security: [['bearerAuth' => []]],
        tags: ['家庭'],
    )]
    #[OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '已移除')]
    public function removeMember(Request $request, int $userId)
    {
        HomeService::removeMember(
            (int)$request->user->home_id,
            $request->user->home_role ?? HomeUser::ROLE_MEMBER,
            $request->userId(),
            $userId
        );

        AuditService::log($request, 'delete', 'home_member', $userId);

        return api_success(null, '成员已移除');
    }
}
