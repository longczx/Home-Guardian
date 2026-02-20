<?php
/**
 * Home Guardian - WebSocket 实时推送服务
 *
 * 独立的 Workerman Worker 进程，监听 8788 端口处理 WebSocket 连接。
 * 通过订阅 Redis Pub/Sub 频道接收来自其他进程的消息，推送给前端客户端。
 *
 * 数据流：
 *   MqttSubscriber → Redis Pub/Sub → WebSocketServer → 浏览器
 *
 * 认证方式：
 *   前端通过 URL 参数传递 JWT: ws://host/ws?token={access_token}
 *   握手阶段验证 token，验证失败则断开连接。
 *
 * 推送过滤：
 *   根据用户的位置作用域（locations），只推送有权查看的设备数据。
 */

namespace app\process;

use app\service\JwtService;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

class WebSocketServer
{
    /**
     * 已认证的 WebSocket 连接
     * key: 连接 ID, value: ['connection' => TcpConnection, 'user' => object]
     *
     * @var array
     */
    private static array $clients = [];

    /**
     * Worker 进程启动回调
     *
     * @param Worker $worker WebSocket Worker 实例
     */
    public function onWorkerStart(Worker $worker): void
    {
        // 在子进程中创建 Redis 订阅连接
        // 不能在主进程创建，因为 fork 后连接会共享导致冲突
        $this->subscribeRedis();
    }

    /**
     * 新 WebSocket 连接建立
     *
     * 在握手完成后验证 JWT token，验证失败则关闭连接。
     *
     * @param TcpConnection $connection 客户端连接
     */
    public function onConnect(TcpConnection $connection): void
    {
        // onConnect 阶段 HTTP 信息尚未解析，实际认证在 onMessage 的第一条消息中处理
        // 或者在 onWebSocketConnect 回调中处理
    }

    /**
     * WebSocket 握手完成回调
     *
     * Webman 会在此回调中提供 HTTP 头信息，可以从 URL 参数中提取 token。
     *
     * @param TcpConnection $connection
     * @param string        $httpBuffer HTTP 握手请求原始数据
     */
    public function onWebSocketConnect(TcpConnection $connection, string $httpBuffer): void
    {
        // 从 URL 参数中提取 token: /ws?token=xxx
        $queryString = '';
        if (preg_match('/GET\s+\/ws\?([^\s]+)\s+HTTP/', $httpBuffer, $matches)) {
            $queryString = $matches[1];
        }

        parse_str($queryString, $params);
        $token = $params['token'] ?? '';

        if (empty($token)) {
            $connection->send(json_encode([
                'type'    => 'error',
                'message' => '缺少认证令牌',
            ]));
            $connection->close();
            return;
        }

        // 验证 JWT
        $payload = JwtService::verifyAccessToken($token);
        if (!$payload) {
            $connection->send(json_encode([
                'type'    => 'error',
                'message' => '认证令牌无效或已过期',
            ]));
            $connection->close();
            return;
        }

        // 认证成功，注册连接
        self::$clients[$connection->id] = [
            'connection' => $connection,
            'user'       => (object)[
                'id'        => $payload->sub,
                'username'  => $payload->username,
                'roles'     => $payload->roles ?? [],
                'locations' => $payload->locations ?? [],
                'is_admin'  => !empty((array)($payload->permissions ?? [])['admin'] ?? false),
            ],
        ];

        // 发送欢迎消息
        $connection->send(json_encode([
            'type'    => 'connected',
            'message' => "欢迎, {$payload->username}",
            'user_id' => $payload->sub,
        ]));
    }

    /**
     * 收到客户端消息
     *
     * WebSocket 连接建立后，客户端可以发送心跳或订阅指令。
     *
     * @param TcpConnection $connection
     * @param mixed         $data 客户端发送的消息
     */
    public function onMessage(TcpConnection $connection, mixed $data): void
    {
        $message = json_decode($data, true);
        if (!$message) {
            return;
        }

        // 心跳响应
        if (($message['type'] ?? '') === 'ping') {
            $connection->send(json_encode(['type' => 'pong']));
        }
    }

    /**
     * 连接关闭
     *
     * @param TcpConnection $connection
     */
    public function onClose(TcpConnection $connection): void
    {
        unset(self::$clients[$connection->id]);
    }

