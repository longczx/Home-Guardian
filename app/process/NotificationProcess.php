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
            if (!$this->redis) $this->initRedis();
            $queue = new \app\service\ReliableQueue($this->redis, self::QUEUE_KEY, self::QUEUE_KEY . ':processing');
            foreach ($queue->reserve(20) as $raw) {
                $task = json_decode($raw, true);
                if (!is_array($task) || !is_array($task['channel_ids'] ?? null)
                    || !is_string($task['title'] ?? '') || !is_string($task['content'] ?? '')
                    || !is_array($task['extra'] ?? [])
                    || count(array_filter($task['channel_ids'], fn ($id) => !is_int($id) && !(is_string($id) && ctype_digit($id)))) > 0) {
                    $queue->move($raw, self::QUEUE_KEY . ':deadletter');
                    continue;
                }
                foreach (array_unique($task['channel_ids']) as $channelId) {
                    $channel = \app\model\NotificationChannel::withoutGlobalScopes()->find((int)$channelId);
                    if (!$channel || (isset($task['extra']['home_id']) && (int)$task['extra']['home_id'] !== (int)$channel->home_id)) continue;
                    \app\model\NotificationDelivery::withoutGlobalScopes()->firstOrCreate([
                        'delivery_key' => hash('sha256', ($task['task_id'] ?? $raw) . ':' . $channelId),
                    ], [
                        'home_id' => $channel->home_id, 'channel_id' => $channelId,
                        'title' => mb_substr($task['title'] ?? '通知', 0, 255), 'content' => $task['content'] ?? '',
                        'extra' => $task['extra'] ?? [], 'next_attempt_at' => now(),
                    ]);
                }
                $queue->acknowledge($raw);
            }
            $this->deliverPending();
        } catch (\Throwable $e) {
            Log::error('通知队列异常，任务保留待重试: ' . $e->getMessage());
            if ($e instanceof \RedisException) $this->redis = null;
        }
    }

    public function deliverPending(): void
    {
        $deliveries = \app\model\NotificationDelivery::withoutGlobalScopes()
            ->where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(20)->get();
        foreach ($deliveries as $delivery) {
            $results = NotificationService::send([$delivery->channel_id], $delivery->title, $delivery->content, $delivery->extra ?? []);
            $result = $results[$delivery->channel_id] ?? ['status' => 'skipped', 'error' => null];
            $attempts = $delivery->attempts + 1;
            $failed = $result['status'] === 'failed';
            $delivery->update([
                'status' => $failed && $attempts < 5 ? 'pending' : $result['status'],
                'attempts' => $attempts, 'last_error' => $result['error'],
                'next_attempt_at' => $failed && $attempts < 5 ? now()->addSeconds(min(300, 2 ** $attempts)) : null,
                'sent_at' => $result['status'] === 'sent' ? now() : null,
            ]);
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
