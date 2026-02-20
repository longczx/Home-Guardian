<?php
/**
 * Home Guardian - 告警服务
 *
 * 封装告警规则管理和告警触发的业务逻辑。
 * 告警规则的实时匹配由 AlertEngineProcess 负责（内存中运行），
 * 此服务负责规则的 CRUD 和告警日志的管理。
 */

namespace app\service;

use app\model\AlertRule;
use app\model\AlertLog;
use app\exception\BusinessException;
use support\Redis;
use support\Log;

class AlertService
{
    /**
     * Redis 键：告警规则缓存（AlertEngine 从此键加载规则）
     */
    private const RULES_CACHE_KEY = 'alert:rules:all';

    /**
     * Redis 频道：规则变更通知
     * AlertEngine 订阅此频道，收到通知后重新加载规则
     */
    private const RULES_CHANGED_CHANNEL = 'alert:rules:changed';

    /**
     * 创建告警规则
     *
     * 创建后通知告警引擎重新加载规则。
     *
     * @param  array $data 规则数据
     * @return AlertRule
     */
    public static function createRule(array $data): AlertRule
    {
        $rule = AlertRule::create($data);

        // 通知告警引擎重新加载规则
        self::notifyRulesChanged();

        return $rule;
    }

    /**
     * 更新告警规则
     *
     * @param  int   $id   规则 ID
     * @param  array $data 更新数据
     * @return AlertRule
     *
     * @throws BusinessException 规则不存在
     */
    public static function updateRule(int $id, array $data): AlertRule
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            throw new BusinessException('告警规则不存在', 404, 3001);
        }

        $rule->update($data);
        self::notifyRulesChanged();

        return $rule->fresh();
    }

    /**
     * 删除告警规则
     *
     * @param int $id 规则 ID
     * @throws BusinessException 规则不存在
     */
    public static function deleteRule(int $id): void
    {
        $rule = AlertRule::find($id);
        if (!$rule) {
            throw new BusinessException('告警规则不存在', 404, 3001);
        }

        $rule->delete();
        self::notifyRulesChanged();
    }

    /**
     * 触发告警
     *
     * 由 AlertEngineProcess 在规则匹配成功时调用。
     * 创建告警日志并异步发送通知。
     *
     * @param AlertRule $rule           匹配的规则
     * @param int       $deviceId       触发设备 ID
     * @param mixed     $triggeredValue 触发时的遥测值
     */
    public static function triggerAlert(AlertRule $rule, int $deviceId, mixed $triggeredValue): void
    {
        // 记录告警日志
        $alertLog = AlertLog::create([
            'rule_id'         => $rule->id,
            'device_id'       => $deviceId,
            'triggered_at'    => now(),
            'triggered_value' => is_array($triggeredValue) ? $triggeredValue : [$triggeredValue],
            'status'          => AlertLog::STATUS_TRIGGERED,
        ]);

        // 通过 Redis Pub/Sub 推送到 WebSocket（前端实时弹窗）
        $wsPayload = json_encode([
            'type'    => 'alert',
            'data'    => [
                'alert_log_id' => $alertLog->id,
                'rule_id'      => $rule->id,
                'rule_name'    => $rule->name,
                'device_id'    => $deviceId,
                'value'        => $triggeredValue,
                'triggered_at' => $alertLog->triggered_at->toIso8601String(),
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            Redis::connection('pubsub')->publish('ws:broadcast', $wsPayload);
        } catch (\Throwable $e) {
            Log::error("告警 WebSocket 推送失败: {$e->getMessage()}");
        }

        // 异步发送通知（推入 Redis 队列，由通知进程处理）
        if (!empty($rule->notification_channel_ids)) {
            $notifyTask = json_encode([
                'channel_ids' => $rule->notification_channel_ids,
                'title'       => "告警触发: {$rule->name}",
                'content'     => "设备 ID {$deviceId} 的 {$rule->telemetry_key} 值 ({$triggeredValue}) 触发了告警规则 [{$rule->name}]",
                'extra'       => [
                    'rule_id'   => $rule->id,
                    'device_id' => $deviceId,
                    'value'     => $triggeredValue,
                ],
            ], JSON_UNESCAPED_UNICODE);

            Redis::connection('queue')->lPush('notify:queue', $notifyTask);
        }
    }

    /**
     * 确认告警（标记为已处理）
     *
     * @param int $alertLogId 告警日志 ID
     * @param int $userId     确认人 ID
     *
     * @throws BusinessException 告警不存在
     */
    public static function acknowledgeAlert(int $alertLogId, int $userId): AlertLog
    {
        $alertLog = AlertLog::find($alertLogId);
        if (!$alertLog) {
            throw new BusinessException('告警记录不存在', 404, 3002);
        }

        $alertLog->update([
            'status'          => AlertLog::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
        ]);

        return $alertLog->fresh();
    }

    /**
     * 解决告警
     *
     * @param int $alertLogId 告警日志 ID
     *
     * @throws BusinessException 告警不存在
     */
    public static function resolveAlert(int $alertLogId): AlertLog
    {
        $alertLog = AlertLog::find($alertLogId);
        if (!$alertLog) {
            throw new BusinessException('告警记录不存在', 404, 3002);
        }

        $alertLog->update([
            'status' => AlertLog::STATUS_RESOLVED,
        ]);

        return $alertLog->fresh();
    }

    /**
     * 获取所有启用的告警规则（供 AlertEngine 加载）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEnabledRules()
    {
        return AlertRule::enabled()->with('device:id,device_uid')->get();
    }

    /**
     * 通知告警引擎重新加载规则
     *
     * 通过 Redis Pub/Sub 发送变更信号。
     */
    private static function notifyRulesChanged(): void
    {
        try {
            Redis::connection('pubsub')->publish(self::RULES_CHANGED_CHANNEL, 'reload');
        } catch (\Throwable $e) {
            Log::error("通知告警引擎刷新规则失败: {$e->getMessage()}");
        }
    }
}
