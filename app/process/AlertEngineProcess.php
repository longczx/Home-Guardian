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
 *   - 使用 Redis 实现防抖（trigger_duration_sec）
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
use app\service\NotificationService;
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
     * Worker 进程启动回调
     */
    public function onWorkerStart(): void
    {
        $this->initRedis();

        // 加载告警规则到内存
        $this->loadRules();

        // 监听规则变更通知（通过 Timer 轮询 Redis 频道）
        $this->watchRuleChanges();

        // 定时消费 alert_stream 队列（每 100ms）
        Timer::add(0.1, [$this, 'processAlertStream']);

        // 定时消费通知队列（每 500ms）
        Timer::add(0.5, [$this, 'processNotifyQueue']);

        // 每小时清理过期的 refresh_token
        Timer::add(3600, [$this, 'cleanExpiredTokens']);

        Log::info('AlertEngineProcess 告警引擎进程已启动，已加载 ' . count($this->rules) . ' 条规则');
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
                $changed = $this->redisCache->get('hg:alert:rules:changed');
                if ($changed) {
                    $this->redisCache->del('hg:alert:rules:changed');
                    $this->loadRules();
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
                        // 防抖检查
                        if ($this->checkDebounce($rule)) {
                            AlertService::triggerAlert($rule, $deviceId, $numericValue);
                        }
                    } else {
                        // 条件不满足，清除防抖计时
                        $this->clearDebounce($rule);
                    }
                }

                // 同时检查遥测条件型自动化规则
                AutomationService::checkTelemetryTrigger($deviceId, $metricKey, $value);
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
     * 清除防抖计时
     *
     * 当条件不再满足时，重置防抖状态。
     *
     * @param AlertRule $rule 告警规则
     */
    private function clearDebounce(AlertRule $rule): void
    {
        $key = "hg:alert:debounce:{$rule->id}";
        $this->redisCache->del($key);
    }

    /**
     * 消费通知队列
     *
     * 读取 notify:queue 中的通知任务并调用 NotificationService 发送。
     */
    public function processNotifyQueue(): void
    {
        try {
            for ($i = 0; $i < 10; $i++) {
                $raw = $this->redis->rPop('hg:q:notify:queue');
                if (!$raw) {
                    break;
                }

                $task = json_decode($raw, true);
                if (!$task) {
                    continue;
                }

                NotificationService::send(
                    $task['channel_ids'] ?? [],
                    $task['title'] ?? '通知',
                    $task['content'] ?? '',
                    $task['extra'] ?? []
                );
            }
        } catch (\Throwable $e) {
            Log::error("通知发送异常: {$e->getMessage()}");
        }
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
