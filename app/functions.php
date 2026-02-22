<?php
/**
 * Home Guardian - 全局辅助函数
 *
 * 这些函数在整个应用中随处可用（通过 config/autoload.php 自动加载）。
 * 保持函数简洁、通用，避免在此放置业务逻辑。
 */

/**
 * 返回当前时间的 Carbon 实例
 *
 * illuminate/support 新版已将此函数移除出全局命名空间，
 * 在此补充定义以保持与 Laravel 风格代码的兼容性。
 *
 * @return \Illuminate\Support\Carbon
 */
if (!function_exists('now')) {
    function now(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::now();
    }
}

/**
 * 生成标准 JSON API 成功响应
 *
 * @param  mixed  $data    响应数据
 * @param  string $message 成功消息
 * @param  int    $code    HTTP 状态码
 * @return \support\Response
 */
function api_success(mixed $data = null, string $message = 'ok', int $code = 200): \support\Response
{
    return new \support\Response($code, [
        'Content-Type' => 'application/json',
    ], json_encode([
        'code'    => 0,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * 生成标准 JSON API 错误响应
 *
 * @param  string $message   错误消息
 * @param  int    $code      HTTP 状态码
 * @param  int    $errorCode 业务错误码（0 为成功，非 0 为各类错误）
 * @param  mixed  $data      附加数据（如验证错误详情）
 * @return \support\Response
 */
function api_error(string $message = 'error', int $code = 400, int $errorCode = 1, mixed $data = null): \support\Response
{
    return new \support\Response($code, [
        'Content-Type' => 'application/json',
    ], json_encode([
        'code'    => $errorCode,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * 生成分页格式的 JSON 响应
 *
 * 配合 Eloquent 的 paginate() 使用，自动提取分页元信息。
 *
 * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator 分页器实例
 * @param  string $message 成功消息
 * @return \support\Response
 */
function api_paginate(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator, string $message = 'ok'): \support\Response
{
    return new \support\Response(200, [
        'Content-Type' => 'application/json',
    ], json_encode([
        'code'    => 0,
        'message' => $message,
        'data'    => [
            'items'        => $paginator->items(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ],
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * 获取当前已认证用户信息
 *
 * 从请求对象中获取 JWT 中间件解析后的用户数据。
 * 仅在通过 AuthMiddleware 的路由中可用。
 *
 * @param  \support\Request $request 当前请求
 * @return object|null 用户信息对象（包含 id, username, roles, permissions, locations）
 */
function current_user(\support\Request $request): ?object
{
    return $request->user ?? null;
}
