<?php
namespace app\service;
use app\model\{Device, DeviceState, CommandLog};
class DevicePresentationService
{
    public static function describe(Device $device): array
    {
        $data = $device->toArray(); $state = DeviceState::find($device->id);
        $data['state'] = $state?->state ?? [];
        $data['reported_state'] = $state?->reported_state;
        $data['reported_at'] = $state?->reported_at?->toIso8601String();
        $last = CommandLog::where('device_id', $device->id)->orderByDesc('id')->limit(20)->get()->first(fn ($c) => ($c->payload['action'] ?? '') !== 'get_info');
        $data['last_command_status'] = $last?->status;
        $data['state_source'] = $device->type === 'ac' ? 'infrared_unverified' : ($state?->reported_state !== null ? 'device_report' : ($state ? 'desired' : 'unknown'));
        $data['state_note'] = match ($data['state_source']) {
            'infrared_unverified' => '显示期望状态；网关回执只表示红外发送，空调实际状态未验证',
            'device_report' => '设备上报与期望状态分别保存；请结合上报时间判断',
            'desired' => '仅有期望状态，尚无设备状态上报',
            default => '暂无设备状态',
        };
        $metrics = [];
        foreach ($device->metric_fields ?? [] as $field) {
            $reading = ObservationService::latest($device->id, $field['key']);
            if ($reading) $metrics[] = $reading + ['metric_key'=>$field['key'], 'fresh'=>ObservationService::fresh($reading, max(30, (int)($device->report_interval_sec ?? 300)*3))];
        }
        $data['latest_metrics'] = $metrics;
        $data['data_stale'] = count($metrics) === 0 || in_array(false, array_column($metrics, 'fresh'), true);
        return $data;
    }
}
