<?php
/**
 * Home Guardian - MQTT 订阅进程
 *
 * 作为 EMQX 的 MQTT 客户端，以超级用户身份连接，订阅所有设备的上行主题。
 * 收到的消息分流处理：
 *
 *   1. 遥测数据 (home/upstream/{uid}/telemetry/post)
 *      → 推入 Redis data_ingest_queue 队列（由 DataIngestProcess 批量写入 PgSQL）
 *      → 推入 Redis alert_stream 队列（由 AlertEngineProcess 实时匹配规则）
 *      → 缓存最新值到 Redis（供 API 快速查询）
 *      → 通过 Redis Pub/Sub 推送到 WebSocket
 *
 *   2. 设备状态 (home/upstream/{uid}/state/post)
 *      → 更新 devices 表的 is_online / last_seen
 *      → 通过 Redis Pub/Sub 推送到 WebSocket
 *
 *   3. 指令回复 (home/upstream/{uid}/command/reply)
 *      → 更新 command_logs 表状态
 *      → 通过 Redis Pub/Sub 推送到 WebSocket
 *
 *   4. 从 Redis 队列读取待发送的指令
 *      → 通过 MQTT 发布到设备的下行主题
 */

namespace app\process;

use app\service\DeviceService;
use app\service\MqttCommandService;
use app\model\Device;
use Workerman\Mqtt\Client as MqttClient;
use Workerman\Timer;
use support\Log;

class MqttSubscriber
{
    /**
     * MQTT 客户端实例
     */
    private ?MqttClient $mqttClient = null;

    /**
     * Redis 连接（用于队列操作）
     */
    private ?\Redis $redis = null;

    /**
     * Redis 连接（用于 Pub/Sub 发布）
     */
    private ?\Redis $redisPub = null;

    /**
     * Worker 进程启动回调
     */
    public function onWorkerStart(): void
    {
        // 初始化 Redis 连接
        $this->initRedis();

        // 连接 MQTT Broker
        $this->connectMqtt();

        // 定时从 Redis 队列读取待发送的指令（每 100ms 检查一次）
        Timer::add(0.1, [$this, 'processCommandQueue']);

        Log::info('MqttSubscriber 进程已启动');
    }

    /**
     * 初始化 Redis 连接
     */
    private function initRedis(): void
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $password = getenv('REDIS_PASSWORD') ?: '';

        // 队列操作连接（DB1）
        $this->redis = new \Redis();
        $this->redis->connect($host, $port, 3);
        if ($password) {
            $this->redis->auth($password);
        }
        $this->redis->select(1);

