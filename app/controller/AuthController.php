<?php
/**
 * Home Guardian - 认证控制器
 *
 * 处理用户认证相关的 API 请求：
 *   POST /api/auth/login       — 用户登录
 *   POST /api/auth/refresh     — 刷新 access_token
 *   POST /api/auth/logout      — 注销当前设备
 *   POST /api/auth/logout-all  — 注销所有设备
 *   GET  /api/auth/me          — 获取当前用户信息
 */

namespace app\controller;

use app\service\AuthService;
use app\service\AuditService;
use app\exception\BusinessException;
use support\Request;

class AuthController
{
    /**
     * 用户登录
     *
     * POST /api/auth/login
     * Body: { "username": "dad", "password": "123456" }
     */
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
}
