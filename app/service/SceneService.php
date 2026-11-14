<?php
namespace app\service;
use app\model\{Scene, SceneRun, Device, Home};
use app\exception\BusinessException;
class SceneService
{
    public static function validate(int $homeId, array $actions): void {
        if (!$actions || !array_is_list($actions) || count($actions) > 16) throw new BusinessException('场景需有 1–16 个设备动作', 422, 4002);
        $ids = [];
        foreach ($actions as $action) {
            if (!is_array($action) || !is_int($action['device_id'] ?? null) || !is_string($action['action'] ?? null)
                || !is_array($action['params'] ?? null) || GatewayModuleService::supports($action['action'])) throw new BusinessException('场景仅支持设备控制动作', 422, 4002);
            $device = Device::withoutGlobalScopes()->where('home_id', $homeId)->find($action['device_id']);
            if (!$device || !$device->capability || $device->type === 'gateway') throw new BusinessException('目标设备不存在或未声明控制能力', 422, 4002);
            if (in_array($device->id, $ids, true)) throw new BusinessException('同一设备请合并为一个动作', 422, 4002);
            ActuatorService::validate($device->capability, $action['action'], $action['params']); $ids[] = $device->id;
        }
    }
    public static function execute(int $homeId, int $sceneId, string $requestKey, ?string $mode = null): SceneRun {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $requestKey)) throw new BusinessException('场景执行标识无效', 422, 4002);
        $db = (new Scene)->getConnection();
        [$run, $created, $actions] = $db->transaction(function () use ($db, $homeId, $sceneId, $requestKey, $mode) {
            $home = Home::where('id', $homeId)->lockForUpdate()->first();
            if (!$home) throw new BusinessException('家庭不存在', 404, 8020);
            $existing = SceneRun::withoutGlobalScopes()->where('home_id', $homeId)->where('request_key', $requestKey)->first();
            if ($existing) {
                if ((int)$existing->scene_id !== $sceneId || ($existing->trigger_context['mode'] ?? null) !== $mode) throw new BusinessException('执行标识已用于其他场景，请刷新后重试', 409, 4002);
                return [$existing, false, []];
            }
            $scene = Scene::withoutGlobalScopes()->where('home_id', $homeId)->find($sceneId);
            if (!$scene || ($mode && $scene->enter_mode !== $mode)) throw new BusinessException('场景不存在或模式绑定已变化，请刷新', 409, 4001);
            self::validate($homeId, $scene->actions);
            $locations = Device::withoutGlobalScopes()->where('home_id', $homeId)->whereIn('id', array_column($scene->actions, 'device_id'))->pluck('location')->filter()->unique()->values()->all();
            if ($mode && $home->mode !== $mode) $home->update(['mode'=>$mode,'mode_changed_at'=>now()]);
            $run = SceneRun::create(['home_id'=>$homeId,'scene_id'=>$sceneId,'scene_name'=>$scene->name,'request_key'=>$requestKey,
                'trigger_type'=>$mode ? 'mode_entry' : 'manual','trigger_context'=>['mode'=>$mode,'actions'=>$scene->actions],
                'locations'=>$locations,'action_results'=>[],'status'=>'running','started_at'=>now()]);
            return [$run, true, $scene->actions];
        });
        if (!$created) return AutomationRunService::refresh($run);
        foreach ($actions as $index => $action) {
            $command = null;
            try {
                $device = Device::withoutGlobalScopes()->where('home_id', $homeId)->find($action['device_id']);
                if (!$device) throw new BusinessException('设备已移除', 404, 2001);
                // Keep the control lock through commit, with command/outbox/result link in one transaction.
                $command = DeviceControlService::send($device, $action['action'], $action['params'], true, false, function ($log) use ($run, $device, $action, $index) {
                    $results = $run->action_results;
                    $results[] = ['index'=>$index,'type'=>'device_command','device_id'=>$device->id,'device_name'=>$device->name,
                        'action'=>$action['action'],'infrared_unverified'=>$device->type === 'ac','status'=>'pending',
                        'command_id'=>$log->id,'request_id'=>$log->request_id];
                    $run->update(['action_results'=>$results]);
                });
                CommandOutboxService::tryDispatch($command->request_id);
            } catch (\Throwable $e) {
                // A committed outbox stays pending even if immediate queue dispatch fails.
                $run->refresh(); $results = $run->action_results;
                if (array_filter($results, fn ($result) => ($result['index'] ?? null) === $index && isset($result['command_id']))) continue;
                $results[] = ['index'=>$index,'type'=>'device_command','device_id'=>$action['device_id'],'action'=>$action['action'],
                    'status'=>'failed','error'=>$e instanceof BusinessException ? $e->getMessage() : '提交失败，请检查服务日志'];
                $run->update(['action_results'=>$results]);
            }
        }
        $run->update(['submission_finished'=>true]);
        return AutomationRunService::refresh($run);
    }
}
