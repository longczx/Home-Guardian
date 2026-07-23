<?php
/**
 * Home Guardian - 告警引擎进程
 *
 * 从 Redis alert_stream 队列消费遥测数据，与内存中的告警规则进行实时匹配。
 * 匹配成功后触发告警（写日志 + 发通知 + 推 WebSocket）。
 *
 * 同时负责遥测条件类型的自动化规则匹配。
 *
 * 性能特点：
 *   - 告警规则在内存中缓存，变更时通过 Redis Pub/Sub 通知刷新
 *   - 匹配过程纯内存计算，不查库
 *   - 使用 Redis 实现双向迟滞防抖（trigger_duration_sec）：触发要持续满足 N 秒，
 *     恢复也要持续正常 N 秒，抑制阈值边缘抖动引发的告警/恢复通知风暴
 *
 * 还额外处理：
 *   - 通知队列消费（notify:queue → NotificationService）
 *   - 定期清理过期的 refresh_token
 */

namespace app\process;

use app\model\AlertRule;
use app\model\RefreshToken;
use app\service\AlertService;
use app\service\AutomationService;
use Workerman\Timer;
use support\Log;

class AlertEngineProcess
{
    /**
     * Redis 连接（队列操作）
     */
    private ?\Redis $redis = null;

    /**
     * Redis 连接（缓存操作，DB0）
     */
    private ?\Redis $redisCache = null;

    /**
     * 内存中的告警规则列表
     *
     * @var AlertRule[]
     */
    private array $rules = [];

    /**
     * 内存中的遥测型自动化规则列表（避免每条遥测都查库）
     *
     * @var \app\model\Automation[]
     */
    private array $automations = [];

    /**
     * Worker 进程启动回调
     */
    public function onWorkerStart(): void
    {
        $this->initRedis();

        // 加载告警规则和自动化规则到内存
        $this->loadRules();
        $this->loadAutomations();

        // 监听规则变更通知（通过 Timer 轮询 Redis 标记键）
        $this->watchRuleChanges();

        // 定时消费 alert_stream 队列（每 100ms）
        Timer::add(0.1, [$this, 'processAlertStream']);

        // 每小时清理过期的 refresh_token
        Timer::add(3600, [$this, 'cleanExpiredTokens']);

        Log::info('AlertEngineProcess 告警引擎进程已启动，已加载 ' . count($this->rules)
            . ' 条告警规则、' . count($this->automations) . ' 条遥测自动化规则');
    }

    /**
     * 初始化 Redis 连接
     */
    private function initRedis(): void
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $password = getenv('REDIS_PASSWORD') ?: '';

        // 队列连接（DB1）
        $this->redis = new \Redis();
        $this->redis->connect($host, $port, 3);
        if ($password) $this->redis->auth($password);
        $this->redis->select(1);

