<?php
/**
 * Home Guardian - CORS 跨域中间件
 *
 * 处理浏览器的跨源资源共享（CORS）预检请求和响应头。
 * 在前后端分离架构中，前端（Vue3 开发服务器或不同域名部署）
 * 需要通过 CORS 才能正常访问 API。
 *
 * 工作流程：
 *   1. OPTIONS 预检请求 → 直接返回 204 + CORS 头（不进入业务逻辑）
 *   2. 其他请求 → 正常处理后附加 CORS 响应头
 *
 * 注意：生产环境应将 Access-Control-Allow-Origin 限制为具体域名。
 */

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class CorsMiddleware implements MiddlewareInterface
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
        // OPTIONS 预检请求：浏览器在发送实际请求前会先发送 OPTIONS 探测
        // 直接返回空响应 + CORS 头即可，无需进入路由和业务逻辑
        if ($request->method() === 'OPTIONS') {
            $response = new Response(204);
        } else {
            // 非预检请求：传递给下一个处理器（中间件或控制器）
            $response = $handler($request);
        }

        // 统一附加 CORS 响应头
        $response->withHeaders([
            // 允许的来源域名（开发环境用 *，生产环境应限制为实际前端域名）
            'Access-Control-Allow-Origin'      => '*',
            // 允许的 HTTP 方法
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            // 允许前端发送的自定义请求头
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, Accept',
            // 允许前端读取的自定义响应头
            'Access-Control-Expose-Headers'    => 'X-Request-Id',
            // 预检结果缓存时间（秒），减少不必要的 OPTIONS 请求
            'Access-Control-Max-Age'           => '86400',
            // 不允许携带 Cookie（JWT 方案不需要 Cookie）
            'Access-Control-Allow-Credentials' => 'false',
        ]);

        return $response;
    }
}
