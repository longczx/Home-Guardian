<?php
/**
 * Home Guardian - 通知发送进程
 *
 * 从 Redis notify:queue 队列消费通知任务，调用 NotificationService 发送。
 *
 * 之所以独立成进程：通知发送涉及同步的 HTTP 请求（curl，最长十几秒）和
 * 邮件发送，若放在告警引擎进程的事件循环里，会阻塞 alert_stream 的消费，
 * 导致一个慢渠道拖垮整个告警匹配。独立进程把这类慢 IO 与告警引擎解耦。
 */

namespace app\process;

use app\service\NotificationService;
use Workerman\Timer;
use support\Log;

class NotificationProcess
{
    /**
     * Redis 连接（DB1: 队列）
     */
    private ?\Redis $redis = null;

    /**
     * 通知队列键名（AlertService / AutomationService 通过 queue 连接 lPush，
     * 带 hg:q: 前缀，实际键名为 hg:q:notify:queue）
     */
    private const QUEUE_KEY = 'hg:q:notify:queue';

    /**
     * Worker 进程启动回调
     */
    public function onWorkerStart(): void
    {
        $this->initRedis();

        // 每 500ms 消费一次通知队列
        Timer::add(0.5, [$this, 'processQueue']);

        Log::info('NotificationProcess 通知发送进程已启动');
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
        if ($password) {
            $this->redis->auth($password);
        }
        $this->redis->select(1); // DB1: 队列
    }

    /**
     * 消费通知队列并发送
     *
     * 每次最多处理 20 条，单条失败不影响其它任务。
     */
    public function processQueue(): void
    {
        try {
            for ($i = 0; $i < 20; $i++) {
                $raw = $this->redis->rPop(self::QUEUE_KEY);
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

            // Redis 连接断开时重连
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
        if ($this->redis) {
            $this->redis->close();
        }
        Log::info('NotificationProcess 通知发送进程已停止');
    }
}
