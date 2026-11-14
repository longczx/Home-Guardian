<?php
namespace app\process;
use app\model\{Home, NotificationChannel};
use app\service\{BackupHealthService, NotificationOutboxService};
use support\Redis;
use Workerman\Timer;
class BackupHealthProcess {
    public function onWorkerStart(): void { Timer::add(300,fn()=> $this->check()); }
    public function check(): void {
        try {
            if (Home::withoutGlobalScopes()->count()!==1) return;
            $home=Home::withoutGlobalScopes()->first();
            $health=BackupHealthService::summary()['health'];
            if (!$health['needs_attention']) return;
            $channels=NotificationChannel::withoutGlobalScopes()->where('home_id',$home->id)->where('is_enabled',true)->pluck('id')->all();
            if (!$channels) return;
            // Stable daily task IDs let the durable outbox deduplicate notifications without losing retries.
            $signature=hash('sha256',$home->id.':'.gmdate('Y-m-d'));
            $key='backup:health:'.$home->id;
            if (!Redis::connection('default')->set($key,'1','EX',86400,'NX')) return;
            try { NotificationOutboxService::persist(['task_id'=>'backup-health-'.$signature,'channel_ids'=>$channels,'title'=>'备份需要关注',
                'content'=>implode('；',$health['warnings']).'。请到 App「备份与恢复」查看。','extra'=>['home_id'=>$home->id]]); }
            catch(\Throwable $e) { Redis::connection('default')->del($key); throw $e; }
        } catch(\Throwable $e) { \support\Log::warning('备份健康检查失败，请检查备份目录与服务状态'); }
    }
}
