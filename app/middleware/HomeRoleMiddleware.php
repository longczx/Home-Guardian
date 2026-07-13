<?php
/**
 * Home Guardian - 家庭角色中间件
 *
 * 校验当前用户在家庭内的角色是否达到要求，用于成员管理/邀请码等
 * 不适合用 resource.action 权限表达的"家庭治理"类接口。
 * 必须在 AuthMiddleware 之后执行（依赖 $request->user->home_role）。
 *
 * 使用方式：
 *   Route::post('/api/invites', [InviteController::class, 'store'])
 *       ->middleware([new HomeRoleMiddleware(HomeUser::ROLE_ADMIN)]);
 *
 * 角色梯度：owner > admin > member，传入的是"最低要求"。
 */

namespace app\middleware;

use app\model\HomeUser;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class HomeRoleMiddleware implements MiddlewareInterface
{
    /** 角色 → 等级 */
    private const ROLE_LEVEL = [
        HomeUser::ROLE_MEMBER => 1,
        HomeUser::ROLE_ADMIN  => 2,
        HomeUser::ROLE_OWNER  => 3,
    ];

    public function __construct(
        private readonly string $minRole = HomeUser::ROLE_ADMIN
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        if (!$request->user) {
            return api_error('未认证', 401, 1000);
        }

        $role = $request->user->home_role ?? HomeUser::ROLE_MEMBER;
        $level = self::ROLE_LEVEL[$role] ?? 0;
        $required = self::ROLE_LEVEL[$this->minRole] ?? PHP_INT_MAX;

        if ($level < $required) {
            return api_error(
                '权限不足，需要家庭' . HomeUser::roleLabel($this->minRole) . '及以上角色',
                403,
                1005
            );
        }

        return $handler($request);
    }
}
