<?php
namespace app\service;
class BackupHealthService {
    public static function summary(?string $directory = null): array {
        $rows=[];
        foreach(glob(($directory ?? runtime_path('backup-catalog')).'/*.json') ?: [] as $file) {
            $row=json_decode(file_get_contents($file),true);
            if (!is_array($row) || !preg_match('/^\d{8}T\d{6}Z-[a-f0-9]{8}$/',$row['id'] ?? '') || !is_string($row['created_at'] ?? null) || strtotime($row['created_at']) === false) continue;
            $rows[]=array_intersect_key($row,array_flip(['id','created_at','status','size_bytes','commit','error','integrity_verified_at','database_restore_verified_at','redis_restore_verified_at','external_copy_at','external_copy_label','drill_failed_at','drill_error']));
        }
        usort($rows,fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
        $last=null; $failures=0;
        foreach($rows as $row) { if (($row['status'] ?? '')==='success') { $last=$row; break; } if (($row['status'] ?? '')==='failed') $failures++; }
        $age=$last ? max(0,(time()-strtotime($last['created_at']))/3600) : null;
        $warnings=[];
        if (!$last) $warnings[]='尚无成功备份'; elseif ($age>36) $warnings[]='最近成功备份已超过 36 小时';
        if ($failures) $warnings[]='最近连续失败 '.$failures.' 次';
        if (($rows[0]['status'] ?? '')==='running' && time()-strtotime($rows[0]['created_at'])>7200) $warnings[]='备份任务超过 2 小时未完成';
        if ($last && empty($last['database_restore_verified_at'])) $warnings[]='最新备份尚未完成数据库恢复演练';
        if ($last && !empty($last['database_restore_verified_at']) && empty($last['redis_restore_verified_at'])) $warnings[]='最新备份尚未完成 Redis 恢复演练';
        if ($last && !empty($last['drill_error'])) $warnings[]='最新备份最近一次恢复演练失败';
        if ($last && empty($last['external_copy_at'])) $warnings[]='最新备份尚未登记异机副本';
        return ['items'=>array_slice($rows,0,100),'health'=>['last_success'=>$last,'age_hours'=>$age===null?null:round($age,1),
            'consecutive_failures'=>$failures,'warnings'=>$warnings,'stale'=>$age===null || $age>36,
            'needs_attention'=>(bool)$warnings,'checked_at'=>gmdate('c')]];
    }
}
