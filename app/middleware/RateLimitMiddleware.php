<?php
/**
 * Home Guardian - 简单限流中间件（滑动固定窗口）
 *
 * 用于保护公开接口（登录 / 注册 / 配网注册）免受爆破与刷量。
 * 以 "客户端 IP + 路由标识" 为 key，在 Redis 里按固定时间窗计数，超限返回 429。
 *
 * 用法：
 *   ->middleware([new RateLimitMiddleware('login', 10, 60)])  // 60s 内最多 10 次
 *
 * Redis 不可用时放行（fail-open）：宁可短暂失去限流，也不因缓存故障锁死登录。
 */

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use support\Redis;
use support\Log;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $bucket,   // 路由标识，如 'login'
        private readonly int $limit = 20,  // 窗口内最大请求数
        private readonly int $window = 60  // 窗口秒数
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        $ip = $this->clientIp($request);
        $key = "ratelimit:{$this->bucket}:{$ip}";

        try {
            $redis = Redis::connection('default');
            $count = $redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $this->window);
            }
            if ($count > $this->limit) {
                $ttl = $redis->ttl($key);
                return api_error("请求过于频繁，请 {$ttl} 秒后再试", 429, 1006);
            }
        } catch (\Throwable $e) {
            // 缓存故障不阻断业务
            Log::warning("限流失效（Redis 异常）: {$e->getMessage()}");
        }

        return $handler($request);
    }

    /**
     * 取真实客户端 IP（信任反代传入的 X-Forwarded-For 首段）
     */
    private function clientIp(Request $request): string
    {
        $xff = $request->header('x-forwarded-for', '');
        if ($xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
        return $request->getRealIp();
    }
}
