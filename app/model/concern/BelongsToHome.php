<?php
/**
 * Home Guardian - 家庭归属 trait
 *
 * 给带 home_id 的模型统一提供：
 *   1. HomeScope 全局作用域（查询自动限定当前家庭）
 *   2. 创建时自动回填 home_id（有请求上下文取用户家庭，否则默认家庭 1）
 *   3. home() 关联
 */

namespace app\model\concern;

use app\model\Home;
use app\model\scope\HomeScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToHome
{
    public static function bootBelongsToHome(): void
    {
        static::addGlobalScope(new HomeScope());

        static::creating(function ($model) {
            if (empty($model->home_id)) {
                $model->home_id = HomeScope::currentHomeId() ?? Home::DEFAULT_HOME_ID;
            }
        });
    }

    /**
     * 归属家庭
     */
    public function home(): BelongsTo
    {
        return $this->belongsTo(Home::class, 'home_id');
    }
}
