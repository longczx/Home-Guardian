<?php
/**
 * Home Guardian - 设备服务
 *
 * 封装设备相关的业务逻辑，包括设备 CRUD、MQTT 凭证管理、
 * 在线状态更新等。Controller 层调用此服务处理业务逻辑。
 */

namespace app\service;

use app\model\Device;
use app\model\DeviceAttribute;
use app\exception\BusinessException;

class DeviceService
{
    /**
     * 创建新设备
     *
     * 自动生成 MQTT 凭证（mqtt_username 默认等于 device_uid）。
     *
     * @param  array $data 设备数据
     * @return Device 创建的设备实例
     *
     * @throws BusinessException device_uid 已存在
     */
    public static function create(array $data): Device
    {
        // 检查 device_uid 唯一性
        if (Device::where('device_uid', $data['device_uid'])->exists()) {
            throw new BusinessException('设备 UID 已存在', 409, 2002);
        }

        // 设置 MQTT 用户名（默认与 device_uid 相同）
        $data['mqtt_username'] = $data['mqtt_username'] ?? $data['device_uid'];

        // 如果提供了 MQTT 密码明文，生成 bcrypt 哈希
        if (!empty($data['mqtt_password'])) {
            $data['mqtt_password_hash'] = password_hash($data['mqtt_password'], PASSWORD_BCRYPT);
            unset($data['mqtt_password']); // 不要存明文
        }

        // metric_fields JSON 字符串转数组
        if (isset($data['metric_fields']) && is_string($data['metric_fields'])) {
            $data['metric_fields'] = json_decode($data['metric_fields'], true);
        }

        // capability JSON 字符串转数组（执行器控制能力）
        if (isset($data['capability']) && is_string($data['capability'])) {
            $data['capability'] = $data['capability'] === '' ? null : json_decode($data['capability'], true);
        }

        return Device::create($data);
    }

    /**
     * 更新设备信息
     *
     * @param  int   $id   设备 ID
     * @param  array $data 更新数据
     * @return Device 更新后的设备实例
     *
     * @throws BusinessException 设备不存在
     */
    public static function update(int $id, array $data): Device
    {
        $device = Device::find($id);
        if (!$device) {
            throw new BusinessException('设备不存在', 404, 2001);
        }

        // 如果更新 MQTT 密码，重新生成哈希
        if (!empty($data['mqtt_password'])) {
            $data['mqtt_password_hash'] = password_hash($data['mqtt_password'], PASSWORD_BCRYPT);
            unset($data['mqtt_password']);
        }

        // metric_fields JSON 字符串转数组
        if (isset($data['metric_fields']) && is_string($data['metric_fields'])) {
            $data['metric_fields'] = json_decode($data['metric_fields'], true);
        }

        // capability JSON 字符串转数组（执行器控制能力）
        if (isset($data['capability']) && is_string($data['capability'])) {
            $data['capability'] = $data['capability'] === '' ? null : json_decode($data['capability'], true);
        }

        $device->update($data);
        return $device->fresh();
    }

    /**
     * 删除设备
     *
     * 级联删除关联的属性、指令日志、告警规则等（由数据库外键约束保证）。
     *
     * @param  int $id 设备 ID
     * @throws BusinessException 设备不存在
     */
    public static function delete(int $id): void
    {
        $device = Device::find($id);
        if (!$device) {
            throw new BusinessException('设备不存在', 404, 2001);
        }

        // 网关须先删空其下子设备，避免子设备失去所属网关成为"孤儿"
        if ($device->type === 'gateway') {
            $childCount = Device::where('gateway_uid', $device->device_uid)->count();
            if ($childCount > 0) {
                throw new BusinessException(
                    "该网关下还挂着 {$childCount} 个子设备，请先删除或迁移这些设备后再删网关",
                    409,
                    2003
                );
            }
        }

        $device->delete();
    }

    /**
     * 更新设备在线状态
     *
     * 由 MqttSubscriber 进程调用，在收到设备遥测/状态消息或 MQTT LWT 时更新。
     *
     * @param string $deviceUid 设备唯一标识
     * @param bool   $isOnline  是否在线
     */
    public static function updateOnlineStatus(string $deviceUid, bool $isOnline): void
    {
        Device::where('device_uid', $deviceUid)->update([
            'is_online' => $isOnline,
            'last_seen' => $isOnline ? now() : null,
        ]);
    }

