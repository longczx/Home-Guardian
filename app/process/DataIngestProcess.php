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
     * Worker 进程启动回调
     */
    public function onWorkerStart(): void
    {
        // 初始化 Redis 连接（使用 DB1: 队列专用）
        $this->initRedis();

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
        try {
            $items = [];

            // 从队列中批量弹出数据
            for ($i = 0; $i < self::BATCH_SIZE; $i++) {
                $raw = $this->redis->rPop(self::QUEUE_KEY);
                if (!$raw) {
                    break;
                }

                $item = json_decode($raw, true);
                if (!$item) {
                    continue;
                }

                $items[] = [
                    'ts'         => $item['ts'],
                    'device_id'  => (int)$item['device_id'],
                    'metric_key' => $item['metric_key'],
                    'value'      => $item['value'],  // 已经是 JSON 字符串
                ];
            }

            // 没有数据则跳过
            if (empty($items)) {
                return;
            }

            // 批量插入 telemetry_logs 表
            $this->bulkInsert($items);

        } catch (\Throwable $e) {
            Log::error("数据入库失败: {$e->getMessage()}", [
                'items_count' => count($items ?? []),
            ]);

            // 如果是 Redis 连接断开，尝试重连
            if (str_contains($e->getMessage(), 'Redis') || str_contains($e->getMessage(), 'Connection')) {
                $this->initRedis();
            }
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
