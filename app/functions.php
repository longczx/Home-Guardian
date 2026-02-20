<?php
/**
 * Home Guardian - 全局辅助函数
 *
 * 这些函数在整个应用中随处可用（通过 config/autoload.php 自动加载）。
 * 保持函数简洁、通用，避免在此放置业务逻辑。
 */

/**
 * 获取环境变量值
 *
 * 从 .env 文件加载的环境变量中读取值，支持布尔和 null 类型的自动转换。
 * 在 Webman 中，.env 由 support/bootstrap.php 通过 vlucas/phpdotenv 加载。
 *
 * @param  string $key     环境变量名
 * @param  mixed  $default 变量不存在时的默认值
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);

    // getenv() 未找到时返回 false
    if ($value === false) {
        return $default;
    }

    // 自动转换常见的字符串表示为对应的 PHP 类型
    return match (strtolower($value)) {
        'true', '(true)'   => true,
        'false', '(false)' => false,
        'null', '(null)'   => null,
        'empty', '(empty)' => '',
        default             => $value,
    };
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
