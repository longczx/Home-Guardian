<?php
/**
 * Home Guardian - Admin 后台异常处理器
 *
 * admin 路由下的异常渲染为 HTML 页面，而非 JSON。
 */

namespace app\exception;

use support\Log;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;
use Throwable;

class AdminExceptionHandler extends ExceptionHandler
{
    public $dontReport = [];

    public function report(Throwable $exception): void
    {
        Log::error($exception->getMessage(), [
            'exception' => get_class($exception),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }

    public function render(Request $request, Throwable $exception): Response
    {
        $debug   = config('app.debug', false);
        $code    = $exception->getCode() ?: 500;
        $message = $exception->getMessage();

        if (!$debug && $code >= 500) {
            $message = '服务器内部错误';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>错误 - Home Guardian</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
        .error-box { background: #fff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 12px rgba(0,0,0,.1); text-align: center; max-width: 500px; }
        .error-code { font-size: 48px; font-weight: bold; color: #e74c3c; margin-bottom: 10px; }
        .error-msg { font-size: 16px; color: #666; margin-bottom: 20px; }
        a { color: #1e88e5; text-decoration: none; }
    </style>
</head>
<body>
    <div class="error-box">
        <div class="error-code">{$code}</div>
        <div class="error-msg">{$message}</div>
        <a href="/admin/dashboard">返回后台首页</a>
    </div>
</body>
</html>
HTML;

        return new Response($code >= 100 && $code < 600 ? $code : 500, [
            'Content-Type' => 'text/html; charset=utf-8',
        ], $html);
    }
}
