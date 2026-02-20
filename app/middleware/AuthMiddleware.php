<?php
/**
 * Home Guardian - JWT 认证中间件
 *
 * 验证 HTTP 请求中的 JWT access_token，将解码后的用户信息
 * 挂载到 $request->user 属性上，供后续中间件和控制器使用。
 *
 * Token 传递方式：
 *   - HTTP 请求: Authorization: Bearer {access_token}
 *   - WebSocket:  ws://host/ws?token={access_token}（在 WebSocket 服务中单独处理）
 *
 * 本中间件仅在路由组级别注册（不是全局中间件），
 * 不需要认证的接口（如登录、健康检查）不会经过此中间件。
 */

namespace app\middleware;

use app\service\JwtService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AuthMiddleware implements MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param  Request  $request 当前请求
     * @param  callable $handler 下一个中间件或控制器
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        // 从 Authorization 头中提取 Bearer token
        $token = $this->extractToken($request);

        if (empty($token)) {
            return api_error('未提供认证令牌', 401, 1000);
        }

        // 解码并验证 JWT（不查库，纯内存操作）
        $payload = JwtService::verifyAccessToken($token);

        if (!$payload) {
            return api_error('认证令牌无效或已过期', 401, 1001);
        }

        // 将用户信息挂载到 request 对象上
        // 后续代码通过 $request->user->id / $request->isAdmin() 等方式访问
        $request->user = (object)[
            'id'          => $payload->sub,
            'username'    => $payload->username,
            'roles'       => $payload->roles ?? [],
            'permissions' => $payload->permissions ?? (object)[],
            'locations'   => $payload->locations ?? [],
        ];

        // 继续处理请求
        return $handler($request);
    }

    /**
     * 从请求头中提取 Bearer Token
     *
     * 支持标准的 Authorization: Bearer {token} 格式。
     *
     * @param  Request $request
     * @return string|null token 字符串，未找到时返回 null
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('authorization', '');

        // 匹配 "Bearer " 前缀（不区分大小写）
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
