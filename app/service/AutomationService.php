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
use support\Redis;
use support\Log;

class AutomationService
{
    /**
     * Redis 键：遥测型自动化规则变更标记
     *
     * 与告警规则同理，AlertEngineProcess 轮询此键，发现变更后重新加载内存中的
     * 自动化规则。用 default 连接（DB0，带 hg: 前缀），实际键名 'hg:automation:rules:changed'。
     */
    private const RULES_CHANGED_KEY = 'automation:rules:changed';

    public static function validatedInput(array $data, ?Automation $existing = null): array
    {
        $data = array_intersect_key($data, array_flip(['name', 'description', 'trigger_type',
            'trigger_config', 'actions', 'is_enabled', 'created_by']));
        if ($existing) unset($data['created_by']);
        $homeId = $existing ? (int)$existing->home_id
            : (\app\model\scope\HomeScope::currentHomeId() ?? \app\model\Home::DEFAULT_HOME_ID);
        if (!$existing) $data['home_id'] = $homeId;
        $merged = array_merge($existing?->toArray() ?? [], $data);
        $config = $merged['trigger_config'] ?? [];
        $actions = $merged['actions'] ?? [];
        if (!is_array($config) || !is_array($actions) || !$actions || count($actions) > 20) {
            throw new BusinessException('触发配置和动作必须为有效对象/数组', 422, 4002);
        }
        $checkDevice = static function ($id) use ($homeId): void {
            $device = Device::withoutGlobalScopes()->where('home_id', $homeId)->find($id);
            if (!$device) throw new BusinessException('设备不属于当前家庭', 422, 4002);
            try { $request = request(); } catch (\Throwable) { $request = null; }
            if ($request?->user && !$request->canAccessLocation($device->location)) {
                throw new BusinessException('无权使用目标设备', 403, 1004);
            }
        };
        if (($merged['trigger_type'] ?? '') === Automation::TRIGGER_TELEMETRY) {
            $checkDevice($config['device_id'] ?? 0);
            if (!is_string($config['metric_key'] ?? null) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $config['metric_key']) || !isset($config['condition'], $config['value'])
                || !(is_numeric($config['value']) || is_bool($config['value'])) || !in_array($config['condition'], ['GREATER_THAN','LESS_THAN','EQUALS','NOT_EQUALS'], true)) {
                throw new BusinessException('遥测触发条件无效', 422, 4002);
            }
            foreach (['duration_sec', 'cooldown_sec'] as $key) {
                if (isset($config[$key]) && (!is_numeric($config[$key]) || $config[$key] < 0)) {
                    throw new BusinessException('持续时间/冷却时间必须为非负数', 422, 4002);
                }
            }
        } elseif (($merged['trigger_type'] ?? '') === Automation::TRIGGER_SCHEDULE) {
            if (!\Cron\CronExpression::isValidExpression($config['cron'] ?? '')) {
                throw new BusinessException('定时表达式无效', 422, 4002);
            }
        } else throw new BusinessException('触发类型无效', 422, 4002);
        AutomationPolicyService::validate($config, $checkDevice);
        foreach ($actions as $action) {
            if (!is_array($action)) throw new BusinessException('动作必须为对象', 422, 4002);
            if (($action['type'] ?? '') === Automation::ACTION_DEVICE_COMMAND) {
                $checkDevice($action['device_id'] ?? 0);
                if (!is_array($action['payload'] ?? null) || !is_string($action['payload']['action'] ?? null)
                    || !is_array($action['payload']['params'] ?? [])) throw new BusinessException('设备动作格式无效', 422, 4002);
                $target = Device::withoutGlobalScopes()->where('home_id', $homeId)->find($action['device_id']);
                ActuatorService::validate($target->capability ?? [], $action['payload']['action'], $action['payload']['params'] ?? []);
            } elseif (($action['type'] ?? '') === Automation::ACTION_NOTIFY) {
                $ids = $action['channel_ids'] ?? [];
                if (!is_array($ids) || !$ids || \app\model\NotificationChannel::withoutGlobalScopes()
                    ->where('home_id', $homeId)->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
                    throw new BusinessException('通知渠道不属于当前家庭', 422, 4002);
                }
            } else throw new BusinessException('动作类型无效', 422, 4002);
        }
        return $data;
    }

    /**
     * 创建自动化规则
     *
     * @param  array $data 规则数据
     * @return Automation
     */
    public static function create(array $data): Automation
    {
        $data = self::validatedInput($data);
        $automation = Automation::create($data);
        self::notifyRulesChanged();
        return $automation;
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

        $data = self::validatedInput($data, $automation);
        $automation->update($data);
        self::clearDuration($id);
        Redis::connection('default')->del("automation:latch:{$id}");
        self::notifyRulesChanged();
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
        self::notifyRulesChanged();
    }

    /**
     * 执行自动化规则的所有动作
     *
     * 按 actions 数组顺序依次执行每个动作。
     * 单个动作执行失败不影响后续动作。
     *
     * @param Automation $automation 自动化规则实例
     */
    public static function executeActions(Automation $automation, array $context = []): bool
    {
        // Database defaults are not hydrated on a newly created Eloquent instance.
        if (!$automation->home_id) $automation->refresh();
        $actions = $automation->actions ?? [];
        $deviceIds = AutomationPolicyService::deviceIds($automation);
        $policy = AutomationPolicyService::check($automation);
        if (!$policy['eligible']) {
            // Keep useful skip explanations without a new row for every sensor report.
            if (Redis::connection('default')->set("automation:skip:{$automation->id}", '1', 'EX', 60, 'NX')) {
                $locations = Device::withoutGlobalScopes()->where('home_id', $automation->home_id)->whereIn('id', $deviceIds)->pluck('location')->unique()->values()->all();
                \app\model\AutomationRun::create(['home_id'=>$automation->home_id, 'automation_id'=>$automation->id, 'automation_name'=>$automation->name,
                    'trigger_type'=>$automation->trigger_type, 'trigger_context'=>array_merge($automation->trigger_config, $context, ['policy'=>$policy]),
                    'locations'=>$locations, 'action_results'=>[], 'started_at'=>now(), 'finished_at'=>now(), 'status'=>'skipped','submission_finished'=>true]);
            }
            return false;
        }
        $locations = Device::withoutGlobalScopes()->where('home_id', $automation->home_id)->whereIn('id', $deviceIds)
            ->pluck('location')->unique()->values()->all();
        $run = \app\model\AutomationRun::create(['home_id'=>$automation->home_id, 'automation_id'=>$automation->id,
            'automation_name'=>$automation->name, 'trigger_type'=>$automation->trigger_type,
            'trigger_context'=>array_merge($automation->trigger_config ?? [], $context, ['policy'=>$policy]), 'locations'=>$locations,
            'action_results'=>[], 'started_at'=>now(), 'status'=>'running']);
        $results = [];

        foreach ($actions as $index => $action) {
            try {
                $result = match ($action['type'] ?? '') {
                    Automation::ACTION_DEVICE_COMMAND => self::executeDeviceCommand($action, $automation),
                    Automation::ACTION_NOTIFY         => self::executeNotify($action, $automation),
                    default => throw new BusinessException('未知的自动化动作类型', 422, 4002),
                };
                $results[] = array_merge(['index'=>$index, 'type'=>$action['type'], 'status'=>'pending'], $result);
            } catch (\Throwable $e) {
                $results[] = ['index'=>$index, 'type'=>$action['type'] ?? '', 'status'=>$e instanceof BusinessException && in_array($e->getBusinessCode(), [2110,2111,2112,2113], true) ? 'skipped' : 'failed',
                    'error'=>$e instanceof BusinessException ? $e->getMessage() : '提交动作失败，请检查服务日志'];
                Log::error("自动化 [{$automation->name}] 动作 #{$index} 执行失败: {$e->getMessage()}");
            }
            $run->update(['action_results'=>$results]);
        }

        // 更新最后触发时间
        $automation->update(['last_triggered_at' => now()]);
        $run->update(['submission_finished'=>true]);
        AutomationRunService::refresh($run);
        return true;
    }

    /**
     * 执行设备控制动作
     *
     * 通过 MqttCommandService 向目标设备发送 MQTT 指令。
     *
     * @param array $action 动作配置，如 {"type": "device_command", "device_id": 2, "payload": {"action": "turn_on"}}
     */
    private static function executeDeviceCommand(array $action, Automation $automation): array
    {
        $deviceId = $action['device_id'] ?? null;
        $payload = $action['payload'] ?? [];

        if (!$deviceId) {
            throw new BusinessException('自动化设备控制动作缺少 device_id', 422, 4002);
        }

        $device = Device::withoutGlobalScopes()->where('home_id', $automation->home_id)->find($deviceId);
        if (!$device) throw new BusinessException('自动化目标设备不属于当前家庭', 422, 4002);
        $command = DeviceControlService::send($device, $payload['action'] ?? '', $payload['params'] ?? []);

        Log::info("自动化: 已向设备 {$deviceId} 发送控制指令", $payload);
        return ['device_id'=>$deviceId, 'action'=>$payload['action'] ?? '', 'command_id'=>$command->id, 'request_id'=>$command->request_id];
    }

    /**
     * 执行通知推送动作
     *
     * 通过 NotificationService 向指定渠道发送通知。
     *
     * @param array      $action     动作配置，如 {"type": "notify", "channel_ids": [1, 3]}
     * @param Automation $automation 所属的自动化规则（用于生成通知标题）
     */
    private static function executeNotify(array $action, Automation $automation): array
    {
        $channelIds = \app\model\NotificationChannel::withoutGlobalScopes()
            ->where('home_id', $automation->home_id)->whereIn('id', $action['channel_ids'] ?? [])->pluck('id')->all();

        if (empty($channelIds)) {
            throw new BusinessException('没有可用的通知渠道', 422, 4002);
        }

        // 推入通知队列由 NotificationProcess 异步发送，避免在告警引擎事件循环里同步阻塞
        $taskId = bin2hex(random_bytes(16));
        $task = json_encode([
            'task_id' => $taskId,
            'channel_ids' => $channelIds,
            'title'       => "自动化触发: {$automation->name}",
            'content'     => $automation->description ?: "自动化规则 [{$automation->name}] 已触发执行",
            'extra'       => ['automation_id' => $automation->id, 'home_id' => $automation->home_id],
        ], JSON_UNESCAPED_UNICODE);

        Redis::connection('queue')->lPush('notify:queue', $task);
        return ['task_id'=>$taskId, 'channel_ids'=>$channelIds];
    }

    /**
     * 获取所有启用的遥测型自动化规则（供 AlertEngine 加载到内存缓存）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEnabledTelemetryAutomations()
    {
        return Automation::enabled()
            ->ofTriggerType(Automation::TRIGGER_TELEMETRY)
            ->get();
    }

    /**
     * 基于内存中已缓存的自动化规则匹配遥测数据（不查库）
     *
     * 由 AlertEngineProcess 在消费遥测数据时调用，避免每条数据都全表查询自动化规则。
     *
     * @param iterable $automations 已缓存的遥测型自动化规则
     * @param int      $deviceId    设备 ID
     * @param string   $metricKey   遥测指标名
     * @param mixed    $value       遥测值
     */
    public static function evaluateTelemetry(iterable $automations, int $deviceId, string $metricKey, mixed $value, ?string $observedAt = null): void
    {
        foreach ($automations as $automation) {
            self::matchAndExecute($automation, $deviceId, $metricKey, $value, $observedAt);
        }
    }

    /**
     * 单条自动化规则的匹配与执行
     *
     * @param Automation $automation 规则
     * @param int        $deviceId   设备 ID
     * @param string     $metricKey  指标名
     * @param mixed      $value      遥测值
     */
    private static function matchAndExecute(Automation $automation, int $deviceId, string $metricKey, mixed $value, ?string $observedAt = null): void
    {
        $config = $automation->trigger_config;

        // 检查是否匹配目标设备和指标
        if (($config['device_id'] ?? null) != $deviceId) {
            return;
        }
        if (($config['metric_key'] ?? '') !== $metricKey) {
            return;
        }

        if (!ObservationService::fresh(['ts'=>$observedAt ?? now()->toIso8601String()], (int)($config['max_age_sec'] ?? 900))) {
            self::clearDuration($automation->id);
            return;
        }
        $redis = Redis::connection('default');
        $sampleKey = "automation:sample:{$automation->id}";
        $previous = (int)$redis->get($sampleKey);
        if ($previous && time() - $previous > (int)($config['max_age_sec'] ?? 900)) self::clearDuration($automation->id);
        $redis->setex($sampleKey, 86400, time());
        $condition = $config['condition'] ?? '';
        $threshold = $config['value'] ?? null;

        $met = ObservationService::compare($value, $condition, $threshold);

        if (!$met) {
            // 条件不满足，清除防抖计时，避免"持续满足"被旧记录污染
            self::clearDuration($automation->id);
            Redis::connection('default')->del("automation:latch:{$automation->id}");
            return;
        }

        // 防抖：无 duration 配置时立即触发
        $durationSec = $config['duration_sec'] ?? 0;
        if ($durationSec <= 0 || self::checkDuration($automation->id, $durationSec)) {
            $redis = Redis::connection('default');
            $latch = "automation:latch:{$automation->id}";
            if ($redis->exists($latch)) return;
            $cooldown = max(0, (int)($config['cooldown_sec'] ?? 0));
            $last = (int)$redis->get("automation:last:{$automation->id}");
            if ($last && time() - $last < $cooldown) return;
            // 持续满足只触发一次；条件恢复后才解除，进程重启仍保留状态。
            if (!$redis->setnx($latch, '1')) return;
            if (self::executeActions($automation, ['device_id'=>$deviceId, 'metric_key'=>$metricKey, 'observed_value'=>$value])) {
                $redis->set("automation:last:{$automation->id}", time());
            } else $redis->del($latch);
        }
    }

    /**
     * 通知告警引擎重新加载遥测型自动化规则
     */
    private static function notifyRulesChanged(): void
    {
        try {
            Redis::connection('default')->setex(self::RULES_CHANGED_KEY, 600, bin2hex(random_bytes(8)));
        } catch (\Throwable $e) {
            Log::error("通知引擎刷新自动化规则失败: {$e->getMessage()}");
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
            return true;
        }

        return false;
    }

    /**
     * 清除指定自动化规则的防抖计时
     *
     * @param int $automationId 自动化规则 ID
     */
    private static function clearDuration(int $automationId): void
    {
        try {
            Redis::connection('default')->del("automation:duration:{$automationId}");
        } catch (\Throwable $e) {
            // 忽略，下次条件满足时会重新计时
        }
    }
}
