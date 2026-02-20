<?php
/**
 * Home Guardian - 自动化服务
 *
 * 封装场景自动化规则的业务逻辑。
 * 自动化规则支持两种触发方式：
 *   - telemetry: 遥测数据条件触发（复用告警引擎的数据消费流程）
 *   - schedule:  定时计划触发（由 Crontab 进程调度）
 *
 * 每条规则的 actions 是一个动作数组，按顺序依次执行。
 */

namespace app\service;

use app\model\Automation;
use app\model\Device;
use app\exception\BusinessException;
use support\Log;

class AutomationService
{
    /**
     * 创建自动化规则
     *
     * @param  array $data 规则数据
     * @return Automation
     */
    public static function create(array $data): Automation
    {
        return Automation::create($data);
    }

    /**
     * 更新自动化规则
     *
     * @param  int   $id   规则 ID
     * @param  array $data 更新数据
     * @return Automation
     *
     * @throws BusinessException 规则不存在
     */
    public static function update(int $id, array $data): Automation
    {
        $automation = Automation::find($id);
        if (!$automation) {
            throw new BusinessException('自动化规则不存在', 404, 4001);
        }

        $automation->update($data);
        return $automation->fresh();
    }

    /**
     * 删除自动化规则
     *
     * @param int $id 规则 ID
     * @throws BusinessException 规则不存在
     */
    public static function delete(int $id): void
    {
        $automation = Automation::find($id);
        if (!$automation) {
            throw new BusinessException('自动化规则不存在', 404, 4001);
        }

        $automation->delete();
    }

    /**
     * 执行自动化规则的所有动作
     *
     * 按 actions 数组顺序依次执行每个动作。
     * 单个动作执行失败不影响后续动作。
     *
     * @param Automation $automation 自动化规则实例
     */
    public static function executeActions(Automation $automation): void
    {
        $actions = $automation->actions ?? [];

        foreach ($actions as $index => $action) {
            try {
                match ($action['type'] ?? '') {
                    Automation::ACTION_DEVICE_COMMAND => self::executeDeviceCommand($action),
                    Automation::ACTION_NOTIFY         => self::executeNotify($action, $automation),
                    default => Log::warning("未知的自动化动作类型: " . ($action['type'] ?? 'null')),
                };
            } catch (\Throwable $e) {
                Log::error("自动化 [{$automation->name}] 动作 #{$index} 执行失败: {$e->getMessage()}");
            }
        }

        // 更新最后触发时间
        $automation->update(['last_triggered_at' => now()]);
    }

    /**
     * 执行设备控制动作
     *
     * 通过 MqttCommandService 向目标设备发送 MQTT 指令。
     *
     * @param array $action 动作配置，如 {"type": "device_command", "device_id": 2, "payload": {"action": "turn_on"}}
     */
    private static function executeDeviceCommand(array $action): void
    {
        $deviceId = $action['device_id'] ?? null;
        $payload = $action['payload'] ?? [];

        if (!$deviceId) {
            Log::warning('自动化设备控制动作缺少 device_id');
            return;
        }

        MqttCommandService::sendCommand($deviceId, $payload);

        Log::info("自动化: 已向设备 {$deviceId} 发送控制指令", $payload);
    }

    /**
     * 执行通知推送动作
     *
     * 通过 NotificationService 向指定渠道发送通知。
     *
     * @param array      $action     动作配置，如 {"type": "notify", "channel_ids": [1, 3]}
     * @param Automation $automation 所属的自动化规则（用于生成通知标题）
     */
    private static function executeNotify(array $action, Automation $automation): void
    {
        $channelIds = $action['channel_ids'] ?? [];

        if (empty($channelIds)) {
            return;
        }

        NotificationService::send(
            $channelIds,
            "自动化触发: {$automation->name}",
            $automation->description ?: "自动化规则 [{$automation->name}] 已触发执行",
            ['automation_id' => $automation->id]
        );
    }

    /**
     * 检查遥测条件是否满足自动化触发
     *
     * 由告警引擎进程复用，在消费遥测数据时同时匹配自动化规则。
     *
     * @param int    $deviceId  设备 ID
     * @param string $metricKey 遥测指标名
     * @param mixed  $value     遥测值
     */
    public static function checkTelemetryTrigger(int $deviceId, string $metricKey, mixed $value): void
    {
        $automations = Automation::enabled()
            ->ofTriggerType(Automation::TRIGGER_TELEMETRY)
            ->get();

        foreach ($automations as $automation) {
            $config = $automation->trigger_config;

            // 检查是否匹配目标设备和指标
            if (($config['device_id'] ?? null) != $deviceId) {
                continue;
            }
            if (($config['metric_key'] ?? '') !== $metricKey) {
                continue;
            }

            // 检查条件是否满足
            $condition = $config['condition'] ?? '';
            $threshold = $config['value'] ?? null;

            if (!is_numeric($value) || !is_numeric($threshold)) {
                continue;
            }

            $met = match ($condition) {
                'GREATER_THAN' => (float)$value > (float)$threshold,
                'LESS_THAN'    => (float)$value < (float)$threshold,
                'EQUALS'       => abs((float)$value - (float)$threshold) < 0.0001,
                'NOT_EQUALS'   => abs((float)$value - (float)$threshold) >= 0.0001,
                default        => false,
            };

            if ($met) {
                // 防抖：检查 duration_sec 是否满足（使用 Redis 计时）
                // 简化实现：无 duration 配置时立即触发
                $durationSec = $config['duration_sec'] ?? 0;
                if ($durationSec <= 0 || self::checkDuration($automation->id, $durationSec)) {
                    self::executeActions($automation);
                }
            }
        }
    }

    /**
     * 检查条件持续时间是否满足（防抖）
     *
     * 使用 Redis 记录条件首次满足的时间，持续满足指定秒数后才触发。
     *
     * @param  int $automationId 自动化规则 ID
     * @param  int $durationSec  需要持续的秒数
     * @return bool 是否满足持续时间要求
     */
    private static function checkDuration(int $automationId, int $durationSec): bool
    {
        $key = "automation:duration:{$automationId}";
        $redis = \support\Redis::connection('default');

        $firstHit = $redis->get($key);

        if (!$firstHit) {
            // 首次满足条件，记录时间并设置过期
            $redis->setEx($key, $durationSec + 60, time());
            return false;
        }

        // 检查是否已持续足够长时间
        if (time() - (int)$firstHit >= $durationSec) {
            $redis->del($key); // 清除记录，防止重复触发
            return true;
        }

        return false;
    }
}