        // 缓存连接（DB0 — 用于防抖计时）
        $this->redisCache = new \Redis();
        $this->redisCache->connect($host, $port, 3);
        if ($password) $this->redisCache->auth($password);
        $this->redisCache->select(0);
    }

    /**
     * 从数据库加载所有启用的告警规则到内存
     */
    private function loadRules(): void
    {
        try {
            $this->rules = AlertService::getEnabledRules()->all();
            Log::info('告警规则已刷新，当前规则数: ' . count($this->rules));
        } catch (\Throwable $e) {
            Log::error("加载告警规则失败: {$e->getMessage()}");
        }
    }

    /**
     * 从数据库加载所有启用的遥测型自动化规则到内存
     */
    private function loadAutomations(): void
    {
        try {
            $this->automations = AutomationService::getEnabledTelemetryAutomations()->all();
            Log::info('自动化规则已刷新，当前规则数: ' . count($this->automations));
        } catch (\Throwable $e) {
            Log::error("加载自动化规则失败: {$e->getMessage()}");
        }
    }

    /**
     * 监听告警规则变更通知
     *
     * 当规则被创建/修改/删除时，AlertService 会发布消息到 Redis，
     * 本进程收到后重新加载规则。
     */
    private function watchRuleChanges(): void
    {
        // 使用轮询方式检查变更标记（比 Pub/Sub 更简单可靠）
        Timer::add(5, function () {
            try {
                if ($this->redisCache->get('hg:alert:rules:changed')) {
                    $this->redisCache->del('hg:alert:rules:changed');
                    $this->loadRules();
                }
                if ($this->redisCache->get('hg:automation:rules:changed')) {
                    $this->redisCache->del('hg:automation:rules:changed');
                    $this->loadAutomations();
                }
            } catch (\Throwable $e) {
                // 忽略，下次重试
            }
        });
    }

    /**
     * 消费 alert_stream 队列并匹配告警规则
     *
     * 每次最多处理 100 条数据，避免单次处理过久。
     */
    public function processAlertStream(): void
    {
        try {
            for ($i = 0; $i < 100; $i++) {
                $raw = $this->redis->rPop('hg:q:alert_stream');
                if (!$raw) {
                    break;
                }

                $data = json_decode($raw, true);
                if (!$data) {
                    continue;
                }

                $deviceId = $data['device_id'] ?? 0;
                $metricKey = $data['metric_key'] ?? '';
                $value = $data['value'] ?? null;

                if (!$deviceId || !$metricKey || $value === null) {
                    continue;
                }

                // 遍历所有规则进行匹配
                foreach ($this->rules as $rule) {
                    // 快速跳过不相关的规则
                    if ($rule->device_id != $deviceId || $rule->telemetry_key !== $metricKey) {
                        continue;
                    }

                    // 提取数值（如果 value 是 JSON 数组格式，取第一个元素）
                    $numericValue = is_array($value) ? ($value[0] ?? $value) : $value;

                    // 评估条件
                    if ($rule->evaluate($numericValue)) {
                        // 条件满足：清"恢复计时"（防止半途抖回被误判恢复），走触发防抖
                        $this->clearResolveTimer($rule);
                        // 防抖通过则触发（AlertService 内部按"激活标记"去重，持续满足不会重复告警）
                        if ($this->checkDebounce($rule)) {
                            AlertService::triggerAlert($rule, $deviceId, $numericValue);
                        }
                    } else {
                        // 条件不再满足：清触发防抖；恢复方向同样需持续满足 trigger_duration_sec 秒
                        // 才真正 resolve —— 双向迟滞，抑制阈值边缘抖动导致的"日志/恢复通知风暴"
                        $this->clearDebounce($rule);
                        if ($this->checkResolveDebounce($rule)) {
                            AlertService::resolveActive($rule, $deviceId, $numericValue);
                        }
                    }
                }

                // 同时检查遥测条件型自动化规则（使用内存缓存，不查库）
                AutomationService::evaluateTelemetry($this->automations, $deviceId, $metricKey, $value);
            }
        } catch (\Throwable $e) {
            Log::error("告警引擎处理异常: {$e->getMessage()}");

            // Redis 连接断开时重连
            if (str_contains($e->getMessage(), 'Redis') || str_contains($e->getMessage(), 'Connection')) {
                $this->initRedis();
            }
        }
    }

    /**
     * 防抖检查
     *
     * 规则需要条件持续满足 trigger_duration_sec 秒才触发告警。
     * 使用 Redis 记录首次满足时间，持续满足指定时间后返回 true。
     *
     * @param  AlertRule $rule 告警规则
     * @return bool 是否应该触发告警
     */
    private function checkDebounce(AlertRule $rule): bool
    {
        $durationSec = $rule->trigger_duration_sec ?? 0;

        // 无防抖要求，直接触发
        if ($durationSec <= 0) {
            return true;
        }

        $key = "hg:alert:debounce:{$rule->id}";
        $firstHit = $this->redisCache->get($key);

        if (!$firstHit) {
            // 首次满足条件，记录时间
            $this->redisCache->setEx($key, $durationSec + 60, time());
            return false;
        }

        // 检查是否持续够久
        if (time() - (int)$firstHit >= $durationSec) {
            $this->redisCache->del($key);
            return true;
        }

        return false;
    }

    /**
     * 清除触发防抖计时
     *
     * 当条件不再满足时，重置触发防抖状态。
     *
     * @param AlertRule $rule 告警规则
     */
    private function clearDebounce(AlertRule $rule): void
    {
        $key = "hg:alert:debounce:{$rule->id}";
        $this->redisCache->del($key);
    }

    /**
     * 恢复防抖检查（触发防抖的镜像）
     *
     * 条件跌回正常后，需持续 trigger_duration_sec 秒才真正判定为恢复。
     * 这段时间内若再次越阈值，会 clearResolveTimer 取消恢复，告警保持激活——
     * 从而在阈值边缘抖动时不反复 resolve/re-trigger，避免"日志风暴 + 恢复通知风暴"。
     *
     * trigger_duration_sec<=0（立即触发）时也立即恢复，保持向后兼容。
     *
     * @param  AlertRule $rule 告警规则
     * @return bool 是否应判定为恢复
     */
    private function checkResolveDebounce(AlertRule $rule): bool
    {
        $durationSec = $rule->trigger_duration_sec ?? 0;

        // 无防抖要求，立即恢复（原行为）
        if ($durationSec <= 0) {
            return true;
        }

        $key = "hg:alert:resolve:{$rule->id}";
        $firstClear = $this->redisCache->get($key);

        if (!$firstClear) {
            // 首次跌回正常，记录时间；尚不判定恢复
            $this->redisCache->setEx($key, $durationSec + 60, time());
            return false;
        }

        // 持续正常够久 → 判定恢复并清计时
        if (time() - (int)$firstClear >= $durationSec) {
            $this->redisCache->del($key);
            return true;
        }

        return false;
    }

    /**
     * 清除恢复防抖计时
     *
     * 条件再次满足时调用，取消进行中的"恢复判定"，使告警保持激活。
     *
     * @param AlertRule $rule 告警规则
     */
    private function clearResolveTimer(AlertRule $rule): void
    {
        $key = "hg:alert:resolve:{$rule->id}";
        $this->redisCache->del($key);
    }

    /**
     * 清理过期的 refresh_token
     *
     * 定时任务，每小时执行一次。
     */
    public function cleanExpiredTokens(): void
    {
        try {
            $deleted = RefreshToken::expired()->delete();
            if ($deleted > 0) {
                Log::info("已清理 {$deleted} 条过期的 refresh_token");
            }
        } catch (\Throwable $e) {
            Log::error("清理过期 token 失败: {$e->getMessage()}");
        }
    }

    /**
     * 进程停止回调
     */
    public function onWorkerStop(): void
    {
        if ($this->redis) $this->redis->close();
        if ($this->redisCache) $this->redisCache->close();
        Log::info('AlertEngineProcess 告警引擎进程已停止');
    }
}
