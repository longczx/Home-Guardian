<?php
namespace app\model;
use app\model\concern\BelongsToHome;
use support\Model;
class SceneRun extends Model {
    use BelongsToHome;
    protected $table = 'scene_runs'; public $timestamps = false; protected $guarded = ['id'];
    protected $casts = ['trigger_context'=>'array','locations'=>'array','action_results'=>'array','submission_finished'=>'boolean','started_at'=>'datetime','finished_at'=>'datetime'];
}
