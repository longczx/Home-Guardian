<?php
/**
 * Home Guardian - JWT 服务
 *
 * 负责 JWT access_token 的签发与验证，是认证系统的核心组件。
 * 使用 firebase/php-jwt 库实现标准的 JWT 签发/解码流程。
 *
 * Token 设计：
 *   - access_token:  JWT 格式，自包含用户信息，验证时不查库
 *   - refresh_token: 随机字符串，存储在 refresh_tokens 表中（非本类职责）
 *
 * JWT Payload 结构（编入角色/权限/位置，中间件鉴权时完全不查库）：
 *   {
 *     "sub": 1,              // 用户 ID
 *     "username": "dad",     // 用户名
 *     "roles": ["admin"],    // 角色列表
 *     "permissions": {},     // 权限集合
 *     "locations": [],       // 位置作用域
 *     "iat": 1739900000,     // 签发时间
 *     "exp": 1739907200      // 过期时间
 *   }
 */

namespace app\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use support\Redis;
use support\Log;

class JwtService
{
    /**
     * JWT 签名算法
     */
    private const ALGORITHM = 'HS256';

    /**
     * Redis 键前缀：用户的 token 版本号
     *
     * access_token 签发时把当前版本号写入 payload 的 tv 字段；验证时若 token 的
     * 版本号小于用户当前版本号，则视为已撤销。注销所有设备 / 修改密码时递增版本号，
     * 即可让该用户已签发的所有 access_token 立即失效（无需等待过期）。
     * 用 default 连接（DB0，带 hg: 前缀），实际键名为 'hg:user:tokenver:{id}'。
     */
    private const TOKEN_VERSION_KEY = 'user:tokenver:';

    /**
     * 签发 access_token
     *
     * 将用户的核心信息编入 JWT payload，后续 API 请求验证时
     * 只需解码 JWT 即可获取用户身份和权限，无需查询数据库。
     *
     * @param  int    $userId      用户 ID
     * @param  string $username    用户名
     * @param  array  $roles       角色名列表
     * @param  array  $permissions 权限集合
     * @param  array  $locations   允许的位置作用域
     * @return string 签名后的 JWT 字符串
     */
    public static function issueAccessToken(
        int $userId,
        string $username,
        array $roles,
        array $permissions,
        array $locations
    ): string {
        $now = time();
        $ttl = (int)(getenv('JWT_ACCESS_TTL') ?: 7200); // 默认 2 小时

        $payload = [
            'iss'         => 'home-guardian',     // 签发者
            'sub'         => $userId,              // 用户 ID
            'username'    => $username,
            'roles'       => $roles,
            'permissions' => $permissions,
            'locations'   => $locations,
            'tv'          => self::getTokenVersion($userId), // token 版本号（用于主动撤销）
            'iat'         => $now,                 // 签发时间
            'exp'         => $now + $ttl,          // 过期时间
        ];

        return JWT::encode($payload, self::getSecret(), self::ALGORITHM);
    }

    /**
     * 验证并解码 access_token
     *
     * 验证签名和有效期，成功后返回 payload 对象。
     * 失败时返回 null，由调用方决定如何处理（通常返回 401）。
     *
     * @param  string $token JWT 字符串
     * @return object|null 解码后的 payload 对象，失败返回 null
     */
    public static function verifyAccessToken(string $token): ?object
    {
        try {
            $payload = JWT::decode($token, new Key(self::getSecret(), self::ALGORITHM));
        } catch (ExpiredException) {
            // Token 已过期 — 前端应使用 refresh_token 刷新
            return null;
        } catch (SignatureInvalidException) {
            // 签名无效 — 可能被篡改
            return null;
        } catch (\Throwable) {
            // 其他解码错误（格式错误、算法不匹配等）
            return null;
        }

        // 撤销校验：token 版本号低于用户当前版本号则视为已失效（注销所有设备/改密后）
        $tokenVersion = (int)($payload->tv ?? 0);
        if ($tokenVersion < self::getTokenVersion((int)$payload->sub)) {
            return null;
        }

        return $payload;
    }

    /**
     * 读取用户当前的 token 版本号
     *
     * Redis 不可用时返回 0（fail-open）：宁可放过校验也不因缓存故障锁死全部登录，
     * 兼顾家庭场景的可用性。撤销失效窗口仅限 Redis 故障期间。
     *
     * @param  int $userId 用户 ID
     * @return int 当前版本号，未设置或异常时为 0
     */
    public static function getTokenVersion(int $userId): int
    {
        try {
            return (int)(Redis::connection('default')->get(self::TOKEN_VERSION_KEY . $userId) ?? 0);
        } catch (\Throwable $e) {
            Log::error("读取 token 版本号失败: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 递增用户的 token 版本号，使其已签发的所有 access_token 立即失效
     *
     * 用于"注销所有设备"和"修改密码"等需要全局踢下线的场景。
     *
     * @param int $userId 用户 ID
     */
    public static function bumpTokenVersion(int $userId): void
    {
        try {
            Redis::connection('default')->incr(self::TOKEN_VERSION_KEY . $userId);
        } catch (\Throwable $e) {
            Log::error("递增 token 版本号失败: {$e->getMessage()}");
        }
    }

    /**
     * 生成 refresh_token
     *
     * refresh_token 是纯随机字符串（非 JWT），用于换取新的 access_token。
     * 生成后需要将其哈希值存入 refresh_tokens 表。
     *
     * @return string 64 字符的随机十六进制字符串
     */
    public static function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 计算 refresh_token 的哈希值
     *
     * 数据库中只存哈希值，不存明文。验证时用同样的方式计算哈希后比对。
     *
     * @param  string $token 明文 refresh_token
     * @return string SHA-256 哈希值
     */
    public static function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 获取 JWT 密钥
     *
     * 从环境变量读取，如果未配置则抛出异常，绝不使用默认值。
     *
     * @return string JWT 签名密钥
     * @throws \RuntimeException 当 JWT_SECRET 未配置时
     */
    private static function getSecret(): string
    {
        $secret = getenv('JWT_SECRET');

        if (empty($secret) || $secret === 'your_jwt_secret_key_here') {
            throw new \RuntimeException(
                'JWT_SECRET 未配置或仍为默认值，请在 .env 文件中设置安全的密钥'
            );
        }

        return $secret;
    }
}
