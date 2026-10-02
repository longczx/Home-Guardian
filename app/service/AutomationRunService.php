<?php
namespace app\service;

use app\model\{AutomationRun, CommandLog, NotificationDelivery};

class AutomationRunService
{
    // Queue submission is not completion: derive the outcome from durable ACK/delivery records.
    public static function refresh(AutomationRun $run): AutomationRun
    {
        if ($run->status !== 'running') return $run;
        if (!$run->submission_finished) {
            if ($run->started_at->lt(now()->subDay())) $run->update(['status'=>'failed', 'finished_at'=>now()]);
            return $run;
        }
        $results = $run->action_results ?? [];
        if (!$results) {
            $run->update(['status'=>'failed', 'finished_at'=>now()]);
            return $run;
        }
        foreach ($results as &$result) {
            if (($result['status'] ?? '') !== 'pending') continue;
            if (isset($result['command_id'])) {
                $command = CommandLog::find($result['command_id']);
                $result['command_status'] = $command?->status;
                if ($command?->status === CommandLog::STATUS_REPLIED_OK) $result['status'] = 'success';
                elseif (!$command || in_array($command->status, ['replied_error', 'timeout'], true)) {
                    $result['status'] = 'failed';
                    $result['error'] = $command?->status === 'timeout' ? '设备未在期限内确认' : '设备执行失败或指令记录已删除';
                }
            } elseif (isset($result['task_id'])) {
                $deliveries = NotificationDelivery::withoutGlobalScopes()->where('home_id', $run->home_id)
                    ->whereIn('delivery_key', array_map(fn ($id) => hash('sha256', $result['task_id'] . ':' . $id), $result['channel_ids']))->get();
                $states = $deliveries->pluck('status')->all();
                $result['deliveries'] = $deliveries->map(fn ($d) => ['channel_id' => $d->channel_id,
                    'status' => $d->status, 'attempts' => $d->attempts])->all();
                if (count($states) === count($result['channel_ids']) && !array_intersect($states, ['pending', 'retrying', 'sending'])) {
                    $result['status'] = count(array_filter($states, fn ($s) => $s === 'sent')) === count($states) ? 'success' : 'failed';
                    if ($result['status'] === 'failed') $result['error'] = '部分通知渠道发送失败或未启用';
                }
            }
            if ($result['status'] === 'pending' && $run->started_at->lt(now()->subDay())) {
                $result['status'] = 'failed'; $result['error'] = '超过一天仍未获得执行结果';
            }
        }
        unset($result);
        $states = array_column($results, 'status');
        $pending = in_array('pending', $states, true);
        $failed = in_array('failed', $states, true);
        $run->update(['action_results' => $results,
            'status' => $pending ? 'running' : ($failed ? (in_array('success', $states, true) ? 'partial_failed' : 'failed') : 'success'),
            'finished_at' => $pending ? null : now()]);
        return $run;
    }
}
