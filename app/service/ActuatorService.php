<?php
/**
 * Home Guardian - 执行器控制服务
 *
 * 执行器控制的统一入口（API 与自动化共用）。负责：
 *   1. 按设备 capability 校验 action 与 params 合法性
 *   2. discrete 模式：单控制点直发；merge 模式：合并完整状态后下发（action=set_state）
 *   3. 设备状态双写：Redis（hg:device:state:{id}）+ device_states 表
 *   4. 通过 WS 推送状态变更，多端同步
 *
 * 状态语义：
 *   - 闭环设备由 state/post 上报真实状态（见 MqttSubscriber，reported=true）
 *   - 开环设备（红外）下发即更新"期望状态"（reported=false），作为前端展示依据
 */

namespace app\service;

use app\model\Device;
use app\model\DeviceState;
use app\model\CapabilityTemplate;
use app\exception\BusinessException;
use support\Redis;
use support\Log;

class ActuatorService
{
    /**
     * 状态缓存键前缀（default 连接，DB0，带 hg: 前缀 → hg:device:state:{id}）
     */
    private const STATE_CACHE_KEY = 'device:state:';

    /**
     * 状态缓存有效期（秒）
     */
    private const STATE_TTL = 86400;

    /**
     * 处理一条控制指令
     *
     * @param  Device $device 目标设备
     * @param  string $action 指令动作
     * @param  array  $params 指令参数
     * @return \app\model\CommandLog
     *
     * @throws BusinessException 设备无控制能力或参数校验失败
     */
    public static function applyCommand(Device $device, string $action, array $params): \app\model\CommandLog
    {
        $cap = $device->capability;
        if (empty($cap) || empty($cap['controls']) || !is_array($cap['controls'])) {
            throw new BusinessException('该设备未配置控制能力', 422, 2100);
        }

        // 1. 校验 action + params
        self::validate($cap, $action, $params);

        $mode = $cap['control_mode'] ?? CapabilityTemplate::MODE_DISCRETE;

        if ($mode === CapabilityTemplate::MODE_MERGE) {
            // 2a. merge：读期望状态 → 合并改动 → 下发完整状态
            $current = self::getState($device->id) ?: self::defaultState($cap);
            $next = array_merge($current, $params);

            self::saveState($device->id, $next, false);
            $log = MqttCommandService::sendCommand($device->id, ['action' => $action, 'params' => $next]);
            self::pushStateWs($device, $next);
            return $log;
        }

        // 2b. discrete：单点直发，乐观更新对应 state_key
        $log = MqttCommandService::sendCommand($device->id, ['action' => $action, 'params' => $params]);

        $patch = self::statePatchFor($cap, $action, $params);
        if (!empty($patch)) {
            $next = array_merge(self::getState($device->id) ?: [], $patch);
            self::saveState($device->id, $next, false);
            self::pushStateWs($device, $next);
        }
        return $log;
    }

    /* ============================
     * 校验
     * ============================ */

    /**
     * 校验 action 是否被声明，且每个 param 命中控制点并符合类型/范围/枚举
     *
     * @throws BusinessException
     */
    private static function validate(array $cap, string $action, array $params): void
    {
        // action => [param => controlPoint]
        $commands = [];
        foreach ($cap['controls'] as $c) {
            if (!isset($c['command'], $c['param'])) {
                continue;
            }
            $commands[$c['command']][$c['param']] = $c;
        }

        if (!isset($commands[$action])) {
            throw new BusinessException("不支持的指令: {$action}", 422, 2101);
        }

        foreach ($params as $key => $value) {
            $cp = $commands[$action][$key] ?? null;
            if (!$cp) {
                throw new BusinessException("非法参数: {$key}", 422, 2102);
            }
            self::validateValue($cp, $value);
        }
    }

