<?php
namespace app\service;
use app\model\TelemetryLog;
use support\Redis;
class ObservationService
{
    public static function latest(int $deviceId, string $metric): ?array
    {
        try {
            $cached = json_decode(Redis::connection('default')->get("device:observations:{$deviceId}") ?: '{}', true);
            if (isset($cached[$metric]['ts'])) return $cached[$metric];
        } catch (\Throwable) {}
        $row = TelemetryLog::where('device_id', $deviceId)->where('metric_key', $metric)->orderByDesc('ts')->first();
        return $row ? ['value'=>$row->value, 'ts'=>$row->ts->toIso8601String()] : null;
    }
    public static function fresh(?array $reading, int $seconds): bool
    {
        if (!$reading || !isset($reading['ts'])) return false;
        try { $age = now()->timestamp - \Illuminate\Support\Carbon::parse($reading['ts'])->timestamp; }
        catch (\Throwable) { return false; }
        return $age >= 0 && $age <= $seconds;
    }
    public static function compare(mixed $value, string $operator, mixed $target): bool
    {
        if (is_bool($value)) $value = (int)$value;
        if (is_bool($target)) $target = (int)$target;
        if (!is_numeric($value) || !is_numeric($target)) return false;
        return match ($operator) {
            'GREATER_THAN' => (float)$value > (float)$target,
            'LESS_THAN' => (float)$value < (float)$target,
            'EQUALS' => abs((float)$value - (float)$target) < 0.0001,
            'NOT_EQUALS' => abs((float)$value - (float)$target) >= 0.0001,
            default => false,
        };
    }
}