        // Pub/Sub 发布连接（DB2）
        $this->redisPub = new \Redis();
        $this->redisPub->connect($host, $port, 3);
        if ($password) {
            $this->redisPub->auth($password);
        }
        $this->redisPub->select(2);
    }

    /**
     * 连接 MQTT Broker
     */
    private function connectMqtt(): void
    {
        $host = getenv('MQTT_HOST') ?: 'emqx';
        $port = getenv('MQTT_PORT') ?: 1883;
        $username = getenv('MQTT_SUPER_USERNAME') ?: 'hg_internal_client';
        $password = getenv('MQTT_SUPER_PASSWORD') ?: '';

        $this->mqttClient = new MqttClient(
            "mqtt://{$host}:{$port}",
            [
                'username'   => $username,
                'password'   => $password,
                'client_id'  => 'hg_server_' . getmypid(),
                'keepalive'  => 60,
                'clean_session' => true,
                'reconnect_period' => 5,  // 断开后 5 秒自动重连
            ]
        );

        // 连接成功回调
        $this->mqttClient->onConnect = function () {
            Log::info('MQTT 已连接到 Broker');

            // 订阅所有设备的上行主题
            $this->mqttClient->subscribe('home/upstream/#', ['qos' => 1]);
        };

        // 收到消息回调
        $this->mqttClient->onMessage = function ($topic, $payload) {
            try {
                $this->handleMessage($topic, $payload);
            } catch (\Throwable $e) {
                Log::error("MQTT 消息处理异常: {$e->getMessage()}", [
                    'topic'   => $topic,
                    'payload' => mb_substr($payload, 0, 500),
                ]);
            }
        };

        // 连接错误回调
        $this->mqttClient->onError = function (\Exception $e) {
            Log::error("MQTT 连接错误: {$e->getMessage()}");
        };

        // 连接关闭回调
        $this->mqttClient->onClose = function () {
            Log::warning('MQTT 连接已关闭，将自动重连');
        };

        $this->mqttClient->connect();
    }

    /**
     * 处理收到的 MQTT 消息
     *
     * 根据主题路径分发到对应的处理方法。
     *
     * @param string $topic   消息主题
     * @param string $payload 消息内容（JSON）
     */
    private function handleMessage(string $topic, string $payload): void
    {
        // 解析主题: home/upstream/{device_uid}/{module}/{action}
        $parts = explode('/', $topic);
        if (count($parts) < 5 || $parts[0] !== 'home' || $parts[1] !== 'upstream') {
            return;
        }

        $deviceUid = $parts[2];
        $module = $parts[3];
        $action = $parts[4];

        $data = json_decode($payload, true);
        if (!$data) {
            Log::warning("MQTT 消息 JSON 解析失败: {$topic}");
            return;
        }

        // 根据模块分发处理
        match ($module) {
            'telemetry' => $this->handleTelemetry($deviceUid, $data),
            'state'     => $this->handleState($deviceUid, $data),
            'command'   => $this->handleCommandReply($deviceUid, $data),
            default     => Log::debug("未识别的 MQTT 模块: {$module}"),
        };
    }

    /**
     * 处理遥测数据
     *
     * @param string $deviceUid 设备 UID
     * @param array  $data      遥测数据，如 {"temperature": 25.6, "humidity": 60}
     */
    private function handleTelemetry(string $deviceUid, array $data): void
    {
        // 查找设备 ID（使用内存缓存避免频繁查库）
        static $deviceCache = [];
        if (!isset($deviceCache[$deviceUid])) {
            $device = Device::where('device_uid', $deviceUid)->first();
            if (!$device) {
                Log::warning("收到未注册设备的遥测数据: {$deviceUid}");
                return;
            }
            $deviceCache[$deviceUid] = [
                'id'       => $device->id,
                'location' => $device->location,
            ];
        }

        $deviceInfo = $deviceCache[$deviceUid];
        $deviceId = $deviceInfo['id'];
        $now = date('Y-m-d H:i:s.u');

        // 遍历每个遥测指标
        foreach ($data as $metricKey => $value) {
            // 跳过非遥测数据的字段（如 timestamp）
            if ($metricKey === 'timestamp' || $metricKey === 'request_id') {
                continue;
            }

            // 1. 推入数据写入队列（DataIngestProcess 批量入库）
            $ingestItem = json_encode([
                'ts'         => $now,
                'device_id'  => $deviceId,
                'metric_key' => $metricKey,
                'value'      => is_array($value) ? json_encode($value) : json_encode($value),
            ]);
            $this->redis->lPush('hg:q:data_ingest_queue', $ingestItem);

            // 2. 推入告警检测队列（AlertEngineProcess 实时匹配）
            $alertItem = json_encode([
                'device_id'  => $deviceId,
                'metric_key' => $metricKey,
                'value'      => $value,
                'ts'         => $now,
            ]);
            $this->redis->lPush('hg:q:alert_stream', $alertItem);
        }

        // 3. 缓存最新值到 Redis（供 API 快速查询）
        $cacheKey = "hg:device:latest:{$deviceId}";
        $cacheRedis = new \Redis();
        $cacheRedis->connect(getenv('REDIS_HOST') ?: 'redis', (int)(getenv('REDIS_PORT') ?: 6379), 1);
        $password = getenv('REDIS_PASSWORD') ?: '';
        if ($password) $cacheRedis->auth($password);
        $cacheRedis->select(0);
        $cacheRedis->setEx($cacheKey, 3600, json_encode($data));
        $cacheRedis->close();

        // 4. 推送到 WebSocket（实时仪表盘更新）
        $wsMessage = json_encode([
            'type'            => 'telemetry',
            'device_id'       => $deviceId,
            'device_uid'      => $deviceUid,
            'device_location' => $deviceInfo['location'],
            'data'            => $data,
            'ts'              => $now,
        ], JSON_UNESCAPED_UNICODE);

        $this->redisPub->publish('ws:broadcast', $wsMessage);

        // 更新设备在线状态
        DeviceService::updateOnlineStatus($deviceUid, true);
    }

    /**
     * 处理设备状态上报
     *
     * @param string $deviceUid 设备 UID
     * @param array  $data      状态数据，如 {"status": "online"} 或 LWT {"status": "offline"}
     */
    private function handleState(string $deviceUid, array $data): void
    {
        $status = $data['status'] ?? 'online';
        $isOnline = ($status !== 'offline');

        DeviceService::updateOnlineStatus($deviceUid, $isOnline);

        // 推送到 WebSocket
        $device = Device::where('device_uid', $deviceUid)->first();
        if ($device) {
            $wsMessage = json_encode([
                'type'            => 'device_status',
                'device_id'       => $device->id,
                'device_uid'      => $deviceUid,
                'device_location' => $device->location,
                'is_online'       => $isOnline,
            ], JSON_UNESCAPED_UNICODE);

            $this->redisPub->publish('ws:broadcast', $wsMessage);
        }
    }

    /**
     * 处理指令回复
     *
     * @param string $deviceUid 设备 UID
     * @param array  $data      回复数据，如 {"request_id": "cmd_xxx", "status": "ok"}
     */
    private function handleCommandReply(string $deviceUid, array $data): void
    {
        $requestId = $data['request_id'] ?? '';
        if (empty($requestId)) {
            return;
        }

        $status = ($data['status'] ?? '') === 'ok'
            ? 'replied_ok'
            : 'replied_error';

        MqttCommandService::handleCommandReply($requestId, $status, $data);
    }

    /**
     * 从 Redis 队列读取待发送的指令并通过 MQTT 发布
     *
     * 由 Timer 定时调用（每 100ms）。
     */
    public function processCommandQueue(): void
    {
        try {
            // 每次最多处理 10 条指令
            for ($i = 0; $i < 10; $i++) {
                $message = $this->redis->rPop('hg:q:mqtt:command:send');
                if (!$message) {
                    break;
                }

                $command = json_decode($message, true);
                if (!$command || empty($command['topic'])) {
                    continue;
                }

                // 通过 MQTT 发布指令
                $this->mqttClient->publish(
                    $command['topic'],
                    $command['payload'] ?? '',
                    ['qos' => $command['qos'] ?? 1]
                );
            }
        } catch (\Throwable $e) {
            Log::error("指令发送失败: {$e->getMessage()}");
        }
    }

    /**
     * Worker 进程停止回调
     */
    public function onWorkerStop(): void
    {
        if ($this->mqttClient) {
            $this->mqttClient->close();
        }
        if ($this->redis) {
            $this->redis->close();
        }
        if ($this->redisPub) {
            $this->redisPub->close();
        }
        Log::info('MqttSubscriber 进程已停止');
    }
}
