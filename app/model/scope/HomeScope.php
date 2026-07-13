<?php
/**
 * Home Guardian - 家庭作用域（Eloquent Global Scope）
 *
 * 多家庭隔离的地基：凡带 home_id 的模型统一挂载本作用域，
 * 查询自动追加 WHERE home_id = {当前请求用户的家庭}，
 * 一处定义处处生效，杜绝某个 controller 忘加过滤的隔离漏洞。
 *
 * 无家庭上下文时不加过滤，覆盖三类场景：
 *   - 后端常驻进程（alert_engine / mqtt_subscriber 等）：按设备自带的 home_id 处理
 *   - LayUI 平台后台（session 认证，无 JWT 用户）：平台运维视角看全部
 *   - 公开接口（设备自注册等）：由业务层显式指定 home_id
 *
 * 单家庭版全部数据 home_id = 1，作用域形同直通；未来多家庭版零改造。
 */

namespace app\model\scope;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class HomeScope implements Scope
{
    /**
     * 应用作用域：有家庭上下文时限定 home_id
     */
    public function apply(Builder $builder, Model $model): void
    {
        $homeId = self::currentHomeId();
        if ($homeId !== null) {
            $builder->where($model->getTable() . '.home_id', $homeId);
        }
    }

    /**
     * 当前请求上下文的家庭 ID
     *
     * 来源是 AuthMiddleware 从 JWT 解出后挂到 $request->user 的 home_id。
     * 非 HTTP 上下文（常驻进程）或未认证请求返回 null。
     */
    public static function currentHomeId(): ?int
    {
        try {
            $request = request();
        } catch (\Throwable) {
            return null;
        }

        $homeId = $request->user->home_id ?? null;

        return $homeId !== null ? (int)$homeId : null;
    }
}
