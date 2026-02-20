<?php
/**
 * Home Guardian - 通知渠道模型
 *
 * 对应 notification_channels 表，定义告警通知的发送渠道。
 * 每个渠道有独立的配置（config JSONB），支持多种类型：
 *   - email:       SMTP 邮件
 *   - webhook:     通用 HTTP Webhook
 *   - telegram:    Telegram Bot
 *   - wechat_work: 企业微信群机器人
 *   - dingtalk:    钉钉群机器人
 *
 * 告警规则通过 notification_channel_ids 字段关联一个或多个通知渠道。
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    protected $table = 'notification_channels';

    protected $fillable = [
        'name',
        'type',
        'config',
        'is_enabled',
        'created_by',
    ];

    protected $casts = [
        'config'     => 'array',     // JSONB → PHP 数组
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 渠道类型常量
     */
    const TYPE_EMAIL       = 'email';
    const TYPE_WEBHOOK     = 'webhook';
    const TYPE_TELEGRAM    = 'telegram';
    const TYPE_WECHAT_WORK = 'wechat_work';
    const TYPE_DINGTALK    = 'dingtalk';

    /**
     * 创建此渠道的用户
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 只查询启用的渠道
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * 按渠道类型筛选
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
