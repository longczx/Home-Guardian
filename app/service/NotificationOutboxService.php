<?php
namespace app\service;

use app\model\{NotificationChannel, NotificationDelivery};

/** Persist delivery intent on the caller's DB transaction, before acknowledging its event. */
class NotificationOutboxService
{
    public static function persist(array $task): void
    {
        foreach (array_unique($task['channel_ids']) as $id) {
            $channel = NotificationChannel::withoutGlobalScopes()->find((int)$id);
            if (!$channel || (isset($task['extra']['home_id']) && (int)$channel->home_id !== (int)$task['extra']['home_id'])) continue;
            NotificationDelivery::withoutGlobalScopes()->firstOrCreate([
                'delivery_key'=>hash('sha256', ($task['task_id'] ?? json_encode($task)) . ':' . $id),
            ], [
                'home_id'=>$channel->home_id, 'channel_id'=>$id,
                'title'=>mb_substr($task['title'], 0, 255), 'content'=>$task['content'],
                'extra'=>$task['extra'] ?? [], 'next_attempt_at'=>now(),
            ]);
        }
    }
}
