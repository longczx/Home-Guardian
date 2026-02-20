<?php
/**
 * Home Guardian - Redis 配置
 *
 * Redis 在本项目中承担三重角色：
 *   1. 缓存层 — 设备最新状态、JWT 黑名单等热数据
 *   2. 消息队列 — data_ingest_queue、alert_stream 等异步任务
 *   3. Pub/Sub — 桥接 MQTT 数据到 WebSocket Worker
 *
 * 使用不同的 database 编号隔离不同用途的数据，避免 FLUSHDB 误伤。
 *
 * @see https://www.workerman.net/doc/webman/db/redis.html
 */

return [
    // 默认连接（通用缓存）
    'default' => [
        'host'     => 'redis://' . (getenv('REDIS_HOST') ?: 'redis'),
        'options'  => [
            'prefix' => 'hg:',           // 全局键前缀，防止与其他应用冲突
        ],
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int)(getenv('REDIS_PORT') ?: 6379),
        'database' => 0,                  // DB0: 通用缓存（设备状态、配置缓存等）
    ],

    // 消息队列专用连接
    'queue' => [
        'host'     => 'redis://' . (getenv('REDIS_HOST') ?: 'redis'),
        'options'  => [
            'prefix' => 'hg:q:',
        ],
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int)(getenv('REDIS_PORT') ?: 6379),
        'database' => 1,                  // DB1: 队列（data_ingest、alert_stream）
    ],

    // Pub/Sub 专用连接（MQTT → WebSocket 桥接）
    'pubsub' => [
        'host'     => 'redis://' . (getenv('REDIS_HOST') ?: 'redis'),
        'options'  => [],
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int)(getenv('REDIS_PORT') ?: 6379),
        'database' => 2,                  // DB2: Pub/Sub（频道隔离）
    ],
];
