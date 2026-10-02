<?php
namespace app\controller;

use app\model\{Device, CommandLog, DeviceState, TelemetryLog};
use app\service\MqttCommandService;
use support\Request;

class DeviceDiagnosticsController
{
    private function device(Request $request, int $id): ?Device
    {
        return Device::where('home_id', (int)$request->user->home_id)
            ->inLocations($request->user->locations ?? [])->find($id);
    }
    public function show(Request $request, int $id)
    {
        $device = $this->device($request, $id);
        if (!$device) return api_error('设备不存在或无权访问', 404, 2001);
        $gateway = $device->gateway_uid ? Device::where('home_id', $device->home_id)
            ->inLocations($request->user->locations ?? [])->where('device_uid', $device->gateway_uid)
            ->first(['id', 'name', 'is_online', 'last_seen']) : null;
        $commands = CommandLog::where('device_id', $id)->orderByDesc('id')->limit(10)
            ->get(['id', 'request_id', 'status', 'sent_at', 'replied_at', 'payload', 'reply']);
        $info = CommandLog::where('device_id', $id)->where('status', 'replied_ok')
            ->where('payload->action', 'get_info')->orderByDesc('id')->first();
        $reply = $info?->reply ?? [];
        $reply = is_array($reply['data'] ?? null) ? $reply['data'] : $reply;
        $telemetry = TelemetryLog::where('device_id', $id)->orderByDesc('ts')->first(['ts']);
        return api_success([
            'device' => $device->only(['id','name','device_uid','type','location','is_active','is_online','last_seen','firmware_version']),
            'gateway' => $gateway, 'last_telemetry_at' => $telemetry?->ts,
            'state' => DeviceState::find($id)?->only(['state','reported_at','updated_at']),
            'info' => array_intersect_key($reply, array_flip(['firmware','uptime_s','free_heap','wifi_rssi','sensor_count','module_count'])),
            'info_at' => $info?->replied_at,
            'commands' => $commands->map(fn ($c) => ['id'=>$c->id,'request_id'=>$c->request_id,
                'action'=>$c->payload['action'] ?? '', 'status'=>$c->status,'sent_at'=>$c->sent_at,'replied_at'=>$c->replied_at]),
        ]);
    }
    public function probe(Request $request, int $id)
    {
        $device = $this->device($request, $id);
        if (!$device) return api_error('设备不存在或无权访问', 404, 2001);
        if ($device->type !== 'gateway') return api_error('请在所属 ESP32 网关上读取诊断信息', 422, 2100);
        return api_success(MqttCommandService::sendCommand($id, ['action'=>'get_info','params'=>[]]));
    }
}
