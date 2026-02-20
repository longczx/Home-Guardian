<?php
/**
 * Home Guardian - 审计日志中间件
 *
 * 自动记录写操作（POST/PUT/PATCH/DELETE）的审计日志。
 * 仅记录成功的操作（响应码 2xx），失败的请求不记录。
 *
 * 使用方式：
 *   在需要审计的路由组中注册此中间件，或在 Controller 中手动调用 AuditService::log()。
 *
 * 注意：此中间件只做基础的自动记录，对于需要记录详细变更内容的操作
 * （如 update 前后差异），应在 Controller/Service 层手动调用 AuditService。
 */

namespace app\middleware;

use app\service\AuditService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AuditLogMiddleware implements MiddlewareInterface
{
    /**
     * HTTP 方法到审计操作的映射
     */
    private const METHOD_ACTION_MAP = [
        'POST'   => 'create',
        'PUT'    => 'update',
        'PATCH'  => 'update',
        'DELETE' => 'delete',
    ];

    /**
     * 处理请求
     */
    public function process(Request $request, callable $handler): Response
    {
        // 执行请求
        $response = $handler($request);

        // 只记录写操作（GET/HEAD/OPTIONS 不记录）
        $method = strtoupper($request->method());
        if (!isset(self::METHOD_ACTION_MAP[$method])) {
            return $response;
        }

        // 只记录成功的操作（2xx 响应码）
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            return $response;
        }

        // 从请求路径推断资源类型和 ID
        $resourceInfo = $this->parseResourceFromPath($request->path());

        if ($resourceInfo) {
            AuditService::log(
                $request,
                self::METHOD_ACTION_MAP[$method],
                $resourceInfo['type'],
                $resourceInfo['id']
            );
        }

        return $response;
    }

    /**
     * 从 URL 路径解析资源类型和 ID
     *
     * 支持的路径格式：
     *   /api/devices        → type=device, id=null
     *   /api/devices/123    → type=device, id=123
     *   /api/alert-rules/5  → type=alert_rule, id=5
     *
     * @param  string $path URL 路径
     * @return array|null   解析结果，无法识别时返回 null
     */
    private function parseResourceFromPath(string $path): ?array
    {
        // 移除 /api/ 前缀
        $path = preg_replace('#^/api/#', '', $path);

        // 分割路径段
        $segments = array_filter(explode('/', $path));
        $segments = array_values($segments);

        if (empty($segments)) {
            return null;
        }

        // 第一段是资源名（复数形式转单数、横线转下划线）
        $resourceName = $segments[0];
        $resourceType = rtrim(str_replace('-', '_', $resourceName), 's');

        // 第二段是资源 ID（如果存在且为数字）
        $resourceId = null;
        if (isset($segments[1]) && is_numeric($segments[1])) {
            $resourceId = (int)$segments[1];
        }

        return [
            'type' => $resourceType,
            'id'   => $resourceId,
        ];
    }
}
