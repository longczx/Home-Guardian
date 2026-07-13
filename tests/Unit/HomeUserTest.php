<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\model\HomeUser;
use app\model\InviteCode;

/**
 * 家庭角色体系测试（纯内存逻辑，不依赖数据库 / Redis）
 *
 * 覆盖：角色能力判断、角色→权限矩阵映射、权限合并、邀请码状态。
 */
class HomeUserTest extends TestCase
{
    /* ============ 角色能力 ============ */

    public function test_can_manage(): void
    {
        $this->assertTrue(HomeUser::canManage(HomeUser::ROLE_OWNER));
        $this->assertTrue(HomeUser::canManage(HomeUser::ROLE_ADMIN));
        $this->assertFalse(HomeUser::canManage(HomeUser::ROLE_MEMBER));
        $this->assertFalse(HomeUser::canManage(null));
        $this->assertFalse(HomeUser::canManage('bogus'));
    }

    public function test_role_label(): void
    {
        $this->assertSame('户主', HomeUser::roleLabel(HomeUser::ROLE_OWNER));
        $this->assertSame('管理员', HomeUser::roleLabel(HomeUser::ROLE_ADMIN));
        $this->assertSame('成员', HomeUser::roleLabel(HomeUser::ROLE_MEMBER));
        $this->assertSame('成员', HomeUser::roleLabel(null));
    }

    public function test_invitable_roles_exclude_owner(): void
    {
        $this->assertNotContains(HomeUser::ROLE_OWNER, HomeUser::INVITABLE_ROLES);
        $this->assertContains(HomeUser::ROLE_ADMIN, HomeUser::INVITABLE_ROLES);
        $this->assertContains(HomeUser::ROLE_MEMBER, HomeUser::INVITABLE_ROLES);
    }

    /* ============ 权限矩阵映射 ============ */

    public function test_member_permissions_are_read_and_control_only(): void
    {
        $perms = HomeUser::permissionsFor(HomeUser::ROLE_MEMBER);

        // member 可看设备、控设备、看告警并确认
        $this->assertSame(['view'], $perms['devices']);
        $this->assertContains('send', $perms['commands']);
        $this->assertContains('ack', $perms['alerts']);

        // member 不能管理规则/设备
        $this->assertNotContains('create', $perms['devices']);
        $this->assertNotContains('edit', $perms['alerts']);
        $this->assertNotContains('delete', $perms['alerts']);
    }

    public function test_admin_and_owner_permissions_include_management(): void
    {
        foreach ([HomeUser::ROLE_ADMIN, HomeUser::ROLE_OWNER] as $role) {
            $perms = HomeUser::permissionsFor($role);
            $this->assertContains('create', $perms['devices'], $role);
            $this->assertContains('delete', $perms['devices'], $role);
            $this->assertContains('edit', $perms['alerts'], $role);
        }
    }

    public function test_unknown_role_falls_back_to_member(): void
    {
        $this->assertSame(
            HomeUser::permissionsFor(HomeUser::ROLE_MEMBER),
            HomeUser::permissionsFor('bogus')
        );
    }

    /* ============ 权限合并 ============ */

    public function test_merge_permissions_takes_union(): void
    {
        $merged = HomeUser::mergePermissions(
            ['devices' => ['view'], 'users' => ['view']],
            ['devices' => ['view', 'edit']]
        );

        sort($merged['devices']);
        $this->assertSame(['edit', 'view'], $merged['devices']);
        $this->assertSame(['view'], $merged['users']);
    }

    public function test_merge_permissions_admin_passthrough(): void
    {
        $this->assertSame(['admin' => true], HomeUser::mergePermissions(['admin' => true], ['devices' => ['view']]));
        $this->assertSame(['admin' => true], HomeUser::mergePermissions(['devices' => ['view']], ['admin' => true]));
    }

    /* ============ 邀请码状态 ============ */

    public function test_invite_code_status(): void
    {
        $pending = new InviteCode();
        $pending->expires_at = date('Y-m-d H:i:s', time() + 3600);
        $this->assertSame('pending', $pending->statusText());
        $this->assertTrue($pending->isUsable());

        $expired = new InviteCode();
        $expired->expires_at = date('Y-m-d H:i:s', time() - 3600);
        $this->assertSame('expired', $expired->statusText());
        $this->assertFalse($expired->isUsable());

        $used = new InviteCode();
        $used->expires_at = date('Y-m-d H:i:s', time() + 3600);
        $used->used_by = 2;
        $this->assertSame('used', $used->statusText());
        $this->assertFalse($used->isUsable());
    }
}
