<?php
namespace app\service;

use app\model\{Device, CommandLog};
use support\{Redis, Log};

class CommandOutboxService
{
    public static function dispatch(?string $requestId = null): void
    {
        $db = (new Device)->getConnection();
        $query = $db->table('command_outbox')->whereNull('dispatched_at');
        if ($requestId) $query->where('request_id', $requestId);
        foreach ($query->orderBy('created_at')->limit(100)->get() as $row) {
            $command = CommandLog::where('request_id', $row->request_id)->first();
            // Never replay an expired physical action after an outage.
            if ($command && in_array($command->status, ['queued', 'sent', 'delivered'], true) && $command->sent_at->gte(now()->subSeconds(60))) {
                Redis::connection('queue')->lPush('mqtt:command:send', $row->message);
            } elseif ($command) {
                CommandLog::where('id', $command->id)->pending()->update(['status'=>'timeout', 'replied_at'=>now()]);
            }
            // A crash between LPUSH and this update may duplicate the same request_id;
            // the device's durable ACK cache protects physical execution.
            $db->table('command_outbox')->where('request_id', $row->request_id)->update(['dispatched_at'=>now()]);
        }
    }

    public static function tryDispatch(?string $requestId = null): void
    {
        try { self::dispatch($requestId); }
        catch (\Throwable $e) { Log::error('指令已持久化，等待重新投递: ' . $e->getMessage()); }
    }
}
