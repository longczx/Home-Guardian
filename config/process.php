<?php
/**
 * Home Guardian - 进程配置
 *
 * 定义 Webman 管理的所有 Worker 进程。
 * 除了默认的 HTTP Worker 和文件监控，还包含：
 *   - WebSocket 实时推送服务（端口 8788）
 *   - MQTT 订阅进程（连接 EMQX）
 *   - 数据入库进程（批量写入 PostgreSQL）
 *   - 告警引擎进程（实时规则匹配）
 */

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP Worker（主 Web 服务）
    |--------------------------------------------------------------------------
    | 监听 8787 端口，处理所有 REST API 请求。
    | Nginx 反向代理将 / 路径的请求转发到此端口。
    */
    'webman' => [
        'handler'  => Http::class,
        'listen'   => 'http://0.0.0.0:8787',
        'count'    => cpu_count() * 4,  // Worker 进程数，根据 CPU 核心数自动调整
        'user'     => '',
        'group'    => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context'  => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger'       => Log::channel('default'),
            'appPath'      => app_path(),
            'publicPath'   => public_path(),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket Worker（实时推送服务）
    |--------------------------------------------------------------------------
    | 监听 8788 端口，处理 WebSocket 连接。
    | Nginx 将 /ws 路径的请求转发到此端口。
    |
    | 单进程运行：WebSocket 需要维护连接池，多进程会导致连接分散。
    | 通过 Redis Pub/Sub 从其他进程接收需要推送的数据。
    */
    'websocket' => [
        'handler'  => app\process\WebSocketServer::class,
        'listen'   => 'websocket://0.0.0.0:8788',
        'count'    => 1,  // 单进程，连接池集中管理
        'context'  => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | MQTT 订阅进程
    |--------------------------------------------------------------------------
    | 连接 EMQX Broker，订阅所有设备的上行主题。
    | 收到消息后分流到数据入库队列、告警检测队列和 WebSocket 推送。
    |
    | 单进程运行：MQTT 连接是有状态的，多进程会产生重复消费。
    */
    'mqtt_subscriber' => [
        'handler'  => app\process\MqttSubscriber::class,
        'count'    => 1,  // 单进程，避免重复消费
        'listen'   => '',  // 不监听端口
    ],

    /*
    |--------------------------------------------------------------------------
    | 数据入库进程
    |--------------------------------------------------------------------------
    | 从 Redis data_ingest_queue 队列批量取出遥测数据，
    | 使用 Bulk Insert 高效写入 PostgreSQL telemetry_logs 超表。
    |
    | 单进程即可，批量写入效率远高于逐条插入。
    | 如果数据量特别大，可增加为 2 个进程。
    */
    'data_ingest' => [
        'handler'  => app\process\DataIngestProcess::class,
        'count'    => 1,
        'listen'   => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | 告警引擎进程
    |--------------------------------------------------------------------------
    | 从 Redis alert_stream 队列消费遥测数据，
    | 在内存中与告警规则和自动化规则进行实时匹配。
    |
    | 同时负责：通知队列消费、过期 Token 清理。
    */
    'alert_engine' => [
        'handler'  => app\process\AlertEngineProcess::class,
        'count'    => 1,
        'listen'   => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | 文件监控进程（开发环境热重载）
    |--------------------------------------------------------------------------
    | 监控文件变更并自动重载 Worker，仅在非守护模式的 Linux 环境下生效。
    | Windows 环境和守护进程模式下自动禁用。
    */
    'monitor' => [
        'handler'  => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            'monitorDir' => array_merge(
                [
                    app_path(),
                    config_path(),
                    base_path() . '/process',
                    base_path() . '/support',
                    base_path() . '/resource',
                    base_path() . '/.env',
                ],
                glob(base_path() . '/plugin/*/app'),
                glob(base_path() . '/plugin/*/config'),
                glob(base_path() . '/plugin/*/api')
            ),
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env',
            ],
            'options' => [
                'enable_file_monitor'   => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ],
        ],
    ],
];
