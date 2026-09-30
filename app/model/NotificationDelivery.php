<?php

namespace app\model;

use app\model\concern\BelongsToHome;
use support\Model;

class NotificationDelivery extends Model
{
    use BelongsToHome;
    protected $table = 'notification_deliveries';
    protected $guarded = ['id'];
    protected $casts = ['extra' => 'array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
}
