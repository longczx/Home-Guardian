<?php
/**
 * Home Guardian - 全局中间件配置
 *
 * 此处注册的中间件会对所有 HTTP 请求生效（无论路由如何定义）。
 * 执行顺序：按数组顺序从外到内包裹，请求进入时正序执行，响应返回时逆序执行。
 *
 * 路由级中间件在 config/route.php 中通过 ->middleware() 方法注册。
 */

return [
    // 全局中间件（对所有路由生效）
    '' => [
        // CORS 跨域处理 — 必须在最外层，确保 OPTIONS 预检请求也能正确响应
        app\middleware\CorsMiddleware::class,
    ],
];
