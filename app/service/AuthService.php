<?php
/**
 * Home Guardian - 认证服务
 *
 * 处理用户认证相关的核心业务逻辑：
 *   - 用户登录（验证凭证 → 签发双 Token）
 *   - Token 刷新（验证 refresh_token → 签发新 access_token）
 *   - 用户注销（删除 refresh_token）
 *   - 全设备注销（清空用户所有 refresh_token）
 *
 * 双 Token 认证流程：
 *   1. 登录 → 返回 access_token (JWT, 2h) + refresh_token (随机串, 30d)
 *   2. API 请求 → 用 access_token 鉴权（不查库，纯 JWT 解码）
 *   3. access_token 过期 → 用 refresh_token 换取新的 access_token
 *   4. refresh_token 过期 → 需要重新登录
 */

namespace app\service;

use app\model\User;
use app\model\RefreshToken;
use app\exception\BusinessException;

class AuthService
{
    /**
     * 用户登录
     *
     * 验证用户名和密码，成功后签发 access_token 和 refresh_token。
     * refresh_token 的哈希值存入数据库，明文只返回给客户端一次。
     *
     * @param  string $username   用户名
     * @param  string $password   明文密码
     * @param  string $deviceInfo 登录设备信息（如 "Chrome/Mac"）
     * @return array  包含 access_token、refresh_token 和用户基本信息
     *
     * @throws BusinessException 用户名或密码错误、账号被禁用
     */
    public static function login(string $username, string $password, string $deviceInfo = ''): array
    {
        // 查找用户（包含角色关联，用于生成 JWT payload）
        $user = User::with('roles', 'allowedLocations')
            ->where('username', $username)
            ->first();

        // 用户不存在或密码错误 — 统一返回模糊提示，防止用户名枚举
        if (!$user || !$user->verifyPassword($password)) {
            throw new BusinessException('用户名或密码错误', 401, 1001);
        }

        // 检查账号是否被禁用
        if (!$user->is_active) {
            throw new BusinessException('账号已被禁用，请联系管理员', 403, 1002);
        }

        // 收集 JWT 需要的用户权限信息
        $roles = $user->roles->pluck('name')->toArray();
        $permissions = $user->getMergedPermissions();
        $locations = $user->getAllowedLocationList();

        // 签发 access_token（JWT，包含完整权限信息）
        $accessToken = JwtService::issueAccessToken(
            $user->id,
            $user->username,
            $roles,
            $permissions,
            $locations
        );

        // 生成 refresh_token 并存入数据库
        $refreshToken = JwtService::generateRefreshToken();
        $refreshTtl = (int)(getenv('JWT_REFRESH_TTL') ?: 2592000); // 默认 30 天

        RefreshToken::create([
            'user_id'     => $user->id,
            'token_hash'  => JwtService::hashRefreshToken($refreshToken),
            'device_info' => $deviceInfo,
            'expires_at'  => date('Y-m-d H:i:s', time() + $refreshTtl),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => (int)(getenv('JWT_ACCESS_TTL') ?: 7200),
            'user' => [
                'id'       => $user->id,
                'username' => $user->username,
                'email'    => $user->email,
                'roles'    => $roles,
            ],
        ];
    }

    /**
     * 刷新 access_token
     *
     * 使用 refresh_token 换取新的 access_token。
     * 采用 Token 轮换策略：每次刷新都会销毁旧的 refresh_token 并生成新的，
     * 如果旧 token 被重复使用，说明可能被盗，所有 token 都会失效。
     *
     * @param  string $refreshTokenStr 客户端持有的 refresh_token 明文
     * @return array  包含新的 access_token 和 refresh_token
     *
     * @throws BusinessException refresh_token 无效或已过期
     */
    public static function refresh(string $refreshTokenStr): array
    {
        // 通过哈希值查找数据库中的 token 记录
        $tokenHash = JwtService::hashRefreshToken($refreshTokenStr);

        $refreshTokenRecord = RefreshToken::valid()
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$refreshTokenRecord) {
            throw new BusinessException('refresh_token 无效或已过期，请重新登录', 401, 1003);
        }

        // 加载用户及关联数据
        $user = User::with('roles', 'allowedLocations')
            ->find($refreshTokenRecord->user_id);

        if (!$user || !$user->is_active) {
            // 用户已被删除或禁用，清理所有 token
            RefreshToken::where('user_id', $refreshTokenRecord->user_id)->delete();
            throw new BusinessException('账号已被禁用或不存在', 403, 1002);
        }

        // 删除旧的 refresh_token（Token 轮换）
        $refreshTokenRecord->delete();

        // 签发新的 access_token
        $roles = $user->roles->pluck('name')->toArray();
        $permissions = $user->getMergedPermissions();
        $locations = $user->getAllowedLocationList();

        $newAccessToken = JwtService::issueAccessToken(
            $user->id,
            $user->username,
            $roles,
            $permissions,
            $locations
        );

        // 签发新的 refresh_token
        $newRefreshToken = JwtService::generateRefreshToken();
        $refreshTtl = (int)(getenv('JWT_REFRESH_TTL') ?: 2592000);

        RefreshToken::create([
            'user_id'     => $user->id,
            'token_hash'  => JwtService::hashRefreshToken($newRefreshToken),
            'device_info' => $refreshTokenRecord->device_info,
            'expires_at'  => date('Y-m-d H:i:s', time() + $refreshTtl),
        ]);

        return [
            'access_token'  => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in'    => (int)(getenv('JWT_ACCESS_TTL') ?: 7200),
        ];
    }

    /**
     * 用户注销（退出当前设备）
     *
     * 删除指定的 refresh_token 记录，该设备的 access_token 会在过期后自然失效。
     *
     * @param string $refreshTokenStr 要注销的 refresh_token
     */
    public static function logout(string $refreshTokenStr): void
    {
        $tokenHash = JwtService::hashRefreshToken($refreshTokenStr);
        RefreshToken::where('token_hash', $tokenHash)->delete();
    }

    /**
     * 全设备注销（退出所有登录会话）
     *
     * 删除该用户的所有 refresh_token 记录。
     * 已签发的 access_token 仍会在有效期内可用（最多 2 小时）。
     *
     * @param int $userId 用户 ID
     */
    public static function logoutAll(int $userId): void
    {
        RefreshToken::where('user_id', $userId)->delete();
    }
}
