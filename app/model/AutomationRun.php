<?php
namespace app\model;

use app\model\concern\BelongsToHome;
use support\Model;

class AutomationRun extends Model
{
    use BelongsToHome;
    protected $table = 'automation_runs';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['trigger_context' => 'array', 'locations' => 'array',
        'action_results' => 'array', 'submission_finished'=>'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
