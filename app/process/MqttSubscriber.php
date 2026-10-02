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
     * 设备信息内存缓存的有效期（秒）
     *
     * 缓存避免每条遥测都查库，但必须有 TTL：否则设备改了 location / 被删除后，
     * 常驻进程会一直用旧值（影响 WS 按位置过滤、对已删除设备继续入库）。
     */
    private const DEVICE_CACHE_TTL = 300;

    /**
     * MQTT 客户端实例
     */
    private ?MqttClient $mqttClient = null;
    private bool $mqttConnected = false;
    private array $inFlight = [];

    /**
     * Redis 连接（用于队列操作）
     */
    private ?\Redis $redis = null;

    /**
     * Redis 连接（用于 Pub/Sub 发布）
     */
    private ?\Redis $redisPub = null;

    /**
     * Redis 连接（用于缓存最新遥测值，DB0）
     */
    private ?\Redis $redisCache = null;

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

        // 缓存连接（DB0，存储设备最新遥测值）
        $this->redisCache = new \Redis();
        $this->redisCache->connect($host, $port, 3);
        if ($password) {
            $this->redisCache->auth($password);
        }
        $this->redisCache->select(0);
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
            $this->mqttConnected = true;
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
            $this->mqttConnected = false;
            $this->inFlight = [];
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
            'manifest'  => $this->handleManifest($deviceUid, $data),
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
        // 查找设备 ID（使用带 TTL 的内存缓存避免频繁查库）
        static $deviceCache = [];
        $nowTs = time();
        $cached = $deviceCache[$deviceUid] ?? null;
        if ($cached === null || $cached['expire'] <= $nowTs) {
            $device = Device::where('device_uid', $deviceUid)->first();
            if (!$device) {
                Log::warning("收到未注册设备的遥测数据: {$deviceUid}");
                unset($deviceCache[$deviceUid]);
                return;
            }
            $cached = [
                'id'       => $device->id,
                'location' => $device->location,
                'expire'   => $nowTs + self::DEVICE_CACHE_TTL,
            ];
            $deviceCache[$deviceUid] = $cached;
        }

        $deviceInfo = $cached;
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
                'event_id'   => bin2hex(random_bytes(16)),
                'value'      => json_encode($value),  // 标量/数组统一编码为 JSON，入库为 jsonb
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
        try {
            $cacheKey = "hg:device:latest:{$deviceId}";
            $this->redisCache->setEx($cacheKey, 3600, json_encode($data));
            $observationKey = "hg:device:observations:{$deviceId}";
            $observations = json_decode($this->redisCache->get($observationKey) ?: '{}', true) ?: [];
            foreach ($data as $key => $value) {
                if ($key === 'timestamp' || $key === 'request_id') continue;
                $observations[$key] = ['value'=>$value, 'ts'=>date('c')];
            }
            $this->redisCache->setEx($observationKey, 86400, json_encode($observations));
        } catch (\Throwable $e) {
            Log::error("Redis 缓存写入失败: {$e->getMessage()}");
            // 尝试重连
            try {
                $host = getenv('REDIS_HOST') ?: 'redis';
                $port = (int)(getenv('REDIS_PORT') ?: 6379);
                $password = getenv('REDIS_PASSWORD') ?: '';
                $this->redisCache = new \Redis();
                $this->redisCache->connect($host, $port, 3);
                if ($password) $this->redisCache->auth($password);
                $this->redisCache->select(0);
            } catch (\Throwable $e2) {
                $this->redisCache = null;
            }
        }

        // 4. 推送到 WebSocket（实时仪表盘更新）
        try {
            $wsMessage = json_encode([
                'type'            => 'telemetry',
                'device_id'       => $deviceId,
                'device_uid'      => $deviceUid,
                'device_location' => $deviceInfo['location'],
                'data'            => $data,
                'ts'              => $now,
            ], JSON_UNESCAPED_UNICODE);

            $this->redisPub->publish('ws:broadcast', $wsMessage);
        } catch (\Throwable $e) {
            Log::error("WebSocket 推送失败: {$e->getMessage()}");
        }

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

            // 离线告警闭环：上线→解决 offline 告警；离线→触发（内部按激活标记去重，重复上报安全）
            try {
                if ($isOnline) {
                    \app\service\AlertService::resolveOfflineAlerts($device->id);
                } else {
                    \app\service\AlertService::triggerOfflineAlerts($device->id);
                }
            } catch (\Throwable $e) {
                Log::error("离线告警处理失败: {$e->getMessage()}");
            }

            // 执行器状态上报：state/post 带 state 字段时，落库并推送（闭环设备的真实状态）
            if (isset($data['state']) && is_array($data['state'])) {
                try {
                    \app\service\ActuatorService::saveState($device->id, $data['state'], true);
                    $this->redisPub->publish('ws:broadcast', json_encode([
                        'type'            => 'device_state',
                        'device_id'       => $device->id,
                        'device_uid'      => $deviceUid,
                        'device_location' => $device->location,
                        'state'           => $data['state'],
                    ], JSON_UNESCAPED_UNICODE));
                } catch (\Throwable $e) {
                    \support\Log::error("保存设备状态失败: {$e->getMessage()}");
                }
            }

            // 网关离线时，批量将其下所有传感器也标记为离线
            if (!$isOnline && $device->type === 'gateway') {
                $sensors = Device::where('gateway_uid', $deviceUid)->get(['id', 'device_uid', 'location']);
                foreach ($sensors as $sensor) {
                    DeviceService::updateOnlineStatus($sensor->device_uid, false);
                    $sensorMsg = json_encode([
                        'type'            => 'device_status',
                        'device_id'       => $sensor->id,
                        'device_uid'      => $sensor->device_uid,
                        'device_location' => $sensor->location,
                        'is_online'       => false,
                    ], JSON_UNESCAPED_UNICODE);
                    $this->redisPub->publish('ws:broadcast', $sensorMsg);

                    try { \app\service\AlertService::triggerOfflineAlerts($sensor->id); }
                    catch (\Throwable $e) { Log::error("传感器离线告警失败: {$e->getMessage()}"); }
                }
            }
        }
    }

    /**
     * 处理子设备清单（设备热插拔）
     *
     * 网关每次连上 MQTT、以及运行期增删模块后，都会发布一份自己当前挂载的子设备
     * 清单。平台据此自动增补设备记录——无需重新配网、无需配对码，这正是热插拔
     * 与首次自注册（一次性配对码）的区别。
     *
     * 清单里缺席的子设备只标离线不删除，避免误拔一次就丢掉历史遥测与告警规则。
     *
     * @param string $gatewayUid 上报者（必须是网关）
     * @param array  $data       {"devices": [{device_uid, name, type, metric_fields?}]}
     */
    private function handleManifest(string $gatewayUid, array $data): void
    {
        $devices = $data['devices'] ?? null;
        if (!is_array($devices)) {
            Log::warning("清单格式无效: {$gatewayUid}");
            return;
        }

        $gateway = Device::where('device_uid', $gatewayUid)->first();
        if (!$gateway) {
            Log::warning("清单来自未知设备: {$gatewayUid}");
            return;
        }
        // 只有网关能声明子设备，否则任一设备都可凭自己的凭证凭空造设备
        if ($gateway->type !== 'gateway') {
            Log::warning("非网关设备尝试上报子设备清单: {$gatewayUid}");
            return;
        }

        try {
            $result = DeviceService::syncGatewayChildren($gateway, $devices);
        } catch (\Throwable $e) {
            Log::error("同步子设备清单失败({$gatewayUid}): {$e->getMessage()}");
            return;
        }

        if ($result['created']) {
            Log::info("网关 {$gatewayUid} 新增子设备: " . implode(', ', $result['created']));
        }

        // 被拔掉的子设备推一次离线，让各端列表即时反映
        foreach ($result['absent'] as $dev) {
            $this->redisPub->publish('ws:broadcast', json_encode([
                'type'            => 'device_status',
                'device_id'       => $dev->id,
                'device_uid'      => $dev->device_uid,
                'device_location' => $dev->location,
                'is_online'       => false,
            ], JSON_UNESCAPED_UNICODE));
        }

        // 新增设备会改变设备列表，通知各端刷新
        if ($result['created'] || count($result['absent'])) {
            $this->redisPub->publish('ws:broadcast', json_encode([
                'type'        => 'device_list_changed',
                'gateway_uid' => $gatewayUid,
                'device_id' => $gateway->id,
                'home_id' => $gateway->home_id,
            ], JSON_UNESCAPED_UNICODE));
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

        MqttCommandService::handleCommandReply($requestId, $status, $data, $deviceUid);
    }

    /**
     * 从 Redis 队列读取待发送的指令并通过 MQTT 发布
     *
     * 由 Timer 定时调用（每 100ms）。
     */
    public function processCommandQueue(): void
    {
        if (!$this->mqttConnected || !$this->mqttClient) return;
        try {
            if (!$this->redis) $this->initRedis();
            $queue = new \app\service\ReliableQueue($this->redis,
                'hg:q:mqtt:command:send', 'hg:q:mqtt:command:processing');
            foreach ($queue->reserve(10) as $raw) {
                $command = json_decode($raw, true);
                $requestId = $command['request_id'] ?? json_decode($command['payload'] ?? '{}', true)['request_id'] ?? null;
                if (!$requestId || empty($command['topic'])) {
                    $queue->move($raw, 'hg:q:mqtt:command:deadletter');
                    continue;
                }
                $log = \app\model\CommandLog::where('request_id', $requestId)->first();
                if (!$log || in_array($log->status, ['replied_ok', 'replied_error', 'timeout'], true)) {
                    $queue->acknowledge($raw);
                    continue;
                }
                if ($log->sent_at->getTimestamp() < time() - 60) {
                    $log->update(['status' => 'timeout', 'replied_at' => now()]);
                    $queue->acknowledge($raw);
                    continue;
                }
                if (($this->inFlight[$requestId] ?? 0) > time() - 10) continue;
                $this->inFlight[$requestId] = time();
                $this->mqttClient->publish($command['topic'], $command['payload'] ?? '', ['qos' => 1],
                    function ($error = null) use ($queue, $raw, $requestId) {
                        unset($this->inFlight[$requestId]);
                        if ($error) return;
                        try {
                            \app\model\CommandLog::where('request_id', $requestId)->where('status', 'queued')
                                ->update(['status' => 'sent']);
                            $queue->acknowledge($raw);
                        } catch (\Throwable $e) {
                            Log::error('MQTT 确认保存失败，保留指令重试: ' . $e->getMessage());
                        }
                    });
            }
        } catch (\Throwable $e) {
            Log::error('指令发送失败，保留待重试: ' . $e->getMessage());
            if ($e instanceof \RedisException) $this->redis = null;
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
        if ($this->redisCache) {
            $this->redisCache->close();
        }
        Log::info('MqttSubscriber 进程已停止');
    }
}
