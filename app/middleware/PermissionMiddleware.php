<?php
/**
 * Home Guardian - 权限检查中间件
 *
 * 基于 RBAC + 位置作用域的权限检查，在路由级别使用。
 * 必须在 AuthMiddleware 之后执行（依赖 $request->user）。
 *
 * 使用方式（在路由定义中）：
 *   Route::get('/api/devices', [DeviceController::class, 'index'])
 *       ->middleware([
 *           AuthMiddleware::class,
 *           new PermissionMiddleware('devices.view'),
 *       ]);
 *
 * 权限检查两层：
 *   第一层：角色权限 — 用户是否有该操作的权限（如 devices.control）
 *   第二层：位置作用域 — 当操作涉及具体设备时，设备位置是否在用户允许范围内
 *
 * 注意：位置作用域的检查在 Controller/Service 层实现（因为需要查询具体设备），
 * 此中间件只负责第一层的角色权限检查。
 */

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class PermissionMiddleware implements MiddlewareInterface
{
    /**
     * 需要检查的权限标识
     *
     * 格式: "resource.action"，如 "devices.view"、"users.create"
     *
     * @var string
     */
    private string $permission;

    /**
     * @param string $permission 权限标识，格式: "resource.action"
     */
    public function __construct(string $permission = '')
    {
        $this->permission = $permission;
    }

    /**
     * 处理请求
     *
     * @param  Request  $request 当前请求（已挂载 $request->user）
     * @param  callable $handler 下一个中间件或控制器
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        // 如果未配置具体权限，直接放行（仅需认证即可访问的接口）
        if (empty($this->permission)) {
            return $handler($request);
        }

        // 安全检查：确保已通过认证中间件
        if (!$request->user) {
            return api_error('未认证', 401, 1000);
        }

        // 使用 Request 类中封装的权限检查方法
        if (!$request->hasPermission($this->permission)) {
            return api_error(
                "权限不足，需要 [{$this->permission}] 权限",
                403,
                1004
            );
        }

        return $handler($request);
    }
}