    /**
     * 按控制点定义校验单个值
     *
     * @throws BusinessException
     */
    private static function validateValue(array $cp, mixed $value): void
    {
        $label = $cp['label'] ?? $cp['key'] ?? $cp['param'] ?? '参数';
        $type = $cp['value_type'] ?? 'string';

        switch ($type) {
            case 'bool':
                if (!is_bool($value)) {
                    throw new BusinessException("{$label} 必须为布尔值", 422, 2103);
                }
                break;

            case 'int':
            case 'float':
                if (!is_numeric($value)) {
                    throw new BusinessException("{$label} 必须为数值", 422, 2103);
                }
                $num = $value + 0;
                if (isset($cp['min']) && $num < $cp['min']) {
                    throw new BusinessException("{$label} 不能小于 {$cp['min']}", 422, 2104);
                }
                if (isset($cp['max']) && $num > $cp['max']) {
                    throw new BusinessException("{$label} 不能大于 {$cp['max']}", 422, 2104);
                }
                break;

            case 'enum':
                $allowed = array_map(fn($o) => $o['value'] ?? null, $cp['options'] ?? []);
                if (!in_array($value, $allowed, true)) {
                    throw new BusinessException("{$label} 取值非法", 422, 2105);
                }
                break;

            case 'string':
            default:
                if (!is_string($value) && !is_numeric($value)) {
                    throw new BusinessException("{$label} 必须为字符串", 422, 2103);
                }
                break;
        }
    }

    /**
     * discrete 模式：根据 action+params 推导出要更新的状态片段 {state_key: value}
     */
    private static function statePatchFor(array $cap, string $action, array $params): array
    {
        $patch = [];
        foreach ($cap['controls'] as $c) {
            if (($c['command'] ?? null) !== $action) {
                continue;
            }
            $param = $c['param'] ?? null;
            $stateKey = $c['state_key'] ?? $param;
            if ($param !== null && array_key_exists($param, $params)) {
                $patch[$stateKey] = $params[$param];
            }
        }
        return $patch;
    }

    /**
     * 用各控制点的 default 拼出初始状态
     */
    private static function defaultState(array $cap): array
    {
        $state = [];
        foreach ($cap['controls'] as $c) {
            $key = $c['state_key'] ?? $c['param'] ?? null;
            if ($key !== null && array_key_exists('default', $c)) {
                $state[$key] = $c['default'];
            }
        }
        return $state;
    }

    /* ============================
     * 状态读写（Redis + DB 双写）
     * ============================ */

    /**
     * 读取设备当前状态（优先 Redis，回退 DB）
     *
     * @return array|null
     */
    public static function getState(int $deviceId): ?array
    {
        try {
            $raw = Redis::connection('default')->get(self::STATE_CACHE_KEY . $deviceId);
            if ($raw) {
                return json_decode($raw, true);
            }
        } catch (\Throwable $e) {
            Log::error("读取设备状态缓存失败: {$e->getMessage()}");
        }

        $row = DeviceState::find($deviceId);
        return $row?->state;
    }

    /**
     * 写入设备状态（Redis + DB 双写）
     *
     * @param int   $deviceId 设备 ID
     * @param array $state    完整状态
     * @param bool  $reported true=设备真实上报，false=指令产生的期望状态
     */
    public static function saveState(int $deviceId, array $state, bool $reported): void
    {
        try {
            Redis::connection('default')->setex(
                self::STATE_CACHE_KEY . $deviceId,
                self::STATE_TTL,
                json_encode($state, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            Log::error("写入设备状态缓存失败: {$e->getMessage()}");
        }

        $attrs = ['state' => $state, 'updated_at' => now()];
        if ($reported) {
            $attrs['reported_at'] = now();
        }

        DeviceState::updateOrCreate(['device_id' => $deviceId], $attrs);
    }

    /**
     * 推送状态变更到 WebSocket
     */
    private static function pushStateWs(Device $device, array $state): void
    {
        try {
            Redis::connection('pubsub')->publish('ws:broadcast', json_encode([
                'type'            => 'device_state',
                'device_id'       => $device->id,
                'device_uid'      => $device->device_uid,
                'device_location' => $device->location,
                'state'           => $state,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error("设备状态 WS 推送失败: {$e->getMessage()}");
        }
    }
}
