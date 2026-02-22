<?php
/**
 * Home Guardian - Admin 后台认证中间件
 *
 * 检查 session 中的管理员登录状态，
 * 未登录的请求重定向到 /admin/login。
 */

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 登录页本身不需要认证
        $path = $request->path();
        if ($path === '/admin/login') {
            return $handler($request);
        }

        // 检查 session 登录状态
        $adminUser = $request->session()->get('admin_user');
        if (!$adminUser) {
            return redirect('/admin/login');
        }

        // 将管理员信息挂载到 request
        $request->adminUser = $adminUser;

        return $handler($request);
    }
}
