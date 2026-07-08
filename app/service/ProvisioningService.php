<?php
/**
 * Home Guardian - 设备自助配网服务
 *
 * 三个动作：
 *   1. createCode  — 移动端生成短时配对码（绑定用户 + 位置）
 *   2. register    — 设备凭配对码自注册：建网关 + 子传感器，返回 MQTT 凭证
 *   3. statusOf    — 移动端轮询配对码状态，判断设备是否已上线
 *
 * 注册出的设备复用现有网关-子设备模型：网关持有 MQTT 凭证，传感器挂在其下（不单独连 MQTT）。
 */

namespace app\service;

use app\model\ProvisionCode;
use app\model\Device;
use app\exception\BusinessException;

class ProvisioningService
{
    /** 配对码字符集（去除易混淆的 0/O/1/I） */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 配对码长度 */
    private const CODE_LENGTH = 8;

    /** 有效期（秒） */
    private const TTL_SECONDS = 600;

    /**
     * 生成一个配对码
     *
     * @param  int         $userId   生成者
     * @param  string|null $location 预设位置（可空）
     * @return ProvisionCode
     */
    public static function createCode(int $userId, ?string $location = null): ProvisionCode
    {
        return ProvisionCode::create([
            'code'       => self::generateUniqueCode(),
            'user_id'    => $userId,
            'location'   => $location ?: null,
            'status'     => ProvisionCode::STATUS_PENDING,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);
    }

    /**
     * 生成不重复的配对码
     *
     * @throws BusinessException 多次尝试仍冲突
     */
    private static function generateUniqueCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $max)];
            }
            if (!ProvisionCode::where('code', $code)->exists()) {
                return $code;
            }
        }
        throw new BusinessException('配对码生成失败，请重试', 500, 9001);
    }

    /**
     * 设备自注册
     *
     * @param  array $payload {provision_code, gateway:{device_uid,name,firmware_version,capability?}, sensors:[{...}]}
     * @return array {mqtt:{host,port,username,password}, gateway_uid, devices:[...]}
     *
     * @throws BusinessException 配对码无效/过期/已用，或缺少必要字段
     */
    public static function register(array $payload): array
    {
        $code = trim((string)($payload['provision_code'] ?? ''));
        if ($code === '') {
            throw new BusinessException('缺少配对码', 422, 9002);
        }

        $record = ProvisionCode::where('code', $code)->first();
        if (!$record) {
            throw new BusinessException('配对码无效', 422, 9003);
        }
        if ($record->status !== ProvisionCode::STATUS_PENDING) {
            throw new BusinessException('配对码已被使用', 410, 9004);
        }
        if (strtotime((string)$record->expires_at) < time()) {
            $record->update(['status' => ProvisionCode::STATUS_EXPIRED]);
            throw new BusinessException('配对码已过期', 410, 9005);
        }

        $gw = $payload['gateway'] ?? [];
        $gwUid = trim((string)($gw['device_uid'] ?? ''));
        if ($gwUid === '') {
            throw new BusinessException('缺少网关 device_uid', 422, 9006);
        }

        // 网关 MQTT 明文密码（仅在响应里返回一次，库里只存 bcrypt）
        $mqttPassword = bin2hex(random_bytes(16));

        // 多表写入用事务（用模型实例的连接，兼容生产 PgSQL 与测试 SQLite；
        // getConnection() 是实例方法，不能静态调用）
        $devices = (new Device)->getConnection()->transaction(function () use ($record, $gw, $gwUid, $payload, $mqttPassword) {
            $created = [];

            // 1. 网关（幂等 upsert：支持恢复出厂后重新配网）
            $gwData = [
                'device_uid'         => $gwUid,
                'name'               => $gw['name'] ?? $gwUid,
                'type'               => 'gateway',
                'location'           => $record->location,
                'firmware_version'   => $gw['firmware_version'] ?? null,
                'mqtt_username'      => $gwUid,
                'mqtt_password_hash' => password_hash($mqttPassword, PASSWORD_BCRYPT),
            ];
            if (array_key_exists('capability', $gw)) {
                $gwData['capability'] = $gw['capability'];
            }
            $gateway = self::upsertDevice($gwUid, $gwData);
            $created[] = ['device_uid' => $gwUid, 'id' => $gateway->id, 'role' => 'gateway'];

            // 2. 子传感器/子设备
            foreach (($payload['sensors'] ?? []) as $i => $s) {
                $sUid = trim((string)($s['device_uid'] ?? '')) ?: ($gwUid . '-s' . ($i + 1));
                $sData = [
                    'device_uid'    => $sUid,
                    'name'          => $s['name'] ?? $sUid,
                    'type'          => $s['type'] ?? 'sensor',
                    'location'      => $record->location,
                    'gateway_uid'   => $gwUid,
                    'mqtt_username' => $sUid, // 子设备不连 MQTT，但保持列唯一
                ];
                if (array_key_exists('metric_fields', $s)) {
                    $sData['metric_fields'] = $s['metric_fields'];
                }
                if (array_key_exists('capability', $s)) {
                    $sData['capability'] = $s['capability'];
                }
                $sensor = self::upsertDevice($sUid, $sData);
                $created[] = ['device_uid' => $sUid, 'id' => $sensor->id, 'role' => $sData['type']];
            }

            // 3. 标记配对码已用
            $record->update([
                'status'     => ProvisionCode::STATUS_REGISTERED,
                'device_uid' => $gwUid,
                'used_at'    => now(),
            ]);

            return $created;
        });

        return [
            'mqtt' => [
                // 返回设备可达的公网 MQTT 地址；MQTT_PUBLIC_HOST 未配则回退 MQTT_HOST
                'host'     => getenv('MQTT_PUBLIC_HOST') ?: (getenv('MQTT_HOST') ?: 'emqx'),
                'port'     => (int)(getenv('MQTT_PORT') ?: 1883),
                'username' => $gwUid,
                'password' => $mqttPassword,
            ],
            'gateway_uid' => $gwUid,
            'devices'     => $devices,
        ];
    }

    /**
     * 按 device_uid upsert 一台设备
     */
    private static function upsertDevice(string $uid, array $data): Device
    {
        $device = Device::where('device_uid', $uid)->first();
        if ($device) {
            $device->update($data);
            return $device;
        }
        return Device::create($data);
    }

    /**
     * 查询配对码状态（供移动端轮询）
     *
     * @param  string $code   配对码
     * @param  int    $userId 当前用户（仅能查自己的码）
     * @return array {status, device|null}
     *
     * @throws BusinessException 码不存在
     */
    public static function statusOf(string $code, int $userId): array
    {
        $record = ProvisionCode::where('code', $code)->where('user_id', $userId)->first();
        if (!$record) {
            throw new BusinessException('配对码不存在', 404, 9007);
        }

        $status = $record->status;
        if ($status === ProvisionCode::STATUS_PENDING && strtotime((string)$record->expires_at) < time()) {
            $status = ProvisionCode::STATUS_EXPIRED;
        }

        $device = null;
        if ($record->device_uid) {
            $d = Device::where('device_uid', $record->device_uid)
                ->first(['id', 'device_uid', 'name', 'is_online']);
            $device = $d ? $d->toArray() : null;
        }

        return ['status' => $status, 'device' => $device];
    }
}
