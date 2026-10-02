<?php
namespace app\service;
use app\model\{Device, CommandLog};
use app\exception\BusinessException;
use support\Redis;
class DeviceControlService
{
    // One control submission at a time; Redis lock spans API and worker processes.
    public static function send(Device $device, string $action, array $params, bool $manual = false): CommandLog
    {
        $redis = Redis::connection('default');
        $key = "device:control:lock:{$device->id}"; $token = bin2hex(random_bytes(16));
        if (!$redis->set($key, $token, 'EX', 30, 'NX')) throw new BusinessException('该设备正在处理另一条控制请求，请稍后再试', 409, 2110);
        try {
            $device->refresh();
            if (!$manual && $device->manual_override_until?->isFuture()) throw new BusinessException('人工接管期间暂停自动化，至 ' . $device->manual_override_until->toIso8601String(), 409, 2111);
            $commands = CommandLog::where('device_id', $device->id)->orderByDesc('id')->limit(20)->get();
            foreach ($commands as $command) {
                if (($command->payload['action'] ?? '') === 'get_info') continue;
                if (in_array($command->status, ['queued','sent','delivered'], true)) throw new BusinessException('上一条控制指令仍在等待回执', 409, 2112);
            }
            $last = $commands->first(fn ($c) => ($c->payload['action'] ?? '') !== 'get_info');
            // Protect IR appliances from frequent automated commands; manual control is allowed after ACK.
            if (!$manual && $device->type === 'ac' && $last?->sent_at->gt(now()->subSeconds(180))) throw new BusinessException('空调自动控制保护间隔为 180 秒', 409, 2113);
            // Set the hold before enqueueing so automation cannot race a manual submission.
            $previous = $device->manual_override_until;
            if ($manual) $device->update(['manual_override_until'=>now()->addMinutes(30)]);
            try { return ActuatorService::applyCommand($device, $action, $params); }
            catch (\Throwable $e) { if ($manual) $device->update(['manual_override_until'=>$previous]); throw $e; }
        } finally {
            $redis->eval("if redis.call('get',KEYS[1]) == ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end", 1, $key, $token);
        }
    }
}
