<?php
/**
 * Home Guardian - 邀请码模型
 *
 * 对应 invite_codes 表。owner/admin 生成邀请码（一次性 + TTL），
 * 新用户凭码自助注册并自动加入家庭，取代后台手工建号。
 * 生成风格与配网码一致：8 位去易混淆字符集。
 */

namespace app\model;

use app\model\concern\BelongsToHome;
use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InviteCode extends Model
{
    use BelongsToHome;

    protected $table = 'invite_codes';

    protected $fillable = [
        'home_id',
        'code',
        'role',
        'created_by',
        'used_by',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'used_at'    => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    /**
     * 邀请码当前状态（used / expired / pending）
     */
    public function statusText(): string
    {
        if ($this->used_by) {
            return 'used';
        }
        if ($this->expires_at && $this->expires_at->getTimestamp() < time()) {
            return 'expired';
        }
        return 'pending';
    }

    /**
     * 是否仍可使用
     */
    public function isUsable(): bool
    {
        return $this->statusText() === 'pending';
    }
}
