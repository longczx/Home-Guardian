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
     * Redis 键：规则变更标记
     *
     * AlertEngineProcess 每隔几秒轮询此键，发现存在即重新加载规则后删除。
     * 用 default 连接（DB0，带 hg: 前缀）写入，实际键名为 'hg:alert:rules:changed'，
     * 与告警引擎裸 \Redis 在 DB0 读取的键名保持一致。
     */
    private const RULES_CHANGED_KEY = 'alert:rules:changed';

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
    public static function triggerAlert(AlertRule $rule, int $deviceId, mixed $triggeredValue, string $kind = 'telemetry'): ?AlertLog
    {
        $cache = self::cache();
        $activeKey = "alert:active:{$rule->id}:{$deviceId}";

        // 降噪去重：已处于"激活(firing)"状态则不重复建日志/发通知，直到恢复
        if ($cache) {
            try { if ($cache->get($activeKey)) return null; } catch (\Throwable $e) {}
        }

        $severity = $rule->severity ?: AlertRule::SEVERITY_WARNING;

        $alertLog = AlertLog::create([
            'home_id'         => $rule->home_id ?? \app\model\Home::DEFAULT_HOME_ID, // 常驻进程无请求上下文，随规则归属
            'rule_id'         => $rule->id,
            'device_id'       => $deviceId,
            'triggered_at'    => now(),
            'triggered_value' => is_array($triggeredValue) ? $triggeredValue : [$triggeredValue],
            'status'          => AlertLog::STATUS_TRIGGERED,
            'severity'        => $severity,
        ]);

        // 置激活标记（安全 TTL 30 天，防止异常残留）
        if ($cache) {
            try { $cache->setex($activeKey, 2592000, (string)$alertLog->id); } catch (\Throwable $e) {}
        }

        self::pushWs('alert', $rule, $deviceId, [
            'alert_log_id' => $alertLog->id,
            'severity'     => $severity,
            'value'        => $triggeredValue,
            'triggered_at' => $alertLog->triggered_at->toIso8601String(),
        ]);

        // 通知：受冷却窗口约束，防止同规则反复轰炸（闪断场景）
        if (!empty($rule->notification_channel_ids)) {
            $cooldownKey = "alert:cooldown:{$rule->id}:{$deviceId}";
            $inCooldown = false;
            if ($cache) {
                try { $inCooldown = (bool)$cache->get($cooldownKey); } catch (\Throwable $e) {}
            }
            if (!$inCooldown) {
                $label = AlertRule::severityLabel($severity);
                $content = $kind === AlertRule::TRIGGER_OFFLINE
                    ? "设备(ID {$deviceId}) 已离线，触发规则 [{$rule->name}]"
                    : "设备(ID {$deviceId}) 的 {$rule->telemetry_key} 值 (" . self::scalar($triggeredValue) . ") 触发规则 [{$rule->name}]";
                self::queueNotify($rule->notification_channel_ids, "【{$label}】{$rule->name}", $content, [
                    'rule_id' => $rule->id, 'device_id' => $deviceId, 'severity' => $severity, 'value' => $triggeredValue,
                ]);
                $cd = (int)($rule->notify_cooldown_sec ?? 600);
                if ($cache && $cd > 0) {
                    try { $cache->setex($cooldownKey, $cd, (string)time()); } catch (\Throwable $e) {}
                }
            }
        }

        return $alertLog;
    }

    /**
     * 条件恢复：自动 resolve 该规则+设备的激活告警，并（可选）发恢复通知。
     * 由告警引擎在"条件不再满足"时调用；无激活告警时快速返回。
     */
    public static function resolveActive(AlertRule $rule, int $deviceId, mixed $value = null): void
    {
        $cache = self::cache();
        $activeKey = "alert:active:{$rule->id}:{$deviceId}";

        // 无激活标记 → 不查库直接返回（热路径高频调用）
        if ($cache) {
            try { if (!$cache->get($activeKey)) return; } catch (\Throwable $e) {}
        }

        $affected = AlertLog::where('rule_id', $rule->id)
            ->where('device_id', $deviceId)
            ->whereIn('status', [AlertLog::STATUS_TRIGGERED, AlertLog::STATUS_ACKNOWLEDGED])
            ->update(['status' => AlertLog::STATUS_RESOLVED, 'resolved_at' => now()]);

        if ($cache) { try { $cache->del($activeKey); } catch (\Throwable $e) {} }

        if ($affected <= 0) return;

        self::pushWs('alert_resolved', $rule, $deviceId, ['value' => $value]);

        if ($rule->notify_on_recovery && !empty($rule->notification_channel_ids)) {
            self::queueNotify($rule->notification_channel_ids, "【恢复】{$rule->name}",
                "设备(ID {$deviceId}) 的规则 [{$rule->name}] 已恢复正常", [
                'rule_id' => $rule->id, 'device_id' => $deviceId, 'event' => 'recovered',
            ]);
        }
    }

    /**
     * 设备离线 → 触发该设备所有 offline 型规则
     */
    public static function triggerOfflineAlerts(int $deviceId): void
    {
        $rules = AlertRule::enabled()->where('device_id', $deviceId)->offlineType()->get();
        foreach ($rules as $rule) {
            self::triggerAlert($rule, $deviceId, 'offline', AlertRule::TRIGGER_OFFLINE);
        }
    }

    /**
     * 设备恢复在线 → 自动解决该设备的 offline 告警
     */
    public static function resolveOfflineAlerts(int $deviceId): void
    {
        $rules = AlertRule::enabled()->where('device_id', $deviceId)->offlineType()->get();
        foreach ($rules as $rule) {
            self::resolveActive($rule, $deviceId, 'online');
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
            'status'      => AlertLog::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        // 清激活标记，允许该规则+设备后续再次触发
        $cache = self::cache();
        if ($cache) {
            try { $cache->del("alert:active:{$alertLog->rule_id}:{$alertLog->device_id}"); } catch (\Throwable $e) {}
        }

        return $alertLog->fresh();
    }

    /**
     * 获取所有启用的【遥测型】告警规则（供 AlertEngine 加载匹配）
     * offline 型规则由 MqttSubscriber 的上下线事件处理，不在此列。
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEnabledRules()
    {
        return AlertRule::enabled()->telemetryType()->with('device:id,device_uid')->get();
    }

    /* ============================
     * 内部辅助
     * ============================ */

    /** DB0 缓存连接（用于激活标记/冷却），失败返回 null（fail-open） */
    private static function cache()
    {
        try {
            return Redis::connection('default');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 推送告警相关事件到 WebSocket */
    private static function pushWs(string $type, AlertRule $rule, int $deviceId, array $data): void
    {
        $payload = json_encode([
            'type' => $type,
            'data' => array_merge([
                'rule_id'   => $rule->id,
                'rule_name' => $rule->name,
                'device_id' => $deviceId,
            ], $data),
        ], JSON_UNESCAPED_UNICODE);

        try {
            Redis::connection('pubsub')->publish('ws:broadcast', $payload);
        } catch (\Throwable $e) {
            Log::error("告警 WS 推送失败: {$e->getMessage()}");
        }
    }

    /** 通知任务入队（由 NotificationProcess 异步发送） */
    private static function queueNotify(array $channelIds, string $title, string $content, array $extra): void
    {
        $task = json_encode([
            'channel_ids' => $channelIds,
            'title'       => $title,
            'content'     => $content,
            'extra'       => $extra,
        ], JSON_UNESCAPED_UNICODE);

        try {
            Redis::connection('queue')->lPush('notify:queue', $task);
        } catch (\Throwable $e) {
            Log::error("通知入队失败: {$e->getMessage()}");
        }
    }

    /** 把遥测值转成可读标量字符串 */
    private static function scalar(mixed $v): string
    {
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        if (is_bool($v))  return $v ? 'true' : 'false';
        return (string)$v;
    }

    /**
     * 通知告警引擎重新加载规则
     *
     * 写入一个带过期时间的变更标记键，告警引擎轮询到后重新加载并删除。
     * 之所以不用 Pub/Sub：引擎用的是定时轮询消费，Pub/Sub 的瞬时消息引擎收不到。
     */
    private static function notifyRulesChanged(): void
    {
        try {
            Redis::connection('default')->setex(self::RULES_CHANGED_KEY, 600, (string)time());
        } catch (\Throwable $e) {
            Log::error("通知告警引擎刷新规则失败: {$e->getMessage()}");
        }
    }
}
