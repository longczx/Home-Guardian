<?php
/**
 * Home Guardian - 全局异常处理器
 *
 * 统一捕获所有未处理的异常，返回标准 JSON 格式的错误响应。
 * 在生产环境中隐藏敏感的异常堆栈信息，仅在调试模式下输出。
 *
 * @see config/exception.php 中注册此处理器
 */

namespace app\exception;

use support\Log;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * 不需要记录日志的异常类型
     *
     * 这些异常属于"预期内的业务异常"（如认证失败、参数错误），
     * 不需要写入日志文件以避免日志膨胀。
     */
    public $dontReport = [
        BusinessException::class,
    ];

    /**
     * 记录异常到日志
     *
     * 只记录非预期的系统异常（500 级别），便于排查问题。
     * 4xx 级别的客户端错误不记录，因为它们是正常的业务拒绝。
     *
     * @param Throwable $exception 异常实例
     */
    public function report(Throwable $exception): void
    {
        // 跳过不需要记录的异常类型
        foreach ($this->dontReport as $type) {
            if ($exception instanceof $type) {
                return;
            }
        }

        // 记录完整的异常信息（类名、消息、文件、行号、堆栈）
        Log::error($exception->getMessage(), [
            'exception' => get_class($exception),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }

    /**
     * 将异常渲染为 HTTP 响应
     *
     * 所有 API 响应统一为 JSON 格式，前端不会收到 HTML 错误页面。
     *
     * @param  Request   $request   当前请求
     * @param  Throwable $exception 异常实例
     * @return Response
     */
    public function render(Request $request, Throwable $exception): Response
    {
        // 业务异常：直接使用异常中携带的状态码和消息
        if ($exception instanceof BusinessException) {
            return new Response($exception->getCode() ?: 400, [
                'Content-Type' => 'application/json',
            ], json_encode([
                'code'    => $exception->getBusinessCode(),
                'message' => $exception->getMessage(),
                'data'    => $exception->getData(),
            ], JSON_UNESCAPED_UNICODE));
        }

        // 系统异常：根据调试模式决定返回信息的详细程度
        $debug = config('app.debug', false);

        $response = [
            'code'    => 500,
            'message' => $debug ? $exception->getMessage() : '服务器内部错误',
        ];

        // 调试模式下附加详细的异常信息，方便开发排错
        if ($debug) {
            $response['data'] = [
                'exception' => get_class($exception),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => explode("\n", $exception->getTraceAsString()),
            ];
        }

        return new Response(500, [
            'Content-Type' => 'application/json',
        ], json_encode($response, JSON_UNESCAPED_UNICODE));
    }
}
