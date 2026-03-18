<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\service\DeviceService;
use app\model\Device;

class DeviceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // 清空 devices 表
        Device::query()->delete();
    }

    /* =============================================
     * MQTT 超级用户认证
     * ============================================= */

    public function test_super_user_auth_success(): void
    {
        $result = DeviceService::verifyMqttCredentials('hg_test_super', 'test_super_pass_123');
        $this->assertTrue($result);
    }

    public function test_super_user_auth_wrong_password(): void
    {
        $result = DeviceService::verifyMqttCredentials('hg_test_super', 'wrong_password');
        $this->assertFalse($result);
    }

    public function test_super_user_auth_empty_password(): void
    {
        $result = DeviceService::verifyMqttCredentials('hg_test_super', '');
        $this->assertFalse($result);
    }

    /* =============================================
     * MQTT 设备认证
     * ============================================= */

    public function test_device_auth_success(): void
    {
        Device::create([
            'device_uid'         => 'sensor-001',
            'name'               => '温湿度传感器',
            'mqtt_username'      => 'sensor-001',
            'mqtt_password_hash' => password_hash('device_pass', PASSWORD_BCRYPT),
        ]);

        $result = DeviceService::verifyMqttCredentials('sensor-001', 'device_pass');
        $this->assertTrue($result);
    }

    public function test_device_auth_wrong_password(): void
    {
        Device::create([
            'device_uid'         => 'sensor-002',
            'name'               => '门磁传感器',
            'mqtt_username'      => 'sensor-002',
            'mqtt_password_hash' => password_hash('correct', PASSWORD_BCRYPT),
        ]);

        $this->assertFalse(DeviceService::verifyMqttCredentials('sensor-002', 'wrong'));
    }

    public function test_device_auth_unknown_username(): void
    {
        $this->assertFalse(DeviceService::verifyMqttCredentials('nonexistent', 'any'));
    }

    public function test_device_auth_no_password_hash(): void
    {
        Device::create([
            'device_uid'         => 'sensor-003',
            'name'               => '无密码设备',
            'mqtt_username'      => 'sensor-003',
            'mqtt_password_hash' => null,
        ]);

        $this->assertFalse(DeviceService::verifyMqttCredentials('sensor-003', 'any'));
    }

    /* =============================================
     * MQTT ACL - 超级用户
     * ============================================= */

    public function test_super_user_acl_allows_any_publish(): void
    {
        $this->assertTrue(
            DeviceService::checkMqttAcl('hg_test_super', 'home/upstream/any/telemetry/post', 'publish')
        );
    }

    public function test_super_user_acl_allows_any_subscribe(): void
    {
        $this->assertTrue(
            DeviceService::checkMqttAcl('hg_test_super', 'home/downstream/any/command', 'subscribe')
        );
    }

    public function test_super_user_acl_allows_wildcard(): void
    {
        $this->assertTrue(
            DeviceService::checkMqttAcl('hg_test_super', 'home/upstream/#', 'subscribe')
        );
    }

    /* =============================================
     * MQTT ACL - 普通设备
     * ============================================= */

    public function test_device_can_publish_own_upstream(): void
    {
        Device::create([
            'device_uid'    => 'dev-100',
            'name'          => '测试设备',
            'mqtt_username' => 'dev-100',
        ]);

        $this->assertTrue(
            DeviceService::checkMqttAcl('dev-100', 'home/upstream/dev-100/telemetry/post', 'publish')
        );
    }

    public function test_device_cannot_publish_other_upstream(): void
    {
        Device::create([
            'device_uid'    => 'dev-200',
            'name'          => '设备A',
            'mqtt_username' => 'dev-200',
        ]);

        $this->assertFalse(
            DeviceService::checkMqttAcl('dev-200', 'home/upstream/dev-999/telemetry/post', 'publish')
        );
    }

    public function test_device_can_subscribe_own_downstream(): void
    {
        Device::create([
            'device_uid'    => 'dev-300',
            'name'          => '设备B',
            'mqtt_username' => 'dev-300',
        ]);

        $this->assertTrue(
            DeviceService::checkMqttAcl('dev-300', 'home/downstream/dev-300/command', 'subscribe')
        );
    }

    public function test_device_cannot_subscribe_other_downstream(): void
    {
        Device::create([
            'device_uid'    => 'dev-400',
            'name'          => '设备C',
            'mqtt_username' => 'dev-400',
        ]);

        $this->assertFalse(
            DeviceService::checkMqttAcl('dev-400', 'home/downstream/dev-999/command', 'subscribe')
        );
    }

    public function test_device_cannot_subscribe_upstream(): void
    {
        Device::create([
            'device_uid'    => 'dev-500',
            'name'          => '设备D',
            'mqtt_username' => 'dev-500',
        ]);

        $this->assertFalse(
            DeviceService::checkMqttAcl('dev-500', 'home/upstream/dev-500/telemetry/post', 'subscribe')
        );
    }

    public function test_acl_unknown_device_denied(): void
    {
        $this->assertFalse(
            DeviceService::checkMqttAcl('ghost-device', 'home/upstream/ghost-device/state/post', 'publish')
        );
    }

    public function test_acl_unknown_action_denied(): void
    {
        Device::create([
            'device_uid'    => 'dev-600',
            'name'          => '设备E',
            'mqtt_username' => 'dev-600',
        ]);

        $this->assertFalse(
            DeviceService::checkMqttAcl('dev-600', 'home/upstream/dev-600/telemetry/post', 'unknown')
        );
    }

    /* =============================================
     * 设备在线状态更新
     * ============================================= */

    public function test_update_online_status_sets_online(): void
    {
        Device::create([
            'device_uid'    => 'status-test',
            'name'          => '状态测试',
            'mqtt_username' => 'status-test',
            'is_online'     => false,
        ]);

        DeviceService::updateOnlineStatus('status-test', true);

        $device = Device::where('device_uid', 'status-test')->first();
        $this->assertTrue($device->is_online);
        $this->assertNotNull($device->last_seen);
    }

    public function test_update_online_status_sets_offline(): void
    {
        Device::create([
            'device_uid'    => 'offline-test',
            'name'          => '离线测试',
            'mqtt_username' => 'offline-test',
            'is_online'     => true,
            'last_seen'     => now(),
        ]);

        DeviceService::updateOnlineStatus('offline-test', false);

        $device = Device::where('device_uid', 'offline-test')->first();
        $this->assertFalse($device->is_online);
        $this->assertNull($device->last_seen);
    }

    /* =============================================
     * 设备 CRUD
     * ============================================= */

    public function test_create_device(): void
    {
        $device = DeviceService::create([
            'device_uid'    => 'new-device',
            'name'          => '新设备',
            'type'          => 'camera',
            'mqtt_password' => 'secret123',
        ]);

        $this->assertInstanceOf(Device::class, $device);
        $this->assertEquals('new-device', $device->device_uid);
        $this->assertEquals('new-device', $device->mqtt_username);
        $this->assertNotEmpty($device->mqtt_password_hash);
        $this->assertTrue(password_verify('secret123', $device->mqtt_password_hash));
    }

    public function test_create_duplicate_uid_throws(): void
    {
        DeviceService::create([
            'device_uid' => 'dup-001',
            'name'       => '第一台',
        ]);

        $this->expectException(\app\exception\BusinessException::class);
        DeviceService::create([
            'device_uid' => 'dup-001',
            'name'       => '重复',
        ]);
    }

    public function test_delete_device(): void
    {
        $device = DeviceService::create([
            'device_uid' => 'del-001',
            'name'       => '待删除',
        ]);

        DeviceService::delete($device->id);
        $this->assertNull(Device::find($device->id));
    }

    public function test_delete_nonexistent_throws(): void
    {
        $this->expectException(\app\exception\BusinessException::class);
        DeviceService::delete(99999);
    }
}
