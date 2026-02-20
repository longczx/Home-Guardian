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
            // 设备只能发布到自己的 upstream 主题
            return str_starts_with($topic, "home/upstream/{$uid}/");
        }

        if ($action === 'subscribe') {
            // 设备只能订阅自己的 downstream 主题
            return str_starts_with($topic, "home/downstream/{$uid}/");
        }

        return false;
    }
}
