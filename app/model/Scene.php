<?php
namespace app\model;
use app\model\concern\BelongsToHome;
use support\Model;
class Scene extends Model {
    use BelongsToHome;
    protected $table = 'scenes'; protected $guarded = ['id']; protected $casts = ['actions'=>'array'];
}
