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
use support\Db;
use support\Log;

class DataIngestProcess
{
    /**
     * Redis 连接
     */
    private ?\Redis $redis = null;

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
     * 处理中队列：批量取出的数据先暂存于此，入库成功后清空。
     * 进程异常退出后，残留在此的数据会在下次启动时移回主队列，避免丢失。
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
        $this->recoverProcessing();

        // 定时批量入库
        Timer::add(self::INTERVAL, [$this, 'processBatch']);

        Log::info('DataIngestProcess 数据入库进程已启动');
    }

    /**
     * 将上次残留在处理中队列的数据移回主队列（崩溃恢复）
     */
    private function recoverProcessing(): void
    {
        try {
            $recovered = 0;
            // 有界循环，防止异常情况下死循环
            while ($recovered < 100000 && $this->redis->rpoplpush(self::PROCESSING_KEY, self::QUEUE_KEY)) {
                $recovered++;
            }
            if ($recovered > 0) {
                Log::warning("已从处理中队列恢复 {$recovered} 条待入库遥测数据");
            }
        } catch (\Throwable $e) {
            Log::error("恢复处理中队列失败: {$e->getMessage()}");
        }
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
        try {
            $items = [];

            // 用 RPOPLPUSH 把数据从主队列可靠地搬到处理中队列：
            // 即使入库阶段进程崩溃，数据仍在处理中队列里，下次启动会恢复。
            for ($i = 0; $i < self::BATCH_SIZE; $i++) {
                $raw = $this->redis->rpoplpush(self::QUEUE_KEY, self::PROCESSING_KEY);
                if ($raw === false || $raw === null) {
                    break;
                }

                $item = json_decode($raw, true);
                if (!$item || !isset($item['ts'], $item['device_id'], $item['metric_key'])) {
                    // 非法数据：移入死信队列，避免污染整批
                    $this->redis->lPush(self::DEADLETTER_KEY, $raw);
                    continue;
                }

                $items[] = [
                    'ts'         => $item['ts'],
                    'device_id'  => (int)$item['device_id'],
                    'metric_key' => $item['metric_key'],
                    'value'      => $item['value'],  // 已经是 JSON 字符串
                ];
            }

            // 没有有效数据则清空处理中队列后返回
            if (empty($items)) {
                $this->redis->del(self::PROCESSING_KEY);
                return;
            }

            // 优先批量插入；失败则降级为逐条插入，把坏记录单独丢死信，其余正常入库
            try {
                $this->bulkInsert($items);
            } catch (\Throwable $e) {
                Log::error("批量入库失败，降级为逐条入库: {$e->getMessage()}");
                $this->insertOneByOne($items);
            }

            // 提交点：本批已落库（或坏数据已进死信），清空处理中队列
            $this->redis->del(self::PROCESSING_KEY);

        } catch (\Throwable $e) {
            Log::error("数据入库失败: {$e->getMessage()}", [
                'items_count' => count($items ?? []),
            ]);

            // 如果是 Redis 连接断开，尝试重连（处理中队列的数据下次启动会恢复）
            if (str_contains($e->getMessage(), 'Redis') || str_contains($e->getMessage(), 'Connection')) {
                $this->initRedis();
            }
        }
    }

    /**
     * 逐条插入（批量插入失败时的降级路径）
     *
     * 单条失败不影响其它记录，坏记录移入死信队列。
     *
     * @param array $items 待插入的数据数组
     */
    private function insertOneByOne(array $items): void
    {
        $failed = 0;
        foreach ($items as $item) {
            try {
                $this->bulkInsert([$item]);
            } catch (\Throwable $e) {
                $failed++;
                $this->redis->lPush(self::DEADLETTER_KEY, json_encode($item));
                Log::error("单条遥测入库失败，已移入死信: {$e->getMessage()}", ['item' => $item]);
            }
        }
        if ($failed > 0) {
            Log::warning("本批逐条入库完成，{$failed} 条进入死信队列");
        }
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
            $placeholders[] = "(?, ?, ?, ?::jsonb)";
            $bindings[] = $item['ts'];
            $bindings[] = $item['device_id'];
            $bindings[] = $item['metric_key'];
            $bindings[] = $item['value'];
        }

        $sql = "INSERT INTO telemetry_logs (ts, device_id, metric_key, value) VALUES "
             . implode(', ', $placeholders);

        Db::insert($sql, $bindings);

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
