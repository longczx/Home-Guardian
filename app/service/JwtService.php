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

class JwtService
{
    /**
     * JWT 签名算法
     */
    private const ALGORITHM = 'HS256';

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
            return JWT::decode($token, new Key(self::getSecret(), self::ALGORITHM));
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
