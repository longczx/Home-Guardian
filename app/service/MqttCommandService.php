<?php
/**
 * Home Guardian - MQTT 指令服务
 *
 * 负责通过 MQTT 向设备发送控制指令，并记录指令日志。
 * 使用 Redis Pub/Sub 将指令请求传递给 MqttSubscriber 进程，
 * 由该进程通过 MQTT 客户端实际发送到 EMQX。
 *
 * 指令流程：
 *   1. Controller 调用 MqttCommandService::sendCommand()
 *   2. 生成 request_id，写入 command_logs 表（status = sent）
 *   3. 通过 Redis 将指令推送给 MqttSubscriber 进程
 *   4. MqttSubscriber 发布到 MQTT 主题 home/downstream/{device_id}/command/set
 *   5. 设备执行后回复 home/upstream/{device_id}/command/reply
 *   6. MqttSubscriber 更新 command_logs 状态
 */

namespace app\service;

use app\model\CommandLog;
use app\model\Device;
use app\exception\BusinessException;
use support\Redis;

class MqttCommandService
{
    /**
     * Redis 频道：指令发送队列
     * MqttSubscriber 进程监听此频道，收到后通过 MQTT 发送
     */
    private const COMMAND_CHANNEL = 'mqtt:command:send';

    /**
     * 向设备发送控制指令
     *
     * @param  int    $deviceId 目标设备 ID
     * @param  array  $payload  指令内容（将作为 MQTT 消息 payload 发送）
     * @return CommandLog 创建的指令日志记录
     *
     * @throws BusinessException 设备不存在或离线
     */
    public static function sendCommand(int $deviceId, array $payload): CommandLog
    {
        // 验证设备存在
        $device = Device::find($deviceId);
        if (!$device) {
            throw new BusinessException('设备不存在', 404, 2001);
        }

        // 生成唯一的请求 ID，用于追踪指令生命周期
        $requestId = self::generateRequestId();

        // 构建 MQTT 主题
        $topic = "home/downstream/{$device->device_uid}/command/set";

        // 在 payload 中注入 request_id，设备回复时需原样返回
        $mqttPayload = array_merge($payload, ['request_id' => $requestId]);

        // 记录指令日志
        $commandLog = CommandLog::create([
            'request_id' => $requestId,
            'device_id'  => $deviceId,
            'topic'      => $topic,
            'payload'    => $mqttPayload,
            'status'     => CommandLog::STATUS_SENT,
            'sent_at'    => now(),
        ]);

        // 通过 Redis 推送给 MQTT 进程
        $message = json_encode([
            'topic'   => $topic,
            'payload' => json_encode($mqttPayload),
            'qos'     => 1,  // QoS 1: 至少送达一次
        ], JSON_UNESCAPED_UNICODE);

        Redis::connection('queue')->lPush(self::COMMAND_CHANNEL, $message);

        return $commandLog;
    }

    /**
     * 处理设备的指令回复
     *
     * 由 MqttSubscriber 进程调用，更新指令日志的状态。
     *
     * @param string $requestId 请求 ID（设备原样返回的）
     * @param string $status    回复状态（replied_ok / replied_error）
     * @param array  $replyData 回复数据
     */
    public static function handleCommandReply(string $requestId, string $status, array $replyData = []): void
    {
        $commandLog = CommandLog::where('request_id', $requestId)->first();

        if (!$commandLog) {
            \support\Log::warning("收到未知 request_id 的指令回复: {$requestId}");
            return;
        }

        $commandLog->update([
            'status'     => $status,
            'replied_at' => now(),
        ]);

        // 如果需要，可以通过 Redis Pub/Sub 通知前端指令执行结果
        $notification = json_encode([
            'type'       => 'command_reply',
            'request_id' => $requestId,
            'device_id'  => $commandLog->device_id,
            'status'     => $status,
            'data'       => $replyData,
        ], JSON_UNESCAPED_UNICODE);

        Redis::connection('pubsub')->publish('ws:broadcast', $notification);
    }

    /**
     * 生成唯一的请求 ID
     *
     * 格式: cmd_{时间戳}_{随机字符串}
     *
     * @return string
     */
    private static function generateRequestId(): string
    {
        return 'cmd_' . time() . '_' . bin2hex(random_bytes(8));
    }
}
