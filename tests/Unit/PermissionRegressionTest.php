<?php

namespace Tests\Unit;

use app\exception\BusinessException;
use app\model\Device;
use app\model\HomeUser;
use app\model\User;
use app\service\AuthService;
use app\service\DeviceService;
use app\service\HomeService;
use app\service\JwtService;
use app\service\RealtimeAccessService;
use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

class PermissionRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        DB::table('home_users')->delete();
        DB::table('refresh_tokens')->delete();
        DB::table('users')->where('id', 100)->delete();
        DB::table('users')->insert(['id' => 100, 'username' => 'regression',
            'password_hash' => password_hash('secret', PASSWORD_BCRYPT), 'is_active' => true]);
    }

    public function test_removed_member_cannot_regain_membership_by_logging_in(): void
    {
        HomeUser::create(['home_id' => 1, 'user_id' => 100, 'role' => 'member']);
        $session = AuthService::login('regression', 'secret');
        HomeService::removeMember(1, 'owner', 999, 100);
        self::assertNull(JwtService::verifyAccessToken($session['access_token']));
        self::assertSame(0, DB::table('refresh_tokens')->where('user_id', 100)->count());
        try { AuthService::login('regression', 'secret'); self::fail('Removed account logged in'); }
        catch (BusinessException $e) { self::assertStringContainsString('未加入家庭', $e->getMessage()); }
        self::assertSame(0, HomeUser::where('user_id', 100)->count());
    }

    public function test_session_revocation_is_persistent_and_inactive_account_fails_closed(): void
    {
        $token = JwtService::issueAccessToken(100, 'regression', [], [], []);
        AuthService::logoutAll(100);
        self::assertNull(JwtService::verifyAccessToken($token));
        self::assertSame(1, JwtService::getTokenVersion(100));
        $new = JwtService::issueAccessToken(100, 'regression', [], [], []);
        User::find(100)->update(['is_active' => false]);
        self::assertNull(JwtService::verifyAccessToken($new));
    }

    public function test_role_demotion_and_owner_transfer_revoke_all_affected_sessions(): void
    {
        DB::table('homes')->updateOrInsert(['id' => 1], ['name' => 'test home']);
        HomeUser::create(['home_id' => 1, 'user_id' => 100, 'role' => 'admin']);
        $admin = AuthService::login('regression', 'secret');
        HomeService::setMemberRole(1, 100, 'member');
        self::assertNull(JwtService::verifyAccessToken($admin['access_token']));
        DB::table('users')->updateOrInsert(['id' => 101], ['username' => 'old_owner', 'password_hash' => 'unused', 'is_active' => true, 'auth_version' => 0]);
        HomeUser::create(['home_id' => 1, 'user_id' => 101, 'role' => 'owner']);
        $owner = JwtService::issueAccessToken(101, 'old_owner', [], [], [], 1, 'owner');
        $member = AuthService::login('regression', 'secret');
        HomeService::setMemberRole(1, 100, 'owner');
        self::assertNull(JwtService::verifyAccessToken($owner));
        self::assertNull(JwtService::verifyAccessToken($member['access_token']));
        self::assertSame(1, HomeUser::where('home_id', 1)->where('role', 'owner')->count());
        self::assertSame('admin', HomeUser::where('user_id', 101)->value('role'));
    }

    public function test_device_write_drops_internal_fields_and_preserves_gateway_home(): void
    {
        $input = DeviceService::writeInput(['device_uid' => 'safe', 'name' => 'safe',
            'home_id' => 999, 'id' => 10, 'is_online' => true, 'last_seen' => 'now', 'mqtt_password_hash' => 'forged']);
        self::assertSame(1, $input['home_id']);
        foreach (['id', 'is_online', 'last_seen', 'mqtt_password_hash'] as $field) self::assertArrayNotHasKey($field, $input);
        $device = new Device(['home_id' => 1, 'device_uid' => 'fixed', 'type' => 'sensor']);
        self::assertArrayNotHasKey('device_uid', DeviceService::writeInput(['device_uid' => 'changed'], $device));
        self::assertArrayNotHasKey('home_id', DeviceService::writeInput(['home_id' => 999], $device));
    }

    public function test_malformed_capability_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        DeviceService::writeInput(['device_uid' => 'safe', 'name' => 'safe', 'capability' => '{broken']);
    }

    public function test_cross_home_gateway_is_rejected(): void
    {
        Device::where('device_uid', 'foreign-gateway')->delete();
        Device::create(['home_id' => 2, 'device_uid' => 'foreign-gateway', 'name' => 'foreign', 'type' => 'gateway']);
        $this->expectException(BusinessException::class);
        DeviceService::writeInput(['device_uid' => 'safe', 'name' => 'safe', 'gateway_uid' => 'foreign-gateway']);
    }

    public function test_realtime_requires_home_location_and_event_permission(): void
    {
        $payload = (object)['sub' => 100, 'home_id' => 1, 'locations' => ['kitchen'],
            'permissions' => (object)['devices' => ['view'], 'alerts' => []]];
        $device = new Device(['home_id' => 1, 'location' => 'kitchen']);
        self::assertTrue(RealtimeAccessService::canReceive($payload, ['type' => 'telemetry', 'device_id' => 1], $device));
        self::assertFalse(RealtimeAccessService::canReceive($payload, ['type' => 'alert', 'device_id' => 1], $device));
        $device->location = 'bedroom';
        self::assertFalse(RealtimeAccessService::canReceive($payload, ['type' => 'command_reply', 'device_id' => 1], $device));
        $device->location = 'kitchen'; $device->home_id = 2;
        self::assertFalse(RealtimeAccessService::canReceive($payload, ['type' => 'device_state', 'device_id' => 1], $device));
        self::assertFalse(RealtimeAccessService::canReceive($payload, ['type' => 'notification']));
    }
}
