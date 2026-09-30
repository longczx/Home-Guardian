<?php
/**
 * Home Guardian - 数据入库进程
 *
 * 从 Redis 队列中批量读取遥测数据，使用批量插入（Bulk Insert）
 * 高效写入 PostgreSQL 的 telemetry_logs 超表。
 *
 * 这是"削峰填谷"策略的执行者：
 *   - MQTT 进程高频推入队列（微秒级）
 *   - 本进程定时批量取出并入库（秒级）
 *   - 有效降低数据库写入压力
 *
 * 批量写入参数：
 *   - 每次最多取 500 条记录
 *   - 每 2 秒执行一次（即使不满 500 条也会写入）
 */

namespace app\process;

use Workerman\Timer;
use support\Log;

class DataIngestProcess
{
    /**
     * Redis 连接
     */
    private ?\Redis $redis = null;
    private int $retryAt = 0;
    private int $failures = 0;

    /**
     * 每次批量写入的最大记录数
     */
    private const BATCH_SIZE = 500;

    /**
     * 入库间隔（秒）
     */
    private const INTERVAL = 2;

    /**
     * 队列名称
     */
    private const QUEUE_KEY = 'hg:q:data_ingest_queue';

    /**
     * 处理中队列：批量取出的数据先暂存于此，入库成功后逐条确认。
     * 进程异常退出后，残留记录直接优先重试，依靠 event_id 避免重复入库。
     */
    private const PROCESSING_KEY = 'hg:q:data_ingest_processing';

    /**
     * 死信队列：无法入库的坏数据（如非法 jsonb）移入此处，便于排查，不阻塞正常数据。
     */
    private const DEADLETTER_KEY = 'hg:q:data_ingest_deadletter';

    /**
     * Worker 进程启动回调
     */
    public function onWorkerStart(): void
    {
        // 初始化 Redis 连接（使用 DB1: 队列专用）
        $this->initRedis();

        // 恢复上次异常退出时残留在处理中队列的数据
        // 处理中记录直接优先重试，无需清空或搬回主队列。

        // 定时批量入库
        Timer::add(self::INTERVAL, [$this, 'processBatch']);

        Log::info('DataIngestProcess 数据入库进程已启动');
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
     * 批量处理队列中的遥测数据
     *
     * 从 Redis 队列中取出最多 BATCH_SIZE 条记录，
     * 使用单条 INSERT 语句批量写入 PostgreSQL。
     */
    public function processBatch(): void
    {
        if (time() < $this->retryAt) return;
        try {
            if (!$this->redis) $this->initRedis();
            $queue = new \app\service\ReliableQueue($this->redis, self::QUEUE_KEY, self::PROCESSING_KEY);
            $records = [];
            foreach ($queue->reserve(self::BATCH_SIZE) as $raw) {
                $item = json_decode($raw, true);
                if (!is_array($item) || !isset($item['ts'], $item['device_id'], $item['metric_key'], $item['value'])
                    || !is_string($item['value']) || json_decode($item['value']) === null && $item['value'] !== 'null') {
                    $queue->move($raw, self::DEADLETTER_KEY);
                    continue;
                }
                $item['event_id'] = $item['event_id'] ?? substr(hash('sha256', $raw), 0, 32);
                $records[] = ['raw' => $raw, 'item' => $item];
            }
            if (!$records) return;
            try {
                $this->bulkInsert(array_column($records, 'item'));
                foreach ($records as $record) $queue->acknowledge($record['raw']);
            } catch (\Throwable $e) {
                if (!self::isDataError($e)) throw $e;
                // 仅格式/约束错误逐条分离；连接中断保留记录并退避重试。
                foreach ($records as $record) {
                    try {
                        $this->bulkInsert([$record['item']]);
                    } catch (\Throwable $error) {
                        if (!self::isDataError($error)) throw $error;
                        $queue->move($record['raw'], self::DEADLETTER_KEY);
                        continue;
                    }
                    $queue->acknowledge($record['raw']);
                }
            }
            $this->failures = 0;
        } catch (\Throwable $e) {
            $this->retryAt = time() + min(60, 2 ** min(++$this->failures, 6));
            Log::error('遥测入库失败，处理中记录保留待重试: ' . $e->getMessage());
            if ($e instanceof \RedisException) $this->redis = null;
        }
    }

    public static function isDataError(\Throwable $error): bool
    {
        $state = $error instanceof \Illuminate\Database\QueryException
            ? ($error->errorInfo[0] ?? (string)$error->getCode()) : (string)$error->getCode();
        return str_starts_with($state, '22') || str_starts_with($state, '23');
    }

    /**
     * 批量插入遥测数据到 PostgreSQL
     *
     * 使用原生 SQL 构建多行 INSERT 语句，性能优于逐条插入数个数量级。
     * 使用参数绑定防止 SQL 注入。
     *
     * @param array $items 待插入的数据数组
     */
    private function bulkInsert(array $items): void
    {
        if (empty($items)) {
            return;
        }

        $placeholders = [];
        $bindings = [];

        foreach ($items as $index => $item) {
            $placeholders[] = "(?, ?, ?, ?::jsonb, ?)";
            $bindings[] = $item['ts'];
            $bindings[] = $item['device_id'];
            $bindings[] = $item['metric_key'];
            $bindings[] = $item['value'];
            $bindings[] = $item['event_id'];
        }

        $sql = "INSERT INTO telemetry_logs (ts, device_id, metric_key, value, event_id) VALUES "
             . implode(', ', $placeholders) . ' ON CONFLICT (ts, event_id) DO NOTHING';

        (new \app\model\Device)->getConnection()->insert($sql, $bindings);

        // 记录入库统计（调试级别，生产环境可关闭）
        Log::debug("批量入库完成: {count} 条遥测记录", [
            'count' => count($items),
        ]);
    }

    /**
     * 进程停止回调
     */
    public function onWorkerStop(): void
    {
        // 进程停止前处理完剩余队列数据
        try {
            $this->processBatch();
        } catch (\Throwable $e) {
            Log::error("进程停止时入库失败: {$e->getMessage()}");
        }

        if ($this->redis) {
            $this->redis->close();
        }

        Log::info('DataIngestProcess 数据入库进程已停止');
    }
}
