<?php
/**
 * Home Guardian - 审计日志服务
 *
 * 统一写入审计日志的服务类，供中间件和 Controller/Service 层调用。
 * 审计日志记录所有关键用户操作，便于安全审计和问题追溯。
 *
 * 使用方式：
 *   AuditService::log($request, 'create', 'device', $device->id, ['name' => 'ESP32']);
 */

namespace app\service;

use app\model\AuditLog;
use support\Request;

class AuditService
{
    /**
     * 写入审计日志
     *
     * @param  Request     $request      当前请求（用于获取用户 ID、IP、UA）
     * @param  string      $action       操作类型（login/logout/create/update/delete/control/command_send）
     * @param  string      $resourceType 资源类型（user/device/alert_rule 等）
     * @param  int|null    $resourceId   被操作资源的 ID（登录/注销时可为 null）
     * @param  array|null  $detail       操作详情（如修改前后差异、指令内容）
     */
    public static function log(
        Request $request,
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        ?array $detail = null
    ): void {
        try {
            AuditLog::create([
                'user_id'       => $request->userId(),
                'action'        => $action,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
                'detail'        => $detail,
                'ip_address'    => $request->clientIp(),
                'user_agent'    => mb_substr($request->header('user-agent', ''), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // 审计日志写入失败不应影响业务流程，仅记录错误日志
            \support\Log::error('审计日志写入失败: ' . $e->getMessage(), [
                'action'        => $action,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
            ]);
        }
    }

    /**
     * 记录数据变更详情（update 操作时使用）
     *
     * 比对模型修改前后的差异，只记录实际变更的字段。
     *
     * @param  array $original 修改前的数据
     * @param  array $changed  修改后的数据
     * @return array 变更差异，格式: {"字段名": {"old": "旧值", "new": "新值"}}
     */
    public static function diffChanges(array $original, array $changed): array
    {
        $diff = [];

        foreach ($changed as $key => $newValue) {
            $oldValue = $original[$key] ?? null;

            // 跳过未变更的字段
            if ($oldValue === $newValue) {
                continue;
            }

            // 隐藏敏感字段的具体值
            if (in_array($key, ['password_hash', 'mqtt_password_hash', 'token_hash'])) {
                $diff[$key] = ['old' => '***', 'new' => '***'];
                continue;
            }

            $diff[$key] = ['old' => $oldValue, 'new' => $newValue];
        }

        return $diff;
    }
}
