<?php
/**
 * Home Guardian - 认证控制器
 *
 * 处理用户认证相关的 API 请求：
 *   POST /api/auth/login           — 用户登录
 *   POST /api/auth/refresh         — 刷新 access_token
 *   POST /api/auth/logout          — 注销当前设备
 *   POST /api/auth/logout-all      — 注销所有设备
 *   POST /api/auth/change-password — 修改密码
 *   GET  /api/auth/me              — 获取当前用户信息
 */

namespace app\controller;

use app\model\User;
use app\service\AuthService;
use app\service\AuditService;
use app\exception\BusinessException;
use support\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: '认证', description: '登录、注销、Token 刷新、个人信息')]
class AuthController
{
    /**
     * 用户登录
     *
     * POST /api/auth/login
     * Body: { "username": "dad", "password": "123456" }
     */
    #[OA\Post(
        path: '/auth/login',
        summary: '用户登录',
        description: '使用用户名和密码登录，获取 JWT access_token 和 refresh_token。',
        tags: ['认证'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['username', 'password'],
            properties: [
                new OA\Property(property: 'username', type: 'string', example: 'dad'),
                new OA\Property(property: 'password', type: 'string', example: '123456'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: '登录成功',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'integer', example: 0),
                new OA\Property(property: 'message', type: 'string', example: '登录成功'),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'username', type: 'string', example: 'dad'),
                        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
                    ], type: 'object'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ], type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 422, description: '参数缺失', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 401, description: '用户名或密码错误', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function login(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');

        // 参数校验
        if (empty($username) || empty($password)) {
            return api_error('用户名和密码不能为空', 422, 1000);
        }

        // 获取设备信息（用于 refresh_token 记录）
        $deviceInfo = $request->header('user-agent', 'Unknown');

        $result = AuthService::login($username, $password, $deviceInfo);

        // 记录登录审计日志
        // 由于登录前 $request->user 为 null，手动设置用户信息用于日志记录
        $request->user = (object)['id' => $result['user']['id']];
        AuditService::log($request, 'login', 'user', $result['user']['id'], [
            'username' => $username,
        ]);

        return api_success($result, '登录成功');
    }

    /**
     * 刷新 access_token
     *
     * POST /api/auth/refresh
     * Body: { "refresh_token": "abc123..." }
     */
    #[OA\Post(
        path: '/auth/refresh',
        summary: '刷新 access_token',
        description: '使用 refresh_token 获取新的 access_token，无需重新登录。',
        tags: ['认证'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['refresh_token'],
            properties: [
                new OA\Property(property: 'refresh_token', type: 'string'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: '刷新成功',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'integer', example: 0),
                new OA\Property(property: 'message', type: 'string', example: '刷新成功'),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ], type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 422, description: '参数缺失', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 401, description: 'Token 无效或已过期', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function refresh(Request $request)
    {
        $refreshToken = $request->post('refresh_token', '');

        if (empty($refreshToken)) {
            return api_error('refresh_token 不能为空', 422, 1000);
        }

        $result = AuthService::refresh($refreshToken);

        return api_success($result, '刷新成功');
    }

    /**
     * 注销当前设备
     *
     * POST /api/auth/logout
     * Body: { "refresh_token": "abc123..." }
     * Header: Authorization: Bearer {access_token}
     */
    #[OA\Post(
        path: '/auth/logout',
        summary: '注销当前设备',
        description: '注销当前设备的登录状态，使该设备的 refresh_token 失效。',
        security: [['bearerAuth' => []]],
        tags: ['认证'],
    )]
    #[OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'refresh_token', type: 'string', description: '可选，传入则吊销该 token'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: '已注销', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    public function logout(Request $request)
    {
        $refreshToken = $request->post('refresh_token', '');

        if (!empty($refreshToken)) {
            AuthService::logout($refreshToken);
        }

        // 记录注销审计日志
        AuditService::log($request, 'logout', 'user', $request->userId());

        return api_success(null, '已注销');
    }

    /**
     * 注销所有设备
     *
     * POST /api/auth/logout-all
     * Header: Authorization: Bearer {access_token}
     */
    #[OA\Post(
        path: '/auth/logout-all',
        summary: '注销所有设备',
        description: '注销该用户在所有设备上的登录状态。',
        security: [['bearerAuth' => []]],
        tags: ['认证'],
    )]
    #[OA\Response(response: 200, description: '已注销所有设备', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    public function logoutAll(Request $request)
    {
        AuthService::logoutAll($request->userId());

        AuditService::log($request, 'logout', 'user', $request->userId(), [
            'scope' => 'all_devices',
        ]);

        return api_success(null, '已注销所有设备');
    }

    /**
     * 获取当前用户信息
     *
     * GET /api/auth/me
     * Header: Authorization: Bearer {access_token}
     */
    #[OA\Get(
        path: '/auth/me',
        summary: '获取当前用户信息',
        description: '获取当前已认证用户的基本信息、角色、权限和位置作用域。',
        security: [['bearerAuth' => []]],
        tags: ['认证'],
    )]
    #[OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'integer', example: 0),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 1),
                    new OA\Property(property: 'username', type: 'string', example: 'dad'),
                    new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'locations', type: 'array', items: new OA\Items(type: 'string')),
                ], type: 'object'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: '未认证', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function me(Request $request)
    {
        return api_success([
            'id'          => $request->user->id,
            'username'    => $request->user->username,
            'roles'       => $request->user->roles,
            'permissions' => $request->user->permissions,
            'locations'   => $request->user->locations,
        ]);
    }

    /**
     * 修改密码
     *
     * POST /api/auth/change-password
     * Body: { "old_password": "xxx", "new_password": "yyy" }
     * Header: Authorization: Bearer {access_token}
     */
    #[OA\Post(
        path: '/auth/change-password',
        summary: '修改密码',
        description: '修改当前用户的密码，需提供旧密码和新密码。',
        security: [['bearerAuth' => []]],
        tags: ['认证'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['old_password', 'new_password'],
            properties: [
                new OA\Property(property: 'old_password', type: 'string'),
                new OA\Property(property: 'new_password', type: 'string', minLength: 6),
            ]
        )
    )]
    #[OA\Response(response: 200, description: '密码修改成功', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    #[OA\Response(response: 422, description: '参数校验失败', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    #[OA\Response(response: 401, description: '旧密码错误', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'))]
    public function changePassword(Request $request)
    {
        $oldPassword = $request->post('old_password', '');
        $newPassword = $request->post('new_password', '');

        if (empty($oldPassword) || empty($newPassword)) {
            return api_error('旧密码和新密码不能为空', 422, 1000);
        }

        if (mb_strlen($newPassword) < 6) {
            return api_error('新密码长度不能少于 6 位', 422, 1001);
        }

        $user = User::find($request->userId());
        if (!$user) {
            return api_error('用户不存在', 404, 1002);
        }

        if (!$user->verifyPassword($oldPassword)) {
            return api_error('旧密码错误', 401, 1003);
        }

        $user->setPassword($newPassword);
        $user->save();

        AuditService::log($request, 'change_password', 'user', $user->id);

        return api_success(null, '密码修改成功');
    }
}
