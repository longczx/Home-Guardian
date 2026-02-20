<?php
/**
 * Home Guardian - Refresh Token 模型
 *
 * 对应 refresh_tokens 表，存储 JWT 刷新令牌的哈希值。
 * refresh_token 本身是随机字符串，数据库中只存哈希值（SHA-256），
 * 即使数据库被泄露，攻击者也无法还原有效的 token。
 *
 * 生命周期管理：
 *   - 登录时创建新记录
 *   - 刷新时删除旧记录、创建新记录（token 轮换）
 *   - 注销时删除对应记录
 *   - 全注销时删除该用户的所有记录
 *   - 过期记录由定时任务清理
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    /**
     * 只有 created_at，没有 updated_at（token 创建后不修改）
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'token_hash',
        'device_info',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Token 所属用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /* ============================
     * 查询作用域
     * ============================ */

    /**
     * 只查询未过期的 token
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * 已过期的 token（用于定时清理）
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }
}
