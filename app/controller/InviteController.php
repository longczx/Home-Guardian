<?php
/**
 * Home Guardian - 邀请码控制器
 *
 *   GET    /api/invites        — 邀请码列表（owner/admin）
 *   POST   /api/invites        — 生成邀请码（owner/admin）
 *   DELETE /api/invites/{id}   — 作废邀请码（owner/admin）
 */

namespace app\controller;

use app\model\HomeUser;
use app\model\InviteCode;
use app\service\AuditService;
use app\service\HomeService;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '邀请码', description: '家庭成员邀请码的生成与管理')]
class InviteController
{
    #[OA\Get(
        path: '/invites',
        summary: '邀请码列表',
        security: [['bearerAuth' => []]],
        tags: ['邀请码'],
    )]
    #[OA\Response(response: 200, description: '成功')]
    public function index(Request $request)
    {
        $invites = InviteCode::with(['creator:id,username', 'usedByUser:id,username'])
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get()
            ->map(function (InviteCode $invite) {
                $arr = $invite->toArray();
                $arr['status'] = $invite->statusText();
                return $arr;
            });

        return api_success($invites);
    }

    #[OA\Post(
        path: '/invites',
        summary: '生成邀请码',
        description: '生成一次性邀请码，新用户凭码自助注册并加入家庭。需要 owner/admin 角色。',
        security: [['bearerAuth' => []]],
        tags: ['邀请码'],
    )]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'role', type: 'string', enum: ['admin', 'member'], default: 'member', description: '注册后获得的家庭角色'),
            new OA\Property(property: 'ttl', type: 'integer', description: '有效期秒数，默认 72 小时'),
        ]
    ))]
    #[OA\Response(response: 201, description: '生成成功')]
    public function store(Request $request)
    {
        $invite = HomeService::createInvite(
            (int)$request->user->home_id,
            $request->userId(),
            (string)$request->post('role', HomeUser::ROLE_MEMBER),
            $request->post('ttl') !== null ? (int)$request->post('ttl') : null
        );

        AuditService::log($request, 'create', 'invite_code', $invite->id, [
            'role' => $invite->role,
        ]);

        return api_success($invite, '邀请码已生成', 201);
    }

    #[OA\Delete(
        path: '/invites/{id}',
        summary: '作废邀请码',
        security: [['bearerAuth' => []]],
        tags: ['邀请码'],
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: '已作废')]
    public function destroy(Request $request, int $id)
    {
        $invite = InviteCode::find($id);
        if (!$invite) {
            return api_error('邀请码不存在', 404, 8016);
        }
        if ($invite->used_by) {
            return api_error('邀请码已被使用，无法作废', 410, 8017);
        }

        $invite->delete();

        AuditService::log($request, 'delete', 'invite_code', $id);

        return api_success(null, '邀请码已作废');
    }
}