    /**
     * 订阅 Redis Pub/Sub 频道
     *
     * 使用原生 Redis 扩展创建阻塞式订阅连接。
     * 在 Workerman 中使用 Timer 定时轮询方式避免阻塞事件循环。
     */
    private function subscribeRedis(): void
    {
        $redisHost = getenv('REDIS_HOST') ?: 'redis';
        $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
        $redisPassword = getenv('REDIS_PASSWORD') ?: '';

        // 使用非阻塞方式订阅：定时从 Redis List 中弹出消息
        // 这比 Pub/Sub 更可靠（不会因为 Worker 重启丢失消息）
        \Workerman\Timer::add(0.1, function () use ($redisHost, $redisPort, $redisPassword) {
            static $redis = null;

            if (!$redis) {
                try {
                    $redis = new \Redis();
                    $redis->connect($redisHost, $redisPort, 3);
                    if ($redisPassword) {
                        $redis->auth($redisPassword);
                    }
                    $redis->select(2); // DB2: Pub/Sub
                } catch (\Throwable $e) {
                    $redis = null;
                    return;
                }
            }

            try {
                // 使用 BRPOP 替代 Pub/Sub，确保消息不丢失
                // 但这里用 RPOP 非阻塞，因为在 Timer 中
                while ($message = $redis->rPop('ws:broadcast:queue')) {
                    $this->broadcastToClients($message);
                }
            } catch (\Throwable $e) {
                $redis = null; // 连接断开，下次重连
            }
        });

        // 同时订阅 Pub/Sub 频道（用于实时性要求更高的场景）
        $this->startPubSubSubscription($redisHost, $redisPort, $redisPassword);
    }

    /**
     * 启动 Redis Pub/Sub 订阅
     *
     * 使用异步方式订阅，不阻塞 Worker 事件循环。
     */
    private function startPubSubSubscription(string $host, int $port, string $password): void
    {
        $connection = new \Workerman\Connection\AsyncTcpConnection("tcp://{$host}:{$port}");

        $connection->onConnect = function ($conn) use ($password) {
            // 认证
            if ($password) {
                $conn->send("AUTH {$password}\r\n");
            }
            // 选择 DB2
            $conn->send("SELECT 2\r\n");
            // 订阅广播频道
            $conn->send("SUBSCRIBE ws:broadcast\r\n");
        };

        $connection->onMessage = function ($conn, $data) {
            // 解析 Redis RESP 协议消息
            // SUBSCRIBE 响应格式: *3\r\n$9\r\nsubscribe\r\n$12\r\nws:broadcast\r\n:1\r\n
            // MESSAGE 格式: *3\r\n$7\r\nmessage\r\n$12\r\nws:broadcast\r\n$...\r\n{payload}\r\n
            if (str_contains($data, 'message')) {
                // 提取最后一个 \r\n 分隔的有效 JSON 数据
                $lines = explode("\r\n", trim($data));
                $payload = end($lines);
                if ($payload && $payload[0] === '{') {
                    $this->broadcastToClients($payload);
                }
            }
        };

        $connection->onError = function ($conn, $code, $msg) {
            \support\Log::error("WebSocket Redis Pub/Sub 连接错误: [{$code}] {$msg}");
        };

        $connection->onClose = function ($conn) {
            \support\Log::warning('WebSocket Redis Pub/Sub 连接断开，5 秒后重连');
            // 5 秒后重连
            \Workerman\Timer::add(5, function () use ($conn) {
                $conn->reconnect();
            }, null, false);
        };

        $connection->connect();
    }

    /**
     * 向所有已认证的 WebSocket 客户端广播消息
     *
     * 根据消息中的 device_id 和用户的位置作用域进行过滤推送。
     *
     * @param string $message JSON 格式的消息
     */
    private function broadcastToClients(string $message): void
    {
        $data = json_decode($message, true);
        if (!$data) {
            return;
        }

        // 获取消息关联的设备位置（用于权限过滤）
        $deviceLocation = $data['device_location'] ?? null;

        foreach (self::$clients as $clientInfo) {
            $connection = $clientInfo['connection'];
            $user = $clientInfo['user'];

            // admin 收到所有消息
            if ($user->is_admin) {
                $connection->send($message);
                continue;
            }

            // 非位置相关的消息（如系统通知）推送给所有人
            if (!$deviceLocation) {
                $connection->send($message);
                continue;
            }

            // 位置作用域为空 = 不限制
            $allowedLocations = $user->locations ?? [];
            if (empty($allowedLocations) || in_array($deviceLocation, $allowedLocations)) {
                $connection->send($message);
            }
        }
    }
}
