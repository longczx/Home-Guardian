<?php
/**
 * Home Guardian - 业务异常类
 *
 * 用于在 Service/Controller 层主动抛出可预期的业务错误，
 * 例如"用户名已存在"、"设备不存在"、"无权操作"等。
 *
 * 与系统异常（RuntimeException、PDOException 等）不同，业务异常：
 *   1. 不会记录到日志（因为是预期行为，不需要排查）
 *   2. 直接将消息返回给前端（安全的、用户可读的错误信息）
 *   3. 支持自定义业务错误码，便于前端做差异化处理
 *
 * @example throw new BusinessException('用户名已存在', 409, 1001);
 */

namespace app\exception;

use RuntimeException;

class BusinessException extends RuntimeException
{
    /**
     * 业务错误码
     *
     * 与 HTTP 状态码独立，用于更细粒度的错误分类。
     * 约定：0 = 成功，1-999 = 通用错误，1000+ = 模块级错误。
     */
    protected int $businessCode;

    /**
     * 附加数据
     *
     * 可选的错误详情，如参数校验的具体失败字段。
     */
    protected mixed $data;

    /**
     * @param string $message      用户可读的错误消息
     * @param int    $httpCode     HTTP 状态码（400/401/403/404/409/422 等）
     * @param int    $businessCode 业务错误码
     * @param mixed  $data         附加数据
     */
    public function __construct(
        string $message = '操作失败',
        int $httpCode = 400,
        int $businessCode = 1,
        mixed $data = null
    ) {
        $this->businessCode = $businessCode;
        $this->data = $data;
        parent::__construct($message, $httpCode);
    }

    /**
     * 获取业务错误码
     */
    public function getBusinessCode(): int
    {
        return $this->businessCode;
    }

    /**
     * 获取附加数据
     */
    public function getData(): mixed
    {
        return $this->data;
    }
}
