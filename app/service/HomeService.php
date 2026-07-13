<?php
/**
 * Home Guardian - 家庭服务
 *
 * 家庭治理相关的核心业务：
 *   - 邀请码生成/作废（owner/admin）
 *   - 凭邀请码自助注册入家庭（取代后台手工建号）
 *   - 成员列表 / 角色调整 / 移除 / owner 转让
 *
 * 单家庭版所有操作都发生在默认家庭内；接口均以 home_id 为参数，
 * 未来多家庭版直接复用。
 */

namespace app\service;

use app\exception\BusinessException;
use app\model\Home;
use app\model\HomeUser;
use app\model\InviteCode;
use app\model\User;

class HomeService
{
    /** 邀请码字符集（与配网码一致，去除易混淆的 0/O/1/I） */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** 邀请码长度 */
    private const CODE_LENGTH = 8;

    /** 邀请码默认有效期（秒）：72 小时 */
    private const DEFAULT_TTL = 259200;

    /* ============================
     * 邀请码
     * ============================ */

    /**
     * 生成邀请码
     *
     * @param int         $homeId    家庭
     * @param int         $createdBy 生成者
     * @param string      $role      注册后获得的角色（admin/member）
     * @param int|null    $ttl       有效期秒数，空取默认 72h
     */
    public static function createInvite(int $homeId, int $createdBy, string $role = HomeUser::ROLE_MEMBER, ?int $ttl = null): InviteCode
    {
        if (!in_array($role, HomeUser::INVITABLE_ROLES, true)) {
            throw new BusinessException('邀请角色只能是 ' . implode(' / ', HomeUser::INVITABLE_ROLES), 422, 8001);
        }

        $ttl = $ttl && $ttl > 0 ? min($ttl, 30 * 86400) : self::DEFAULT_TTL;

        return InviteCode::create([
            'home_id'    => $homeId,
            'code'       => self::generateUniqueCode(),
            'role'       => $role,
            'created_by' => $createdBy,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);
    }

    /**
     * 生成不重复的邀请码
     */
    private static function generateUniqueCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $max)];
            }
            if (!InviteCode::withoutGlobalScopes()->where('code', $code)->exists()) {
                return $code;
            }
        }
        throw new BusinessException('邀请码生成失败，请重试', 500, 8002);
    }

    /* ============================
     * 邀请注册
     * ============================ */

    /**
     * 凭邀请码注册新用户并加入家庭
     *
     * 公开接口调用（无 JWT 上下文），邀请码查询绕过 HomeScope。
     * 自托管场景不强制邮箱。
     *
     * @param  array $data {invite_code, username, password, full_name?, email?}
     * @return User  新创建的用户
     */
    public static function register(array $data): User
    {
        $code     = strtoupper(trim((string)($data['invite_code'] ?? '')));
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($code === '') {
            throw new BusinessException('缺少邀请码', 422, 8003);
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            throw new BusinessException('用户名需为 3-32 位字母/数字/下划线', 422, 8004);
        }
        if (mb_strlen($password) < 6) {
            throw new BusinessException('密码长度不能少于 6 位', 422, 8005);
        }

        $invite = InviteCode::withoutGlobalScopes()->where('code', $code)->first();
        if (!$invite) {
            throw new BusinessException('邀请码无效', 422, 8006);
        }
        if (!$invite->isUsable()) {
            throw new BusinessException('邀请码已被使用或已过期', 410, 8007);
        }

        if (User::where('username', $username)->exists()) {
            throw new BusinessException('用户名已被占用', 422, 8008);
        }

        return (new User)->getConnection()->transaction(function () use ($invite, $data, $username, $password) {
            $user = new User();
            $user->username  = $username;
            $user->full_name = trim((string)($data['full_name'] ?? '')) ?: null;
            $user->email     = trim((string)($data['email'] ?? '')) ?: null;
            $user->is_active = true;
            $user->setPassword($password);
            $user->save();

            HomeUser::create([
                'home_id' => $invite->home_id,
                'user_id' => $user->id,
                'role'    => $invite->role,
            ]);

            $invite->update([
                'used_by' => $user->id,
                'used_at' => date('Y-m-d H:i:s'),
            ]);

            return $user;
        });
    }

    /* ============================
     * 成员管理
     * ============================ */

    /**
     * 家庭成员列表（含用户基本信息）
     */
    public static function members(int $homeId): array
    {
        return HomeUser::with('user:id,username,full_name,email,is_active,created_at')
            ->where('home_id', $homeId)
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('joined_at')
            ->get()
            ->toArray();
    }

    /**
     * 修改成员角色（仅 owner）
     *
     * 目标角色为 owner 时执行转让：对方升 owner，当前 owner 降 admin。
     */
    public static function updateMemberRole(int $homeId, int $operatorId, int $targetUserId, string $role): void
    {
        if (!in_array($role, HomeUser::VALID_ROLES, true)) {
            throw new BusinessException('角色非法', 422, 8009);
        }
        if ($operatorId === $targetUserId) {
            throw new BusinessException('不能修改自己的角色', 422, 8010);
        }

        $target = HomeUser::where('home_id', $homeId)->where('user_id', $targetUserId)->first();
        if (!$target) {
            throw new BusinessException('该用户不是家庭成员', 404, 8011);
        }
        if ($target->role === HomeUser::ROLE_OWNER) {
            throw new BusinessException('不能修改户主的角色', 422, 8012);
        }

        self::applyRoleChange($homeId, $target, $role);
    }

    /**
     * 平台后台直接设置成员角色（无操作者限制，仅保护 owner 唯一性）
     */
    public static function setMemberRole(int $homeId, int $targetUserId, string $role): void
    {
        if (!in_array($role, HomeUser::VALID_ROLES, true)) {
            throw new BusinessException('角色非法', 422, 8009);
        }

        $target = HomeUser::where('home_id', $homeId)->where('user_id', $targetUserId)->first();
        if (!$target) {
            throw new BusinessException('该用户不是家庭成员', 404, 8011);
        }
        if ($target->role === HomeUser::ROLE_OWNER) {
            throw new BusinessException('不能修改户主的角色（请先转让）', 422, 8012);
        }

        self::applyRoleChange($homeId, $target, $role);
    }

    /**
     * 应用角色变更；目标是 owner 时把现任 owner 降为 admin（保证唯一）
     */
    private static function applyRoleChange(int $homeId, HomeUser $target, string $role): void
    {
        (new HomeUser)->getConnection()->transaction(function () use ($homeId, $target, $role) {
            if ($role === HomeUser::ROLE_OWNER) {
                HomeUser::where('home_id', $homeId)
                    ->where('role', HomeUser::ROLE_OWNER)
                    ->update(['role' => HomeUser::ROLE_ADMIN]);
            }
            $target->update(['role' => $role]);
        });
    }

    /**
     * 移除成员
     *
     * owner 可移除任何非 owner 成员；admin 只能移除 member。
     * 只删家庭成员关系，不删用户账号（平台后台可兜底处理账号）。
     */
    public static function removeMember(int $homeId, string $operatorRole, int $operatorId, int $targetUserId): void
    {
        if ($operatorId === $targetUserId) {
            throw new BusinessException('不能移除自己', 422, 8013);
        }

        $target = HomeUser::where('home_id', $homeId)->where('user_id', $targetUserId)->first();
        if (!$target) {
            throw new BusinessException('该用户不是家庭成员', 404, 8011);
        }
        if ($target->role === HomeUser::ROLE_OWNER) {
            throw new BusinessException('不能移除户主', 422, 8014);
        }
        if ($operatorRole !== HomeUser::ROLE_OWNER && $target->role !== HomeUser::ROLE_MEMBER) {
            throw new BusinessException('管理员只能移除普通成员', 403, 8015);
        }

        $target->delete();

        // 被移除者立即失去访问权：吊销其所有会话
        AuthService::logoutAll($targetUserId);
    }

    /**
     * 确保用户在家庭内有成员关系（后台手工建号等旁路入口用）
     */
    public static function ensureMembership(int $userId, string $role = HomeUser::ROLE_MEMBER, int $homeId = Home::DEFAULT_HOME_ID): void
    {
        if (!HomeUser::where('home_id', $homeId)->where('user_id', $userId)->exists()) {
            HomeUser::create([
                'home_id' => $homeId,
                'user_id' => $userId,
                'role'    => in_array($role, HomeUser::VALID_ROLES, true) ? $role : HomeUser::ROLE_MEMBER,
            ]);
        }
    }
}
