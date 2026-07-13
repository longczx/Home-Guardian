<?php
/**
 * Home Guardian - 家庭成员关系模型
 *
 * 对应 home_users 表（user ⇄ home 多对多中间表，带家庭内角色）。
 * 家庭内三级角色（与平台侧 roles/permissions 体系互不纠缠）：
 *   - owner:  户主（每家庭唯一），一切权限 + 改角色/转让
 *   - admin:  可管设备/规则/渠道/自动化、邀人、移除 member
 *   - member: 日常使用（看数据、控设备、确认告警）
 */

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeUser extends Model
{
    protected $table = 'home_users';

    public $timestamps = false;

    protected $fillable = [
        'home_id',
        'user_id',
        'role',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    /** 家庭内角色 */
    public const ROLE_OWNER  = 'owner';
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_MEMBER = 'member';

    public const VALID_ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER];

    /** 可通过邀请码授予的角色（owner 只能转让，不能邀出来） */
    public const INVITABLE_ROLES = [self::ROLE_ADMIN, self::ROLE_MEMBER];

    public function home(): BelongsTo
    {
        return $this->belongsTo(Home::class, 'home_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 角色是否具备家庭管理能力（设备/规则/渠道/自动化/邀人）
     */
    public static function canManage(?string $role): bool
    {
        return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    /**
     * 家庭角色 → API 权限集合映射
     *
     * 登录时并入 JWT 的 permissions，现有 PermissionMiddleware 即可
     * 自然执行权限矩阵（见 docs/design/home-user-system.md §4）。
     * users.* 不在此列——成员管理走 home_role 直接校验，平台用户管理仍归平台角色。
     */
    public static function permissionsFor(?string $role): array
    {
        $member = [
            'devices'    => ['view'],
            'commands'   => ['send'],
            'alerts'     => ['view', 'ack'],
            'dashboards' => ['view', 'create', 'edit', 'delete'],
        ];

        if (!self::canManage($role)) {
            return $member;
        }

        // owner / admin：家庭内全量管理能力
        return [
            'devices'    => ['view', 'create', 'edit', 'delete'],
            'commands'   => ['send'],
            'alerts'     => ['view', 'ack', 'create', 'edit', 'delete'],
            'dashboards' => ['view', 'create', 'edit', 'delete'],
        ];
    }

    /**
     * 合并两份 {resource: [actions]} 权限集合（并集；admin=true 直通保留）
     */
    public static function mergePermissions(array $a, array $b): array
    {
        if (!empty($a['admin']) || !empty($b['admin'])) {
            return ['admin' => true];
        }

        $merged = $a;
        foreach ($b as $resource => $actions) {
            $merged[$resource] = array_values(array_unique(
                array_merge((array)($merged[$resource] ?? []), (array)$actions)
            ));
        }
        return $merged;
    }

    /**
     * 角色中文标签
     */
    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            self::ROLE_OWNER => '户主',
            self::ROLE_ADMIN => '管理员',
            default          => '成员',
        };
    }
}