    /**
     * 心跳超时扫描：把 last_seen 过期但仍标记在线的设备置为离线
     *
     * 兜底 MQTT LWT 未触发的「静默死亡」（断电/崩溃/掉网）——仅靠 LWT 时
     * 这类设备会一直显示在线。由 CrontabProcess 每 60s 调用。
     *
     * @param  int $timeoutSec 超时秒数（last_seen 早于 now-timeout 视为离线）
     * @return array 被置离线的设备 [{id, device_uid, location}]，供调用方推 WS / 触发告警
     */
    public static function sweepOfflineDevices(int $timeoutSec): array
    {
        $threshold = now()->subSeconds($timeoutSec);

        $stale = Device::where('is_online', true)
            ->whereNotNull('last_seen')
            ->where('last_seen', '<', $threshold)
            ->get(['id', 'device_uid', 'location']);

        if ($stale->isEmpty()) {
            return [];
        }

        Device::whereIn('id', $stale->pluck('id'))->update(['is_online' => false]);

        return $stale->map(fn ($d) => [
            'id'         => $d->id,
            'device_uid' => $d->device_uid,
            'location'   => $d->location,
        ])->all();
    }

    /**
     * 批量设置设备属性（EAV 模式）
     *
     * 使用 upsert 模式：属性存在则更新，不存在则创建。
     *
     * @param int   $deviceId   设备 ID
     * @param array $attributes 属性键值对，如 ["mac_address" => "AA:BB:CC", "chip" => "ESP32"]
     */
    public static function setAttributes(int $deviceId, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            DeviceAttribute::updateOrCreate(
                ['device_id' => $deviceId, 'attribute_key' => $key],
                ['attribute_value' => (string)$value]
            );
        }
    }

    /**
     * EMQX HTTP 认证：验证设备 MQTT 连接凭证
     *
     * 由 MqttAuthController 调用，EMQX 在设备连接时回调此方法。
     *
     * @param  string $username MQTT 用户名
     * @param  string $password MQTT 密码明文
     * @return bool 认证是否通过
     */
    public static function verifyMqttCredentials(string $username, string $password): bool
    {
        // 超级用户（Webman 内部 MQTT 客户端）使用环境变量验证
        $superUsername = getenv('MQTT_SUPER_USERNAME') ?: '';
        if ($username === $superUsername) {
            $superPassword = getenv('MQTT_SUPER_PASSWORD') ?: '';
            return $password === $superPassword;
        }

        // 普通设备：从 devices 表查找并验证 bcrypt 密码
        $device = Device::where('mqtt_username', $username)->first();
        if (!$device || empty($device->mqtt_password_hash)) {
            return false;
        }

        return password_verify($password, $device->mqtt_password_hash);
    }

    /**
     * EMQX ACL 鉴权：验证设备是否有权发布/订阅指定主题
     *
     * 规则：
     *   - 超级用户（Webman 内部客户端）：拥有所有主题权限
     *   - 普通设备：只能操作自己 device_uid 对应的主题
     *     - PUBLISH:   home/upstream/{自己的uid}/#
     *     - SUBSCRIBE: home/downstream/{自己的uid}/#
     *
     * @param  string $username MQTT 用户名
     * @param  string $topic   请求的主题
     * @param  string $action  操作类型（publish / subscribe）
     * @return bool   是否允许
     */
    public static function checkMqttAcl(string $username, string $topic, string $action): bool
    {
        // 超级用户拥有全部权限
        $superUsername = getenv('MQTT_SUPER_USERNAME') ?: '';
        if ($username === $superUsername) {
            return true;
        }

        // 查找设备对应的 device_uid
        $device = Device::where('mqtt_username', $username)->first();
        if (!$device) {
            return false;
        }

        $uid = $device->device_uid;

        // 根据操作类型检查主题权限
        if ($action === 'publish') {
            // 设备可以发布到自己的 upstream 主题
            if (str_starts_with($topic, "home/upstream/{$uid}/")) {
                return true;
            }

            // 网关可以代其下传感器发布
            if ($device->type === 'gateway') {
                $sensorUids = Device::where('gateway_uid', $uid)->pluck('device_uid')->toArray();
                foreach ($sensorUids as $sensorUid) {
                    if (str_starts_with($topic, "home/upstream/{$sensorUid}/")) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($action === 'subscribe') {
            // 设备可订阅自己的 downstream 主题
            if (str_starts_with($topic, "home/downstream/{$uid}/")) {
                return true;
            }

            // 网关代其下子设备接收指令：允许订阅子设备的 downstream
            // （子设备不直连 MQTT，指令经网关下发；缺此项子执行器收不到指令）
            if ($device->type === 'gateway') {
                $sensorUids = Device::where('gateway_uid', $uid)->pluck('device_uid')->toArray();
                foreach ($sensorUids as $sensorUid) {
                    if (str_starts_with($topic, "home/downstream/{$sensorUid}/")) {
                        return true;
                    }
                }
            }

            return false;
        }

        return false;
    }
}
