<?php
/**
 * Home Guardian - 定时自动化进程
 *
 * 每 60 秒扫描一次所有启用的 schedule 类型自动化规则，
 * 使用 cron-expression 判断是否到期，到期则执行对应动作。
 *
 * 使用 Redis 记录上次执行的分钟数，防止同一分钟内重复触发。
 */

namespace app\process;

use app\model\Automation;
use app\service\AutomationService;
use Cron\CronExpression;
use Workerman\Timer;
use support\Log;

class CrontabProcess
{
    /**
     * Redis 连接（用于防重复触发）
     */
    private ?\Redis $redis = null;

    /**
     * Worker 进程启动回调
     */
    public function onWorkerStart(): void
    {
        $this->initRedis();

        // 每 60 秒扫描一次定时自动化规则
        Timer::add(60, [$this, 'processScheduledAutomations']);

        Log::info('CrontabProcess 定时自动化进程已启动');
    }

    /**
     * 初始化 Redis 连接
     */
    private function initRedis(): void
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $password = getenv('REDIS_PASSWORD') ?: '';

        $this->redis = new \Redis();
        $this->redis->connect($host, $port, 3);
        if ($password) $this->redis->auth($password);
        $this->redis->select(0);
    }

    /**
     * 扫描并执行到期的定时自动化规则
     */
    public function processScheduledAutomations(): void
    {
        try {
            $automations = Automation::enabled()->ofTriggerType('schedule')->get();

            foreach ($automations as $automation) {
                try {
                    $config = $automation->trigger_config;
                    $cronExpr = $config['cron'] ?? null;

                    if (!$cronExpr) {
                        continue;
                    }

                    $cron = new CronExpression($cronExpr);

                    if (!$cron->isDue()) {
                        continue;
                    }

                    // 防止同一分钟内重复触发
                    $currentMinute = date('YmdHi');
                    $lastRunKey = "hg:crontab:last_run:{$automation->id}";
                    $lastRun = $this->redis->get($lastRunKey);

                    if ($lastRun === $currentMinute) {
                        continue;
                    }

                    // 标记本分钟已执行（TTL 120 秒足够覆盖检查间隔）
                    $this->redis->setEx($lastRunKey, 120, $currentMinute);

                    // 执行自动化动作
                    AutomationService::executeActions($automation);

                    // 更新最后触发时间
                    $automation->last_triggered_at = now();
                    $automation->save();

                    Log::info("定时自动化 [{$automation->id}] {$automation->name} 已触发");
                } catch (\Throwable $e) {
                    Log::error("定时自动化 [{$automation->id}] 执行失败: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            Log::error("CrontabProcess 扫描异常: {$e->getMessage()}");

            if (str_contains($e->getMessage(), 'Redis') || str_contains($e->getMessage(), 'Connection')) {
                $this->initRedis();
            }
        }
    }

    /**
     * 进程停止回调
     */
    public function onWorkerStop(): void
    {
        if ($this->redis) $this->redis->close();
        Log::info('CrontabProcess 定时自动化进程已停止');
    }
}
