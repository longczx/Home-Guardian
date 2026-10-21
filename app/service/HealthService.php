<?php
namespace app\service;

use app\model\{Device, CommandLog, NotificationDelivery};
use support\Redis;
use Workerman\Timer;

class HealthService
{
    public const WORKERS = ['mqtt_subscriber', 'data_ingest', 'alert_engine', 'notification', 'crontab', 'websocket'];

    public static function watch(string $name, ?callable $ready = null): void
    {
        $beat = static function () use ($name, $ready) {
            try {
                Redis::connection('default')->setex('health:worker:' . $name, 30,
                    json_encode(['at'=>time(), 'ready'=>$ready ? (bool)$ready() : true]));
            } catch (\Throwable) { /* A missing heartbeat is reported as unhealthy. */ }
        };
        $beat();
        Timer::add(5, $beat);
    }

    public static function snapshot(): array
    {
        $checks = []; $queues = []; $metrics = [];
        try { (new Device)->getConnection()->select('SELECT 1'); $checks['database'] = true; }
        catch (\Throwable) { $checks['database'] = false; }
        try {
            $redis = Redis::connection('default'); $redis->ping(); $checks['redis'] = true;
            foreach (self::WORKERS as $name) {
                $beat = json_decode($redis->get('health:worker:' . $name) ?: '{}', true);
                $checks[$name] = !empty($beat['ready']) && time() - ($beat['at'] ?? 0) <= 30;
            }
            $queue = Redis::connection('queue');
            foreach (['data_ingest_queue', 'data_ingest_processing', 'data_ingest_deadletter', 'alert_stream', 'alert_processing', 'alert_deadletter', 'notify:queue', 'notify:queue:processing', 'notify:queue:deadletter', 'mqtt:command:send', 'mqtt:command:processing', 'mqtt:command:deadletter'] as $key) {
                $oldest = json_decode($queue->lIndex($key, -1) ?: '{}', true);
                $ts = $oldest['ts'] ?? null;
                $age = $ts && strtotime($ts) !== false ? max(0, time()-strtotime($ts)) : null;
                $queues[$key] = ['count'=>$queue->lLen($key), 'oldest_age_sec'=>$age];
                if (str_contains($key, 'deadletter')) $checks['deadletter:' . $key] = $queues[$key]['count'] === 0;
                elseif ($age !== null) $checks['queue:' . $key] = $age <= 120;
            }
        } catch (\Throwable) { $checks['redis'] = false; }
        if ($checks['database']) {
            try {
                $commands = CommandLog::where('sent_at', '>=', now()->subHour());
                $total = (clone $commands)->count();
                $timeouts = (clone $commands)->where('status', 'timeout')->count();
                $metrics = ['command_count_1h'=>$total, 'command_timeouts_1h'=>$timeouts, 'command_timeout_rate_1h'=>$total ? $timeouts/$total : 0,
                    'notification_failures_1h'=>NotificationDelivery::withoutGlobalScopes()->where('status', 'failed')->where('updated_at', '>=', now()->subHour())->count(),
                    'pending_notifications'=>NotificationDelivery::withoutGlobalScopes()->where('status', 'pending')->count(),
                    'pending_commands'=>(new Device)->getConnection()->table('command_outbox')->whereNull('dispatched_at')->count()];
                $oldestDelivery = NotificationDelivery::withoutGlobalScopes()->where('status', 'pending')->min('created_at');
                $oldestCommand = (new Device)->getConnection()->table('command_outbox')->whereNull('dispatched_at')->min('created_at');
                $metrics['oldest_notification_age_sec'] = $oldestDelivery ? max(0, time()-strtotime($oldestDelivery)) : 0;
                $metrics['oldest_command_age_sec'] = $oldestCommand ? max(0, time()-strtotime($oldestCommand)) : 0;
                $checks['notification_backlog'] = $metrics['oldest_notification_age_sec'] <= 300;
                $checks['command_backlog'] = $metrics['oldest_command_age_sec'] <= 60;
                $checks['notification_delivery'] = $metrics['notification_failures_1h'] === 0;
                $checks['command_timeouts'] = $timeouts === 0;
            } catch (\Throwable) { $checks['schema'] = false; }
        }
        $required = array_diff_key($checks, array_flip(['notification_delivery', 'command_timeouts']));
        foreach (array_keys($required) as $key) if (str_starts_with($key, 'deadletter:')) unset($required[$key]);
        return ['ready'=>!in_array(false, $required, true), 'status'=>in_array(false, $checks, true) ? 'degraded' : 'ok', 'checks'=>$checks, 'queues'=>$queues, 'metrics'=>$metrics, 'checked_at'=>now()->toIso8601String()];
    }
}
