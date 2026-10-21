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
    public static function createCode(int $userId, ?string $location = null, ?string $rebindUid = null): ProvisionCode
    {
        return ProvisionCode::create([
            'code'       => self::generateUniqueCode(),
            'rebind_uid' => $rebindUid,
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

        ProvisionCode::withoutGlobalScopes()->where('code', $code)->where('status', ProvisionCode::STATUS_PENDING)->where('expires_at', '<', now())->update(['status'=>ProvisionCode::STATUS_EXPIRED]);
        if (!is_array($payload['gateway'] ?? null) || !is_array($payload['sensors'] ?? []) || count($payload['sensors'] ?? []) > 16) throw new BusinessException('网关和子设备格式无效（最多16个）', 422, 9006);
        $gw = $payload['gateway'] ?? [];
        $gwUid = trim((string)($gw['device_uid'] ?? ''));
        if ($gwUid === '') {
            throw new BusinessException('缺少网关 device_uid', 422, 9006);
        }

        if (!preg_match('/^[a-zA-Z0-9_-]{1,48}$/', $gwUid)) throw new BusinessException('网关 UID 格式无效', 422, 9006);
        $token = $payload['registration_token'] ?? null;
        if ($token !== null && (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token))) throw new BusinessException('注册恢复凭证格式无效', 422, 9008);
        // The device persists the random token before its first request. A lost response can be
        // recovered by that device only; possession of a consumed pairing code is insufficient.
        $mqttPassword = $token ? hash_hmac('sha256', $code . ':' . $gwUid . ':' . $token, getenv('JWT_SECRET')) : bin2hex(random_bytes(16));

        // 多表写入用事务（用模型实例的连接，兼容生产 PgSQL 与测试 SQLite；
        // getConnection() 是实例方法，不能静态调用）
        $devices = (new Device)->getConnection()->transaction(function () use ($code, $token, $gw, $gwUid, $payload, $mqttPassword) {
            $record = ProvisionCode::withoutGlobalScopes()->where('code', $code)->lockForUpdate()->first();
            if (!$record) throw new BusinessException('配对码无效', 422, 9003);
            if ($record->expires_at->isPast()) throw new BusinessException('配对码已过期', 410, 9005);
            if ($record->status !== ProvisionCode::STATUS_PENDING) {
                $gateway = Device::withoutGlobalScopes()->where('device_uid', $gwUid)->first();
                if ($token && $record->device_uid === $gwUid && $record->registration_token_hash
                    && hash_equals($record->registration_token_hash, hash('sha256', $token))
                    && $gateway && (int)$gateway->home_id === (int)$record->home_id
                    && password_verify($mqttPassword, $gateway->mqtt_password_hash)) {
                    return [['device_uid'=>$gwUid, 'id'=>$gateway->id, 'role'=>'gateway']];
                }
                throw new BusinessException('配对码已被使用', 410, 9004);
            }
            if ($record->rebind_uid && $record->rebind_uid !== $gwUid) throw new BusinessException('配对码仅用于指定网关重新配网', 409, 9009);
            $existing = Device::withoutGlobalScopes()->where('device_uid', $gwUid)->lockForUpdate()->first();
            if ($existing && ((int)$existing->home_id !== (int)$record->home_id || $record->rebind_uid !== $gwUid || $existing->type !== 'gateway')) {
                throw new BusinessException('设备已绑定，请由原家庭管理员在设备详情授权重新配网', 409, 9009);
            }
            $created = [];

            // 1. 网关（幂等 upsert：支持恢复出厂后重新配网）
            // home_id 继承配对码归属：设备注册即归属生成该码的家庭
            $gwData = [
                'home_id'            => $record->home_id,
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
            $gateway = self::upsertDevice($gwUid, $gwData, true);
            $created[] = ['device_uid' => $gwUid, 'id' => $gateway->id, 'role' => 'gateway'];

            // 2. 子传感器/子设备
            foreach (($payload['sensors'] ?? []) as $i => $s) {
                if (!is_array($s)) throw new BusinessException('子设备格式无效', 422, 9006);
                $sUid = trim((string)($s['device_uid'] ?? '')) ?: ($gwUid . '-s' . ($i + 1));
                if (!preg_match('/^[a-zA-Z0-9_-]{1,48}$/', $sUid)) throw new BusinessException('子设备 UID 格式无效', 422, 9006);
                $existingSensor = Device::withoutGlobalScopes()->where('device_uid', $sUid)->first();
                $sData = [
                    'home_id'       => $record->home_id,
                    'device_uid'    => $sUid,
                    'name'          => $s['name'] ?? $sUid,
                    'type'          => $s['type'] ?? 'sensor',
                    'location'      => $record->location,
                    'gateway_uid'   => $gwUid,
                    'mqtt_username' => $sUid, // 子设备不连 MQTT，但保持列唯一
                ];
                // 固件自报字段：仅在新建或原字段为空时采用，避免覆盖用户在 App 里手改的 metric_fields
                if (array_key_exists('metric_fields', $s)
                    && (!$existingSensor || empty($existingSensor->metric_fields))) {
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
                'registration_token_hash' => $token ? hash('sha256', $token) : null,
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
    private static function upsertDevice(string $uid, array $data, bool $gateway = false): Device
    {
        $device = Device::withoutGlobalScopes()->where('device_uid', $uid)->lockForUpdate()->first();
        if ($device) {
            if ((int)$device->home_id !== (int)$data['home_id'] || (!$gateway && ($device->gateway_uid !== $data['gateway_uid'] || $device->type === 'gateway'))) {
                throw new BusinessException('设备 UID 已被其他网关或家庭使用', 409, 9009);
            }
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
                ->first(['id', 'device_uid', 'name', 'is_online', 'last_seen']);
            $device = $d ? $d->toArray() : null;
        }

        $stage = $status;
        if ($status === ProvisionCode::STATUS_REGISTERED) {
            $stage = 'connecting';
            if ($device && $device['is_online'] && $d->last_seen?->gte($record->used_at)) {
                $sensorIds = Device::where('gateway_uid', $record->device_uid)->where('home_id', $record->home_id)->where('type', 'sensor')->pluck('id');
                $hasData = $sensorIds->isEmpty() || \app\model\TelemetryLog::whereIn('device_id', $sensorIds)->where('ts', '>=', $record->used_at)->exists();
                $stage = $hasData ? 'ready' : 'awaiting_data';
            }
        }
        return ['status' => $status, 'stage'=>$stage, 'device' => $device];
    }
}
